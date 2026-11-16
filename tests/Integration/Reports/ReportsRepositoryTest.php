<?php

declare(strict_types=1);

use App\Features\Order\StatusOrderRepository;
use App\Features\Reports\CustomerStatsModel;
use App\Features\Reports\DashboardKpiModel;
use App\Features\Reports\ReportsRepository;
use Tests\Support\Seed;

it('builds the dashboard KPI model from the real database', function (): void {
    $repository = new ReportsRepository();

    expect($repository->dashboard())->toBeInstanceOf(DashboardKpiModel::class);
});

it('runs sales, ranking, geo, customer, product and carrier reports', function (): void {
    $repository = new ReportsRepository();
    $dateFrom = '2000-01-01';
    $dateTo = '2099-12-31';

    expect($repository->salesMonthly(2026))->toBeArray()
        ->and($repository->salesDaily($dateFrom, $dateTo))->toBeArray()
        ->and($repository->salesByGateway($dateFrom, $dateTo))->toBeArray()
        ->and($repository->salesByStatus($dateFrom, $dateTo))->toBeArray()
        ->and($repository->salesByHour(date('Y-m-d')))->toBeArray()
        ->and($repository->topProducts($dateFrom, $dateTo, 5))->toBeArray()
        ->and($repository->topCustomers($dateFrom, $dateTo, 5))->toBeArray()
        ->and($repository->topCategories($dateFrom, $dateTo))->toBeArray()
        ->and($repository->salesByCity($dateFrom, $dateTo, 5))->toBeArray()
        ->and($repository->salesByDepartment($dateFrom, $dateTo))->toBeArray()
        ->and($repository->newVsReturning($dateFrom, $dateTo))->toBeInstanceOf(CustomerStatsModel::class)
        ->and($repository->customerFrequency($dateFrom, $dateTo))->toBeArray()
        ->and($repository->productPerformance($dateFrom, $dateTo, 1, 5))->toHaveKeys(['data', 'total', 'page', 'per_page'])
        ->and($repository->stockStatus())->toHaveKeys(['in_stock', 'out_of_stock', 'total'])
        ->and($repository->discountImpact($dateFrom, $dateTo))->toHaveKeys(['with_discount_revenue', 'without_discount_revenue'])
        ->and($repository->carrierDistribution($dateFrom, $dateTo))->toBeArray();
});

it('runs report download queries', function (): void {
    $repository = new ReportsRepository();
    $dateFrom = '2000-01-01';
    $dateTo = '2099-12-31';

    expect($repository->ordersDownload($dateFrom, $dateTo))->toBeArray()
        ->and($repository->productsDownload($dateFrom, $dateTo))->toBeArray();
});

it('lists users with pagination and search filters', function (): void {
    $userId = Seed::user();
    $stmt = Seed::pdo()->prepare(
        'SELECT id, document_id, full_name, email
         FROM users
         WHERE id = ?'
    );
    $stmt->execute([$userId]);

    /** @var array{id:mixed,document_id:mixed,full_name:mixed,email:mixed}|false $user */
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    expect($user)->not->toBeFalse();

    $result = (new ReportsRepository())->usersList(1, 5, (string) $user['email']);
    $ids = array_map(
        static fn (array $row): int => (int) $row['id'],
        $result['data'],
    );

    expect($result)->toHaveKeys(['data', 'total', 'page', 'per_page'])
        ->and($result['page'])->toBe(1)
        ->and($result['per_page'])->toBe(5)
        ->and($result['total'])->toBeGreaterThanOrEqual(1)
        ->and($ids)->toContain($userId)
        ->and($result['data'][0])->toHaveKeys([
            'id',
            'document_id',
            'document_type_name',
            'full_name',
            'num_cell',
            'email',
            'created_at',
            'updated_at',
        ]);
});

it('returns approved orders with agent data using only SELECT on tb_empleados', function (): void {
    $agentId = activeEmployeeIdForReportsRepository();
    if ($agentId === null) {
        PHPUnit\Framework\Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $userId = Seed::user();
    $orderId = Seed::approvedOrderWithCanonicalApprobada($userId);
    Seed::pdo()->prepare(
        'UPDATE orders
         SET agent_id = ?, sap_order_code = ?, created_at = ?
         WHERE order_id = ?'
    )->execute([$agentId, 990123, '2026-03-15 10:00:00', $orderId]);

    $rows = (new ReportsRepository())->approvedOrdersWithAgents('2026-03-01', '2026-03-31');
    $matched = array_values(array_filter(
        $rows,
        static fn (array $row): bool => (int) $row['order_id'] === $orderId,
    ));

    expect($matched)->toHaveCount(1)
        ->and((int) $matched[0]['agent_id'])->toBe($agentId)
        ->and((int) $matched[0]['sap_order_code'])->toBe(990123)
        ->and($matched[0])->toHaveKeys(['agent_usuario', 'agent_email', 'agent_cargo', 'agent_departamento']);
});

function activeEmployeeIdForReportsRepository(): ?int
{
    $stmt = Seed::pdo()->query(
        'SELECT id
         FROM crisenri_intranet.tb_empleados
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
