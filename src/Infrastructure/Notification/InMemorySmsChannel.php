<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

/**
 * Canal de SMS para las pruebas: guarda los mensajes en memoria para poder leerlos.
 */
final class InMemorySmsChannel implements NotificationChannelInterface
{
    /** @var list<array{recipient: string, message: string}> */
    private static array $sent = [];

    public function send(string $recipient, string $subject, string $message): void
    {
        self::$sent[] = ['recipient' => $recipient, 'message' => $message];
    }

    /** Último mensaje enviado a un celular, o null. */
    public static function lastTo(string $recipient): ?string
    {
        foreach (array_reverse(self::$sent) as $sms) {
            if ($sms['recipient'] === $recipient) {
                return $sms['message'];
            }
        }

        return null;
    }

    public static function countTo(string $recipient): int
    {
        return count(array_filter(self::$sent, static fn (array $sms): bool => $sms['recipient'] === $recipient));
    }

    public static function reset(): void
    {
        self::$sent = [];
    }
}
