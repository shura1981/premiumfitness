<?php

declare(strict_types=1);

use App\Features\Checkout\GatewayPayment;
use App\Features\PaymentGateway\Logging\PaymentGatewayLogService;
use App\Shared\Exceptions\ApiException;
use App\Infrastructure\Config\BusinessConfig;
use Tests\Support\Seed;
use Tests\Support\Shop;

/**
 * Addi y Sistecrédito: el pago se abre en su página y el pedido lo aprueba su webhook, como en Bold.
 * El call center confirma cada pago en la plataforma de la pasarela.
 */

/**
 * Pedido sin pagar con la pasarela indicada. Se crea con Bold para no llamar a la pasarela real.
 */
function orderWithGateway(int $userId, string $gateway, int $price = 100000): int
{
    $orderId = Shop::order($userId, [['code' => Seed::catalogProduct(['valor' => $price]), 'quantity' => 1]]);
    Seed::pdo()->prepare('UPDATE orders SET payment_gateway = ? WHERE order_id = ?')->execute([$gateway, $orderId]);

    return $orderId;
}

function orderAmount(int $orderId): float
{
    return (float) Shop::orderRow($orderId)['amount'];
}

dataset('pasarelas sin firma', [
    'Addi'         => ['addi', 'APPROVED', 'REJECTED'],
    'Sistecrédito' => ['sistecredito', 'Approved', 'Rejected'],
]);

it('el aviso aprobado de la pasarela aprueba el pedido y da los puntos del cliente', function (string $gateway, string $approved): void {
    [$client] = Seed::clientWithTrainer();
    $orderId  = orderWithGateway($client, $gateway);

    $result = Shop::gatewayWebhook($gateway, $orderId, orderAmount($orderId), $approved);
    $row    = Shop::orderRow($orderId);

    expect($result)->toMatchArray(['ok' => true, 'processed' => true, 'status' => 'APROBADA'])
        ->and($row['status_value'])->toBe('APROBADA')
        ->and($row['approved_at'])->not->toBeNull()
        ->and(Shop::rows('SELECT 1 FROM point_lots WHERE order_id = ?', [$orderId]))->not->toBe([]);
})->with('pasarelas sin firma');

it('no aprueba el pedido si lo pagado no cubre su valor', function (string $gateway, string $approved): void {
    $orderId = orderWithGateway(Seed::user(), $gateway);

    $result = Shop::gatewayWebhook($gateway, $orderId, orderAmount($orderId) - 1000, $approved);

    expect($result)->toMatchArray(['processed' => false, 'reason' => 'amount_mismatch'])
        ->and(Shop::orderRow($orderId)['approved_at'])->toBeNull();
})->with('pasarelas sin firma');

it('el aviso rechazado deja el pedido rechazado', function (string $gateway, string $approved, string $rejected): void {
    $orderId = orderWithGateway(Seed::user(), $gateway);

    Shop::gatewayWebhook($gateway, $orderId, orderAmount($orderId), $rejected);
    $row = Shop::orderRow($orderId);

    expect($row['status_value'])->toBe('PAGO RECHAZADO')
        ->and($row['approved_at'])->toBeNull();
})->with('pasarelas sin firma');

it('un aviso repetido no aprueba dos veces', function (string $gateway, string $approved): void {
    [$client] = Seed::clientWithTrainer();
    $orderId  = orderWithGateway($client, $gateway);

    Shop::gatewayWebhook($gateway, $orderId, orderAmount($orderId), $approved);
    $again = Shop::gatewayWebhook($gateway, $orderId, orderAmount($orderId), $approved);

    expect($again)->toMatchArray(['processed' => false, 'reason' => 'already_approved'])
        ->and(Shop::rows('SELECT 1 FROM point_lots WHERE order_id = ?', [$orderId]))->toHaveCount(1);
})->with('pasarelas sin firma');

it('ignora el aviso con una referencia ajena', function (string $gateway, string $approved): void {
    $orderId = orderWithGateway(Seed::user(), $gateway);

    $result = Shop::gatewayWebhook($gateway, $orderId, orderAmount($orderId), $approved, reference: (string) $orderId);

    expect($result)->toBe(['ok' => true, 'processed' => false, 'reason' => 'foreign_reference'])
        ->and(Shop::orderRow($orderId)['approved_at'])->toBeNull();
})->with('pasarelas sin firma');

it('un pago que la pasarela cancela queda CANCELADA, distinto de un pedido reversado por un gerente', function (string $gateway, string $cancelled): void {
    $orderId = orderWithGateway(Seed::user(), $gateway);

    Shop::gatewayWebhook($gateway, $orderId, orderAmount($orderId), $cancelled);

    // Nunca se aprobó: no hay dinero, puntos ni comisión que revertir.
    expect(Shop::orderRow($orderId))->toMatchArray(['status_value' => 'CANCELADA', 'approved_at' => null])
        ->and(Shop::orderRow($orderId)['status_value'])->not->toBe('REVERSADA');
})->with(['Addi' => ['addi', 'DECLINED'], 'Sistecrédito' => ['sistecredito', 'Cancelled']]);

// ── Datos que se envían a cada pasarela ───────────────────────────────────

/** @return array<string, mixed> */
function gatewayOrder(): array
{
    return [
        'order_id' => 901, 'amount' => '224910.00', 'shipping_amount' => '0.00', 'tax_amount' => '35910.08',
        'full_name' => 'Ana María Pérez Gómez', 'email' => 'ana@example.com', 'num_cell' => '300 900 0001',
        'document_id' => '1234567', 'document_type_id' => 3, 'address' => 'CL 1 2 3', 'city_name' => 'Cali',
    ];
}

it('arma la solicitud de Addi con el pedido guardado y la vuelta a la app', function (): void {
    $items   = [['product_sku' => 103040, 'product_name' => 'NUTRA C', 'quantity' => 2, 'unit_price' => '112455.00', 'tax_amount' => '35910.08']];
    $payload = (new GatewayPayment(BusinessConfig::fromEnv()))->addiPayload(gatewayOrder(), 'pf-77', $items, GatewayPayment::returnUrl('addi', 'https://app.test/pago/resultado', 'pf-77'));

    expect($payload)->toMatchArray([
        'orderId' => 'pf-77', 'totalAmount' => '224910.0', 'shippingAmount' => '0.0', 'totalTaxesAmount' => '35910.1', 'currency' => 'COP',
    ])
        ->and($payload['items'][0])->toMatchArray(['sku' => '103040', 'quantity' => '2', 'unitPrice' => 112455, 'tax' => 35910])
        ->and($payload['client'])->toMatchArray([
            'idType' => 'CE', 'idNumber' => '1234567', 'firstName' => 'Ana', 'lastName' => 'María Pérez Gómez',
            'cellphone' => '3009000001', 'cellphoneCountryCode' => '+57',
        ])
        ->and($payload['shippingAddress'])->toBe(['lineOne' => 'CL 1 2 3', 'city' => 'Cali', 'country' => 'CO'])
        // Addi vuelve con el id de pago en `id_order`, como en radical y en el sitio.
        ->and($payload['allyUrlRedirection']['redirectionUrl'])->toBe('https://app.test/pago/resultado/addi?id_order=pf-77');
});

it('arma la orden de Sistecrédito con el pedido guardado y la vuelta a la app', function (): void {
    expect(GatewayPayment::sistecreditoPayload(gatewayOrder(), 'pf-77', GatewayPayment::returnUrl('sistecredito', 'https://app.test/pago/resultado', 'pf-77')))->toBe([
        'invoice'     => 'pf-77',
        'description' => 'Pedido PremiumFitness #901',
        'value'       => 224910,
        'docType'     => 'CE',
        'document'    => '1234567',
        // Sistecrédito vuelve con el id de pago en `orderId`.
        'urlResponse' => 'https://app.test/pago/resultado/sistecredito?orderId=pf-77',
    ]);
});

// ── Log de errores de las pasarelas, como en radical: solo cuando falla ───

it('si la pasarela no abre, responde 502 y deja una línea en el log de errores, sin datos sensibles', function (string $gateway): void {
    $directory = sys_get_temp_dir() . '/pf-payment-log-' . bin2hex(random_bytes(6));
    $orderId   = orderWithGateway(Seed::user(), $gateway);
    $payment   = new GatewayPayment(BusinessConfig::fromEnv(), new PaymentGatewayLogService($directory));

    // En las pruebas la API de la pasarela apunta a un puerto cerrado (tests/bootstrap.php).
    expect(fn () => $payment->start($orderId, 'https://app.test/pago/resultado'))
        ->toThrow(fn (ApiException $e) => expect($e->getMessage())->toContain('siguen en el carrito'));

    $files = glob($directory . '/errors-*.log') ?: [];
    $entry = json_decode((string) file_get_contents($files[0]), true);

    expect($files)->toHaveCount(1)
        ->and($entry)->toMatchArray(['level' => 'error', 'gateway' => $gateway, 'source' => 'checkout', 'order_id' => $orderId])
        ->and($entry['context']['payment_code'])->toBe('pf-' . $orderId)
        ->and((string) file_get_contents($files[0]))->not->toContain((string) Shop::rows('SELECT u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.order_id = ?', [$orderId])[0]['email']);

    array_map('unlink', $files);
    rmdir($directory);
})->with(['addi', 'sistecredito']);

it('un pago que abre bien no escribe nada en el log', function (): void {
    $directory = sys_get_temp_dir() . '/pf-payment-log-' . bin2hex(random_bytes(6));
    $orderId   = Shop::order(Seed::user(), [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);

    (new GatewayPayment(BusinessConfig::fromEnv(), new PaymentGatewayLogService($directory)))->start($orderId, 'https://app.test/pago/resultado');

    expect(is_dir($directory))->toBeFalse();
});
