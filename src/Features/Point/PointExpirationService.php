<?php

declare(strict_types=1);

namespace App\Features\Point;

use App\Features\Notification\PushOutbox;
use App\Infrastructure\Database\DB;
use PDO;
use Throwable;

/**
 * Proceso diario de los puntos (regla 16; spec negocio/03), disparado por el cron de cPanel:
 *   - marca como vencidos los lotes cuya fecha ya pasó y deja el movimiento de vencimiento;
 *   - avisa una vez al entrenador de los lotes que vencen en `PUSH_EXPIRY_NOTICE_DAYS` días.
 * Se puede repetir: cada lote vence y se avisa una sola vez (`source_key`).
 */
class PointExpirationService
{
    /** @return array{expired_lots:int, expired_points:int, notices:int} */
    public function run(): array
    {
        $pdo = DB::getInstance();

        // Usa ix_point_lots_expiration (status, expires_at): solo lee los disponibles ya vencidos.
        $due = $pdo->query(
            "SELECT id FROM point_lots WHERE status = 'available' AND expires_at <= NOW() ORDER BY id LIMIT 5000"
        )->fetchAll(PDO::FETCH_COLUMN);

        $expiredLots   = 0;
        $expiredPoints = 0;

        foreach ($due as $lotId) {
            $points = $this->expire($pdo, (int) $lotId);

            if ($points !== null) {
                $expiredLots++;
                $expiredPoints += $points;
            }
        }

        return ['expired_lots' => $expiredLots, 'expired_points' => $expiredPoints, 'notices' => $this->notifyExpiringSoon($pdo)];
    }

    /** @return int|null Puntos que vencieron, o null si otro proceso ya lo había hecho. */
    private function expire(PDO $pdo, int $lotId): ?int
    {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("SELECT remaining_points FROM point_lots WHERE id = ? AND status = 'available' AND expires_at <= NOW() FOR UPDATE");
            $stmt->execute([$lotId]);
            $remaining = $stmt->fetchColumn();

            if ($remaining === false) {
                $pdo->commit();

                return null;
            }

            $remaining = (int) $remaining;

            if ($remaining > 0) {
                $pdo->prepare("INSERT INTO point_movements (point_lot_id, type, points, source_key) VALUES (?, 'expiration', ?, ?)")
                    ->execute([$lotId, -$remaining, "expire:{$lotId}"]);
            }

            $pdo->prepare("UPDATE point_lots SET remaining_points = 0, status = 'expired' WHERE id = ?")->execute([$lotId]);
            $pdo->commit();

            return $remaining;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function notifyExpiringSoon(PDO $pdo): int
    {
        $days = max(1, (int) ($_ENV['PUSH_EXPIRY_NOTICE_DAYS'] ?? 7));
        $stmt = $pdo->prepare(
            "SELECT id, trainer_id, remaining_points, expires_at FROM point_lots
             WHERE status = 'available' AND remaining_points > 0
               AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
             LIMIT 5000"
        );
        $stmt->execute([$days]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM push_notifications WHERE source_key = ?');
        $sent  = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lot) {
            $key = "expiry-notice:{$lot['id']}";
            $count->execute([$key]);

            if ((int) $count->fetchColumn() > 0) {
                continue;
            }

            PushOutbox::enqueue(
                (int) $lot['trainer_id'],
                'points_expiring',
                'Tienes puntos por vencer',
                sprintf(
                    '%s puntos vencen el %s. Úsalos antes.',
                    number_format((int) $lot['remaining_points'], 0, ',', '.'),
                    date('j/n', (int) strtotime((string) $lot['expires_at'])),
                ),
                ['route' => '/coach/puntos'],
                $key,
            );
            $sent++;
        }

        return $sent;
    }
}
