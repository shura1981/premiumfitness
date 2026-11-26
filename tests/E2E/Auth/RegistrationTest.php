<?php

declare(strict_types=1);

use App\Features\Referral\ReferralLink;
use App\Infrastructure\Notification\InMemorySmsChannel;
use Tests\Support\AppFactory;
use Tests\Support\Auth;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Registro, verificación del celular, recuperación y enlaces del entrenador
 | (specs seguridad/01, arquitectura/02, negocio/01 y negocio/02).
 */

/** @return array<string, mixed> */
function userRow(string $phone): array
{
    $stmt = Seed::pdo()->prepare('SELECT * FROM users WHERE num_cell = ?');
    $stmt->execute([$phone]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

it('registra una cuenta sin verificar y envía el código por SMS, sin devolverlo', function (): void {
    $data     = Auth::registration();
    $response = Auth::post('/api/auth/register', $data);
    $row      = userRow($data['phone']);
    $code     = Auth::lastCode($data['phone']);

    expect($response->getStatusCode())->toBe(201)
        ->and(AppFactory::json($response))->toBe(['phone' => $data['phone'], 'expires_in' => 300])
        ->and((string) $response->getBody())->not->toContain((string) $code)
        ->and(AppFactory::cookie($response, 'pf_rt_web'))->toBeNull()
        ->and($row['status'])->toBe('pending')
        ->and($row['role'])->toBe('client')
        ->and($row['full_name'])->toBe('Laura Prueba Gómez')
        ->and(password_verify($data['password'], $row['password']))->toBeTrue()
        ->and($code)->toMatch('/^\d{6}$/');

    // En la base solo está el hash del código.
    $stmt = Seed::pdo()->prepare('SELECT code, purpose, destination FROM verification_codes WHERE user_id = ?');
    $stmt->execute([$row['id']]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);

    expect($stored['code'])->toHaveLength(64)->not->toContain((string) $code)
        ->and($stored['purpose'])->toBe('phone_verification')
        ->and($stored['destination'])->toBe($data['phone']);
});

it('rechaza datos inválidos con un texto por campo', function (): void {
    $response = Auth::post('/api/auth/register', Auth::registration(['phone' => '6011234567', 'password' => 'corta', 'role' => 'admin']));
    $body     = AppFactory::json($response);

    expect($response->getStatusCode())->toBe(422)
        ->and($body['code'])->toBe('validation')
        ->and($body['errors'])->toHaveKeys(['phone', 'password', 'role'])
        ->and($body['errors']['phone'])->toBeString();
});

it('verifica el celular, activa la cuenta y abre la sesión', function (): void {
    $data = Auth::registration();
    Auth::post('/api/auth/register', $data);

    $response = Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => Auth::lastCode($data['phone'])]);
    $row      = userRow($data['phone']);

    expect($response->getStatusCode())->toBe(200)
        ->and(AppFactory::json($response)['user'])->toMatchArray(['phone' => $data['phone'], 'role' => 'client'])
        ->and(AppFactory::cookie($response, 'pf_rt_web'))->not->toBeNull()
        ->and($row['status'])->toBe('active')
        ->and($row['phone_verified_at'])->not->toBeNull()
        ->and(Auth::login($data['phone'], $data['password'])->getStatusCode())->toBe(200);
});

it('un código solo sirve una vez', function (): void {
    $data = Auth::registration();
    Auth::post('/api/auth/register', $data);
    $code = Auth::lastCode($data['phone']);

    Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => $code]);
    Seed::pdo()->prepare("UPDATE users SET status = 'pending' WHERE num_cell = ?")->execute([$data['phone']]);

    expect(Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => $code])->getStatusCode())->toBe(422);
});

it('al sexto intento el código falla aunque sea el correcto', function (): void {
    $data = Auth::registration();
    Auth::post('/api/auth/register', $data);
    $code  = (string) Auth::lastCode($data['phone']);
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 5; $i++) {
        expect(Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => $wrong])->getStatusCode())->toBe(422);
    }

    $response = Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => $code]);

    expect($response->getStatusCode())->toBe(422)
        ->and(AppFactory::json($response)['code'])->toBe('invalid_code')
        ->and(userRow($data['phone'])['status'])->toBe('pending');
});

it('un código vencido no sirve', function (): void {
    $data = Auth::registration();
    Auth::post('/api/auth/register', $data);
    Seed::pdo()->prepare('UPDATE verification_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE destination = ?')
        ->execute([$data['phone']]);

    expect(Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => Auth::lastCode($data['phone'])])->getStatusCode())->toBe(422);
});

it('un código nuevo invalida el anterior', function (): void {
    $data = Auth::registration();
    Auth::post('/api/auth/register', $data);
    $first = (string) Auth::lastCode($data['phone']);

    expect(Auth::post('/api/auth/resend-code', ['phone' => $data['phone']])->getStatusCode())->toBe(200);
    $second = (string) Auth::lastCode($data['phone']);

    if ($first !== $second) {
        expect(Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => $first])->getStatusCode())->toBe(422);
    }

    expect(Auth::post('/api/auth/verify-phone', ['phone' => $data['phone'], 'code' => $second])->getStatusCode())->toBe(200);
});

it('no registra dos cuentas con el mismo celular, correo o documento', function (string $field, string $code): void {
    $existing = Auth::registration();
    Auth::registerAndVerify($existing);

    $response = Auth::post('/api/auth/register', Auth::registration([$field => $existing[$field]]));

    expect($response->getStatusCode())->toBe(409)
        ->and(AppFactory::json($response)['code'])->toBe($code);
})->with([
    ['phone', 'phone_taken'],
    ['email', 'email_taken'],
    ['document_id', 'document_taken'],
]);

it('un celular registrado pero nunca verificado queda para quien sí lo verifica', function (): void {
    $squatter = Auth::registration(['role' => 'trainer']);
    Auth::post('/api/auth/register', $squatter);

    $owner    = Auth::registration(['phone' => $squatter['phone'], 'full_name' => 'Dueña Real Del Celular', 'password' => 'clave-de-la-duena-2']);
    $register = Auth::post('/api/auth/register', $owner);
    $verify   = Auth::post('/api/auth/verify-phone', ['phone' => $owner['phone'], 'code' => Auth::lastCode($owner['phone'])]);
    $row      = userRow($owner['phone']);

    $trainerRow = Seed::pdo()->prepare('SELECT COUNT(*) FROM trainers WHERE user_id = ?');
    $trainerRow->execute([$row['id']]);

    expect($register->getStatusCode())->toBe(201)
        ->and($verify->getStatusCode())->toBe(200)
        ->and($row['full_name'])->toBe('Dueña Real Del Celular')
        ->and($row['role'])->toBe('client')
        ->and((int) $trainerRow->fetchColumn())->toBe(0)
        // La contraseña de quien lo registró primero deja de servir.
        ->and(Auth::login($owner['phone'], $squatter['password'])->getStatusCode())->toBe(401)
        ->and(Auth::login($owner['phone'], $owner['password'])->getStatusCode())->toBe(200);
});

it('limita los códigos a 3 por minuto por celular, aunque cambie la IP', function (): void {
    $data = Auth::registration();
    Auth::post('/api/auth/register', $data);
    Auth::post('/api/auth/resend-code', ['phone' => $data['phone']]);
    Auth::post('/api/auth/resend-code', ['phone' => $data['phone']]);
    Auth::post('/api/auth/resend-code', ['phone' => $data['phone']]);

    $response = Auth::post('/api/auth/resend-code', ['phone' => $data['phone']]);

    expect($response->getStatusCode())->toBe(429)
        ->and($response->getHeaderLine('Retry-After'))->not->toBe('');
});

it('no envía más de 10 códigos al día a un celular', function (): void {
    $data = Auth::registration();
    Auth::post('/api/auth/register', $data);
    $userId = userRow($data['phone'])['id'];

    $insert = Seed::pdo()->prepare(
        "INSERT INTO verification_codes (user_id, code, purpose, channel, destination, expires_at, used_at)
         VALUES (?, ?, 'phone_verification', 'sms', ?, NOW(), NOW())"
    );
    for ($i = 0; $i < 9; $i++) {
        $insert->execute([$userId, hash('sha256', (string) $i), $data['phone']]);
    }

    $sentBefore = InMemorySmsChannel::countTo($data['phone']);
    $response   = Auth::post('/api/auth/resend-code', ['phone' => $data['phone']]);

    expect($response->getStatusCode())->toBe(429)
        ->and(InMemorySmsChannel::countTo($data['phone']))->toBe($sentBefore);
});

it('registra a un entrenador con su cupón único', function (): void {
    $response = Auth::registerAndVerify(['role' => 'trainer', 'full_name' => 'Jorgé Herrera']);
    $userId   = AppFactory::json($response)['user']['id'];

    $stmt = Seed::pdo()->prepare('SELECT coupon_code FROM trainers WHERE user_id = ?');
    $stmt->execute([$userId]);

    expect(AppFactory::json($response)['user']['role'])->toBe('trainer')
        ->and((string) $stmt->fetchColumn())->toMatch('/^JORGE\d{2,}$/');
});

// ── Enlaces del entrenador ────────────────────────────────────────────────

it('muestra el nombre del entrenador de un enlace y nada más', function (): void {
    $trainerId = Seed::trainer();
    $response  = AppFactory::dispatch(AppFactory::request('GET', '/api/referrals/trainers/' . ReferralLink::encodeId($trainerId)));

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys(AppFactory::json($response)))->toBe(['full_name', 'link_id'])
        ->and(AppFactory::json($response)['link_id'])->toBe(ReferralLink::encodeId($trainerId));
});

it('un enlace inválido, de un cliente o de un entrenador suspendido responde 404', function (callable $id): void {
    $response = AppFactory::dispatch(AppFactory::request('GET', '/api/referrals/trainers/' . $id()));

    expect($response->getStatusCode())->toBe(404);
})->with([
    'no es un id'   => [fn (): string => 'abc'],
    'no existe'     => [fn (): string => ReferralLink::encodeId(999_999_999)],
    'es un cliente' => [fn (): string => ReferralLink::encodeId(Seed::user())],
    'suspendido'    => [function (): string {
        $trainerId = Seed::trainer();
        Seed::pdo()->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$trainerId]);

        return ReferralLink::encodeId($trainerId);
    }],
]);

it('asocia al cliente que se registra por el enlace /r/c del entrenador', function (): void {
    $trainerId = Seed::trainer();
    $response  = Auth::registerAndVerify(referral: ['kind' => 'c', 'id' => ReferralLink::encodeId($trainerId)]);
    $user      = AppFactory::json($response)['user'];

    $stmt = Seed::pdo()->prepare('SELECT trainer_id, source FROM client_referrals WHERE client_id = ?');
    $stmt->execute([$user['id']]);

    expect($user['has_trainer'])->toBeTrue()
        ->and($user['trainer_name'])->toBeString()
        ->and($stmt->fetch(PDO::FETCH_ASSOC))->toBe(['trainer_id' => $trainerId, 'source' => 'link']);
});

it('asocia al cliente ya registrado que abre el enlace e inicia sesión, y no le cambia el entrenador después', function (): void {
    $clientId = Seed::user();
    $first    = Seed::trainer();
    $phone    = Seed::phoneOf($clientId);

    $login = Auth::post('/api/auth/login', ['phone' => $phone, 'password' => Seed::PASSWORD, 'role' => 'client', 'referral' => ['kind' => 'c', 'id' => ReferralLink::encodeId($first)]]);
    Auth::post('/api/auth/login', ['phone' => $phone, 'password' => Seed::PASSWORD, 'role' => 'client', 'referral' => ['kind' => 'c', 'id' => ReferralLink::encodeId(Seed::trainer())]]);

    $stmt = Seed::pdo()->prepare('SELECT trainer_id FROM client_referrals WHERE client_id = ?');
    $stmt->execute([$clientId]);

    expect(AppFactory::json($login)['user']['has_trainer'])->toBeTrue()
        ->and($stmt->fetchAll(PDO::FETCH_COLUMN))->toBe([$first]);
});

it('registra al entrenador B como referido de A con los puntos del día, pendientes', function (): void {
    $referrerId = Seed::trainer();
    $response   = Auth::registerAndVerify(['role' => 'trainer'], ['kind' => 't', 'id' => ReferralLink::encodeId($referrerId)]);

    $stmt = Seed::pdo()->prepare('SELECT referrer_id, referral_points, activated_at, activation_order_id FROM trainer_referrals WHERE referred_id = ?');
    $stmt->execute([AppFactory::json($response)['user']['id']]);

    expect($stmt->fetch(PDO::FETCH_ASSOC))->toBe([
        'referrer_id'         => $referrerId,
        'referral_points'     => (int) $_ENV['TRAINER_REFERRAL_POINTS'],
        'activated_at'        => null,
        'activation_order_id' => null,
    ]);
});

it('un enlace no convierte cuentas ni crea vínculos que no aplican', function (string $role, string $kind, string $table, string $column): void {
    $linkTrainer = Seed::trainer();
    $userId      = $role === 'trainer' ? Seed::trainer() : Seed::user();

    $login = Auth::post('/api/auth/login', [
        'phone'    => Seed::phoneOf($userId),
        'password' => Seed::PASSWORD,
        'role'     => $role,
        'referral' => ['kind' => $kind, 'id' => ReferralLink::encodeId($linkTrainer)],
    ]);

    $stmt = Seed::pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$userId]);

    expect($login->getStatusCode())->toBe(200)
        ->and(AppFactory::json($login)['user']['role'])->toBe($role)
        ->and((int) $stmt->fetchColumn())->toBe(0);
})->with([
    'cliente abre /r/t: sigue siendo cliente'        => ['client', 't', 'trainer_referrals', 'referred_id'],
    'entrenador ya registrado abre /r/t: sin referente' => ['trainer', 't', 'trainer_referrals', 'referred_id'],
    'entrenador abre /r/c: no queda como cliente de otro' => ['trainer', 'c', 'client_referrals', 'client_id'],
]);

it('un enlace inválido no impide iniciar sesión', function (mixed $referral): void {
    $response = Auth::post('/api/auth/login', ['phone' => Seed::phoneOf(Seed::user()), 'password' => Seed::PASSWORD, 'role' => 'client', 'referral' => $referral]);

    expect($response->getStatusCode())->toBe(200)
        ->and(AppFactory::json($response)['user']['has_trainer'])->toBeFalse();
})->with([
    'texto'            => ['MQ'],
    'id que no existe' => [['kind' => 'c', 'id' => 'OTk5OTk5OTk5']],
    'tipo desconocido' => [['kind' => 'x', 'id' => 'MQ']],
    'id no numérico'   => [['kind' => 'c', 'id' => "MSBPUiAxPTE"]],
]);

it('el cliente asocia a su entrenador con el cupón, una sola vez', function (): void {
    $trainerId = Seed::trainer();
    $coupon    = Seed::pdo()->prepare('SELECT coupon_code FROM trainers WHERE user_id = ?');
    $coupon->execute([$trainerId]);
    $code    = (string) $coupon->fetchColumn();
    $headers = JwtFactory::authHeader(Seed::user());

    $first  = AppFactory::dispatch(AppFactory::request('POST', '/api/client/coupon', ['coupon_code' => ' ' . strtolower($code) . ' '], $headers));
    $second = AppFactory::dispatch(AppFactory::request('POST', '/api/client/coupon', ['coupon_code' => $code], $headers));

    expect($first->getStatusCode())->toBe(200)
        ->and(AppFactory::json($first))->toMatchArray(['has_trainer' => true, 'role' => 'client'])
        ->and($second->getStatusCode())->toBe(409)
        ->and(AppFactory::json($second)['code'])->toBe('already_linked');
});

it('el cupón no sirve si no existe o si quien lo escribe es un entrenador', function (): void {
    $unknown = AppFactory::dispatch(AppFactory::request('POST', '/api/client/coupon', ['coupon_code' => 'NOEXISTE99'], JwtFactory::authHeader(Seed::user())));
    $trainer = AppFactory::dispatch(AppFactory::request('POST', '/api/client/coupon', ['coupon_code' => 'NOEXISTE99'], JwtFactory::authHeader(Seed::trainer())));

    expect($unknown->getStatusCode())->toBe(404)
        ->and(AppFactory::json($unknown)['code'])->toBe('coupon_not_found')
        ->and($trainer->getStatusCode())->toBe(403);
});

// ── Cambio de entrenador desde el enlace de otro (D-11, spec backend/03 P-36) ──

function changeTrainer(int $clientId, string $linkId): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('POST', '/api/client/trainer', ['link_id' => $linkId], JwtFactory::authHeader($clientId)));
}

it('el cliente cambia de entrenador desde el enlace de otro: solo cambian las compras nuevas', function (): void {
    [$client, $first] = Seed::clientWithTrainer();
    $second           = Seed::trainer();
    $code             = Seed::catalogProduct(['valor' => 100000]);
    $paid             = Shop::order($client, [['code' => $code, 'quantity' => 2]]);
    Shop::pay($paid);
    // Un pedido creado antes del cambio y pagado después sigue siendo del entrenador de ese momento.
    $unpaid = Shop::order($client, [['code' => $code, 'quantity' => 1]]);

    $response = changeTrainer($client, ReferralLink::encodeId($second));
    Shop::pay($unpaid);
    $after = Shop::order($client, [['code' => $code, 'quantity' => 1]]);
    Shop::pay($after);

    $trainerOf = fn (int $orderId): int => (int) Shop::rows('SELECT trainer_id FROM orders_coupons WHERE order_id = ?', [$orderId])[0]['trainer_id'];
    $audit     = Shop::rows("SELECT user_id, data FROM audit_logs WHERE action = 'client.trainer_changed' AND entity_id = ?", [$client]);

    expect($response->getStatusCode())->toBe(200)
        ->and(AppFactory::json($response))->toMatchArray(['has_trainer' => true, 'trainer_link_id' => ReferralLink::encodeId($second)])
        ->and(Shop::rows('SELECT trainer_id, source FROM client_referrals WHERE client_id = ?', [$client]))->toBe([['trainer_id' => $second, 'source' => 'link']])
        ->and([$trainerOf($paid), $trainerOf($unpaid), $trainerOf($after)])->toBe([$first, $first, $second])
        // La comisión y los puntos de lo ya comprado siguen con el primero.
        ->and(Shop::rows("SELECT COUNT(*) n FROM commission_movements WHERE trainer_id = ? AND type = 'sale_credit'", [$first])[0]['n'])->toBe(2)
        ->and(Shop::rows("SELECT COUNT(*) n FROM commission_movements WHERE trainer_id = ? AND type = 'sale_credit'", [$second])[0]['n'])->toBe(1)
        ->and($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]['data'], true))->toBe(['from_trainer_id' => $first, 'to_trainer_id' => $second])
        ->and(Shop::rows("SELECT COUNT(*) n FROM push_notifications WHERE user_id = ? AND type = 'client_linked'", [$second])[0]['n'])->toBe(1)
        ->and(Shop::rows("SELECT COUNT(*) n FROM push_notifications WHERE user_id = ? AND type = 'client_linked'", [$first])[0]['n'])->toBe(0);
});

it('el enlace de su mismo entrenador no cambia nada, y sin entrenador lo asocia', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $alone              = Seed::user();
    $other              = Seed::trainer();

    $same    = changeTrainer($client, ReferralLink::encodeId($trainer));
    $without = changeTrainer($alone, ReferralLink::encodeId($other));

    expect($same->getStatusCode())->toBe(200)
        ->and(Shop::rows("SELECT COUNT(*) n FROM audit_logs WHERE action = 'client.trainer_changed' AND entity_id = ?", [$client])[0]['n'])->toBe(0)
        ->and($without->getStatusCode())->toBe(200)
        ->and(AppFactory::json($without))->toMatchArray(['has_trainer' => true, 'trainer_link_id' => ReferralLink::encodeId($other)])
        ->and(Shop::rows('SELECT trainer_id FROM client_referrals WHERE client_id = ?', [$alone]))->toBe([['trainer_id' => $other]]);
});

it('no cambia de entrenador con un enlace inválido, de un entrenador suspendido o desde una cuenta de entrenador', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $suspended          = Seed::trainer();
    Seed::pdo()->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$suspended]);

    $invalid    = changeTrainer($client, 'abc');
    $notActive  = changeTrainer($client, ReferralLink::encodeId($suspended));
    $fromCoach  = changeTrainer(Seed::trainer(), ReferralLink::encodeId($trainer));
    $noLink     = AppFactory::dispatch(AppFactory::request('POST', '/api/client/trainer', [], JwtFactory::authHeader($client)));

    expect($invalid->getStatusCode())->toBe(404)
        ->and($notActive->getStatusCode())->toBe(404)
        ->and($fromCoach->getStatusCode())->toBe(403)
        ->and($noLink->getStatusCode())->toBe(422)
        ->and(Shop::rows('SELECT trainer_id FROM client_referrals WHERE client_id = ?', [$client]))->toBe([['trainer_id' => $trainer]]);
});

// ── Recuperación de la contraseña ─────────────────────────────────────────

it('recupera la contraseña con el código SMS y cierra todas las sesiones', function (): void {
    $userId  = Seed::user();
    $phone   = Seed::phoneOf($userId);
    $session = AppFactory::cookie(Auth::login($phone), 'pf_rt_web');

    $forgot = Auth::post('/api/auth/forgot-password', ['phone' => $phone]);
    $verify = Auth::post('/api/auth/verify-reset-code', ['phone' => $phone, 'code' => Auth::lastCode($phone)]);
    $token  = AppFactory::json($verify)['reset_token'];
    $reset  = Auth::post('/api/auth/reset-password', ['reset_token' => $token, 'password' => 'recuperada-789']);

    $stored = Seed::pdo()->prepare('SELECT token FROM password_reset_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stored->execute([$userId]);

    expect($forgot->getStatusCode())->toBe(200)
        ->and(AppFactory::json($forgot))->toBe(['expires_in' => 300])
        ->and($verify->getStatusCode())->toBe(200)
        ->and(AppFactory::json($verify)['expires_in'])->toBe(600)
        ->and(AppFactory::cookie($verify, 'pf_rt_web'))->toBeNull()
        ->and((string) $stored->fetchColumn())->not->toBe($token)
        ->and($reset->getStatusCode())->toBe(204)
        ->and(Auth::post('/api/auth/refresh', refreshToken: $session)->getStatusCode())->toBe(401)
        ->and(Auth::login($phone)->getStatusCode())->toBe(401)
        ->and(Auth::login($phone, 'recuperada-789')->getStatusCode())->toBe(200)
        // El token es de un solo uso.
        ->and(Auth::post('/api/auth/reset-password', ['reset_token' => $token, 'password' => 'otra-clave-000'])->getStatusCode())->toBe(422);
});

it('no revela si un celular tiene cuenta al pedir la recuperación', function (): void {
    $unknown  = Seed::phone();
    $response = Auth::post('/api/auth/forgot-password', ['phone' => $unknown]);

    expect($response->getStatusCode())->toBe(200)
        ->and(AppFactory::json($response))->toBe(['expires_in' => 300])
        ->and(InMemorySmsChannel::countTo($unknown))->toBe(0);
});

it('el código de verificar el celular no sirve para cambiar la contraseña', function (): void {
    $userId = Seed::user();
    $phone  = Seed::phoneOf($userId);
    Seed::pdo()->prepare("UPDATE users SET status = 'pending' WHERE id = ?")->execute([$userId]);
    Auth::post('/api/auth/resend-code', ['phone' => $phone]);
    $code = Auth::lastCode($phone);
    Seed::pdo()->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$userId]);

    expect($code)->not->toBeNull()
        ->and(Auth::post('/api/auth/verify-reset-code', ['phone' => $phone, 'code' => $code])->getStatusCode())->toBe(422);
});

it('un token de recuperación inventado o vencido no cambia la contraseña', function (): void {
    $userId = Seed::user();
    $phone  = Seed::phoneOf($userId);
    Auth::post('/api/auth/forgot-password', ['phone' => $phone]);
    $token = AppFactory::json(Auth::post('/api/auth/verify-reset-code', ['phone' => $phone, 'code' => Auth::lastCode($phone)]))['reset_token'];
    Seed::pdo()->prepare('UPDATE password_reset_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE user_id = ?')->execute([$userId]);

    expect(Auth::post('/api/auth/reset-password', ['reset_token' => $token, 'password' => 'recuperada-789'])->getStatusCode())->toBe(422)
        ->and(Auth::post('/api/auth/reset-password', ['reset_token' => str_repeat('a', 64), 'password' => 'recuperada-789'])->getStatusCode())->toBe(422)
        ->and(Auth::login($phone)->getStatusCode())->toBe(200);
});

it('el entrenador puede elegir su cupón al registrarse, si está libre', function (): void {
    $coupon = 'FIT' . random_int(10000, 99999);
    $first  = Auth::registerAndVerify(['role' => 'trainer', 'coupon_code' => strtolower($coupon)]);
    $taken  = Auth::post('/api/auth/register', Auth::registration(['role' => 'trainer', 'coupon_code' => $coupon]));
    $bad    = Auth::post('/api/auth/register', Auth::registration(['role' => 'trainer', 'coupon_code' => 'con espacios']));

    $stored = Seed::pdo()->prepare('SELECT coupon_code FROM trainers WHERE user_id = ?');
    $stored->execute([AppFactory::json($first)['user']['id']]);

    expect((string) $stored->fetchColumn())->toBe($coupon)
        ->and($taken->getStatusCode())->toBe(409)
        ->and(AppFactory::json($taken)['code'])->toBe('coupon_taken')
        ->and($bad->getStatusCode())->toBe(422);
});

it('avisa al entrenador cuando un cliente se asocia o un entrenador se registra con su enlace', function (): void {
    $trainer = Seed::trainer();
    Auth::registerAndVerify(referral: ['kind' => 'c', 'id' => ReferralLink::encodeId($trainer)]);
    Auth::registerAndVerify(['role' => 'trainer'], ['kind' => 't', 'id' => ReferralLink::encodeId($trainer)]);

    $stmt = Seed::pdo()->prepare('SELECT type, body FROM push_notifications WHERE user_id = ? ORDER BY id');
    $stmt->execute([$trainer]);
    $queued = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect(array_column($queued, 'type'))->toBe(['client_linked', 'trainer_referred'])
        // El aviso no lleva el nombre ni el celular del cliente.
        ->and($queued[0]['body'])->not->toContain('Laura');
});

// ── Direcciones ───────────────────────────────────────────────────────────

it('gestiona las direcciones propias y no deja tocar las de otra persona', function (): void {
    $user    = Seed::user();
    $headers = JwtFactory::authHeader($user);
    $city    = (int) Seed::pdo()->query('SELECT MIN(id) FROM cities')->fetchColumn();
    $send    = fn (string $method, string $path, ?array $body = null, ?array $as = null) => AppFactory::dispatch(AppFactory::request($method, '/api/account/addresses' . $path, $body, $as ?? $headers));

    $first  = AppFactory::json($send('POST', '', ['name' => 'Casa', 'address' => 'CL 10 20 30', 'city_id' => $city]));
    $second = AppFactory::json($send('POST', '', ['name' => 'Gimnasio', 'address' => 'CR 5 6 7', 'city_id' => $city]));
    $other  = JwtFactory::authHeader(Seed::user());

    expect($first)->toMatchArray(['name' => 'Casa', 'city_id' => $city, 'is_main' => true, 'locality_id' => null])
        ->and($second['is_main'])->toBeFalse()
        // Otra persona no la ve, no la cambia y no la borra.
        ->and(AppFactory::json($send('GET', '', as: $other))['data'])->toBe([])
        ->and($send('PUT', '/' . $first['id'], ['name' => 'X1', 'address' => 'Dirección ajena', 'city_id' => $city], $other)->getStatusCode())->toBe(404)
        ->and($send('DELETE', '/' . $first['id'], as: $other)->getStatusCode())->toBe(404)
        ->and($send('PATCH', '/' . $first['id'] . '/main', as: $other)->getStatusCode())->toBe(404);

    $send('PATCH', '/' . $second['id'] . '/main');
    $updated = AppFactory::json($send('PUT', '/' . $second['id'], ['name' => 'Gimnasio nuevo', 'address' => 'CR 5 6 8', 'city_id' => $city]));
    $list    = AppFactory::json($send('GET', ''))['data'];

    expect($updated)->toMatchArray(['name' => 'Gimnasio nuevo', 'address' => 'CR 5 6 8', 'is_main' => true])
        ->and(array_column($list, 'id'))->toBe([$second['id'], $first['id']])
        ->and($send('DELETE', '/' . $second['id'])->getStatusCode())->toBe(204);

    // Al borrar la principal, la que queda pasa a serlo.
    expect(AppFactory::json($send('GET', ''))['data'])->toHaveCount(1)
        ->and(AppFactory::json($send('GET', ''))['data'][0])->toMatchArray(['id' => $first['id'], 'is_main' => true])
        ->and($send('POST', '', ['name' => 'Casa', 'address' => 'CL 1', 'city_id' => 999999])->getStatusCode())->toBe(422);
});
