<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events;

use App\Features\PaymentGateway\Events\Handlers\PaymentEventHandlerInterface;
use Throwable;

/**
 * Ejecuta una lista ordenada de handlers para un PaymentEvent.
 *
 * Se comparte entre webhook y cron-job para mantener la misma estrategia de
 * eventos sin acoplar el cron a la regla de idempotencia del dispatcher.
 */
final readonly class PaymentEventHandlerRunner
{
    /** @param list<PaymentEventHandlerInterface> $handlers */
    public function __construct(
        private array $handlers,
    ) {}

    /** @return list<string> */
    public function run(PaymentEvent $event): array
    {
        $errors = [];

        foreach ($this->handlers as $handler) {
            if (!$handler->supports($event)) {
                continue;
            }

            try {
                $handler->handle($event);
            } catch (Throwable $e) {
                $errors[] = $handler::class . ': ' . $e->getMessage();
            }
        }

        return $errors;
    }
}
