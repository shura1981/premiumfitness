<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

/**
 * Canal de SMS para desarrollo local: escribe el mensaje en un archivo en vez de enviarlo.
 * `SmsChannelFactory` no lo entrega en producción.
 */
final readonly class LogSmsChannel implements NotificationChannelInterface
{
    public function __construct(private string $file) {}

    public function send(string $recipient, string $subject, string $message): void
    {
        $directory = dirname($this->file);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $this->file,
            sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $recipient, $message),
            FILE_APPEND | LOCK_EX,
        );
    }
}
