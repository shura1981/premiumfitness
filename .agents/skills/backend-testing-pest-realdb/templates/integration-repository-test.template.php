<?php

declare(strict_types=1);

use App\Features\Order\OrderRepository as RepositoryUnderTest;
use Tests\Support\Seed;

// Reemplazar RepositoryUnderTest por el repositorio real de la feature objetivo.
$newContext = static function (): array {
    return [
        'repo' => new RepositoryUnderTest(),
        'userId' => Seed::user(),
    ];
};

it('creates and finds entity in real DB', function () use ($newContext): void {
    $ctx = $newContext();

    // TODO ejecutar create() con payload valido
    // $id = $ctx['repo']->create([...]);

    // TODO buscar entidad y validar campos
    // $entity = $ctx['repo']->findById($id);
    // expect($entity)->not->toBeFalse();

    expect(true)->toBeTrue();
});

it('handles business edge case in repository', function () use ($newContext): void {
    $ctx = $newContext();

    // TODO caso borde de SQL o regla de negocio
    // expect($ctx['repo']->method(...))->toBe(...);

    expect(true)->toBeTrue();
});
