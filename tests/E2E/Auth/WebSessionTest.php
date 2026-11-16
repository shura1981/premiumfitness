<?php

declare(strict_types=1);

use App\Infrastructure\Auth\JwtService;
use Tests\Support\AppFactory;
use Tests\Support\Auth;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

/*
 | Sesión de la app web (spec seguridad/01, criterios 1, 4, 5 y 8).
 */

it('inicia sesión con celular y contraseña y entrega el token en memoria y la cookie de renovación', function (): void {
    $userId   = Seed::trainer();
    $response = Auth::login(Seed::phoneOf($userId));
    $body     = AppFactory::json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['expires_in'])->toBe(900)
        ->and($body['user'])->toMatchArray(['id' => $userId, 'role' => 'trainer', 'has_trainer' => false, 'trainer_name' => null])
        ->and($body)->not->toHaveKey('refresh_token');

    $claims = JwtService::fromEnv()->decode($body['access_token']);
    expect($claims->sub)->toBe($userId)
        ->and($claims->role)->toBe('trainer')
        ->and($claims->app)->toBe('web');

    // El refresh token solo viaja en la cookie, y solo a /api/auth.
    expect(AppFactory::cookieHeader($response, 'pf_rt_web'))
        ->toContain('Path=/api/auth;')
        ->toContain('HttpOnly')
        ->toContain('Secure')
        ->toContain('SameSite=Strict')
        ->toContain('Max-Age=2592000');
});

it('responde lo mismo si el celular no existe o la contraseña no coincide', function (): void {
    $wrongPassword = Auth::login(Seed::phoneOf(Seed::user()), 'otra-clave-123');
    $unknownPhone  = Auth::login(Seed::phone());

    expect($wrongPassword->getStatusCode())->toBe(401)
        ->and($unknownPhone->getStatusCode())->toBe(401)
        ->and(AppFactory::json($wrongPassword))->toBe(AppFactory::json($unknownPhone))
        ->and(AppFactory::json($wrongPassword)['code'])->toBe('invalid_credentials')
        ->and(AppFactory::cookie($wrongPassword, 'pf_rt_web'))->toBeNull();
});

it('cada login solo acepta cuentas de su rol y no abre sesión con las del otro', function (string $accountRole, string $loginRole): void {
    $userId   = $accountRole === 'trainer' ? Seed::trainer() : Seed::user();
    $response = Auth::login(Seed::phoneOf($userId), role: $loginRole);
    $body     = AppFactory::json($response);

    expect($response->getStatusCode())->toBe(403)
        ->and($body['code'])->toBe('wrong_area')
        ->and($body['role'])->toBe($accountRole)
        ->and($body['message'])->toContain($accountRole === 'trainer' ? 'entrenador' : 'cliente')
        ->and($body)->not->toHaveKey('access_token')
        ->and(AppFactory::cookie($response, 'pf_rt_web'))->toBeNull();
})->with([
    'entrenador en /entrar'     => ['trainer', 'client'],
    'cliente en /coach/entrar' => ['client', 'trainer'],
]);

it('con una contraseña errada no revela de qué rol es la cuenta', function (): void {
    $response = Auth::login(Seed::phoneOf(Seed::trainer()), 'otra-clave-123', role: 'client');

    expect($response->getStatusCode())->toBe(401)
        ->and(AppFactory::json($response)['code'])->toBe('invalid_credentials');
});

it('exige el rol del login', function (?string $role): void {
    $body = ['phone' => Seed::phoneOf(Seed::user()), 'password' => Seed::PASSWORD] + ($role === null ? [] : ['role' => $role]);

    $response = Auth::post('/api/auth/login', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and(AppFactory::json($response)['errors'])->toHaveKey('role');
})->with(['sin rol' => [null], 'rol desconocido' => ['admin']]);

it('no deja entrar a una cuenta suspendida ni a una sin verificar', function (string $status, string $code): void {
    $userId = Seed::user();
    Seed::pdo()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]);

    $response = Auth::login(Seed::phoneOf($userId));

    expect($response->getStatusCode())->toBe(403)
        ->and(AppFactory::json($response)['code'])->toBe($code)
        ->and(AppFactory::json($response))->not->toHaveKey('access_token')
        ->and(AppFactory::cookie($response, 'pf_rt_web'))->toBeNull();
})->with([
    'suspendida'    => ['suspended', 'account_suspended'],
    'sin verificar' => ['pending', 'phone_not_verified'],
]);

it('bloquea la cuenta tras 10 contraseñas fallidas aunque cambie la IP', function (): void {
    $phone = Seed::phoneOf(Seed::user());

    for ($i = 0; $i < 10; $i++) {
        expect(Auth::login($phone, 'incorrecta-' . $i)->getStatusCode())->toBe(401);
    }

    $blocked = Auth::login($phone);

    expect($blocked->getStatusCode())->toBe(429)
        ->and($blocked->getHeaderLine('Retry-After'))->not->toBe('');
});

it('limita el login a 5 por minuto desde una misma IP', function (): void {
    $ip = AppFactory::randomIp();

    for ($i = 0; $i < 5; $i++) {
        Auth::post('/api/auth/login', ['phone' => Seed::phone(), 'password' => 'cualquiera-1', 'role' => 'client'], ip: $ip);
    }

    $response = Auth::post('/api/auth/login', ['phone' => Seed::phone(), 'password' => 'cualquiera-1', 'role' => 'client'], ip: $ip);

    expect($response->getStatusCode())->toBe(429)
        ->and($response->getHeaderLine('Retry-After'))->not->toBe('');
});

it('renueva la sesión con la cookie y rota el refresh token', function (): void {
    $userId = Seed::user();
    $first  = AppFactory::cookie(Auth::login(Seed::phoneOf($userId)), 'pf_rt_web');

    $refresh = Auth::post('/api/auth/refresh', refreshToken: $first);
    $second  = AppFactory::cookie($refresh, 'pf_rt_web');

    expect($refresh->getStatusCode())->toBe(200)
        ->and(AppFactory::json($refresh)['user']['id'])->toBe($userId)
        ->and($second)->not->toBeNull()->not->toBe($first);
});

it('responde 401 al renovar sin cookie o con una cookie inventada', function (?string $token): void {
    expect(Auth::post('/api/auth/refresh', refreshToken: $token)->getStatusCode())->toBe(401);
})->with([null, 'token-inventado']);

it('revoca toda la sesión si se reutiliza un refresh token ya rotado', function (): void {
    $stolen  = AppFactory::cookie(Auth::login(Seed::phoneOf(Seed::user())), 'pf_rt_web');
    $current = AppFactory::cookie(Auth::post('/api/auth/refresh', refreshToken: $stolen), 'pf_rt_web');

    // Pasado el margen de dos pestañas simultáneas, el token viejo solo puede venir de un robo.
    Seed::pdo()->exec('UPDATE refresh_tokens SET revoked_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE revoked_at IS NOT NULL');

    expect(Auth::post('/api/auth/refresh', refreshToken: $stolen)->getStatusCode())->toBe(401)
        ->and(Auth::post('/api/auth/refresh', refreshToken: $current)->getStatusCode())->toBe(401);
});

it('dos pestañas que renuevan a la vez con la misma cookie no cierran la sesión', function (): void {
    $shared = AppFactory::cookie(Auth::login(Seed::phoneOf(Seed::user())), 'pf_rt_web');

    $tabA = Auth::post('/api/auth/refresh', refreshToken: $shared);
    $tabB = Auth::post('/api/auth/refresh', refreshToken: $shared);

    // La segunda recibe su access token, pero la cookie vigente es la que puso la primera.
    expect($tabB->getStatusCode())->toBe(200)
        ->and(AppFactory::cookie($tabB, 'pf_rt_web'))->toBeNull()
        ->and(Auth::post('/api/auth/refresh', refreshToken: AppFactory::cookie($tabA, 'pf_rt_web'))->getStatusCode())->toBe(200);
});

it('cierra la sesión al salir: la cookie se borra y ya no renueva', function (): void {
    $token  = AppFactory::cookie(Auth::login(Seed::phoneOf(Seed::user())), 'pf_rt_web');
    $logout = Auth::post('/api/auth/logout', refreshToken: $token);

    expect($logout->getStatusCode())->toBe(204)
        ->and(AppFactory::cookieHeader($logout, 'pf_rt_web'))->toContain('Max-Age=0')
        ->and(Auth::post('/api/auth/refresh', refreshToken: $token)->getStatusCode())->toBe(401);
});

it('deja de renovar si la cuenta se suspende', function (): void {
    $userId = Seed::user();
    $token  = AppFactory::cookie(Auth::login(Seed::phoneOf($userId)), 'pf_rt_web');
    Seed::pdo()->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$userId]);

    expect(Auth::post('/api/auth/refresh', refreshToken: $token)->getStatusCode())->toBe(401);
});

it('la sesión vence a los 30 días sin uso', function (): void {
    $token = AppFactory::cookie(Auth::login(Seed::phoneOf(Seed::user())), 'pf_rt_web');
    Seed::pdo()->prepare('UPDATE refresh_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE token_hash = ?')
        ->execute([hash('sha256', (string) $token)]);

    expect(Auth::post('/api/auth/refresh', refreshToken: $token)->getStatusCode())->toBe(401);
});

it('cambia la contraseña, cierra las demás sesiones y conserva la actual', function (): void {
    $userId      = Seed::user();
    $phone       = Seed::phoneOf($userId);
    $otherDevice = AppFactory::cookie(Auth::login($phone), 'pf_rt_web');

    $change = AppFactory::dispatch(AppFactory::request(
        'PUT',
        '/api/auth/password',
        ['current_password' => Seed::PASSWORD, 'password' => 'clave-nueva-456'],
        JwtFactory::authHeader($userId),
    ));

    expect($change->getStatusCode())->toBe(200)
        ->and(AppFactory::cookie($change, 'pf_rt_web'))->not->toBeNull()
        ->and(Auth::post('/api/auth/refresh', refreshToken: $otherDevice)->getStatusCode())->toBe(401)
        ->and(Auth::login($phone)->getStatusCode())->toBe(401)
        ->and(Auth::login($phone, 'clave-nueva-456')->getStatusCode())->toBe(200);
});

it('no cambia la contraseña si la actual no coincide', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'PUT',
        '/api/auth/password',
        ['current_password' => 'no-es-esta-1', 'password' => 'clave-nueva-456'],
        JwtFactory::authHeader(Seed::user()),
    ));

    expect($response->getStatusCode())->toBe(422)
        ->and(AppFactory::json($response)['errors'])->toHaveKey('current_password');
});

it('un token del admin no sirve en la app web', function (): void {
    $account = AppFactory::dispatch(AppFactory::request('GET', '/api/account', headers: JwtFactory::adminHeader()));

    expect($account->getStatusCode())->toBe(401);
});

it('devuelve el perfil de quien tiene la sesión y deja cambiar nombre y correo', function (): void {
    $userId  = Seed::user();
    $headers = JwtFactory::authHeader($userId);
    $email   = uniqid('nuevo_', true) . '@example.com';

    $update = AppFactory::dispatch(AppFactory::request('PUT', '/api/account', ['full_name' => 'nombre  nuevo completo', 'email' => strtoupper($email)], $headers));
    $show   = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/account', headers: $headers)));

    expect($update->getStatusCode())->toBe(200)
        ->and($show)->toMatchArray(['id' => $userId, 'full_name' => 'nombre nuevo completo', 'email' => $email, 'role' => 'client'])
        ->and($show)->toHaveKeys(['phone', 'document_id', 'document_type_id', 'document_type_name'])
        ->and($show)->not->toHaveKey('password');
});

it('no deja usar el correo de otra cuenta', function (): void {
    $other = Seed::user();
    $stmt  = Seed::pdo()->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$other]);

    $response = AppFactory::dispatch(AppFactory::request(
        'PUT',
        '/api/account',
        ['full_name' => 'Nombre Completo', 'email' => (string) $stmt->fetchColumn()],
        JwtFactory::authHeader(Seed::user()),
    ));

    expect($response->getStatusCode())->toBe(409)
        ->and(AppFactory::json($response)['code'])->toBe('email_taken');
});

it('sin token responde 401 con un texto para mostrar, no un mensaje técnico', function (): void {
    $body = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/products')));

    expect($body['message'])->toBe('Tu sesión terminó. Vuelve a entrar.')
        ->and($body['code'])->toBe('unauthorized')
        ->and($body)->not->toHaveKey('error');
});

it('busca ciudades poniendo primero la coincidencia exacta', function (): void {
    $body = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/cities/search?q=cali')));

    expect($body['data'][0]['name'])->toBe('CALI')
        ->and(array_keys($body['data'][0]))->toBe(['id', 'name', 'department'])
        ->and(count($body['data']))->toBeLessThanOrEqual(20)
        ->and(AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/cities/search?q=%25')))['data'])->toBe([]);
});
