<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

/**
 * Contrato para canales de envío de notificaciones (email, SMS, WhatsApp).
 */
interface NotificationChannelInterface
{
    /**
     * Envía un mensaje al destinatario.
     *
     * @param string $recipient Dirección del destinatario (email, teléfono, etc.)
     * @param string $subject   Asunto o título del mensaje
     * @param string $message   Contenido del mensaje
     */
    public function send(string $recipient, string $subject, string $message): void;
}
