import { createHmac, randomInt, randomUUID, timingSafeEqual } from 'node:crypto';

/** All fields are resolved/validated by the server, never trusted from a DTO. */
export interface Context {
  tenant: string;
  subject: string;
  destination: string; // Canonical, validated phone.
  purpose: string;
  session: string; // First-factor session for MFA, otherwise empty.
}
export type Delivery = 'prepared' | 'accepted' | 'rejected' | 'unknown';
export interface Challenge {
  id: string;
  mac: string;
  expiresAt: number;
  failures: number;
  consumed: boolean;
  delivery: Delivery;
}
export interface Bucket { count: number; resetAt: number }
export interface State {
  challenges: Record<string, Challenge>;
  buckets: Record<string, Bucket>;
}
export interface Store {
  /**
   * Serialize across ALL clients/instances. Commit mutations even when a callback
   * returns a business rejection. Roll back on exception. Callback must not do IO.
   * Obtain a coherent clock INSIDE this boundary. This broad transaction can be
   * replaced with scoped locks/CAS only if challenge + quota guarantees survive.
   */
  atomic<T>(operation: (state: State) => T): Promise<T>;
}
export interface Sender {
  /** Timeouts/ambiguous results return unknown. No automatic retry. */
  send(destination: string, message: string, idempotencyKey: string): Promise<Exclude<Delivery, 'prepared'>>;
}
export interface Policy { limit: number; seconds: number }
export interface Options {
  ttl: number;
  maxFailures: number;
  cooldown: number;
  requestIp: Policy;
  requestDestination: Policy;
  verifyIp: Policy;
  verifyDestination: Policy;
  globalSend: Policy;
}
export const defaults: Options = {
  ttl: 300, maxFailures: 3, cooldown: 60,
  requestIp: { limit: 3, seconds: 60 }, requestDestination: { limit: 5, seconds: 3600 },
  verifyIp: { limit: 5, seconds: 60 }, verifyDestination: { limit: 10, seconds: 900 },
  globalSend: { limit: 100, seconds: 3600 }, // Set to the actual service budget.
};
export type Result<T> = { ok: true; value: T } | { ok: false; reason: 'invalid' | 'limited'; retryAfter: number };

export class OtpService {
  constructor(
    private readonly store: Store,
    private readonly sender: Sender,
    private readonly secret: string,
    private readonly message: (code: string, ttl: number) => string,
    private readonly options: Options = defaults,
    private readonly now: () => number = () => Math.floor(Date.now() / 1000),
  ) {
    if (Buffer.byteLength(secret) < 32) throw new Error('HMAC key must contain at least 32 random bytes');
    const numbers = [options.ttl, options.maxFailures, options.cooldown,
      ...[options.requestIp, options.requestDestination, options.verifyIp, options.verifyDestination, options.globalSend]
        .flatMap(policy => [policy.limit, policy.seconds])];
    if (numbers.some(n => !Number.isSafeInteger(n) || n < 1)) throw new Error('Invalid OTP configuration');
  }

  async request(context: Context, ip: string): Promise<Result<{ id: string; expiresIn: number; delivery: Delivery }>> {
    const scope = this.scope(context);
    const id = randomUUID();
    const code = randomInt(0, 1_000_000).toString().padStart(6, '0');
    const body = this.message(code, this.options.ttl); // Fail before reserving state.
    const reservation = await this.store.atomic(state => {
      const now = this.now();
      const retryAfter = this.consume(state, [
        [this.key('request-ip', ip), this.options.requestIp],
        [this.key('request-destination', context.destination), this.options.requestDestination],
        [this.key('cooldown', context.destination), { limit: 1, seconds: this.options.cooldown }],
        [this.key('global-send', 'service'), this.options.globalSend],
      ], now);
      if (retryAfter > 0) return retryAfter;
      state.challenges[scope] = { id, mac: this.key('otp', scope, id, code),
        expiresAt: now + this.options.ttl, failures: 0, consumed: false, delivery: 'prepared' };
      return 0;
    });
    if (reservation > 0) return { ok: false, reason: 'limited', retryAfter: reservation };
    let delivery: Exclude<Delivery, 'prepared'>;
    try { delivery = await this.sender.send(context.destination, body, id); }
    catch { delivery = 'unknown'; } // Never log provider errors containing the body.
    await this.store.atomic(state => {
      const record = state.challenges[scope];
      if (record?.id === id) record.delivery = delivery;
    });
    return { ok: true, value: { id, expiresIn: this.options.ttl, delivery } };
  }

  async verify(context: Context, ip: string, id: string, code: string): Promise<Result<{ verified: true }>> {
    const scope = this.scope(context);
    return this.store.atomic(state => {
      const now = this.now();
      const retryAfter = this.consume(state, [
        [this.key('verify-ip', ip), this.options.verifyIp],
        [this.key('verify-destination', context.destination), this.options.verifyDestination],
      ], now);
      if (retryAfter > 0) return { ok: false, reason: 'limited', retryAfter };
      const invalid = { ok: false, reason: 'invalid', retryAfter: 0 } as const;
      const record = state.challenges[scope];
      if (!record || record.id !== id || record.consumed || record.delivery === 'rejected'
        || now >= record.expiresAt || record.failures >= this.options.maxFailures) return invalid;
      const candidate = this.key('otp', scope, id, code);
      if (!/^[0-9]{6}$/.test(code) || !timingSafeEqual(Buffer.from(record.mac, 'hex'), Buffer.from(candidate, 'hex'))) {
        record.failures += 1;
        return invalid; // Commit the counter; do not throw/roll back.
      }
      record.consumed = true;
      return { ok: true, value: { verified: true } };
    });
  }

  private scope(context: Context): string {
    return this.key('scope', context.tenant, context.subject, context.destination, context.purpose, context.session);
  }
  private key(...parts: string[]): string {
    return createHmac('sha256', this.secret).update(JSON.stringify(parts)).digest('hex');
  }
  private consume(state: State, policies: [string, Policy][], now: number): number {
    let retryAfter = 0;
    for (const [key, policy] of policies) {
      const bucket = state.buckets[key];
      if (bucket && now < bucket.resetAt && bucket.count >= policy.limit)
        retryAfter = Math.max(retryAfter, bucket.resetAt - now);
    }
    if (retryAfter > 0) return retryAfter;
    for (const [key, policy] of policies) {
      const bucket = state.buckets[key];
      state.buckets[key] = !bucket || now >= bucket.resetAt
        ? { count: 1, resetAt: now + policy.seconds }
        : { ...bucket, count: bucket.count + 1 };
    }
    return 0;
  }
}

/** Demo/test ONLY: a single JS process, synchronous callback, no external IO. */
export class MemoryStore implements Store {
  private state: State = { challenges: {}, buckets: {} };
  async atomic<T>(operation: (state: State) => T): Promise<T> {
    const draft = structuredClone(this.state);
    const result = operation(draft);
    this.state = draft;
    return result;
  }
}
