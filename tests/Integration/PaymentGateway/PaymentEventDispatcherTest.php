<?php

declare(strict_types=1);

use App\Features\Order\OrderRepository;
use App\Features\Order\StatusOrderRepository;
use App\Features\PaymentGateway\Events\PaymentEventDispatcher;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use App\Features\PaymentGateway\Result\PaymentResult;
use Tests\Support\FakeHandler;
use Tests\Support\Seed;

function pgResult(int $orderId, int $cod, string $text): PaymentResult {
    return new PaymentResult(
        gateway:       'addi',
        orderId:       $orderId,
        transactionId: 'tx-' . uniqid('', true),
        codStatus:     $cod,
        statusText:    $text,
        amount:        100000.0,
        rawPayload:    ['hello' => 'world'],
    );
}

it('returns order_not_found for a non-existent order', function (): void {
    $dispatcher = new PaymentEventDispatcher(new OrderRepository(), new StatusOrderRepository());

    $result = $dispatcher->dispatch(pgResult(99999999, PaymentStatus::APROBADA, PaymentStatus::TEXT_APROBADA));

    expect($result['processed'])->toBeFalse()
        ->and($result['reason'])->toBe('order_not_found');
});

it('persists status, marks payment_state_json and runs handlers when approved', function (): void {
    $userId  = Seed::user();
    $orderId = Seed::order($userId);

    $handler = new FakeHandler();
    $dispatcher = new PaymentEventDispatcher(
        new OrderRepository(),
        new StatusOrderRepository(),
        [$handler],
    );

    $outcome = $dispatcher->dispatch(pgResult($orderId, PaymentStatus::APROBADA, PaymentStatus::TEXT_APROBADA));

    expect($outcome['processed'])->toBeTrue()
        ->and($handler->handleCalls)->toBe(1);

    $persisted = (new OrderRepository())->findById($orderId);
    expect($persisted)->not->toBeFalse()
        ->and($persisted->status_value)->toBe(PaymentStatus::TEXT_APROBADA)
        ->and($persisted->payment_state_json)->toContain('hello');
});

it('skips when order is already APROBADA — no handlers run, no DB change', function (): void {
    $userId  = Seed::user();
    $orderId = Seed::approvedOrderWithCanonicalApprobada($userId);

    $handler = new FakeHandler();
    $dispatcher = new PaymentEventDispatcher(
        new OrderRepository(),
        new StatusOrderRepository(),
        [$handler],
    );

    $outcome = $dispatcher->dispatch(pgResult($orderId, PaymentStatus::RECHAZADA, PaymentStatus::TEXT_RECHAZADA));

    expect($outcome['processed'])->toBeFalse()
        ->and($outcome['reason'])->toBe('already_approved')
        ->and($handler->handleCalls)->toBe(0);

    $persisted = (new OrderRepository())->findById($orderId);
    expect($persisted->status_value)->toBe(PaymentStatus::TEXT_APROBADA);
});

it('isolates handler exceptions and reports them in handler_errors', function (): void {
    $userId  = Seed::user();
    $orderId = Seed::order($userId);

    $failing  = new FakeHandler(supportsResult: true, throws: new RuntimeException('boom'));
    $surviving = new FakeHandler();

    $dispatcher = new PaymentEventDispatcher(
        new OrderRepository(),
        new StatusOrderRepository(),
        [$failing, $surviving],
    );

    $outcome = $dispatcher->dispatch(pgResult($orderId, PaymentStatus::APROBADA, PaymentStatus::TEXT_APROBADA));

    expect($outcome['processed'])->toBeTrue()
        ->and($surviving->handleCalls)->toBe(1)
        ->and($outcome['handler_errors'][0])->toContain('boom');
});
