<?php

declare(strict_types=1);

use App\Features\Order\OrderStatusUpdateValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with integer status_id', function (): void {
    (new OrderStatusUpdateValidator())->validate(['status_id' => 3]);
    expect(true)->toBeTrue();
});

it('fails without status_id', function (): void {
    expect(fn () => (new OrderStatusUpdateValidator())->validate([]))
        ->toThrow(ValidationException::class);
});

it('fails when status_id is not integer', function (): void {
    expect(fn () => (new OrderStatusUpdateValidator())->validate(['status_id' => 'foo']))
        ->toThrow(ValidationException::class);
});
