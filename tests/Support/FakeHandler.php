<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Features\PaymentGateway\Events\Handlers\PaymentEventHandlerInterface;
use App\Features\PaymentGateway\Events\PaymentEvent;
use Throwable;

/**
 * Handler de prueba: cuenta invocaciones, opcionalmente lanza una excepción
 * dada para validar el aislamiento de errores en `PaymentEventDispatcher`.
 */
final class FakeHandler implements PaymentEventHandlerInterface
{
    public int $supportsCalls = 0;
    public int $handleCalls   = 0;
    /** @var list<PaymentEvent> */
    public array $receivedEvents = [];

    public function __construct(
        private readonly bool $supportsResult = true,
        private readonly ?Throwable $throws = null,
    ) {}

    public function supports(PaymentEvent $event): bool
    {
        $this->supportsCalls++;

        return $this->supportsResult;
    }

    public function handle(PaymentEvent $event): void
    {
        $this->handleCalls++;
        $this->receivedEvents[] = $event;

        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
