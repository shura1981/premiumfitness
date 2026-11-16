<?php

declare(strict_types=1);

namespace App\Features\Auth\RefreshToken;

/**
 * Resultado de renovar una sesión. `issued` es null cuando otra petición simultánea
 * ya rotó el token: la cookie vigente es la que puso esa petición.
 */
final readonly class RefreshRotation
{
    public function __construct(
        public int $ownerId,
        public ?IssuedRefreshToken $issued,
    ) {}
}
