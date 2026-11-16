<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Addi\AddiWebhookProcessor;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;

it('processes an APPROVED Addi payload into a canonical PaymentResult', function (): void {
    $payload = [
        'orderId'        => '653449',
        'applicationId'  => 'app-addi-xyz-123',
        'status'         => 'APPROVED',
        'approvedAmount' => 255000,
        'currency'       => 'COP',
    ];

    $result = (new AddiWebhookProcessor())->process($payload);

    expect($result->gateway)->toBe('addi')
        ->and($result->orderId)->toBe(653449)
        ->and($result->transactionId)->toBe('app-addi-xyz-123')
        ->and($result->codStatus)->toBe(PaymentStatus::APROBADA)
        ->and($result->statusText)->toBe(PaymentStatus::TEXT_APROBADA)
        ->and($result->amount)->toBe(255000.0)
        ->and($result->isApproved())->toBeTrue();
});

it('throws when orderId is missing', function (): void {
    expect(fn () => (new AddiWebhookProcessor())->process(['status' => 'APPROVED']))
        ->toThrow(RuntimeException::class);
});

it('throws when status is missing', function (): void {
    expect(fn () => (new AddiWebhookProcessor())->process(['orderId' => 1]))
        ->toThrow(RuntimeException::class);
});
