<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Addi\AddiWebhookProcessor;
use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use Tests\Support\FakePaymentReferences;

it('processes an APPROVED Addi payload into a canonical PaymentResult', function (): void {
    $payload = [
        'orderId'        => 'pf-653449',
        'applicationId'  => 'app-addi-xyz-123',
        'status'         => 'APPROVED',
        'approvedAmount' => 255000,
        'currency'       => 'COP',
    ];

    $result = (new AddiWebhookProcessor(references: new FakePaymentReferences()))->process($payload);

    expect($result->gateway)->toBe('addi')
        ->and($result->orderId)->toBe(653449)
        ->and($result->transactionId)->toBe('app-addi-xyz-123')
        ->and($result->codStatus)->toBe(PaymentStatus::APROBADA)
        ->and($result->statusText)->toBe(PaymentStatus::TEXT_APROBADA)
        ->and($result->amount)->toBe(255000.0)
        ->and($result->isApproved())->toBeTrue();
});

it('throws when orderId is missing', function (): void {
    expect(fn () => (new AddiWebhookProcessor(references: new FakePaymentReferences()))->process(['status' => 'APPROVED']))
        ->toThrow(RuntimeException::class);
});

it('throws when status is missing', function (): void {
    expect(fn () => (new AddiWebhookProcessor(references: new FakePaymentReferences()))->process(['orderId' => 1]))
        ->toThrow(RuntimeException::class);
});

it('marca como ajena una referencia que no es pf-<id>', function (): void {
    expect(fn () => (new AddiWebhookProcessor(references: new FakePaymentReferences()))->process([
        'orderId' => '863', 'applicationId' => 'app-1', 'status' => 'APPROVED', 'approvedAmount' => 1000,
    ]))->toThrow(ForeignPaymentReferenceException::class);
});
