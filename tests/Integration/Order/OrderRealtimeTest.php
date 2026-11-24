<?php

declare(strict_types=1);

use App\Features\Order\OrderRealtime;
use Tests\Support\RecordingRealtime;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Avisos por el socket (spec integraciones/03), como en radical: al admin y a la conexión del dueño.
 */

function realtimeFor(RecordingRealtime $socket): OrderRealtime
{
    return new OrderRealtime(socket: $socket);
}

it('un pedido nuevo le llega al admin con sus datos de la lista', function (): void {
    $orderId = Shop::order(Seed::user(), [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    $socket  = new RecordingRealtime();

    realtimeFor($socket)->created($orderId);
    $sent = $socket->named('order.created');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['to'])->toBe('admin')
        ->and($sent[0]['payload']->order_id)->toBe($orderId)
        ->and($sent[0]['payload']->payment_method)->toBe('Dinero (Bold)');
});

it('SAP y guía avisan al admin y al dueño con el estado de la app', function (): void {
    $user = Seed::user();
    Seed::pdo()->prepare('UPDATE users SET socket_token = ? WHERE id = ?')->execute(['conexion-cliente-1', $user]);
    $orderId = Shop::order($user, [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    Shop::pay($orderId);
    $socket = new RecordingRealtime();

    Seed::pdo()->prepare('UPDATE orders SET sap_order_code = 880011 WHERE order_id = ?')->execute([$orderId]);
    realtimeFor($socket)->processed($orderId);
    Seed::pdo()->prepare("UPDATE orders SET tracking_number = 'G-123', carrier_name = 'SERVIENTREGA' WHERE order_id = ?")->execute([$orderId]);
    realtimeFor($socket)->trackingUpdated($orderId);

    $client = $socket->named('order.updated');

    expect($socket->named('order.processed')[0])->toBe(['to' => 'admin', 'event' => 'order.processed', 'payload' => ['order_id' => $orderId]])
        ->and($socket->named('order.tracking.updated')[0]['payload'])->toBe(['order_id' => $orderId, 'tracking_number' => 'G-123', 'carrier_name' => 'SERVIENTREGA'])
        ->and($client)->toHaveCount(2)
        ->and($client[0]['to'])->toBe('conexion-cliente-1')
        ->and($client[0]['payload'])->toMatchArray(['order_id' => $orderId, 'status' => 'en_proceso'])
        ->and($client[1]['payload'])->toMatchArray(['order_id' => $orderId, 'status' => 'en_camino', 'tracking_number' => 'G-123']);
});

it('sin conexión registrada, el dueño no recibe nada; el admin sí', function (): void {
    $user = Seed::user();
    Seed::pdo()->prepare('UPDATE users SET socket_token = NULL WHERE id = ?')->execute([$user]);
    $orderId = Shop::order($user, [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    $socket  = new RecordingRealtime();

    realtimeFor($socket)->statusChanged($orderId);

    expect($socket->named('order.updated'))->toBe([])
        ->and($socket->named('order.status.changed'))->toHaveCount(1);
});

it('el aviso del pago al dueño lleva el pedido y el estado de la app', function (): void {
    $user = Seed::user();
    Seed::pdo()->prepare('UPDATE users SET socket_token = ? WHERE id = ?')->execute(['conexion-pago-1', $user]);
    $orderId = Shop::order($user, [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    $socket  = new RecordingRealtime();
    $order   = (new App\Features\Order\OrderRepository())->findById($orderId);
    $result  = new App\Features\PaymentGateway\Result\PaymentResult(
        gateway: 'bold', orderId: $orderId, transactionId: 'PAY-9', codStatus: App\Features\PaymentGateway\Normalizer\PaymentStatus::APROBADA,
        statusText: App\Features\PaymentGateway\Normalizer\PaymentStatus::TEXT_APROBADA, amount: 100.0, rawPayload: [],
    );

    (new App\Features\PaymentGateway\Events\Handlers\EmitClientPurchaseStatusHandler($socket, new App\Features\User\UserRepository()))
        ->handle(new App\Features\PaymentGateway\Events\PaymentEvent($order, $result));

    expect($socket->named('purchase.status.changed')[0])->toMatchArray(['to' => 'conexion-pago-1'])
        ->and($socket->named('purchase.status.changed')[0]['payload'])->toMatchArray(['order_id' => $orderId, 'order_status' => 'aprobado', 'status' => 'APROBADA', 'gateway' => 'bold']);
});
