import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MemoryStore, OtpService, defaults, type Context, type Delivery, type Sender } from './otp.ts';

const context: Context = { tenant: 'demo', subject: 'user-1', destination: '+573001234567', purpose: 'login', session: '' };
function setup() {
  let now = 1000;
  let code = '';
  let sends = 0;
  let delivery: Exclude<Delivery, 'prepared'> = 'accepted';
  const sender: Sender = { async send(_destination, body) { code = body; sends++; return delivery; } };
  const service = new OtpService(new MemoryStore(), sender, 's'.repeat(32), otp => otp, defaults, () => now);
  return { service, code: () => code, sends: () => sends, advance: (n: number) => { now += n; },
    reject: () => { delivery = 'rejected'; } };
}
async function issue(f: ReturnType<typeof setup>) {
  const result = await f.service.request(context, '127.0.0.1');
  assert.equal(result.ok, true);
  if (!result.ok) throw new Error('Request failed');
  assert.match(f.code(), /^[0-9]{6}$/);
  assert.equal(JSON.stringify(result).includes(f.code()), false);
  return result.value.id;
}
test('only one of two concurrent verifications succeeds; replay fails', async () => {
  const f = setup(); const id = await issue(f);
  const results = await Promise.all([f.service.verify(context, 'a', id, f.code()), f.service.verify(context, 'b', id, f.code())]);
  assert.equal(results.filter(r => r.ok).length, 1);
  assert.equal((await f.service.verify(context, 'c', id, f.code())).ok, false);
});
test('wrong guesses persist; changing IP does not reset the challenge budget', async () => {
  const f = setup(); const id = await issue(f);
  for (let i = 0; i < 3; i++) assert.equal((await f.service.verify(context, String(i), id, 'wrong!')).ok, false);
  assert.equal((await f.service.verify(context, 'fresh-ip', id, f.code())).ok, false);
});
test('exact expiry, context binding, resend invalidation and cooldown', async () => {
  const f = setup(); const first = await issue(f); const old = f.code();
  assert.equal((await f.service.verify({ ...context, purpose: 'reset' }, 'a', first, old)).ok, false);
  const blocked = await f.service.request(context, 'other-ip');
  assert.deepEqual(blocked, { ok: false, reason: 'limited', retryAfter: 60 });
  assert.equal(f.sends(), 1);
  f.advance(60); const second = await issue(f);
  assert.equal((await f.service.verify(context, 'a', first, old)).ok, false);
  f.advance(300);
  assert.equal((await f.service.verify(context, 'a', second, f.code())).ok, false);
});
test('two simultaneous first requests send only once; rejected delivery cannot verify', async () => {
  const f = setup();
  const results = await Promise.all([f.service.request(context, 'a'), f.service.request(context, 'b')]);
  assert.equal(results.filter(r => r.ok).length, 1); assert.equal(f.sends(), 1);
  const rejected = setup(); rejected.reject(); const id = await issue(rejected);
  assert.equal((await rejected.service.verify(context, 'a', id, rejected.code())).ok, false);
});
test('late rejection of an older send does not invalidate a replacement', async () => {
  let now = 1000;
  let calls = 0;
  let code = '';
  let rejectFirst!: (result: 'rejected') => void;
  const sender: Sender = { async send(_destination, body) {
    calls++;
    if (calls === 1) return new Promise<'rejected'>(resolve => { rejectFirst = resolve; });
    code = body; return 'accepted';
  } };
  const service = new OtpService(new MemoryStore(), sender, 's'.repeat(32), otp => otp, defaults, () => now);
  const first = service.request(context, 'a');
  await Promise.resolve(); // First sender entered after reservation.
  now += 60;
  const replacement = await service.request(context, 'b');
  assert.equal(replacement.ok, true);
  rejectFirst('rejected'); await first;
  if (!replacement.ok) throw new Error('Expected replacement');
  assert.equal((await service.verify(context, 'c', replacement.value.id, code)).ok, true);
});
test('destination quota survives resend and IP rotation, then resets', async () => {
  const f = setup();
  for (let i = 0; i < 5; i++) {
    assert.equal((await f.service.request(context, String(i))).ok, true);
    f.advance(60);
  }
  const blocked = await f.service.request(context, 'new-ip');
  assert.deepEqual(blocked, { ok: false, reason: 'limited', retryAfter: 3300 });
  assert.equal(f.sends(), 5);
  f.advance(3300);
  assert.equal((await f.service.request(context, 'new-ip')).ok, true);
});
test('sender timeout is unknown and never retried automatically', async () => {
  let sends = 0;
  let code = '';
  const service = new OtpService(new MemoryStore(), { async send(_destination, body) {
    sends++; code = body; throw new Error('ambiguous timeout');
  } }, 's'.repeat(32), otp => otp);
  const result = await service.request(context, 'a');
  assert.equal(result.ok, true);
  if (!result.ok) throw new Error('Expected reservation');
  assert.equal(result.value.delivery, 'unknown'); assert.equal(sends, 1);
  assert.equal((await service.verify(context, 'a', result.value.id, code)).ok, true);
});
