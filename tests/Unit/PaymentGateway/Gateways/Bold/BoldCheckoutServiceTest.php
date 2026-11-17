<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Bold\BoldCheckoutService;

it('generates the official Bold SHA-256 integrity hash', function (): void {
    $service = new BoldCheckoutService(
        identityKey: 'PUBLIC-IDENTITY-KEY',
        secretKey: 'SECRET-KEY-XYZ',
        defaultResponseUrl: 'https://radicalnutrition.com/response/bold',
    );

    $expected = hash('sha256', '1050245000COPSECRET-KEY-XYZ');

    expect($service->generateIntegrityHash(1050, 245000, 'cop'))->toBe($expected);
});

it('builds BoldCheckout options with JSON-encoded customerData and billingAddress', function (): void {
    $service = new BoldCheckoutService(
        identityKey: 'PUBLIC-IDENTITY-KEY',
        secretKey: 'SECRET-KEY-XYZ',
        defaultResponseUrl: 'https://radicalnutrition.com/response/bold',
    );

    $config = $service->buildCheckoutConfig([
        'order_id' => 2048,
        'amount'   => 189900,
        'customer' => [
            'email' => 'cliente@example.com',
            'fullName' => 'Cliente Radical',
            'phone' => '3001234567',
            'dialCode' => '+57',
            'documentNumber' => '1020304050',
            'documentType' => 'CC',
        ],
        'billing_address' => [
            'address' => 'Calle 10 # 20-30',
            'city' => 'CALI',
            'state' => 'VALLE DEL CAUCA',
            'country' => 'CO',
        ],
    ]);

    expect($config['apiKey'])->toBe('PUBLIC-IDENTITY-KEY')
        ->and($config['orderId'])->toBe('2048')
        ->and($config['amount'])->toBe('189900')
        ->and($config['currency'])->toBe('COP')
        ->and($config['integritySignature'])->toBe(hash('sha256', '2048189900COPSECRET-KEY-XYZ'))
        ->and($config['redirectionUrl'])->toBe('https://radicalnutrition.com/response/bold?orderId=2048')
        ->and($config['renderMode'])->toBe('embedded')
        ->and($config['customerData'] ?? null)->toBeString()
        ->and(json_decode($config['customerData'] ?? '{}', true))->toMatchArray([
            'email' => 'cliente@example.com',
            'documentType' => 'CC',
        ])
        ->and($config['billingAddress'] ?? null)->toBeString()
        ->and(json_decode($config['billingAddress'] ?? '{}', true))->toMatchArray([
            'city' => 'CALI',
            'country' => 'CO',
        ]);
});

it('acepta la referencia con la regla de Bold y rechaza otra, o un valor no positivo', function (): void {
    $service = new BoldCheckoutService('PUB', 'SEC', 'https://example.com/response/bold');

    expect($service->generateIntegrityHash('pf-12', 10000))->toBe(hash('sha256', 'pf-1210000COPSEC'))
        ->and(fn () => $service->generateIntegrityHash('pf 12', 10000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->generateIntegrityHash(str_repeat('a', 61), 10000))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->generateIntegrityHash('pf-12', 0))->toThrow(InvalidArgumentException::class);
});

