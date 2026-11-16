<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Features\Auth\RefreshToken\RefreshTokenRepository;
use App\Features\Auth\VerificationCode\VerificationCodeRepository;
use App\Infrastructure\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/cron-job/auth/cleanup — limpieza diaria, disparada por el cron de cPanel
 * (`X-Cron-Job-Token`). Borra los refresh tokens vencidos hace más de 30 días y los códigos SMS
 * usados o vencidos. Se puede repetir sin efecto.
 */
class AuthCleanupController
{
    use JsonResponder;

    private const KEEP_EXPIRED_TOKENS_DAYS = 30;

    public function cleanup(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'refresh_tokens_deleted'     => new RefreshTokenRepository()->deleteExpiredOlderThan(self::KEEP_EXPIRED_TOKENS_DAYS),
            'verification_codes_deleted' => new VerificationCodeRepository()->deleteStale(),
        ]);
    }
}
