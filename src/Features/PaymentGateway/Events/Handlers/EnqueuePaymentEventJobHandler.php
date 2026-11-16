<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events\Handlers;

use App\Features\CronJob\PaymentEventJobRepository;
use App\Features\PaymentGateway\Events\PaymentEvent;

/**
 * Encola el procesamiento diferido post-pago cuando el webhook confirma
 * APROBADA. El cron-job consumirá esta cola para SMS/email.
 */
final class EnqueuePaymentEventJobHandler implements PaymentEventHandlerInterface
{
    public function __construct(
        private readonly PaymentEventJobRepository $jobs,
    ) {}

    public function supports(PaymentEvent $event): bool
    {
        return $event->result->isApproved();
    }

    public function handle(PaymentEvent $event): void
    {
        $this->jobs->enqueue($event->order->order_id);
    }
}
