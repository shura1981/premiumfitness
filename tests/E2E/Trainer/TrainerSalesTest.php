<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Ventas del entrenador en el módulo de comisión (spec negocio/06): resumen, días, ciudades,
 | productos e histórico, sumando lo guardado en los pedidos y en commission_movements.
 */

function salesGet(int $trainerId, string $path = ''): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/sales' . $path, headers: JwtFactory::authHeader($trainerId)));
}

/** Compra pagada de un cliente del entrenador. Devuelve el id del pedido. */
function paidSale(int $clientId, array $items, ?int $addressId = null): int
{
    $response = Shop::checkout($clientId, $items, $addressId);
    $orderId  = (int) AppFactory::json($response)['order_id'];
    Shop::pay($orderId);

    return $orderId;
}

/** Dirección del cliente en otra ciudad (Cali, distinta de la de Shop::address y con flete). */
function addressInAnotherCity(int $userId): int
{
    return Shop::address($userId, 'CALI (VAL)');
}

function commissionOf(int $orderId): float
{
    $stmt = Seed::pdo()->prepare("SELECT SUM(amount) FROM commission_movements WHERE order_id = ? AND type IN ('sale_credit', 'adjustment')");
    $stmt->execute([$orderId]);

    return (float) $stmt->fetchColumn();
}

it('resume las ventas del mes con lo guardado: ventas, comisión, valor sin envío, ciudades y productos', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $whey  = Seed::catalogProduct(['valor' => 120000]);
    $crea  = Seed::catalogProduct(['valor' => 80000]);
    $first = paidSale($client, [['code' => $whey, 'quantity' => 2], ['code' => $crea, 'quantity' => 1]]);
    $other = paidSale($client, [['code' => $crea, 'quantity' => 3]], addressInAnotherCity($client));

    $body  = AppFactory::json(salesGet($trainer));
    $order = static fn (int $id): array => Shop::orderRow($id);

    expect($body['period'])->toBe(date('Y-m'))
        ->and($body['totals'])->toMatchArray([
            'sales'    => 2,
            'cities'   => 2,
            'products' => 2,
            'units'    => 6,
        ])
        ->and($body['totals']['commission'])->toEqualWithDelta(commissionOf($first) + commissionOf($other), 0.01)
        ->and($body['totals']['amount'])->toEqualWithDelta(
            ($order($first)['amount'] - $order($first)['shipping_amount']) + ($order($other)['amount'] - $order($other)['shipping_amount']),
            0.01,
        )
        ->and($body['last_7_days'])->toBe(2)
        ->and($body['days'])->toHaveCount(1)
        ->and($body['days'][0])->toMatchArray(['date' => date('Y-m-d'), 'sales' => 2])
        ->and($body['cities'])->toHaveCount(2)
        ->and(array_sum(array_column($body['cities'], 'sales')))->toBe(2)
        // Productos de más a menos unidades, con su parte de las unidades.
        ->and($body['products'][0])->toMatchArray(['code' => $crea, 'units' => 4, 'share' => 66.7])
        ->and($body['products'][1])->toMatchArray(['code' => $whey, 'units' => 2, 'share' => 33.3]);
});

it('no cuenta pedidos sin pagar, compras propias del entrenador ni pedidos anulados por completo', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $product = Seed::catalogProduct(['valor' => 100000]);

    $kept      = paidSale($client, [['code' => $product, 'quantity' => 1]]);
    $cancelled = paidSale($client, [['code' => $product, 'quantity' => 1]]);
    Shop::order($client, [['code' => $product, 'quantity' => 1]]);           // sin pagar
    paidSale($trainer, [['code' => $product, 'quantity' => 1]]);             // compra propia

    // Un ajuste que devuelve toda la comisión deja la venta en cero: ya no cuenta.
    Seed::pdo()->prepare(
        "INSERT INTO commission_movements (order_id, trainer_id, type, amount, source_key) VALUES (?, ?, 'adjustment', ?, ?)"
    )->execute([$cancelled, $trainer, -commissionOf($cancelled), 'test:cancel:' . $cancelled]);

    $body = AppFactory::json(salesGet($trainer));

    expect($body['totals']['sales'])->toBe(1)
        ->and($body['totals']['commission'])->toEqualWithDelta(commissionOf($kept), 0.01)
        ->and($body['totals']['units'])->toBe(1);
});

it('un ajuste parcial descuenta la comisión de la venta, que sigue contando', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId = paidSale($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 2]]);
    $credit  = commissionOf($orderId);
    Seed::pdo()->prepare(
        "INSERT INTO commission_movements (order_id, trainer_id, type, amount, source_key) VALUES (?, ?, 'adjustment', ?, ?)"
    )->execute([$orderId, $trainer, -round($credit / 2, 2), 'test:partial:' . $orderId]);

    $body = AppFactory::json(salesGet($trainer));

    expect($body['totals']['sales'])->toBe(1)
        ->and($body['totals']['commission'])->toEqualWithDelta($credit - round($credit / 2, 2), 0.01);
});

it('cada mes cuenta lo aprobado en él y el histórico suma todos los meses', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $product  = Seed::catalogProduct(['valor' => 100000]);
    $previous = date('Y-m', strtotime('first day of last month'));
    $old      = paidSale($client, [['code' => $product, 'quantity' => 1]]);
    paidSale($client, [['code' => $product, 'quantity' => 1]]);
    Seed::pdo()->prepare('UPDATE orders SET approved_at = ? WHERE order_id = ?')->execute([$previous . '-15 10:00:00', $old]);

    $now     = AppFactory::json(salesGet($trainer));
    $before  = AppFactory::json(salesGet($trainer, '?period=' . $previous));
    $history = AppFactory::json(salesGet($trainer, '/history'));

    expect($now['totals']['sales'])->toBe(1)
        ->and($before['totals']['sales'])->toBe(1)
        ->and($before['days'][0]['date'])->toBe($previous . '-15')
        ->and($history['totals']['sales'])->toBe(2)
        ->and(array_column($history['months'], 'period'))->toBe([date('Y-m'), $previous])
        ->and($history['months'][0]['label'])->toBeString();
});

it('sin ventas responde ceros y listas vacías', function (): void {
    $body = AppFactory::json(salesGet(Seed::trainer()));

    expect($body['totals'])->toBe(['sales' => 0, 'commission' => 0, 'amount' => 0, 'cities' => 0, 'products' => 0, 'units' => 0])
        ->and($body['days'])->toBe([])
        ->and($body['cities'])->toBe([])
        ->and($body['products'])->toBe([]);
});

it('rechaza un mes mal escrito o futuro', function (string $period): void {
    $response = salesGet(Seed::trainer(), '?period=' . $period);

    expect($response->getStatusCode())->toBe(422)
        ->and(AppFactory::json($response)['errors'])->toHaveKey('period');
})->with(['formato' => ['2026-13'], 'texto' => ['noviembre'], 'futuro' => [date('Y-m', strtotime('first day of next month'))]]);

it('cada entrenador ve solo sus ventas', function (): void {
    [$client] = Seed::clientWithTrainer();
    paidSale($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);

    expect(AppFactory::json(salesGet(Seed::trainer()))['totals']['sales'])->toBe(0);
});

it('sin sesión responde 401', function (): void {
    expect(AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/sales'))->getStatusCode())->toBe(401);
});
