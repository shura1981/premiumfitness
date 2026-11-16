<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Validators\AddiConsumeValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with required fields', function (): void {
    (new AddiConsumeValidator())->validate([
        'orderId'             => 1234,
        'totalAmount'         => 250000,
        'allyTransactionDate' => '2026-04-26 10:00:00',
    ]);

    expect(true)->toBeTrue();
});

it('fails without orderId', function (): void {
    expect(fn () => (new AddiConsumeValidator())->validate([
        'totalAmount'         => 250000,
        'allyTransactionDate' => '2026-04-26 10:00:00',
    ]))->toThrow(ValidationException::class);
});

it('fails when orderId is not integer', function (): void {
    expect(fn () => (new AddiConsumeValidator())->validate([
        'orderId'             => 'abc',
        'totalAmount'         => 250000,
        'allyTransactionDate' => '2026-04-26 10:00:00',
    ]))->toThrow(ValidationException::class);
});
