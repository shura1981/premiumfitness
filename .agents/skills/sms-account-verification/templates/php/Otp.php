<?php

declare(strict_types=1);

namespace PortableSms;

use Closure;
use InvalidArgumentException;
use Throwable;

/** All fields are resolved/validated server-side, not trusted from request DTOs. */
final readonly class Context
{
    public function __construct(
        public string $tenant,
        public string $subject,
        public string $destination,
        public string $purpose,
        public string $session = '',
    ) {}
}

final class Challenge
{
    public int $failures = 0;
    public bool $consumed = false;
    public string $delivery = 'prepared';

    public function __construct(public string $id, public string $mac, public int $expiresAt) {}
}

final class State
{
    /** @var array<string, Challenge> */
    public array $challenges = [];
    /** @var array<string, array{count: int, resetAt: int}> */
    public array $buckets = [];
}

interface Store
{
    /**
     * Serialize across ALL instances, commit business rejections, rollback exceptions.
     * Callback must not perform IO. Read coherent time inside this boundary.
     * Production implementation must cover challenge + quota mutations together.
     * @template T
     * @param Closure(State): T $operation
     * @return T
     */
    public function atomic(Closure $operation): mixed;
}

interface Sender
{
    /** Return accepted/rejected/unknown; timeout is unknown. Never retry blindly. */
    public function send(string $destination, string $message, string $idempotencyKey): string;
}

final readonly class Policy
{
    public function __construct(public int $limit, public int $seconds)
    {
        if ($limit < 1 || $seconds < 1) {
            throw new InvalidArgumentException('Invalid rate policy');
        }
    }
}

final readonly class Options
{
    public function __construct(
        public int $ttl = 300,
        public int $maxFailures = 3,
        public int $cooldown = 60,
        public Policy $requestIp = new Policy(3, 60),
        public Policy $requestDestination = new Policy(5, 3600),
        public Policy $verifyIp = new Policy(5, 60),
        public Policy $verifyDestination = new Policy(10, 900),
        public Policy $globalSend = new Policy(100, 3600),
    ) {
        if ($ttl < 1 || $maxFailures < 1 || $cooldown < 1) {
            throw new InvalidArgumentException('Invalid OTP options');
        }
    }
}

/** Transport-neutral result: HTTP adapter maps limited to 429 + Retry-After. */
final readonly class Result
{
    public function __construct(
        public bool $ok,
        public string $reason = '',
        public int $retryAfter = 0,
        public ?string $id = null,
        public int $expiresIn = 0,
        public ?string $delivery = null,
    ) {}
}

final class OtpService
{
    /** @param Closure(string, int): string $message @param Closure(): int $now */
    public function __construct(
        private readonly Store $store,
        private readonly Sender $sender,
        private readonly string $secret,
        private readonly Closure $message,
        private readonly Closure $now,
        private readonly Options $options = new Options(),
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('HMAC key must contain at least 32 random bytes');
        }
    }

    public function request(Context $context, string $ip): Result
    {
        $scope = $this->scope($context);
        $id = bin2hex(random_bytes(16));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $body = ($this->message)($code, $this->options->ttl);
        $retryAfter = $this->store->atomic(function (State $state) use ($scope, $context, $ip, $id, $code): int {
            $now = ($this->now)();
            $retry = $this->consume($state, [
                [$this->key('request-ip', $ip), $this->options->requestIp],
                [$this->key('request-destination', $context->destination), $this->options->requestDestination],
                [$this->key('cooldown', $context->destination), new Policy(1, $this->options->cooldown)],
                [$this->key('global-send', 'service'), $this->options->globalSend],
            ], $now);
            if ($retry > 0) {
                return $retry;
            }
            $state->challenges[$scope] = new Challenge($id, $this->key('otp', $scope, $id, $code), $now + $this->options->ttl);
            return 0;
        });
        if ($retryAfter > 0) {
            return new Result(false, 'limited', $retryAfter);
        }
        try {
            $delivery = $this->sender->send($context->destination, $body, $id);
            if (!in_array($delivery, ['accepted', 'rejected', 'unknown'], true)) {
                $delivery = 'unknown';
            }
        } catch (Throwable) {
            $delivery = 'unknown'; // Do not log errors containing SMS payloads.
        }
        $this->store->atomic(static function (State $state) use ($scope, $id, $delivery): void {
            $record = $state->challenges[$scope] ?? null;
            if ($record?->id === $id) {
                $record->delivery = $delivery;
            }
        });
        return new Result(true, id: $id, expiresIn: $this->options->ttl, delivery: $delivery);
    }

    public function verify(Context $context, string $ip, string $id, string $code): Result
    {
        $scope = $this->scope($context);
        return $this->store->atomic(function (State $state) use ($scope, $context, $ip, $id, $code): Result {
            $now = ($this->now)();
            $retry = $this->consume($state, [
                [$this->key('verify-ip', $ip), $this->options->verifyIp],
                [$this->key('verify-destination', $context->destination), $this->options->verifyDestination],
            ], $now);
            if ($retry > 0) {
                return new Result(false, 'limited', $retry);
            }
            $record = $state->challenges[$scope] ?? null;
            if ($record === null || $record->id !== $id || $record->consumed || $record->delivery === 'rejected'
                || $now >= $record->expiresAt || $record->failures >= $this->options->maxFailures) {
                return new Result(false, 'invalid');
            }
            $candidate = $this->key('otp', $scope, $id, $code);
            if (preg_match('/\A[0-9]{6}\z/', $code) !== 1 || !hash_equals($record->mac, $candidate)) {
                $record->failures++;
                return new Result(false, 'invalid'); // Commit failed attempt.
            }
            $record->consumed = true;
            return new Result(true);
        });
    }

    private function scope(Context $context): string
    {
        return $this->key('scope', $context->tenant, $context->subject, $context->destination, $context->purpose, $context->session);
    }

    private function key(string ...$parts): string
    {
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->secret);
    }

    /** @param list<array{string, Policy}> $policies */
    private function consume(State $state, array $policies, int $now): int
    {
        $retry = 0;
        foreach ($policies as [$key, $policy]) {
            $bucket = $state->buckets[$key] ?? null;
            if ($bucket !== null && $now < $bucket['resetAt'] && $bucket['count'] >= $policy->limit) {
                $retry = max($retry, $bucket['resetAt'] - $now);
            }
        }
        if ($retry > 0) {
            return $retry;
        }
        foreach ($policies as [$key, $policy]) {
            $bucket = $state->buckets[$key] ?? null;
            $state->buckets[$key] = $bucket === null || $now >= $bucket['resetAt']
                ? ['count' => 1, 'resetAt' => $now + $policy->seconds]
                : ['count' => $bucket['count'] + 1, 'resetAt' => $bucket['resetAt']];
        }
        return 0;
    }
}

/** Demo/test ONLY: synchronous callbacks in a single PHP object/process. */
final class MemoryStore implements Store
{
    private State $state;

    public function __construct()
    {
        $this->state = new State();
    }

    public function atomic(Closure $operation): mixed
    {
        $draft = clone $this->state;
        $draft->challenges = array_map(static fn (Challenge $record): Challenge => clone $record, $draft->challenges);
        $result = $operation($draft);
        $this->state = $draft;
        return $result;
    }
}
