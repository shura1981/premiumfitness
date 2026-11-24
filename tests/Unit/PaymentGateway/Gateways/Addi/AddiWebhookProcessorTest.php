<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Gateways\Addi\AddiWebhookProcessor;
use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use Tests\Support\Seed;
use Tests\Support\Shop;

it('processes an APPROVED Addi payload into a canonical PaymentResult', function (): void {
    // El pedido se busca por su id de pago (`orders.payment_code`).
    $orderId = Seed::order(Seed::user());
    $payload = [
        'orderId'        => Shop::reference($orderId),
        'applicationId'  => 'app-addi-xyz-123',
        'status'         => 'APPROVED',
        'approvedAmount' => 255000,
        'currency'       => 'COP',
    ];

    $result = (new AddiWebhookProcessor())->process($payload);

    expect($result->gateway)->toBe('addi')
        ->and($result->orderId)->toBe($orderId)
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

it('marca como ajeno un id de pago que no es de un pedido de PremiumFitness', function (): void {
    $orderId = Seed::order(Seed::user());

    // El número solo (otro proyecto), mayúsculas, sin número o con algo más: ninguno es el `payment_code`.
    foreach ([(string) $orderId, 'PF-' . $orderId, 'pf-', "pf-{$orderId}-1", 'pf-999999999999'] as $foreign) {
        expect(fn () => (new AddiWebhookProcessor())->process([
            'orderId' => $foreign, 'applicationId' => 'app-1', 'status' => 'APPROVED', 'approvedAmount' => 1000,
        ]))->toThrow(ForeignPaymentReferenceException::class);
    }
});
