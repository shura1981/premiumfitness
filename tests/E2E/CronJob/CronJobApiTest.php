<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

// La gestión manual de los trabajos de pago de radical (listar, crear, reiniciar y borrar con la
// sesión de cualquier usuario) no existe aquí: solo queda el proceso que dispara el cron.
it('no expone la gestión de trabajos de pago de radical', function (string $method, string $path): void {
    $response = AppFactory::dispatch(AppFactory::request($method, $path, headers: JwtFactory::authHeader(Seed::user())));

    expect($response->getStatusCode())->toBeIn([404, 405]);
})->with([
    ['GET', '/api/cron-job/payment-event-jobs'],
    ['POST', '/api/cron-job/payment-event-jobs'],
    ['DELETE', '/api/cron-job/payment-event-jobs/1'],
]);

it('rejects process endpoint without cron token', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/cron-job/payment-event-jobs/process',
    ));

    expect($response->getStatusCode())->toBe(401);
});

it('la limpieza diaria borra los refresh tokens y códigos viejos, y conserva los vigentes', function (): void {
    $pdo    = Seed::pdo();
    $userId = Seed::user();
    $insert = $pdo->prepare(
        "INSERT INTO refresh_tokens (user_id, app, family_id, token_hash, expires_at) VALUES (?, 'web', UUID(), ?, ?)"
    );
    $old     = hash('sha256', random_bytes(16));
    $current = hash('sha256', random_bytes(16));
    $insert->execute([$userId, $old, date('Y-m-d H:i:s', strtotime('-31 days'))]);
    $insert->execute([$userId, $current, date('Y-m-d H:i:s', strtotime('+1 day'))]);
    $pdo->prepare(
        "INSERT INTO verification_codes (user_id, code, purpose, channel, destination, expires_at, created_at)
         VALUES (?, ?, 'phone_verification', 'sms', '3000000001', ?, ?)"
    )->execute([$userId, hash('sha256', 'viejo'), date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-2 days'))]);

    $withoutToken = AppFactory::dispatch(AppFactory::request('POST', '/api/cron-job/auth/cleanup'));
    $response     = AppFactory::dispatch(AppFactory::request('POST', '/api/cron-job/auth/cleanup', headers: ['X-Cron-Job-Token' => $_ENV['CRON_JOB_TOKEN']]));
    $body         = AppFactory::json($response);

    $left = $pdo->prepare('SELECT token_hash FROM refresh_tokens WHERE user_id = ?');
    $left->execute([$userId]);
    $codes = $pdo->prepare('SELECT COUNT(*) FROM verification_codes WHERE user_id = ?');
    $codes->execute([$userId]);

    expect($withoutToken->getStatusCode())->toBeIn([401, 403])
        ->and($response->getStatusCode())->toBe(200)
        ->and($body['refresh_tokens_deleted'])->toBeGreaterThanOrEqual(1)
        ->and($body['verification_codes_deleted'])->toBeGreaterThanOrEqual(1)
        ->and($left->fetchAll(PDO::FETCH_COLUMN))->toBe([$current])
        ->and((int) $codes->fetchColumn())->toBe(0);
});
