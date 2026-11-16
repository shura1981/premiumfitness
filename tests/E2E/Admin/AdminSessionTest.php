<?php

declare(strict_types=1);

use App\Infrastructure\Auth\JwtService;
use Tests\Support\AppFactory;
use Tests\Support\Auth;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

/*
 | Sesión del admin (spec seguridad/01, regla 11). Usa el agente de prueba de docker/intranet-local.sql.
 */

const AGENT_EMAIL    = 'agente.prueba@premiumfitness.local';
const AGENT_PASSWORD = 'admin-local-123';

function adminLogin(string $password = AGENT_PASSWORD, string $email = AGENT_EMAIL): Psr\Http\Message\ResponseInterface
{
    return Auth::post('/api/admin/auth/login', ['email' => $email, 'password' => $password]);
}

it('inicia la sesión del admin con el token en memoria y la cookie pf_rt_admin de 12 horas', function (): void {
    $response = adminLogin();
    $body     = AppFactory::json($response);
    $claims   = JwtService::fromEnv()->decode($body['access_token']);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['expires_in'])->toBe(900)
        ->and($body['user'])->toHaveKeys(['id_empleado', 'id_usuario', 'usuario', 'email', 'cargo'])
        ->and($body['user'])->not->toHaveKey('pass')
        ->and($claims->app)->toBe('admin')
        ->and($claims)->not->toHaveProperty('pass')
        ->and(AppFactory::cookieHeader($response, 'pf_rt_admin'))
        ->toContain('Path=/api/admin/auth;')
        ->toContain('HttpOnly')
        ->toContain('Secure')
        ->toContain('SameSite=Strict')
        ->toContain('Max-Age=43200')
        ->and(AppFactory::cookie($response, 'pf_rt_web'))->toBeNull();
});

it('rechaza credenciales incorrectas sin decir cuál falló', function (): void {
    $wrongPassword = adminLogin('no-es-esta');
    $unknownEmail  = adminLogin(AGENT_PASSWORD, 'nadie@premiumfitness.local');

    expect($wrongPassword->getStatusCode())->toBe(401)
        ->and($unknownEmail->getStatusCode())->toBe(401)
        ->and(AppFactory::json($wrongPassword)['message'])->toBe(AppFactory::json($unknownEmail)['message']);
});

it('entra a los endpoints del admin con su token, y el de la app web recibe 403', function (): void {
    $token = AppFactory::json(adminLogin())['access_token'];

    $asAgent = AppFactory::dispatch(AppFactory::request('GET', '/api/admin/agents', headers: ['Authorization' => 'Bearer ' . $token]));
    $asUser  = AppFactory::dispatch(AppFactory::request('GET', '/api/admin/agents', headers: JwtFactory::authHeader(Seed::trainer())));

    expect($asAgent->getStatusCode())->toBe(200)
        ->and($asUser->getStatusCode())->toBe(403);
});

it('renueva la sesión del admin sin extenderla más allá de las 12 horas', function (): void {
    $login = adminLogin();
    $first = (string) AppFactory::cookie($login, 'pf_rt_admin');

    $expiry = Seed::pdo()->prepare('SELECT expires_at FROM refresh_tokens WHERE token_hash = ?');
    $expiry->execute([hash('sha256', $first)]);
    $loginExpiry = (string) $expiry->fetchColumn();

    $refresh = Auth::post('/api/admin/auth/refresh', refreshToken: $first, cookie: 'pf_rt_admin');
    $second  = (string) AppFactory::cookie($refresh, 'pf_rt_admin');
    $expiry->execute([hash('sha256', $second)]);

    expect($refresh->getStatusCode())->toBe(200)
        ->and(AppFactory::json($refresh)['user']['email'])->toBe(AGENT_EMAIL)
        ->and($second)->not->toBe($first)
        ->and((string) $expiry->fetchColumn())->toBe($loginExpiry);
});

it('pide login a las 12 horas', function (): void {
    $token = (string) AppFactory::cookie(adminLogin(), 'pf_rt_admin');
    Seed::pdo()->prepare('UPDATE refresh_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);

    expect(Auth::post('/api/admin/auth/refresh', refreshToken: $token, cookie: 'pf_rt_admin')->getStatusCode())->toBe(401);
});

it('la cookie de una app no renueva la sesión de la otra', function (): void {
    $adminToken = (string) AppFactory::cookie(adminLogin(), 'pf_rt_admin');
    $webToken   = (string) AppFactory::cookie(Auth::login(Seed::phoneOf(Seed::user())), 'pf_rt_web');

    expect(Auth::post('/api/auth/refresh', refreshToken: $adminToken)->getStatusCode())->toBe(401)
        ->and(Auth::post('/api/admin/auth/refresh', refreshToken: $webToken, cookie: 'pf_rt_admin')->getStatusCode())->toBe(401);
});

it('cierra la sesión del admin', function (): void {
    $token  = (string) AppFactory::cookie(adminLogin(), 'pf_rt_admin');
    $logout = Auth::post('/api/admin/auth/logout', refreshToken: $token, cookie: 'pf_rt_admin');

    expect($logout->getStatusCode())->toBe(204)
        ->and(AppFactory::cookieHeader($logout, 'pf_rt_admin'))->toContain('Max-Age=0')
        ->and(Auth::post('/api/admin/auth/refresh', refreshToken: $token, cookie: 'pf_rt_admin')->getStatusCode())->toBe(401);
});
