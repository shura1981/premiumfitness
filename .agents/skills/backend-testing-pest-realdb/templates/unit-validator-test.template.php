<?php

declare(strict_types=1);

use App\Features\Order\OrderValidator as ValidatorUnderTest;
use App\Shared\Exceptions\ValidationException;

// Reemplazar ValidatorUnderTest por el validador real de la feature objetivo.
it('passes with valid payload', function (): void {
    (new ValidatorUnderTest())->validate([
        // TODO payload valido
    ]);

    expect(true)->toBeTrue();
});

it('fails without required fields', function (): void {
    expect(fn () => (new ValidatorUnderTest())->validate([]))
        ->toThrow(ValidationException::class);
});

it('fails with invalid field format', function (): void {
    expect(fn () => (new ValidatorUnderTest())->validate([
        // TODO payload invalido
    ]))->toThrow(ValidationException::class);
});
