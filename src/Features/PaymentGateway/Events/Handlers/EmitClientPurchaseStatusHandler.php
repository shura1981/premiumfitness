<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events\Handlers;

use App\Features\PaymentGateway\Events\PaymentEvent;
use App\Features\User\UserRepository;
use App\Infrastructure\Notification\SocketGatewayClient;

/**
 * Emite `purchase.status.changed` al socket del cliente final tras cualquier
 * actualización de pago (aprobada, rechazada, pendiente, fallida, etc.).
 *
 * No emite si el usuario no tiene `socket_token` registrado: Socket.IO lo
 * descartaría igualmente, pero evitamos el round-trip HTTP innecesario.
 */
final class EmitClientPurchaseStatusHandler implements PaymentEventHandlerInterface
{
    public function __construct(
        private readonly SocketGatewayClient $socket,
        private readonly UserRepository $users,
    ) {}

    public function supports(PaymentEvent $event): bool
    {
        return true;
    }

    public function handle(PaymentEvent $event): void
    {
        $socketToken = $this->users->findSocketTokenById($event->order->user_id);
        if ($socketToken === null || trim($socketToken) === '') {
            return;
        }

        $this->socket->emitToSocket($socketToken, [
            [
                'event'   => 'purchase.status.changed',
                'payload' => [
                    'order_id'       => $event->order->order_id,
                    'user_id'        => $event->order->user_id,
                    'status'         => $event->result->statusText,
                    'status_id'      => $event->result->codStatus,
                    'gateway'        => $event->result->gateway,
                    'transaction_id' => $event->result->transactionId,
                    'amount'         => number_format($event->result->amount, 2, '.', ''),
                    'occurred_at'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
        ]);
    }
}
