<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Duraciones y límites de la autenticación (spec seguridad/01).
 */
final readonly class AuthConfig
{
    public function __construct(
        public int $accessTtlSeconds,
        public int $refreshTtlSeconds,
        public int $adminSessionTtlSeconds,
        public int $otpLength,
        public int $otpTtlSeconds,
        public int $otpMaxAttempts,
        public int $otpMaxSendsPerDay,
        public string $secret,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            accessTtlSeconds: (int) ($_ENV['JWT_ACCESS_TTL_MINUTES'] ?? 15) * 60,
            refreshTtlSeconds: (int) ($_ENV['JWT_REFRESH_TTL_DAYS'] ?? 30) * 86400,
            adminSessionTtlSeconds: (int) ($_ENV['ADMIN_SESSION_TTL_HOURS'] ?? 12) * 3600,
            otpLength: (int) ($_ENV['OTP_LENGTH'] ?? 6),
            otpTtlSeconds: (int) ($_ENV['OTP_TTL_MINUTES'] ?? 5) * 60,
            otpMaxAttempts: (int) ($_ENV['OTP_MAX_ATTEMPTS'] ?? 5),
            otpMaxSendsPerDay: (int) ($_ENV['OTP_MAX_SENDS_PER_DAY'] ?? 10),
            secret: $_ENV['JWT_REFRESH_SECRET'],
        );
    }

    /** Hash con clave de un secreto corto (código SMS, token): no sirve sin `JWT_REFRESH_SECRET`. */
    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->secret);
    }
}
