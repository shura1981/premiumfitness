<?php

declare(strict_types=1);

use App\Features\Order\OrderSapCodeValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with integer sap_order_code', function (): void {
    (new OrderSapCodeValidator())->validate(['sap_order_code' => 700001]);
    expect(true)->toBeTrue();
});

it('fails without sap_order_code', function (): void {
    expect(fn () => (new OrderSapCodeValidator())->validate([]))
        ->toThrow(ValidationException::class);
});

it('fails when sap_order_code is not integer', function (): void {
    expect(fn () => (new OrderSapCodeValidator())->validate(['sap_order_code' => 'NOPE']))
        ->toThrow(ValidationException::class);
});
