<?php

declare(strict_types=1);

use App\Infrastructure\Auth\JwtService;
use PHPUnit\Framework\Assert;
use Tests\Support\AppFactory;
use Tests\Support\Seed;

it('rejects admin reports without admin JWT', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/api/admin/reports/dashboard',
    ));

    expect($response->getStatusCode())->toBe(401);
});

$reportEndpoints = [
    '/api/admin/reports/dashboard',
    '/api/admin/reports/sales/monthly?year=2026',
    '/api/admin/reports/sales/daily?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/sales/by-hour?date=' . date('Y-m-d'),
    '/api/admin/reports/sales/by-gateway?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/sales/by-status?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/top-products?date_from=2000-01-01&date_to=2099-12-31&limit=5',
    '/api/admin/reports/top-customers?date_from=2000-01-01&date_to=2099-12-31&limit=5',
    '/api/admin/reports/top-categories?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/sales/by-city?date_from=2000-01-01&date_to=2099-12-31&limit=5',
    '/api/admin/reports/sales/by-department?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/customers/new-vs-returning?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/customers/frequency?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/users?page=1&per_page=5',
    '/api/admin/reports/products/performance?date_from=2000-01-01&date_to=2099-12-31&page=1&per_page=5',
    '/api/admin/reports/products/stock-status',
    '/api/admin/reports/products/discount-impact?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/carriers/distribution?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/approved-orders-with-agents?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/orders/download?date_from=2000-01-01&date_to=2099-12-31',
    '/api/admin/reports/products/download?date_from=2000-01-01&date_to=2099-12-31',
];

foreach ($reportEndpoints as $endpoint) {
    it("returns JSON for {$endpoint}", function () use ($endpoint): void {
        $employee = activeEmployeeForAdminReportsApi();
        if ($employee === null) {
            Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
        }

        $response = AppFactory::dispatch(AppFactory::request(
            'GET',
            $endpoint,
            headers: adminReportsAuthHeader($employee),
        ));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
            ->and(AppFactory::json($response))->not->toBeNull();
    });
}

it('searches users through the admin users report endpoint', function (): void {
    $employee = activeEmployeeForAdminReportsApi();
    if ($employee === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $userId = Seed::user();
    $stmt = Seed::pdo()->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $email = (string) $stmt->fetchColumn();

    $response = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/api/admin/reports/users?page=1&per_page=5&q=' . rawurlencode($email),
        headers: adminReportsAuthHeader($employee),
    ));

    expect($response->getStatusCode())->toBe(200);
    $body = AppFactory::json($response);
    $ids = array_map(
        static fn (array $row): int => (int) $row['id'],
        $body['data'],
    );

    expect($body)->toHaveKeys(['data', 'total', 'page', 'per_page'])
        ->and($body['page'])->toBe(1)
        ->and($body['per_page'])->toBe(5)
        ->and($ids)->toContain($userId);
});

/**
 * @param array{id:int,id_usuario:int,email:string,usuario:string,cargo:string,departamento:string} $employee
 * @return array{Authorization:string}
 */
function adminReportsAuthHeader(array $employee): array
{
    $token = JwtService::fromEnv()->issue([
        'sub' => $employee['id_usuario'],
        'app' => 'admin',
        'id_empleado' => $employee['id'],
        'usuario' => $employee['usuario'],
        'email' => $employee['email'],
        'cargo' => $employee['cargo'],
        'departamento' => $employee['departamento'],
    ]);

    return ['Authorization' => 'Bearer ' . $token];
}

/** @return array{id:int,id_usuario:int,email:string,usuario:string,cargo:string,departamento:string}|null */
function activeEmployeeForAdminReportsApi(): ?array
{
    $stmt = Seed::pdo()->query(
        'SELECT id, id_usuario, email, usuario, COALESCE(cargo, \'\') AS cargo, departamento
         FROM crisenri_intranet.tb_empleados
         WHERE activo = 1 AND deleteSoft = 0
         ORDER BY id ASC
         LIMIT 1'
    );

    /** @var array{id:mixed,id_usuario:mixed,email:mixed,usuario:mixed,cargo:mixed,departamento:mixed}|false $row */
    $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($row === false) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'id_usuario' => (int) $row['id_usuario'],
        'email' => (string) $row['email'],
        'usuario' => (string) $row['usuario'],
        'cargo' => (string) $row['cargo'],
        'departamento' => (string) $row['departamento'],
    ];
}
