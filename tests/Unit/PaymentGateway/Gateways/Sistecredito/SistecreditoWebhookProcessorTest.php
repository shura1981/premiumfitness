<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Sistecredito\SistecreditoWebhookProcessor;
use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use Tests\Support\Seed;
use Tests\Support\Shop;

it('processes an Approved Sistecredito payload (PascalCase)', function (): void {
    // El pedido se busca por su id de pago (`orders.payment_code`).
    $orderId = Seed::order(Seed::user());
    $payload = [
        'Invoice'  => Shop::reference($orderId),
        'Value'    => 128642,
        'Currency' => 'COP',
        'PaymentMethodResponse' => [
            'TransactionId'  => 'SC-123456',
            'StatusResponse' => 'Approved',
        ],
    ];

    $result = (new SistecreditoWebhookProcessor())->process($payload);

    expect($result->gateway)->toBe('sistecredito')
        ->and($result->orderId)->toBe($orderId)
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

it('marca como ajeno un id de pago que no es de un pedido de PremiumFitness', function (): void {
    $orderId = Seed::order(Seed::user());

    foreach ([(string) $orderId, 'PF-' . $orderId, 'pf-', "pf-{$orderId}-1", 'pf-999999999999'] as $foreign) {
        expect(fn () => (new SistecreditoWebhookProcessor())->process([
            'Invoice' => $foreign, 'Value' => 1000,
            'PaymentMethodResponse' => ['TransactionId' => 'SC-1', 'StatusResponse' => 'Approved'],
        ]))->toThrow(ForeignPaymentReferenceException::class);
    }
});
