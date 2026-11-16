<?php

declare(strict_types=1);

use App\Features\Order\OrderTrackingValidator;
use App\Shared\Exceptions\ValidationException;

it('accepts valid tracking + carrier', function (string $carrier): void {
    (new OrderTrackingValidator())->validate([
        'tracking_number' => 'TRK-0001',
        'carrier_name'    => $carrier,
    ]);

    expect(true)->toBeTrue();
})->with(['SERVIENTREGA', 'MELONN', 'ENVIA']);

it('rejects missing carrier_name', function (): void {
    expect(fn () => (new OrderTrackingValidator())->validate([
        'tracking_number' => 'TRK-0002',
    ]))->toThrow(ValidationException::class);
});

it('rejects missing tracking_number', function (): void {
    expect(fn () => (new OrderTrackingValidator())->validate([
        'carrier_name' => 'MELONN',
    ]))->toThrow(ValidationException::class);
});

it('rejects carrier not in enum', function (): void {
    expect(fn () => (new OrderTrackingValidator())->validate([
        'tracking_number' => 'TRK-0003',
        'carrier_name'    => 'DHL',
    ]))->toThrow(ValidationException::class);
});

it('rejects tracking_number longer than 50 chars', function (): void {
    expect(fn () => (new OrderTrackingValidator())->validate([
        'tracking_number' => str_repeat('X', 51),
        'carrier_name'    => 'ENVIA',
    ]))->toThrow(ValidationException::class);
});
