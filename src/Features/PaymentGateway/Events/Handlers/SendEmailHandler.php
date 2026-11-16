<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events\Handlers;

use App\Features\PaymentGateway\Events\PaymentEvent;
use App\Features\User\UserRepository;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Mail\MailMessage;

/**
 * Notifica al cliente vía email cuando su pago fue APROBADA.
 *
 * Renderiza la plantilla `views/emails/order-payment-approved.html`. Los
 * placeholders `{{subject}}` y `{{year}}` los inyecta automáticamente
 * `Mailer::send()`.
 */
final class SendEmailHandler implements PaymentEventHandlerInterface
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly UserRepository $users,
    ) {}

    public function supports(PaymentEvent $event): bool
    {
        return $event->result->isApproved();
    }

    public function handle(PaymentEvent $event): void
    {
        $user = $this->users->findById($event->order->user_id);
        if ($user === false || trim($user->email) === '') {
            return;
        }

        $subject = sprintf('Tu compra #%d fue aprobada', $event->order->order_id);

        $message = MailMessage::to($user->email, $user->full_name)
            ->subject($subject)
            ->htmlTemplate('order-payment-approved', [
                'customer_name'  => $user->full_name,
                'order_id'       => (string) $event->order->order_id,
                'amount'         => '$' . number_format($event->result->amount, 0, ',', '.'),
                'gateway'        => strtoupper($event->result->gateway),
                'transaction_id' => $event->result->transactionId,
                'date'           => date('Y-m-d H:i:s'),
            ]);

        $this->mailer->send($message);
    }
}
