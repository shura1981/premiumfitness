<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Compra con descuento por rol, IVA por línea y valores guardados en el pedido
 | (reglas 1, 2, 6, 7 y 14; specs negocio/01 y catalogo/01).
 */

it('calcula la compra de un cliente con entrenador: descuento, IVA y envío', function (): void {
    [$client] = Seed::clientWithTrainer();
    $bipro    = Seed::catalogProduct(['valor' => 249900, 'producto' => 'BIPRO PRUEBA']);

    $response = Shop::preview($client, [['code' => $bipro, 'quantity' => 2]]);
    $body     = AppFactory::json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['items'][0])->toMatchArray([
            'code' => $bipro, 'name' => 'BIPRO PRUEBA', 'quantity' => 2, 'unit_price' => 249900,
            'final_unit_price' => 224910, 'discount_percentage' => 10, 'line_total' => 449820, 'available' => true,
        ])
        ->and($body['subtotal_full'])->toBe(499800)
        ->and($body['discount_amount'])->toBe(49980)
        // 449.820 / 1,19 = 378.000 de base y 71.820 de IVA.
        ->and($body['tax_amount'])->toBe(71820)
        // Lo pagado por los productos no llega al mínimo de envío gratis.
        ->and($body['shipping_amount'])->toBe((int) $_ENV['SHIPPING_FEE'])
        ->and($body['total'])->toBe(449820 + (int) $_ENV['SHIPPING_FEE'])
        ->and($body['can_checkout'])->toBeTrue()
        ->and($body['message'])->toBeNull();
});

it('no cobra envío cuando los productos pagados llegan al mínimo', function (): void {
    $code = Seed::catalogProduct(['valor' => (int) $_ENV['SHIPPING_FREE_THRESHOLD']]);
    $body = AppFactory::json(Shop::preview(Seed::user(), [['code' => $code, 'quantity' => 1]]));

    expect($body['shipping_amount'])->toBe(0)
        ->and($body['total'])->toBe((int) $_ENV['SHIPPING_FREE_THRESHOLD']);
});

it('Nutra C no lleva IVA y los demás sí', function (): void {
    $exempt = (int) explode(',', $_ENV['VAT_EXEMPT_PRODUCT_CODES'])[0];
    $other  = Seed::catalogProduct(['valor' => 119000]);
    $user   = Seed::user();

    $onlyExempt = AppFactory::json(Shop::preview($user, [['code' => $exempt, 'quantity' => 1]]));
    $both       = AppFactory::json(Shop::preview($user, [['code' => $exempt, 'quantity' => 1], ['code' => $other, 'quantity' => 1]]));

    expect($onlyExempt['tax_amount'])->toBe(0)
        ->and($both['tax_amount'])->toBe(19000);
});

it('marca lo que ya no se puede comprar y no deja pagar', function (array $overrides): void {
    $user = Seed::user();
    $code = Seed::catalogProduct();
    Seed::updateCatalogProduct($code, $overrides);

    $preview  = AppFactory::json(Shop::preview($user, [['code' => $code, 'quantity' => 1]]));
    $checkout = Shop::checkout($user, [['code' => $code, 'quantity' => 1]]);

    expect($preview['items'][0]['available'])->toBeFalse()
        ->and($preview['can_checkout'])->toBeFalse()
        ->and($preview['total'])->toBe(0)
        ->and($checkout->getStatusCode())->toBe(409)
        ->and(AppFactory::json($checkout)['code'])->toBe('out_of_stock');
})->with([
    'agotado'            => [['agotado' => 1]],
    'pasó a promoción'   => [['promo' => 1]],
    'desactivado'        => [['activo' => 0]],
]);

it('rechaza un carrito mal formado', function (mixed $items): void {
    expect(Shop::preview(Seed::user(), $items)->getStatusCode())->toBe(422);
})->with([
    'vacío'              => [[]],
    'cantidad cero'      => [[['code' => 100507, 'quantity' => 0]]],
    'cantidad negativa'  => [[['code' => 100507, 'quantity' => -3]]],
    'cantidad enorme'    => [[['code' => 100507, 'quantity' => 100]]],
    'código de texto'    => [[['code' => 'abc', 'quantity' => 1]]],
    'sin cantidad'       => [[['code' => 100507]]],
]);

it('une las líneas repetidas del mismo producto', function (): void {
    $code = Seed::catalogProduct(['valor' => 10000]);
    $body = AppFactory::json(Shop::preview(Seed::user(), [['code' => $code, 'quantity' => 1], ['code' => $code, 'quantity' => 2]]));

    expect($body['items'])->toHaveCount(1)
        ->and($body['items'][0]['quantity'])->toBe(3);
});

it('crea el pedido del cliente con todo lo que aplicó guardado', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $code               = Seed::catalogProduct(['valor' => 249900, 'producto' => 'BIPRO GUARDADO', 'sap_code' => 554433]);
    $address            = Shop::address($client);

    $response = Shop::checkout($client, [['code' => $code, 'quantity' => 1]], $address);
    $orderId  = AppFactory::json($response)['order_id'];
    $order    = Shop::orderRow($orderId);
    $item     = Shop::rows('SELECT * FROM orders_items WHERE order_id = ?', [$orderId])[0];
    $coupon   = Shop::rows('SELECT trainer_id, coupon_code, discount_percentage, discount_amount FROM orders_coupons WHERE order_id = ?', [$orderId])[0];

    expect($response->getStatusCode())->toBe(201)
        ->and($order)->toMatchArray([
            'user_id' => $client, 'order_type' => 'client_purchase', 'payment_gateway' => 'bold', 'address_id' => $address,
            'subtotal_full' => '249900.00', 'discount_amount' => '24990.00', 'tax_base' => '189000.00', 'tax_amount' => '35910.00',
            'shipping_amount' => number_format((float) $_ENV['SHIPPING_FEE'], 2, '.', ''),
            'amount' => number_format(224910 + (float) $_ENV['SHIPPING_FEE'], 2, '.', ''),
            // 5 % de 224.910 = 11.245,5 → 11.246 (0,5 sube). El envío no cuenta.
            'reward_percentage' => '5.00', 'reward_points' => 11246,
            'approved_at' => null, 'payment_reference' => null,
        ])
        ->and($item)->toMatchArray([
            'product_sku' => $code, 'product_name' => 'BIPRO GUARDADO', 'sap_code' => 554433, 'quantity' => 1,
            'product_full_price' => '249900.00', 'unit_price' => '224910.00', 'discount_percentage' => '10.0000', 'line_total' => '224910.00',
            'tax_rate' => '19.00', 'tax_base' => '189000.00', 'tax_amount' => '35910.00',
            // 15 % de la base sin IVA.
            'commission_percentage' => '15.00', 'commission_amount' => '28350.00',
        ])
        ->and($coupon['trainer_id'])->toBe($trainer)
        ->and($coupon['coupon_code'])->toBeString()->not->toBeEmpty()
        ->and($coupon['discount_amount'])->toBe('24990.00');
});

it('si la web cambia el producto después, el pedido no cambia', function (): void {
    $user    = Seed::user();
    $code    = Seed::catalogProduct(['valor' => 100000, 'producto' => 'NOMBRE AL COMPRAR']);
    $orderId = Shop::order($user, [['code' => $code, 'quantity' => 1]]);

    Seed::updateCatalogProduct($code, ['valor' => 500000, 'producto' => 'NOMBRE NUEVO', 'promo' => 1]);

    $detail = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders/' . $orderId, headers: JwtFactory::authHeader($user))));

    expect($detail['items'][0])->toMatchArray(['name' => 'NOMBRE AL COMPRAR', 'unit_price' => 100000, 'line_total' => 100000]);
});

it('la compra del entrenador lleva su descuento y no genera comisión ni puntos', function (): void {
    $trainer = Seed::trainer();
    $code    = Seed::catalogProduct(['valor' => 249900]);
    $orderId = Shop::order($trainer, [['code' => $code, 'quantity' => 1]]);
    $order   = Shop::orderRow($orderId);
    $item    = Shop::rows('SELECT unit_price, commission_percentage, commission_amount FROM orders_items WHERE order_id = ?', [$orderId])[0];

    expect($order)->toMatchArray(['order_type' => 'trainer_purchase', 'discount_amount' => '62475.00', 'reward_points' => null, 'reward_percentage' => null])
        ->and($item)->toBe(['unit_price' => '187425.00', 'commission_percentage' => null, 'commission_amount' => null])
        ->and(Shop::rows('SELECT 1 FROM orders_coupons WHERE order_id = ?', [$orderId]))->toBe([]);
});

it('el cliente sin entrenador compra a precio completo y no genera recompensas', function (): void {
    $code    = Seed::catalogProduct(['valor' => 249900]);
    $orderId = Shop::order(Seed::user(), [['code' => $code, 'quantity' => 1]]);

    expect(Shop::orderRow($orderId))->toMatchArray(['discount_amount' => '0.00', 'reward_points' => null])
        ->and(Shop::rows('SELECT 1 FROM orders_coupons WHERE order_id = ?', [$orderId]))->toBe([]);
});

it('guarda el porcentaje de la estrategia del producto en la línea', function (): void {
    [$client] = Seed::clientWithTrainer();
    $code     = Seed::catalogProduct(['valor' => 100000]);
    Seed::discountRule($code, client: 20, commission: 30);

    $orderId = Shop::order($client, [['code' => $code, 'quantity' => 1]]);
    $item    = Shop::rows('SELECT unit_price, discount_percentage, tax_base, commission_percentage, commission_amount FROM orders_items WHERE order_id = ?', [$orderId])[0];

    expect($item)->toBe([
        'unit_price' => '80000.00', 'discount_percentage' => '20.0000', 'tax_base' => '67226.89',
        'commission_percentage' => '30.00', 'commission_amount' => '20168.07',
    ]);
});

it('firma el pago en el servidor con el valor del pedido', function (): void {
    $user     = Seed::user();
    $code     = Seed::catalogProduct(['valor' => (int) $_ENV['SHIPPING_FREE_THRESHOLD'] + 1000]);
    $response = Shop::checkout($user, [['code' => $code, 'quantity' => 1]]);
    $body     = AppFactory::json($response);
    $bold     = $body['payment']['bold'];
    $amount   = (int) $_ENV['SHIPPING_FREE_THRESHOLD'] + 1000;

    expect($body['payment']['gateway'])->toBe('bold')
        ->and($bold['order_id'])->toBe((string) $body['order_id'])
        ->and($bold['amount'])->toBe($amount)
        ->and($bold['currency'])->toBe('COP')
        // Firma de integridad de Bold: sha256(pedido + valor + moneda + secreto).
        ->and($bold['integrity_signature'])->toBe(hash('sha256', $body['order_id'] . $amount . 'COP' . trim($_ENV['BOLD_SECRET_KEY'])))
        ->and($bold['redirection_url'])->toStartWith(rtrim($_ENV['WEB_APP_URL'], '/') . '/pago/resultado?pedido=' . $body['order_id'])
        ->and($bold['description'])->toContain('PremiumFitness')
        // A la app solo viaja la llave pública (`api_key`), nunca el secreto.
        ->and($bold['api_key'])->toBe(trim($_ENV['BOLD_IDENTITY_KEY']))
        ->and(array_keys($bold))->toBe(['order_id', 'amount', 'currency', 'api_key', 'integrity_signature', 'description', 'redirection_url']);
});

it('la vuelta del pago de un entrenador abre su área, /coach', function (): void {
    $trainer  = Seed::trainer();
    $code     = Seed::catalogProduct(['valor' => 100000]);
    $body     = AppFactory::json(Shop::checkout($trainer, [['code' => $code, 'quantity' => 1]]));

    expect($body['payment']['bold']['redirection_url'])
        ->toStartWith(rtrim($_ENV['WEB_APP_URL'], '/') . '/coach/pago/resultado?pedido=' . $body['order_id']);
});

it('ignora los precios que envíe la app', function (): void {
    $user = Seed::user();
    $code = Seed::catalogProduct(['valor' => 100000]);

    $response = AppFactory::dispatch(AppFactory::request('POST', '/api/checkout', [
        'items'           => [['code' => $code, 'quantity' => 1, 'unit_price' => 1, 'final_unit_price' => 1, 'line_total' => 1]],
        'address_id'      => Shop::address($user),
        'payment_gateway' => 'bold',
        'amount'          => 1,
        'total'           => 1,
        'shipping'        => 0,
    ], JwtFactory::authHeader($user)));

    expect(AppFactory::json($response)['payment']['bold']['amount'])->toBe(100000 + (int) $_ENV['SHIPPING_FEE']);
});

it('no deja comprar con la dirección de otra persona ni con una pasarela no habilitada', function (): void {
    $user = Seed::user();
    $code = Seed::catalogProduct();

    $foreignAddress = Shop::checkout($user, [['code' => $code, 'quantity' => 1]], Shop::address(Seed::user()));
    $otherGateway   = Shop::checkout($user, [['code' => $code, 'quantity' => 1]], gateway: 'addi');

    expect($foreignAddress->getStatusCode())->toBe(422)
        ->and(AppFactory::json($foreignAddress)['errors'])->toHaveKey('address_id')
        ->and($otherGateway->getStatusCode())->toBe(422);
});

it('solo ofrece las pasarelas habilitadas', function (): void {
    $body = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/checkout/payment-gateways', headers: JwtFactory::authHeader(Seed::user()))));

    expect(array_column($body['data'], 'id'))->toBe(['bold']);
});

// ── Pedidos del usuario ───────────────────────────────────────────────────

it('lista solo los pedidos propios, el más reciente primero', function (): void {
    $user  = Seed::user();
    $code  = Seed::catalogProduct(['valor' => 50000, 'producto' => 'PRIMERA LINEA']);
    $first = Shop::order($user, [['code' => $code, 'quantity' => 2]]);
    $last  = Shop::order($user, [['code' => $code, 'quantity' => 1]]);
    Shop::order(Seed::user(), [['code' => $code, 'quantity' => 1]]);

    $body = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders', headers: JwtFactory::authHeader($user))));

    expect(array_column($body['data'], 'id'))->toBe([$last, $first])
        ->and($body['data'][1])->toMatchArray([
            'status' => 'pendiente', 'items_count' => 2, 'first_item' => 'PRIMERA LINEA', 'is_redemption' => false, 'redemption_points' => null,
            'total' => 100000 + (int) $_ENV['SHIPPING_FEE'],
        ])
        ->and($body['data'][1]['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-05:00$/');
});

it('el pedido de otra persona responde 404, igual que uno que no existe', function (): void {
    $code    = Seed::catalogProduct();
    $orderId = Shop::order(Seed::user(), [['code' => $code, 'quantity' => 1]]);
    $headers = JwtFactory::authHeader(Seed::user());

    $foreign = AppFactory::dispatch(AppFactory::request('GET', '/api/orders/' . $orderId, headers: $headers));
    $missing = AppFactory::dispatch(AppFactory::request('GET', '/api/orders/999999999', headers: $headers));

    expect($foreign->getStatusCode())->toBe(404)
        ->and((string) $foreign->getBody())->toBe((string) $missing->getBody());
});

it('el detalle trae líneas, totales, dirección y la línea de tiempo según el avance', function (): void {
    $user    = Seed::user();
    $code    = Seed::catalogProduct(['valor' => 100000]);
    $orderId = Shop::order($user, [['code' => $code, 'quantity' => 1]]);
    $detail  = fn (): array => AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders/' . $orderId, headers: JwtFactory::authHeader($user))));
    $done    = fn (array $body): array => array_column(array_filter($body['timeline'], static fn (array $step): bool => $step['done']), 'status');

    $pending = $detail();
    expect($pending['status'])->toBe('pendiente')
        ->and($pending['address'])->toMatchArray(['name' => 'CASA', 'address' => 'CL 1 2 3'])
        ->and($pending['shipping_amount'])->toBe((int) $_ENV['SHIPPING_FEE'])
        ->and($pending['tracking'])->toBeNull()
        ->and($done($pending))->toBe(['pendiente']);

    Shop::pay($orderId);
    expect($detail()['status'])->toBe('aprobado')
        ->and($done($detail()))->toBe(['pendiente', 'aprobado']);

    Seed::pdo()->prepare("UPDATE orders SET sap_order_code = 123456 WHERE order_id = ?")->execute([$orderId]);
    expect($detail()['status'])->toBe('en_proceso');

    Seed::pdo()->prepare("UPDATE orders SET tracking_number = 'GUIA123', carrier_name = 'SERVIENTREGA' WHERE order_id = ?")->execute([$orderId]);
    $shipped = $detail();
    expect($shipped['status'])->toBe('en_camino')
        ->and($shipped['tracking'])->toMatchArray(['carrier' => 'SERVIENTREGA', 'number' => 'GUIA123'])
        ->and($done($shipped))->toBe(['pendiente', 'aprobado', 'en_proceso', 'en_camino']);
});

it('un pago rechazado se ve rechazado', function (): void {
    $user    = Seed::user();
    $orderId = Shop::order($user, [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    Shop::boldWebhook($orderId, 1, type: 'SALE_REJECTED');

    $body = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders/' . $orderId, headers: JwtFactory::authHeader($user))));

    expect($body['status'])->toBe('rechazado')
        ->and(array_column($body['timeline'], 'status'))->toBe(['pendiente', 'rechazado']);
});
