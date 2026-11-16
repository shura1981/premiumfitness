<?php

declare(strict_types=1);

namespace App\Features\Auth\RefreshToken;

use RuntimeException;

/**
 * El refresh token no existe, venció, es de otra app o ya se había usado.
 */
class InvalidRefreshTokenException extends RuntimeException {}
