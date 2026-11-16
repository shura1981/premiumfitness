<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Mail\MailMessage;

/**
 * Canal de notificación por correo electrónico.
 *
 * Usa el Mailer existente con templates HTML.
 */
class EmailNotificationChannel implements NotificationChannelInterface
{
    private readonly Mailer $mailer;

    public function __construct()
    {
        $this->mailer = Mailer::fromEnv();
    }

    public function send(string $recipient, string $subject, string $message): void
    {
        $mailMessage = MailMessage::to($recipient)
            ->subject($subject)
            ->htmlTemplate('verification-code', [
                'code'    => $message,
                'minutes' => '5',
            ]);

        $this->mailer->send($mailMessage);
    }
}
