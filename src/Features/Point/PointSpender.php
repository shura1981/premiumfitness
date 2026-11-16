<?php

declare(strict_types=1);

namespace App\Features\Point;

use App\Infrastructure\Database\DB;
use PDO;

/**
 * Consumo de puntos por FIFO (PRD §7.3): primero el lote más antiguo, con desempate por id.
 * Solo gasta lotes disponibles y sin vencer. Debe llamarse dentro de una transacción, con la
 * fila del entrenador ya bloqueada.
 */
final class PointSpender
{
    /**
     * @param 'redemption'|'adjustment' $type
     * @param string $sourceKey Clave del origen; cada lote consumido deja un movimiento `<clave>:lot:<id>`.
     * @param array{order_id?:?int, adjustment_id?:?int, diet_access_period_id?:?int} $links
     * @throws NotEnoughPointsException sin cambiar nada
     */
    public static function spend(int $trainerId, int $points, string $type, string $sourceKey, array $links = []): void
    {
        if ($points <= 0) {
            return;
        }

        $pdo  = DB::getInstance();
        $lots = $pdo->prepare(
            "SELECT id, remaining_points FROM point_lots
             WHERE trainer_id = ? AND status = 'available' AND expires_at > NOW() AND remaining_points > 0
             ORDER BY accrued_at, id
             FOR UPDATE"
        );
        $lots->execute([$trainerId]);
        /** @var list<array{id:int|string, remaining_points:int|string}> $available */
        $available = $lots->fetchAll(PDO::FETCH_ASSOC);

        if (array_sum(array_map(static fn (array $lot): int => (int) $lot['remaining_points'], $available)) < $points) {
            throw new NotEnoughPointsException('Not enough points.');
        }

        $update   = $pdo->prepare("UPDATE point_lots SET remaining_points = ?, status = IF(? = 0, 'used', status) WHERE id = ?");
        $movement = $pdo->prepare(
            'INSERT INTO point_movements (point_lot_id, order_id, adjustment_id, diet_access_period_id, type, points, source_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $pending = $points;

        foreach ($available as $lot) {
            if ($pending === 0) {
                break;
            }

            $taken = min($pending, (int) $lot['remaining_points']);
            $left  = (int) $lot['remaining_points'] - $taken;

            $update->execute([$left, $left, $lot['id']]);
            $movement->execute([
                $lot['id'],
                $links['order_id'] ?? null,
                $links['adjustment_id'] ?? null,
                $links['diet_access_period_id'] ?? null,
                $type,
                -$taken,
                "{$sourceKey}:lot:{$lot['id']}",
            ]);

            $pending -= $taken;
        }
    }
}
