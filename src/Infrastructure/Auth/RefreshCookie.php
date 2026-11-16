<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cookie del refresh token: una por app, limitada a la ruta de auth de esa app.
 * `httpOnly`, `Secure` y `SameSite=Strict` (spec seguridad/01, regla 6).
 */
final readonly class RefreshCookie
{
    private function __construct(
        private string $name,
        private string $path,
    ) {}

    public static function web(): self
    {
        return new self('pf_rt_web', '/api/auth');
    }

    public static function admin(): self
    {
        return new self('pf_rt_admin', '/api/admin/auth');
    }

    public function read(Request $request): ?string
    {
        $value = $request->getCookieParams()[$this->name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(Response $response, string $token, int $expiresAt): Response
    {
        return $response->withAddedHeader('Set-Cookie', $this->header($token, max(0, $expiresAt - time())));
    }

    public function clear(Response $response): Response
    {
        return $response->withAddedHeader('Set-Cookie', $this->header('', 0));
    }

    private function header(string $value, int $maxAge): string
    {
        return sprintf(
            '%s=%s; Path=%s; Max-Age=%d; HttpOnly; Secure; SameSite=Strict',
            $this->name,
            rawurlencode($value),
            $this->path,
            $maxAge,
        );
    }
}
