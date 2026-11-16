<?php

declare(strict_types=1);

namespace App\Features\Point;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

/**
 * Lotes de puntos del entrenador (spec negocio/03). Cada abono es un lote con su fecha de
 * vencimiento; el saldo es la suma de lo que queda en los lotes disponibles y sin vencer.
 */
class PointLotRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /**
     * Abona puntos: crea el lote y su movimiento de crédito. `source_key` es única: si el origen
     * ya se había abonado no hace nada y devuelve null. Va dentro de la transacción de quien llama.
     *
     * @param array{order_id?:?int, conversion_id?:?int, adjustment_id?:?int, trainer_referral_id?:?int} $links
     */
    public function credit(int $trainerId, string $source, int $points, int $expirationMonths, string $sourceKey, array $links = []): ?int
    {
        if ($points <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO point_lots
                (order_id, trainer_id, conversion_id, adjustment_id, trainer_referral_id, source,
                 initial_points, remaining_points, accrued_at, expires_at, source_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?)'
        );
        $stmt->execute([
            $links['order_id'] ?? null,
            $trainerId,
            $links['conversion_id'] ?? null,
            $links['adjustment_id'] ?? null,
            $links['trainer_referral_id'] ?? null,
            $source,
            $points,
            $points,
            $expirationMonths,
            $sourceKey,
        ]);

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        $lotId = (int) $this->pdo->lastInsertId();

        $this->execute(
            "INSERT INTO point_movements (point_lot_id, order_id, adjustment_id, type, points, source_key)
             VALUES (?, ?, ?, 'credit', ?, ?)",
            [$lotId, $links['order_id'] ?? null, $links['adjustment_id'] ?? null, $points, $sourceKey . ':credit']
        );

        return $lotId;
    }

    /** Puntos que el entrenador puede usar hoy: lotes disponibles y sin vencer. */
    public function availablePoints(int $trainerId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(remaining_points), 0) FROM point_lots
             WHERE trainer_id = ? AND status = 'available' AND expires_at > NOW()"
        );
        $stmt->execute([$trainerId]);

        return (int) $stmt->fetchColumn();
    }
}
