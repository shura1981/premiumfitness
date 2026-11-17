<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Sistecredito\SistecreditoWebhookProcessor;
use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use Tests\Support\FakePaymentReferences;

it('processes an Approved Sistecredito payload (PascalCase)', function (): void {
    $payload = [
        'Invoice'  => 'pf-1112',
        'Value'    => 128642,
        'Currency' => 'COP',
        'PaymentMethodResponse' => [
            'TransactionId'  => 'SC-123456',
            'StatusResponse' => 'Approved',
        ],
    ];

    $result = (new SistecreditoWebhookProcessor(references: new FakePaymentReferences()))->process($payload);

    expect($result->gateway)->toBe('sistecredito')
        ->and($result->orderId)->toBe(1112)
        ->and($result->transactionId)->toBe('SC-123456')
        ->and($result->codStatus)->toBe(PaymentStatus::APROBADA)
        ->and($result->statusText)->toBe(PaymentStatus::TEXT_APROBADA)
        ->and($result->amount)->toBe(128642.0)
        ->and($result->isApproved())->toBeTrue();
});

it('throws when Invoice is missing', function (): void {
    expect(fn () => (new SistecreditoWebhookProcessor(references: new FakePaymentReferences()))->process([
        'PaymentMethodResponse' => ['StatusResponse' => 'Approved'],
    ]))->toThrow(RuntimeException::class);
});

it('throws when PaymentMethodResponse.StatusResponse is missing', function (): void {
    expect(fn () => (new SistecreditoWebhookProcessor(references: new FakePaymentReferences()))->process([
        'Invoice' => '1',
        'PaymentMethodResponse' => [],
    ]))->toThrow(RuntimeException::class);
});

it('marca como ajena una referencia que no es pf-<id>', function (): void {
    expect(fn () => (new SistecreditoWebhookProcessor(references: new FakePaymentReferences()))->process([
        'Invoice' => '863', 'Value' => 1000,
        'PaymentMethodResponse' => ['TransactionId' => 'SC-1', 'StatusResponse' => 'Approved'],
    ]))->toThrow(ForeignPaymentReferenceException::class);
});
