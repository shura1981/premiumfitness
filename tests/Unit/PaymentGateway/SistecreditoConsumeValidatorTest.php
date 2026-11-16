<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Validators\SistecreditoConsumeValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with required fields', function (): void {
    (new SistecreditoConsumeValidator())->validate([
        'Invoice'  => '12345',
        'Value'    => 128642,
        'Currency' => 'COP',
    ]);

    expect(true)->toBeTrue();
});

it('fails without Currency', function (): void {
    expect(fn () => (new SistecreditoConsumeValidator())->validate([
        'Invoice' => '12345',
        'Value'   => 128642,
    ]))->toThrow(ValidationException::class);
});
