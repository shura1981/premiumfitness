<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\Seed;

/*
 | Regresión de seguridad (specs arquitectura/01, S-05 y seguridad/01):
 | radical entregaba un JWT con solo conocer el id o el documento de un usuario.
 | En PremiumFitness ningún endpoint emite tokens sin contraseña o sin un código verificado.
 */

it('does not issue a token from a user id alone', function (): void {
    $userId = Seed::user();

    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/auth/login-with-credential',
        body: ['user_id' => $userId],
    ));

    expect($response->getStatusCode())->toBeIn([404, 405])
        ->and((string) $response->getBody())->not->toContain('"token"');
});

it('does not log a user in from a QR link', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/qr?id=' . base64_encode('1010123456'),
    ));

    expect($response->getStatusCode())->toBeIn([404, 405])
        ->and($response->getHeaderLine('Location'))->toBe('')
        ->and($response->getHeaderLine('Set-Cookie'))->toBe('');
});
