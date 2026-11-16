<?php

declare(strict_types=1);

use App\Features\Order\StatusOrderRepository;

it('returns same id for the same value (idempotent)', function (): void {
    $repo  = new StatusOrderRepository();
    $value = 'TEST_STATUS_' . uniqid();

    $idA = $repo->findOrCreateIdByValue($value);
    $idB = $repo->findOrCreateIdByValue($value);

    expect($idA)->toBe($idB)
        ->and($idA)->toBeGreaterThan(0);
});

it('lists statuses (includes the one just created)', function (): void {
    $repo  = new StatusOrderRepository();
    $value = 'LIST_TEST_' . uniqid();
    $repo->findOrCreateIdByValue($value);

    $values = array_map(fn ($s) => $s->value, $repo->findAll());

    expect($values)->toContain($value);
});
