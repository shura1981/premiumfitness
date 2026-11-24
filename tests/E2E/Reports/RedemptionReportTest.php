<?php

declare(strict_types=1);

use App\Features\Reports\RedemptionReportRepository;
use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Reporte de canjes con puntos (spec negocio/07): solo canjes, los cancelados aparte, con los puntos
 | guardados en cada pedido y línea. Solo para gerentes.
 */

function redemptionReport(string $query = ''): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('GET', '/api/admin/reports/redemptions' . $query, headers: JwtFactory::adminHeader()));
}

/** @param list<array{code:int, quantity:int}> $items */
function redeemFor(int $trainer, array $items): int
{
    $response = AppFactory::dispatch(AppFactory::request('POST', '/api/trainer/redemptions', [
        'items' => $items, 'address_id' => Shop::address($trainer, 'CALI (VAL)'), 'idempotency_key' => bin2hex(random_bytes(16)),
    ], JwtFactory::authHeader($trainer)));

    return (int) AppFactory::json($response)['order_id'];
}

it('cuenta solo canjes, con los puntos exactos, y deja aparte los cancelados', function (): void {
    $day    = (string) Seed::pdo()->query('SELECT CURDATE()')->fetchColumn();
    $range  = "?date_from={$day}&date_to={$day}";
    $before = AppFactory::json(redemptionReport($range))['totals'];

    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 1000000);
    $bipro   = Seed::catalogProduct(['valor' => 100000, 'producto' => 'BIPRO CANJE']);
    $crea    = Seed::catalogProduct(['valor' => 50000, 'producto' => 'CREA CANJE']);
    $kept    = redeemFor($trainer, [['code' => $bipro, 'quantity' => 2]]);
    $undone  = redeemFor($trainer, [['code' => $crea, 'quantity' => 1]]);
    $cancel  = AppFactory::dispatch(AppFactory::request('POST', "/api/admin/orders/{$undone}/adjustments", [
        'type' => 'cancellation', 'reason' => 'El entrenador pidió cancelar',
    ], JwtFactory::adminHeader()));
    // Una compra del mismo producto no es un canje.
    [$client] = Seed::clientWithTrainer($trainer);
    $purchase = Shop::order($client, [['code' => $bipro, 'quantity' => 1]]);
    Shop::pay($purchase);

    $body = AppFactory::json(redemptionReport($range));
    $list = array_column($body['redemptions'], null, 'order_id');
    $repo = new RedemptionReportRepository();

    expect($cancel->getStatusCode())->toBe(201)
        ->and($body['totals'])->toBe([
            'redemptions'      => $before['redemptions'] + 1,
            'points'           => $before['points'] + 200000,
            'units'            => $before['units'] + 2,
            'trainers'         => $before['trainers'] + 1,
            'cancelled'        => $before['cancelled'] + 1,
            'cancelled_points' => $before['cancelled_points'] + 50000,
        ])
        ->and($list[$kept])->toMatchArray(['trainer_id' => $trainer, 'units' => 2, 'points' => 200000, 'status' => 'APROBADA', 'city' => 'CALI'])
        ->and($list[$undone])->toMatchArray(['units' => 1, 'points' => 50000, 'status' => 'CANCELADA'])
        ->and($list)->not->toHaveKey($purchase)
        // Rankings: solo lo efectivo.
        ->and(array_column($repo->trainers($day, $day, 100000), null, 'id')[$trainer])->toBe(['id' => $trainer, 'full_name' => Shop::rows('SELECT full_name FROM users WHERE id = ?', [$trainer])[0]['full_name'], 'redemptions' => 1, 'points' => 200000])
        ->and(array_column($repo->products($day, $day, 100000), null, 'code')[$bipro])->toBe(['code' => $bipro, 'name' => 'BIPRO CANJE', 'units' => 2, 'points' => 200000])
        ->and(array_column($repo->products($day, $day, 100000), null, 'code'))->not->toHaveKey($crea);
});

it('el Excel trae una fila por producto canjeado, con la forma de pago y los valores de referencia', function (): void {
    $day     = (string) Seed::pdo()->query('SELECT CURDATE()')->fetchColumn();
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 500000);
    $code    = Seed::catalogProduct(['valor' => 119000, 'producto' => 'PRODUCTO EXCEL']);
    $orderId = redeemFor($trainer, [['code' => $code, 'quantity' => 2]]);

    $rows = array_values(array_filter(
        AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', "/api/admin/reports/redemptions/export?date_from={$day}&date_to={$day}", headers: JwtFactory::adminHeader()))),
        static fn (array $row): bool => (int) $row['pedido_id'] === $orderId,
    ));

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'producto' => 'PRODUCTO EXCEL', 'codigo_producto' => $code, 'cantidad' => 2,
            'puntos_linea' => 238000, 'puntos_pedido' => 238000, 'forma_pago' => 'Puntos',
            'precio_completo_referencia' => 119000, 'valor_linea_referencia' => 238000,
            // 238.000 con IVA del 19 %: 200.000 de base y 38.000 de IVA.
            'base_sin_iva_referencia' => '200000.00', 'iva_referencia' => '38000.00',
            'estado' => 'APROBADA',
        ]);
});

it('solo lo ven los gerentes y valida las fechas', function (): void {
    $agent = JwtFactory::adminHeader(9001, 'CALL CENTER');

    expect(AppFactory::dispatch(AppFactory::request('GET', '/api/admin/reports/redemptions', headers: $agent))->getStatusCode())->toBe(403)
        ->and(AppFactory::dispatch(AppFactory::request('GET', '/api/admin/reports/redemptions/export', headers: $agent))->getStatusCode())->toBe(403)
        ->and(redemptionReport('?date_from=2026-13-40')->getStatusCode())->toBe(422)
        ->and(redemptionReport('?date_from=2026-11-20&date_to=2026-11-01')->getStatusCode())->toBe(422)
        ->and(redemptionReport()->getStatusCode())->toBe(200);
});
