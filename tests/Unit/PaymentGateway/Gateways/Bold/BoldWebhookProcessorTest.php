<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Bold\BoldWebhookProcessor;
use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use Tests\Support\FakePaymentReferences;

it('processes a SALE_APPROVED Bold webhook payload as APROBADA', function (): void {
    $payload = [
        'type' => 'SALE_APPROVED',
        'data' => [
            'payment_id' => 'BOLD-PAY-987654',
            'amount' => [
                'total' => 240000,
                'currency' => 'COP',
            ],
            'metadata' => [
                'reference' => 'pf-5050',
            ],
        ],
    ];

    $result = (new BoldWebhookProcessor(references: new FakePaymentReferences()))->process($payload);

    expect($result->gateway)->toBe('bold')
        ->and($result->orderId)->toBe(5050)
        ->and($result->transactionId)->toBe('BOLD-PAY-987654')
        ->and($result->codStatus)->toBe(PaymentStatus::APROBADA)
        ->and($result->statusText)->toBe(PaymentStatus::TEXT_APROBADA)
        ->and($result->amount)->toBe(240000.0);
});

it('processes SALE_REJECTED and VOID_APPROVED as RECHAZADA', function (string $eventType): void {
    $payload = [
        'type' => $eventType,
        'data' => [
            'payment_id' => 'BOLD-PAY-111',
            'amount' => ['total' => 95000, 'currency' => 'COP'],
            'metadata' => ['reference' => 'pf-77'],
        ],
    ];

    $result = (new BoldWebhookProcessor(references: new FakePaymentReferences()))->process($payload);

    expect($result->codStatus)->toBe(PaymentStatus::RECHAZADA)
        ->and($result->statusText)->toBe(PaymentStatus::TEXT_RECHAZADA);
})->with(['SALE_REJECTED', 'VOID_APPROVED']);

it('identifies VOID_REJECTED as a no-op event', function (): void {
    $processor = new BoldWebhookProcessor(references: new FakePaymentReferences());

    expect($processor->isVoidRejected(['type' => 'VOID_REJECTED']))->toBeTrue()
        ->and($processor->isVoidRejected(['type' => 'SALE_APPROVED']))->toBeFalse();
});

it('throws when critical fields are missing or currency is not COP', function (array $invalidPayload): void {
    expect(fn () => (new BoldWebhookProcessor(references: new FakePaymentReferences()))->process($invalidPayload))
        ->toThrow(RuntimeException::class);
})->with([
    'missing type' => [['data' => []]],
    'missing reference' => [[
        'type' => 'SALE_APPROVED',
        'data' => [
            'payment_id' => 'BOLD-1',
            'amount' => ['total' => 10000, 'currency' => 'COP'],
            'metadata' => [],
        ],
    ]],
    'missing payment_id' => [[
        'type' => 'SALE_APPROVED',
        'data' => [
            'amount' => ['total' => 10000, 'currency' => 'COP'],
            'metadata' => ['reference' => 'pf-10'],
        ],
    ]],
    'unsupported currency' => [[
        'type' => 'SALE_APPROVED',
        'data' => [
            'payment_id' => 'BOLD-1',
            'amount' => ['total' => 100, 'currency' => 'USD'],
            'metadata' => ['reference' => 'pf-10'],
        ],
    ]],
]);


it('marca como ajena una referencia que no es pf-<id>, como las numéricas del sitio web', function (string $reference): void {
    $payload = [
        'type' => 'SALE_APPROVED',
        'data' => [
            'payment_id' => 'BOLD-AJENO-1',
            'amount'     => ['total' => 50000, 'currency' => 'COP'],
            'metadata'   => ['reference' => $reference],
        ],
    ];

    expect(fn () => (new BoldWebhookProcessor(references: new FakePaymentReferences()))->process($payload))
        ->toThrow(ForeignPaymentReferenceException::class);
})->with(['863', 'PF-863', 'pf-', 'pf-863-1']);
