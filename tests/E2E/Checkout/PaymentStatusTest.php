<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Resultado de un intento de pago para la página de respuesta (spec integraciones/03): la app consulta
 | una vez con el id de pago (`pf-<n>`) y muestra lo que guardó el webhook. Solo el webhook cambia el estado.
 */

function paymentStatus(int $userId, string $reference): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('GET', '/api/payments/' . $reference, headers: JwtFactory::authHeader($userId)));
}

it('antes del webhook el pago está pendiente; con el webhook muestra lo que reportó la pasarela', function (): void {
    $user      = Seed::user();
    $orderId   = Shop::order($user, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);
    $reference = Shop::reference($orderId);
    $amount    = (float) Shop::orderRow($orderId)['amount'];
    // Único: la base de las pruebas no se limpia y una transacción repetida no aprueba.
    $paymentId = 'PAY-' . bin2hex(random_bytes(8));

    $pending = AppFactory::json(paymentStatus($user, $reference));
    Shop::boldWebhook($orderId, $amount, paymentId: $paymentId);
    $approved = AppFactory::json(paymentStatus($user, $reference));

    expect($pending)->toMatchArray([
        'reference' => $reference, 'order_id' => $orderId, 'gateway' => 'bold', 'gateway_name' => 'Bold',
        'status' => 'pendiente', 'gateway_status' => null, 'transaction_id' => null, 'payment_method' => null,
        'amount' => $amount, 'notified_at' => null,
    ])
        ->and($approved)->toMatchArray([
            'status' => 'aprobado', 'gateway_status' => 'APROBADA', 'transaction_id' => $paymentId,
            'payment_method' => 'Bold', 'amount' => $amount,
        ])
        ->and($approved['notified_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

it('un pago rechazado se ve rechazado, con lo que dijo la pasarela', function (): void {
    $user    = Seed::user();
    $orderId = Shop::order($user, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);
    $amount  = (float) Shop::orderRow($orderId)['amount'];

    Seed::pdo()->prepare('UPDATE orders SET payment_gateway = \'addi\' WHERE order_id = ?')->execute([$orderId]);
    Shop::gatewayWebhook('addi', $orderId, $amount, 'REJECTED');

    expect(AppFactory::json(paymentStatus($user, Shop::reference($orderId))))->toMatchArray([
        'status' => 'rechazado', 'gateway_status' => 'PAGO RECHAZADO', 'payment_method' => 'Addi',
    ]);
});

it('solo el dueño del pedido ve su pago; una referencia ajena o inválida no existe', function (string $reference): void {
    $orderId = Shop::order(Seed::user(), [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    $other   = Seed::user();

    expect(paymentStatus($other, $reference === 'ajena' ? Shop::reference($orderId) : $reference)->getStatusCode())->toBe(404);
})->with(['ajena', 'pf-999999999', '863', 'abc']);

it('registra la conexión del socket de la sesión y rechaza una inválida', function (): void {
    $user = Seed::user();
    $put  = fn (mixed $token) => AppFactory::dispatch(AppFactory::request('PUT', '/api/account/socket', ['socket_token' => $token], JwtFactory::authHeader($user)));

    expect($put('Zs3kA9_x-Qm2LpWvAAAB')->getStatusCode())->toBe(204)
        ->and(Shop::rows('SELECT socket_token FROM users WHERE id = ?', [$user])[0]['socket_token'])->toBe('Zs3kA9_x-Qm2LpWvAAAB')
        ->and($put('no válido <script>')->getStatusCode())->toBe(422)
        ->and($put(null)->getStatusCode())->toBe(422);
});
