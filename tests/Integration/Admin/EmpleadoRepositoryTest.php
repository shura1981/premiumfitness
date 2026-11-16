<?php

declare(strict_types=1);

use App\Features\Admin\EmpleadoRepository;
use App\Features\Admin\EmpleadoModel;
use PHPUnit\Framework\Assert;
use Tests\Support\Seed;

it('finds a real employee by email', function (): void {
    $employee = activeEmployeeFromEmployeesTable();
    if ($employee === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $repository = new EmpleadoRepository();

    $found = $repository->findByEmail($employee['email']);

    expect($found)->not->toBeFalse()
        ->and($found->id)->toBe($employee['id'])
        ->and($found->id_usuario)->toBe($employee['id_usuario'])
        ->and($found->email)->toBe($employee['email'])
        ->and($found->usuario)->toBe($employee['usuario']);
});

it('finds a real employee by id', function (): void {
    $employee = activeEmployeeFromEmployeesTable();
    if ($employee === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $repository = new EmpleadoRepository();

    $found = $repository->findById($employee['id']);

    expect($found)->not->toBeFalse()
        ->and($found->id)->toBe($employee['id'])
        ->and($found->email)->toBe($employee['email']);
});

it('lists active non deleted employees from tb_empleados', function (): void {
    $employee = activeEmployeeFromEmployeesTable();
    if ($employee === null) {
        Assert::markTestSkipped('No active employees were found in crisenri_intranet.tb_empleados.');
    }

    $repository = new EmpleadoRepository();

    $emails = array_map(
        static fn (EmpleadoModel $employee): string => $employee->email,
        $repository->findAll(),
    );

    expect($emails)->toContain($employee['email']);
});

/** @return array{id:int,id_usuario:int,email:string,usuario:string}|null */
function activeEmployeeFromEmployeesTable(): ?array
{
    $stmt = Seed::pdo()->query(
        'SELECT id, id_usuario, email, usuario
         FROM ' . App\Infrastructure\Database\IntranetTables::fromEnv()->employees . '
         WHERE activo = 1 AND deleteSoft = 0
         ORDER BY id ASC
         LIMIT 1'
    );

    /** @var array{id:mixed,id_usuario:mixed,email:mixed,usuario:mixed}|false $row */
    $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($row === false) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'id_usuario' => (int) $row['id_usuario'],
        'email' => (string) $row['email'],
        'usuario' => (string) $row['usuario'],
    ];
}
