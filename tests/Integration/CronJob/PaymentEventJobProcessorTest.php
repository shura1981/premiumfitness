<?php

declare(strict_types=1);

use App\Features\CronJob\PaymentEventJobProcessor;
use App\Features\CronJob\PaymentEventJobRepository;
use App\Features\Order\OrderRepository;
use App\Features\PaymentGateway\Events\PaymentEventHandlerRunner;
use Tests\Support\FakeHandler;
use Tests\Support\Seed;

it('enqueues only APROBADA orders and processes them through payment event handlers', function (): void {
    $userId  = Seed::user();
    $orderId = Seed::approvedOrderWithCanonicalApprobada($userId);

    $jobs  = new PaymentEventJobRepository();
    $jobId = $jobs->enqueue($orderId);

    expect($jobId)->toBeGreaterThan(0);

    // Otras pruebas aprueban pedidos y dejan trabajos en cola: este solo procesa el suyo.
    Seed::pdo()->prepare('DELETE FROM payment_event_jobs WHERE processed_at IS NULL AND job_id <> ?')->execute([$jobId]);

    $handler = new FakeHandler();
    $processor = new PaymentEventJobProcessor(
        $jobs,
        new OrderRepository(),
        new PaymentEventHandlerRunner([$handler]),
    );

    $result = $processor->processPending(10);

    expect($result['processed'])->toBeGreaterThanOrEqual(1)
        ->and($handler->handleCalls)->toBeGreaterThanOrEqual(1);

    $job = $jobs->findById($jobId);
    expect($job)->not->toBeNull()
        ->and($job->processed_at)->not->toBeNull();
});

it('does not enqueue non-approved orders', function (): void {
    $userId  = Seed::user();
    $orderId = Seed::order($userId);

    $jobId = (new PaymentEventJobRepository())->enqueue($orderId);

    expect($jobId)->toBe(0);
});
