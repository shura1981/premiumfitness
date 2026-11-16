<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use RuntimeException;

/**
 * Elige el canal de SMS según `SMS_DRIVER`:
 *   - `relax` (por defecto): envío real con Relax Global.
 *   - `log`: desarrollo; escribe el mensaje en `logs/sms-dev.log` en vez de enviarlo.
 *   - `memory`: pruebas; guarda los mensajes en memoria.
 * En producción solo se admite `relax`: un código nunca se escribe en un log.
 */
final class SmsChannelFactory
{
    public static function fromEnv(): NotificationChannelInterface
    {
        $driver = (string) ($_ENV['SMS_DRIVER'] ?? 'relax');

        if ($driver !== 'relax' && ($_ENV['APP_ENV'] ?? 'production') === 'production') {
            throw new RuntimeException('SMS_DRIVER must be "relax" in production.');
        }

        return match ($driver) {
            'relax'  => SmsNotificationChannel::fromEnv(),
            'log'    => new LogSmsChannel(__DIR__ . '/../../../logs/sms-dev.log'),
            'memory' => new InMemorySmsChannel(),
            default  => throw new RuntimeException("Unsupported SMS_DRIVER: {$driver}"),
        };
    }
}
