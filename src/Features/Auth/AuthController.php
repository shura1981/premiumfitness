<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Features\Referral\ReferralLink;
use App\Infrastructure\Auth\RefreshCookie;
use App\Infrastructure\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Autenticación de la app web (contrato: specs/backend/03-api-de-premiumfitness.plan.md).
 *
 *   POST /api/auth/register            → crea la cuenta y envía el código SMS
 *   POST /api/auth/verify-phone        → verifica el celular y abre la sesión
 *   POST /api/auth/resend-code         → reenvía el código
 *   POST /api/auth/login               → celular y contraseña
 *   POST /api/auth/refresh             → renueva con la cookie `pf_rt_web`
 *   POST /api/auth/logout              → cierra la sesión
 *   POST /api/auth/forgot-password     → envía el código de recuperación
 *   POST /api/auth/verify-reset-code   → cambia el código por un token de un solo uso
 *   POST /api/auth/reset-password      → fija la contraseña nueva
 *   PUT  /api/auth/password            → cambia la contraseña (con sesión) y cierra las demás sesiones
 */
class AuthController
{
    use JsonResponder;

    private readonly AuthService $auth;
    private readonly RefreshCookie $cookie;

    public function __construct()
    {
        $this->auth   = new AuthService();
        $this->cookie = RefreshCookie::web();
    }

    public function register(Request $request, Response $response): Response
    {
        $body      = self::body($request);
        $expiresIn = $this->auth->register($body);

        return $this->json($response, ['phone' => (string) PhoneNumber::normalize($body['phone']), 'expires_in' => $expiresIn], 201);
    }

    public function verifyPhone(Request $request, Response $response): Response
    {
        $body    = self::body($request);
        $session = $this->auth->verifyPhone(
            (string) $body['phone'],
            (string) $body['code'],
            ReferralLink::fromRequest($body['referral'] ?? null),
            self::ip($request),
            $request->getHeaderLine('User-Agent'),
        );

        return $this->respondWithSession($response, $session);
    }

    public function resendCode(Request $request, Response $response): Response
    {
        return $this->json($response, ['expires_in' => $this->auth->resendCode((string) self::body($request)['phone'])]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body    = self::body($request);
        $session = $this->auth->login(
            (string) $body['phone'],
            (string) $body['password'],
            (string) $body['role'],
            ReferralLink::fromRequest($body['referral'] ?? null),
            self::ip($request),
            $request->getHeaderLine('User-Agent'),
        );

        return $this->respondWithSession($response, $session);
    }

    public function refresh(Request $request, Response $response): Response
    {
        $session = $this->auth->refresh($this->cookie->read($request), self::ip($request), $request->getHeaderLine('User-Agent'));

        return $this->respondWithSession($response, $session);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout($this->cookie->read($request));

        return $this->cookie->clear($this->noContent($response));
    }

    public function forgotPassword(Request $request, Response $response): Response
    {
        return $this->json($response, ['expires_in' => $this->auth->forgotPassword((string) self::body($request)['phone'])]);
    }

    public function verifyResetCode(Request $request, Response $response): Response
    {
        $body = self::body($request);

        return $this->json($response, $this->auth->verifyResetCode((string) $body['phone'], (string) $body['code']));
    }

    public function resetPassword(Request $request, Response $response): Response
    {
        $body = self::body($request);
        $this->auth->resetPassword((string) $body['reset_token'], (string) $body['password']);

        return $this->cookie->clear($this->noContent($response));
    }

    /**
     * Va bajo /api/auth para poder cambiar la cookie: las demás sesiones se cierran y esta sigue.
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $body   = self::body($request);
        $claims = $request->getAttribute('jwt_claims');

        $session = $this->auth->changePassword(
            is_object($claims) ? (int) ($claims->sub ?? 0) : 0,
            (string) $body['current_password'],
            (string) $body['password'],
            self::ip($request),
            $request->getHeaderLine('User-Agent'),
        );

        return $this->respondWithSession($response, $session);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function respondWithSession(Response $response, SessionResult $session): Response
    {
        $response = $this->json($response, $session->body())->withHeader('Cache-Control', 'no-store');

        return $session->refreshToken === null
            ? $response
            : $this->cookie->set($response, $session->refreshToken->token, $session->refreshToken->expiresAt);
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    private static function ip(Request $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
