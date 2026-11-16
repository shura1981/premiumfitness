<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events\Handlers;

use App\Features\PaymentGateway\Events\PaymentEvent;
use App\Features\User\UserRepository;
use App\Infrastructure\Notification\NotificationChannelInterface;

/**
 * Notifica al cliente vía SMS cuando su pago fue APROBADA.
 *
 * El canal es inyectado (interfaz `NotificationChannelInterface`), así que en
 * tests se puede sustituir por un fake sin tocar este handler.
 */
final class SendSmsHandler implements PaymentEventHandlerInterface
{
    public function __construct(
        private readonly NotificationChannelInterface $channel,
        private readonly UserRepository $users,
    ) {}

    public function supports(PaymentEvent $event): bool
    {
        return $event->result->isApproved();
    }

    public function handle(PaymentEvent $event): void
    {
        $user = $this->users->findById($event->order->user_id);
        if ($user === false || $user->num_cell === null || trim($user->num_cell) === '') {
            return;
        }

        $message = sprintf(
            'Tu compra #%d por $%s fue aprobada. Gracias por confiar en PremiumFitness.',
            $event->order->order_id,
            number_format($event->result->amount, 0, ',', '.'),
        );

        $this->channel->send($user->num_cell, 'Compra aprobada', $message);
    }
}
