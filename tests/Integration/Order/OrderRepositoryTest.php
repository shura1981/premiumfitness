<?php

declare(strict_types=1);

use App\Features\Order\OrderRepository;
use PHPUnit\Framework\Assert;
use Tests\Support\Seed;

$newContext = static function (): array {
    // Un producto real del catálogo local de la intranet (tests/fixtures/intranet-pruebas.sql).
    $product = ['sku' => 100507, 'unit_price' => 249000.0];

    return [
        'repo' => new OrderRepository(),
        'userId' => Seed::user(),
        'product' => $product,
    ];
};

it('creates an order with items in a transaction', function () use ($newContext): void {
    $ctx = $newContext();
    if ($ctx['product'] === null) {
        Assert::markTestSkipped('No products available to test orders_items FK.');
    }

    $unitPrice = $ctx['product']['unit_price'];
    $sku = $ctx['product']['sku'];

    $orderId = $ctx['repo']->create([
        'user_id' => $ctx['userId'],
        'amount'  => number_format($unitPrice, 2, '.', ''),
        'payment_gateway' => 'bold',
        'items'   => [[
            'product_sku'        => $sku,
            'product_full_price' => $unitPrice,
            'sap_code'           => 107050,
            'quantity'           => 1,
            'unit_price'         => $unitPrice,
            'line_total'         => $unitPrice,
        ]],
    ]);

    expect($orderId)->toBeGreaterThan(0);

    $order = $ctx['repo']->findById($orderId);
    expect($order)->not->toBeFalse()
        ->and($order->user_id)->toBe($ctx['userId'])
        ->and($order->items)->toHaveCount(1)
        ->and($order->items[0]->product_sku)->toBe($sku)
        ->and($order->items[0]->sap_code)->toBe(107050)
        ->and($order->carrier_name)->toBeNull()
        ->and($order->tracking_number)->toBeNull();
});

it('creates an order for user_id 1 with PENDING status', function () use ($newContext): void {
    $ctx = $newContext();

    $userExists = (int) Seed::pdo()->query('SELECT COUNT(*) FROM users WHERE id = 1')->fetchColumn();
    if ($userExists === 0) {
        Assert::markTestSkipped('User with id 1 was not found in users table.');
    }

    if ($ctx['product'] === null) {
        Assert::markTestSkipped('No products available to test orders_items FK.');
    }

    $unitPrice = $ctx['product']['unit_price'];
    $sku = $ctx['product']['sku'];

    $statusId = (new App\Features\Order\StatusOrderRepository())
        ->findOrCreateIdByValue('PENDING');

    $orderId = $ctx['repo']->create([
        'user_id'   => 1,
        'amount'    => number_format($unitPrice, 2, '.', ''),
        'payment_gateway' => 'bold',
        'status_id' => $statusId,
        'items'     => [[
            'product_sku'        => $sku,
            'product_full_price' => $unitPrice,
            'quantity'           => 1,
            'unit_price'         => $unitPrice,
            'line_total'         => $unitPrice,
        ]],
    ]);

    $order = $ctx['repo']->findById($orderId);

    expect($order)->not->toBeFalse()
        ->and($order->user_id)->toBe(1)
        ->and($order->status_id)->toBe($statusId)
        ->and($order->status_value)->toBe('PENDING');
});

it('throws when items array is empty', function () use ($newContext): void {
    $ctx = $newContext();

    $ctx['repo']->create([
        'user_id' => $ctx['userId'],
        'amount'  => '10.00',
        'payment_gateway' => 'bold',
        'items'   => [],
    ]);
})->throws(InvalidArgumentException::class);

it('updates tracking_number and carrier_name together', function () use ($newContext): void {
    $ctx = $newContext();
    $orderId = Seed::order($ctx['userId']);

    $ok = $ctx['repo']->updateTrackingNumber($orderId, 'TRK-INT-001', 'MELONN');
    expect($ok)->toBeTrue();

    $order = $ctx['repo']->findById($orderId);
    expect($order->tracking_number)->toBe('TRK-INT-001')
        ->and($order->carrier_name)->toBe('MELONN');
});

it('updates payment state JSON and status_id', function () use ($newContext): void {
    $ctx = $newContext();
    $orderId  = Seed::order($ctx['userId']);
    $statusId = (new App\Features\Order\StatusOrderRepository())
        ->findOrCreateIdByValue('TEST_PAID_' . uniqid());

    $ctx['repo']->markPaymentState($orderId, ['provider' => 'wompi', 'state' => 'APPROVED'], $statusId);

    $order = $ctx['repo']->findById($orderId);
    expect($order->status_id)->toBe($statusId)
        ->and($order->payment_state_json)->toContain('APPROVED');
});

it('updates SAP order code', function () use ($newContext): void {
    $ctx = $newContext();
    $orderId = Seed::order($ctx['userId']);

    $ctx['repo']->updateSapOrderCode($orderId, 700123);

    expect($ctx['repo']->findById($orderId)->sap_order_code)->toBe(700123);
});

it('updates the order agent_id using an existing employee id', function () use ($newContext): void {
    $ctx = $newContext();
    $agentId = activeEmployeeIdForOrderRepository();
    if ($agentId === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $orderId = Seed::order($ctx['userId']);

    expect($ctx['repo']->updateAgentId($orderId, $agentId))->toBeTrue()
        ->and($ctx['repo']->findById($orderId)->agent_id)->toBe($agentId);
});

it('filters all orders by status and date range', function () use ($newContext): void {
    $ctx = $newContext();
    $includedStatus = 'ADMIN_FILTER_INCLUDED_' . uniqid();
    $excludedStatus = 'ADMIN_FILTER_EXCLUDED_' . uniqid();
    $includedStatusId = (new App\Features\Order\StatusOrderRepository())
        ->findOrCreateIdByValue($includedStatus);
    $excludedStatusId = (new App\Features\Order\StatusOrderRepository())
        ->findOrCreateIdByValue($excludedStatus);

    $includedOrderId = Seed::order($ctx['userId']);
    $excludedOrderId = Seed::order($ctx['userId']);

    Seed::pdo()->prepare('UPDATE orders SET status_id = ?, created_at = ? WHERE order_id = ?')
        ->execute([$includedStatusId, '2026-01-15 10:00:00', $includedOrderId]);
    Seed::pdo()->prepare('UPDATE orders SET status_id = ?, created_at = ? WHERE order_id = ?')
        ->execute([$excludedStatusId, '2026-01-15 10:00:00', $excludedOrderId]);

    $result = $ctx['repo']->findAll(1, 20, [
        'status' => $includedStatus,
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]);

    $orderIds = array_map(
        static fn (App\Features\Order\OrderModel $order): int => $order->order_id,
        $result['data'],
    );

    expect($orderIds)->toContain($includedOrderId)
        ->and($orderIds)->not->toContain($excludedOrderId);
});

it('sorts all orders by whitelisted columns', function () use ($newContext): void {
    $ctx = $newContext();
    $status = 'ADMIN_SORT_' . uniqid();
    $statusId = (new App\Features\Order\StatusOrderRepository())
        ->findOrCreateIdByValue($status);

    $lowAmountOrderId = Seed::order($ctx['userId'], amount: 1000);
    $highAmountOrderId = Seed::order($ctx['userId'], amount: 9000);

    Seed::pdo()->prepare('UPDATE orders SET status_id = ?, created_at = ? WHERE order_id IN (?, ?)')
        ->execute([$statusId, '2026-02-15 10:00:00', $lowAmountOrderId, $highAmountOrderId]);

    $result = $ctx['repo']->findAll(1, 20, [
        'status' => $status,
        'date_from' => '2026-02-01',
        'date_to' => '2026-02-28',
        'sort_by' => 'amount',
        'sort_order' => 'ASC',
    ]);

    expect($result['data'][0]->order_id)->toBe($lowAmountOrderId)
        ->and($result['data'][1]->order_id)->toBe($highAmountOrderId);
});

it('lists orders by user paginated', function () use ($newContext): void {
    $ctx = $newContext();
    Seed::order($ctx['userId']);
    Seed::order($ctx['userId']);

    $result = $ctx['repo']->findAllByUser($ctx['userId'], 1, 10);

    expect($result['total'])->toBeGreaterThanOrEqual(2)
        ->and($result['data'])->not->toBeEmpty();
});

it('deletes an order (cascades items via FK)', function () use ($newContext): void {
    $ctx = $newContext();
    $orderId = Seed::order($ctx['userId']);

    expect($ctx['repo']->delete($orderId))->toBeTrue()
        ->and($ctx['repo']->findById($orderId))->toBeFalse();
});

function activeEmployeeIdForOrderRepository(): ?int
{
    $stmt = Seed::pdo()->query(
        'SELECT id
         FROM ' . App\Infrastructure\Database\IntranetTables::fromEnv()->employees . '
         WHERE activo = 1 AND deleteSoft = 0
         ORDER BY id ASC
         LIMIT 1'
    );

    $id = $stmt !== false ? $stmt->fetchColumn() : false;
    if ($id === false) {
        return null;
    }

    return (int) $id;
}
