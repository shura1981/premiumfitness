<?php

declare(strict_types=1);

namespace App\Features\Notification;

use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\HttpRequestException;
use PDO;
use Throwable;

/**
 * Envía las notificaciones pendientes de la outbox (spec integraciones/01, regla 3). Lo dispara el
 * cron de cPanel cada minuto; nunca corre dentro de la petición de un usuario.
 *
 *   - Un mensaje por dispositivo del usuario.
 *   - Si falla, reintenta en las siguientes pasadas hasta `PUSH_MAX_ATTEMPTS`, con espera creciente.
 *   - Un token que FCM marca como inválido se borra.
 *   - Un usuario sin dispositivos no tiene a dónde recibirla: queda como enviada (la ve al abrir la app).
 */
class PushSender
{
    public function __construct(private readonly ?FcmPushChannel $channel = null) {}

    /** @return array{sent:int, failed:int, skipped:int, configured:bool} */
    public function sendPending(int $limit = 100): array
    {
        $channel = $this->channel ?? FcmPushChannel::fromEnv();
        $result  = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'configured' => $channel->isConfigured()];

        // Sin credenciales de Firebase no se toca la cola: se enviará cuando estén.
        if (!$result['configured']) {
            return $result;
        }

        $pdo         = DB::getInstance();
        $maxAttempts = max(1, (int) ($_ENV['PUSH_MAX_ATTEMPTS'] ?? 5));

        // Espera creciente: cada intento fallido aleja el siguiente (1, 2, 4, 8… minutos).
        $stmt = $pdo->prepare(
            "SELECT id, user_id, title, body, data, attempts FROM push_notifications
             WHERE status = 'pending' AND attempts < ?
               AND created_at <= DATE_SUB(NOW(), INTERVAL IF(attempts = 0, 0, POW(2, attempts - 1)) MINUTE)
             ORDER BY id LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([$maxAttempts]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $notification) {
            $outcome = $this->deliver($pdo, $channel, $notification, $maxAttempts);
            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $notification
     * @return 'sent'|'failed'|'skipped'
     */
    private function deliver(PDO $pdo, FcmPushChannel $channel, array $notification, int $maxAttempts): string
    {
        // Se toma la fila antes de enviar: dos pasadas del cron no envían la misma notificación.
        $claim = $pdo->prepare("UPDATE push_notifications SET attempts = attempts + 1 WHERE id = ? AND status = 'pending' AND attempts = ?");
        $claim->execute([$notification['id'], $notification['attempts']]);

        if ($claim->rowCount() !== 1) {
            return 'skipped';
        }

        $devices = $pdo->prepare('SELECT id, token FROM push_devices WHERE user_id = ?');
        $devices->execute([$notification['user_id']]);
        $devices = $devices->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, mixed> $data */
        $data      = json_decode((string) ($notification['data'] ?? '{}'), true) ?: [];
        $delivered = $devices === [];
        $lastError = null;

        foreach ($devices as $device) {
            try {
                $channel->send((string) $device['token'], (string) $notification['title'], (string) $notification['body'], $data);
                $delivered = true;
            } catch (HttpRequestException $e) {
                $lastError = 'FCM ' . $e->statusCode();

                // El token ya no existe (app desinstalada o permiso retirado): se borra el dispositivo.
                if (in_array($e->statusCode(), [400, 404], true) && preg_match('/UNREGISTERED|INVALID_ARGUMENT/', (string) $e->errorPayload()) === 1) {
                    $pdo->prepare('DELETE FROM push_devices WHERE id = ?')->execute([$device['id']]);
                }
            } catch (Throwable $e) {
                $lastError = mb_substr($e::class, 0, 200);
            }
        }

        if ($delivered) {
            $pdo->prepare("UPDATE push_notifications SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")->execute([$notification['id']]);

            return 'sent';
        }

        $exhausted = (int) $notification['attempts'] + 1 >= $maxAttempts;
        $pdo->prepare('UPDATE push_notifications SET status = ?, last_error = ? WHERE id = ?')
            ->execute([$exhausted ? 'failed' : 'pending', $lastError, $notification['id']]);

        return 'failed';
    }
}
