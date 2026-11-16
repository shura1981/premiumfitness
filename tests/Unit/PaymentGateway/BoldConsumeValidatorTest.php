<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Validators\BoldConsumeValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with required Bold consume fields', function (): void {
    (new BoldConsumeValidator())->validate([
        'order_id'        => 12345,
        'amount'          => 150000,
        'currency'        => 'COP',
        'description'     => 'Pedido Radical Nutrition #12345',
        'redirection_url' => 'https://radicalnutrition.com/response/bold',
    ]);

    expect(true)->toBeTrue();
});

it('fails without amount', function (): void {
    expect(fn () => (new BoldConsumeValidator())->validate([
        'order_id' => 12345,
        'currency' => 'COP',
    ]))->toThrow(ValidationException::class);
});

it('fails with non-COP currency', function (): void {
    expect(fn () => (new BoldConsumeValidator())->validate([
        'order_id' => 12345,
        'amount'   => 150000,
        'currency' => 'USD',
    ]))->toThrow(ValidationException::class);
});

