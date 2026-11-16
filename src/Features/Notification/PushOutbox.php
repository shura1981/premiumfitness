<?php

declare(strict_types=1);

namespace App\Features\Notification;

use App\Infrastructure\Database\DB;

/**
 * Outbox de notificaciones push (spec integraciones/01). Quien genera un aviso lo deja aquí, en la
 * misma transacción de la operación; el cron lo envía después con FCM. Nunca se envía dentro de la
 * petición del usuario. `source_key` es única: el mismo aviso no se encola dos veces.
 */
final class PushOutbox
{
    /** @param array<string, scalar|null> $data Ruta de la app y el id relacionado. */
    public static function enqueue(int $userId, string $type, string $title, string $body, array $data, string $sourceKey): void
    {
        DB::getInstance()->prepare(
            "INSERT IGNORE INTO push_notifications (user_id, app, type, title, body, data, source_key)
             VALUES (?, 'web', ?, ?, ?, ?, ?)"
        )->execute([
            $userId,
            $type,
            mb_substr($title, 0, 100),
            mb_substr($body, 0, 255),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $sourceKey,
        ]);
    }
}
