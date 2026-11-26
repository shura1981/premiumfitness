<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events\Handlers;

use App\Features\PaymentGateway\Events\PaymentEvent;
use App\Infrastructure\Notification\RealtimeGateway;

/**
 * Hace broadcast al panel admin tras cualquier cambio de estado de pago.
 *
 * Emite `order.status.changed` para TODOS los estados (aprobada, rechazada,
 * pendiente, error, etc.) con el payload completo necesario para que el admin
 * pueda insertar o actualizar la fila sin consultar el backend.
 */
final class BroadcastAdminOrderStatusHandler implements PaymentEventHandlerInterface
{
    public function __construct(
        private readonly RealtimeGateway $socket,
    ) {}

    public function supports(PaymentEvent $event): bool
    {
        return true;
    }

    public function handle(PaymentEvent $event): void
    {
        $this->socket->broadcastToAdmin([
            [
                'event'   => 'order.status.changed',
                'payload' => [
                    'order_id'    => $event->order->order_id,
                    'status_id'   => $event->result->codStatus,
                    'status_value' => $event->result->statusText,
                ],
            ],
        ]);
    }
}
