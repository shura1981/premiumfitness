<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Validators\BoldStateQueryValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with order_id', function (): void {
    (new BoldStateQueryValidator())->validateQuery(['order_id' => 1024]);
    expect(true)->toBeTrue();
});

it('passes with payment_id', function (): void {
    (new BoldStateQueryValidator())->validateQuery(['payment_id' => 'BOLD_TX_987654']);
    expect(true)->toBeTrue();
});

it('fails when neither order_id nor payment_id is provided', function (): void {
    expect(fn () => (new BoldStateQueryValidator())->validateQuery([]))
        ->toThrow(ValidationException::class);
});

it('fails when payment_id format is invalid', function (): void {
    expect(fn () => (new BoldStateQueryValidator())->validateQuery(['payment_id' => 'bad']))
        ->toThrow(ValidationException::class);
});

