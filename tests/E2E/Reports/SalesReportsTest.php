<?php

declare(strict_types=1);

use App\Features\Reports\ReportsRepository;
use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Los reportes de ventas del admin cuentan solo ventas aprobadas. Un canje se paga con puntos: no es
 | una venta y no mueve ningún número de ventas, aunque sus líneas guarden el precio completo.
 */

function today(): string
{
    return (string) Seed::pdo()->query('SELECT CURDATE()')->fetchColumn();
}

/**
 * Todo lo que muestran los reportes de ventas del día, del mes y del año, como texto para comparar.
 * Del dashboard solo cuentan los pedidos y el dinero (no los usuarios ni los productos del catálogo).
 */
function salesSnapshot(): string
{
    $reports = new ReportsRepository();
    $day     = today();
    $year    = (int) substr($day, 0, 4);
    $kpis    = $reports->dashboard();

    return (string) json_encode([
        'dashboard' => [
            $kpis->total_orders, $kpis->revenue_approved, $kpis->revenue_total, $kpis->pending_orders, $kpis->rejected_orders,
            $kpis->approved_orders, $kpis->orders_this_month, $kpis->revenue_this_month, $kpis->avg_ticket,
        ],
        'monthly'     => $reports->salesMonthly($year),
        'daily'       => $reports->salesDaily($day, $day),
        'hour'        => $reports->salesByHour($day),
        'gateway'     => $reports->salesByGateway($day, $day),
        'status'      => $reports->salesByStatus($day, $day),
        'customers'   => $reports->topCustomers($day, $day, 1000),
        'categories'  => $reports->topCategories($day, $day),
        'city'        => $reports->salesByCity($day, $day, 1000),
        'department'  => $reports->salesByDepartment($day, $day),
        'new'         => $reports->newVsReturning($day, $day),
        'frequency'   => $reports->customerFrequency($day, $day),
        'discount'    => $reports->discountImpact($day, $day),
        'orders'      => count($reports->ordersDownload($day, $day)),
    ]);
}

/** @return array{total_sold:int, revenue:int}|null Lo vendido del producto hoy, según el rendimiento de productos. */
function productSales(int $code): ?array
{
    $reports = new ReportsRepository();
    $day     = today();
    $rows    = array_filter($reports->productsDownload($day, $day, 100000), static fn (array $row): bool => (int) $row['sku'] === $code);
    $row     = array_values($rows)[0] ?? null;

    return $row === null ? null : ['total_sold' => (int) $row['total_sold'], 'revenue' => (int) round((float) $row['revenue'])];
}

function topProductRevenue(int $code): ?int
{
    $day = today();

    foreach ((new ReportsRepository())->topProducts($day, $day, 100000) as $product) {
        if ((int) $product->product_sku === $code) {
            return (int) round((float) $product->revenue);
        }
    }

    return null;
}

it('un canje aprobado no cambia ningún reporte de ventas', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 1000000);
    $code    = Seed::catalogProduct(['valor' => 249000]);
    $address = Shop::address($trainer);
    $before  = salesSnapshot();

    $redeem = AppFactory::dispatch(AppFactory::request('POST', '/api/trainer/redemptions', [
        'items' => [['code' => $code, 'quantity' => 2]], 'address_id' => $address, 'idempotency_key' => bin2hex(random_bytes(16)),
    ], JwtFactory::authHeader($trainer)));

    expect($redeem->getStatusCode())->toBe(201)
        ->and(salesSnapshot())->toBe($before)
        // Sus líneas guardan 498.000 de precio completo, pero no se vendió nada.
        ->and(topProductRevenue($code))->toBeNull()
        ->and(productSales($code))->toBe(['total_sold' => 0, 'revenue' => 0]);
});

it('una venta aprobada suma exactamente lo pagado; una sin pagar no suma', function (): void {
    $day    = today();
    $code   = Seed::catalogProduct(['valor' => 100000]);
    $user   = Seed::user();
    $before = (new ReportsRepository())->salesDaily($day, $day)[0] ?? null;

    $pending = Shop::order($user, [['code' => $code, 'quantity' => 3]]);
    expect(productSales($code))->toBe(['total_sold' => 0, 'revenue' => 0])
        ->and(topProductRevenue($code))->toBeNull();

    $paid = Shop::order($user, [['code' => $code, 'quantity' => 1]]);
    Shop::pay($paid);
    $amount = (int) round((float) Shop::orderRow($paid)['amount']);
    $after  = (new ReportsRepository())->salesDaily($day, $day)[0];

    expect((int) $after->order_count)->toBe((int) ($before->order_count ?? 0) + 1)
        ->and((int) round((float) $after->revenue))->toBe((int) round((float) ($before->revenue ?? 0)) + $amount)
        // Por producto cuenta la línea pagada (sin envío) y no la del pedido pendiente.
        ->and(productSales($code))->toBe(['total_sold' => 1, 'revenue' => 100000])
        ->and(topProductRevenue($code))->toBe(100000)
        ->and(Shop::orderRow($pending)['approved_at'])->toBeNull();
});
