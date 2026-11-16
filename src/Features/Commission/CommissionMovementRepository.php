<?php

declare(strict_types=1);

namespace App\Features\Commission;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

/**
 * Movimientos de comisión del entrenador (spec negocio/04): abonos por venta y débitos por
 * conversión, ajuste o liquidación. El saldo es la suma; nunca se recalcula desde los pedidos.
 */
class CommissionMovementRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /**
     * Registra un movimiento. `source_key` es única: repetir el mismo origen no hace nada y
     * devuelve false. Va dentro de la transacción de quien llama.
     *
     * @param array{order_id?:?int, conversion_id?:?int, adjustment_id?:?int, settlement_id?:?int, tax_base?:?float} $links
     */
    public function record(int $trainerId, string $type, float $amount, string $sourceKey, array $links = []): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO commission_movements
                (order_id, trainer_id, conversion_id, adjustment_id, settlement_id, type, tax_base, amount, source_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $links['order_id'] ?? null,
            $trainerId,
            $links['conversion_id'] ?? null,
            $links['adjustment_id'] ?? null,
            $links['settlement_id'] ?? null,
            $type,
            $links['tax_base'] ?? null,
            round($amount, 2),
            $sourceKey,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Comisión sin liquidar ni convertir. */
    public function balance(int $trainerId): float
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM commission_movements WHERE trainer_id = ?');
        $stmt->execute([$trainerId]);

        return (float) $stmt->fetchColumn();
    }
}
