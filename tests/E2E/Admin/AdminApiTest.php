<?php

declare(strict_types=1);

use App\Infrastructure\Auth\JwtService;
use PHPUnit\Framework\Assert;
use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

beforeEach(function (): void {
    clearAdminRateLimiterCache();
});

function clearAdminRateLimiterCache(): void
{
    $directory = dirname(__DIR__, 3) . '/logs/rate-limiter';

    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }
}

it('rejects admin login with an invalid payload', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/admin/auth/login',
        body: [
            'email' => 'invalid-email',
            'password' => 'abc',
        ],
    ));

    expect($response->getStatusCode())->toBe(422);
});

it('rejects admin login with unknown credentials', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/admin/auth/login',
        body: [
            'email' => 'missing_admin_' . uniqid() . '@example.com',
            'password' => 'wrong-password',
        ],
    ));

    expect($response->getStatusCode())->toBe(401);
});

it('rejects admin routes when a regular user JWT is used', function (): void {
    $userId = Seed::user();

    $response = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/api/admin/agents',
        headers: JwtFactory::authHeader($userId),
    ));

    expect($response->getStatusCode())->toBe(403);
});

it('lists and fetches agents with an admin JWT', function (): void {
    $employee = activeAdminEmployeeForAdminApi();
    if ($employee === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $headers = adminAuthHeader($employee);

    $list = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/api/admin/agents',
        headers: $headers,
    ));

    expect($list->getStatusCode())->toBe(200);
    $agents = AppFactory::json($list);
    $emails = array_map(
        static fn (array $agent): string => (string) $agent['email'],
        $agents,
    );

    expect($emails)->toContain($employee['email']);

    $get = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/api/admin/agents/' . $employee['id'],
        headers: $headers,
    ));

    expect($get->getStatusCode())->toBe(200);
    $agent = AppFactory::json($get);
    expect($agent['id'])->toBe($employee['id'])
        ->and($agent['email'])->toBe($employee['email']);
});

it('updates the order agent_id through the admin endpoint', function (): void {
    $employee = activeAdminEmployeeForAdminApi();
    if ($employee === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $userId = Seed::user();
    $orderId = Seed::order($userId);

    $response = AppFactory::dispatch(AppFactory::request(
        'PATCH',
        "/api/admin/orders/{$orderId}/agent-id",
        body: ['agent_id' => $employee['id']],
        headers: adminAuthHeader($employee),
    ));

    expect($response->getStatusCode())->toBe(200);
    $body = AppFactory::json($response);
    expect($body['order_id'])->toBe($orderId)
        ->and($body['agent_id'])->toBe($employee['id']);
});

it('rejects order agent_id updates without agent_id', function (): void {
    $employee = activeAdminEmployeeForAdminApi();
    if ($employee === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $userId = Seed::user();
    $orderId = Seed::order($userId);

    $response = AppFactory::dispatch(AppFactory::request(
        'PATCH',
        "/api/admin/orders/{$orderId}/agent-id",
        body: [],
        headers: adminAuthHeader($employee),
    ));

    expect($response->getStatusCode())->toBe(422);
});

/**
 * @param array{id:int,id_usuario:int,email:string,usuario:string,cargo:string,departamento:string} $employee
 * @return array{Authorization:string}
 */
function adminAuthHeader(array $employee): array
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
function activeAdminEmployeeForAdminApi(): ?array
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
