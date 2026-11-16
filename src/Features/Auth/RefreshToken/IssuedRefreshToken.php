<?php

declare(strict_types=1);

namespace App\Features\Auth\RefreshToken;

/**
 * Refresh token recién emitido. `token` solo existe aquí y en la cookie: en la base se guarda su hash.
 */
final readonly class IssuedRefreshToken
{
    public function __construct(
        public string $token,
        public int $expiresAt,
    ) {}
}
