<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Sistecredito\SistecreditoWebhookProcessor;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;

it('processes an Approved Sistecredito payload (PascalCase)', function (): void {
    $payload = [
        'Invoice'  => '1112',
        'Value'    => 128642,
        'Currency' => 'COP',
        'PaymentMethodResponse' => [
            'TransactionId'  => 'SC-123456',
            'StatusResponse' => 'Approved',
        ],
    ];

    $result = (new SistecreditoWebhookProcessor())->process($payload);

    expect($result->gateway)->toBe('sistecredito')
        ->and($result->orderId)->toBe(1112)
        ->and($result->transactionId)->toBe('SC-123456')
        ->and($result->codStatus)->toBe(PaymentStatus::APROBADA)
        ->and($result->statusText)->toBe(PaymentStatus::TEXT_APROBADA)
        ->and($result->amount)->toBe(128642.0)
        ->and($result->isApproved())->toBeTrue();
});

it('throws when Invoice is missing', function (): void {
    expect(fn () => (new SistecreditoWebhookProcessor())->process([
        'PaymentMethodResponse' => ['StatusResponse' => 'Approved'],
    ]))->toThrow(RuntimeException::class);
});

it('throws when PaymentMethodResponse.StatusResponse is missing', function (): void {
    expect(fn () => (new SistecreditoWebhookProcessor())->process([
        'Invoice' => '1',
        'PaymentMethodResponse' => [],
    ]))->toThrow(RuntimeException::class);
});
