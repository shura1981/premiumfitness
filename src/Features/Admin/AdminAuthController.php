<?php

declare(strict_types=1);

namespace App\Features\Admin;

use App\Features\Auth\LoginThrottle;
use App\Features\Auth\RefreshToken\InvalidRefreshTokenException;
use App\Features\Auth\RefreshToken\IssuedRefreshToken;
use App\Features\Auth\RefreshToken\RefreshTokenService;
use App\Infrastructure\Auth\JwtService;
use App\Infrastructure\Auth\RefreshCookie;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sesión del admin con los agentes de `crisenri_intranet.tb_empleados` (spec seguridad/01, regla 11).
 * La tabla es de la intranet: solo se lee, y la contraseña se verifica tal como está guardada.
 *
 *   POST /api/admin/auth/login     → correo y contraseña
 *   POST /api/admin/auth/refresh   → renueva con la cookie `pf_rt_admin`; la sesión dura 12 horas
 *   POST /api/admin/auth/logout
 */
class AdminAuthController
{
    use JsonResponder;

    private const APP = 'admin';

    private readonly EmpleadoRepository $repository;
    private readonly RefreshTokenService $refreshTokens;
    private readonly LoginThrottle $throttle;
    private readonly JwtService $jwt;
    private readonly RefreshCookie $cookie;

    public function __construct()
    {
        $this->repository    = new EmpleadoRepository();
        $this->refreshTokens = new RefreshTokenService();
        $this->throttle      = new LoginThrottle('admin-login-failures');
        $this->jwt           = JwtService::fromEnv();
        $this->cookie        = RefreshCookie::admin();
    }

    public function login(Request $request, Response $response): Response
    {
        /** @var array<string, mixed> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $pass  = (string) ($body['password'] ?? '');

        if (($retryAfter = $this->throttle->retryAfter($email)) > 0) {
            throw ApiException::tooManyRequests($retryAfter);
        }

        $empleado = $this->repository->findByEmail($email);

        if ($empleado === false || !$this->verifyPassword($pass, $empleado->pass)) {
            $this->throttle->registerFailure($email);

            throw new ApiException(401, 'invalid_credentials', 'El correo o la contraseña no coinciden.');
        }

        $this->throttle->clear($email);

        $refreshToken = $this->refreshTokens->issue(
            self::APP,
            $empleado->id_usuario,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('User-Agent'),
        );

        return $this->respondWithSession($response, $empleado, $refreshToken);
    }

    public function refresh(Request $request, Response $response): Response
    {
        $token = $this->cookie->read($request);

        if ($token === null) {
            throw ApiException::unauthorized();
        }

        try {
            $rotation = $this->refreshTokens->rotate(
                self::APP,
                $token,
                (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (InvalidRefreshTokenException) {
            throw ApiException::unauthorized();
        }

        $empleado = $this->repository->findById($rotation->ownerId);

        if ($empleado === false) {
            throw ApiException::unauthorized();
        }

        return $this->respondWithSession($response, $empleado, $rotation->issued);
    }

    public function logout(Request $request, Response $response): Response
    {
        $token = $this->cookie->read($request);

        if ($token !== null) {
            $this->refreshTokens->revokeFamilyOf($token);
        }

        return $this->cookie->clear($this->noContent($response));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** La intranet guarda algunas contraseñas con hash y otras no; aquí no se cambian (S-06). */
    private function verifyPassword(string $plainText, string $stored): bool
    {
        if ($stored === '' || $plainText === '') {
            return false;
        }

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
            return password_verify($plainText, $stored);
        }

        return hash_equals($stored, $plainText);
    }

    private function respondWithSession(Response $response, EmpleadoModel $empleado, ?IssuedRefreshToken $refreshToken): Response
    {
        $agent = [
            'id_empleado'  => $empleado->id,
            'id_usuario'   => $empleado->id_usuario,
            'usuario'      => $empleado->usuario,
            'email'        => $empleado->email,
            'cargo'        => $empleado->cargo,
            'departamento' => $empleado->departamento,
            'foto'         => $empleado->foto,
            'celular'      => $empleado->celular === null ? null : (string) $empleado->celular,
            'sede'         => $empleado->sede,
        ];

        $accessToken = $this->jwt->issue(['sub' => $empleado->id_usuario, 'app' => self::APP, 'F' => $empleado->F] + $agent);

        $response = $this->json($response, [
            'access_token' => $accessToken,
            'expires_in'   => $this->jwt->ttlSeconds(),
            'user'         => $agent,
        ])->withHeader('Cache-Control', 'no-store');

        return $refreshToken === null
            ? $response
            : $this->cookie->set($response, $refreshToken->token, $refreshToken->expiresAt);
    }
}
