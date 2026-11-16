<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Features\Auth\RefreshToken\IssuedRefreshToken;

/**
 * Sesión abierta o renovada: el cuerpo de la respuesta y el refresh token para la cookie.
 * `refreshToken` es null cuando la cookie vigente ya la puso otra petición simultánea.
 */
final readonly class SessionResult
{
    /** @param array<string, mixed> $user */
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public array $user,
        public ?IssuedRefreshToken $refreshToken,
    ) {}

    /** @return array{access_token:string, expires_in:int, user:array<string, mixed>} */
    public function body(): array
    {
        return ['access_token' => $this->accessToken, 'expires_in' => $this->expiresIn, 'user' => $this->user];
    }
}
