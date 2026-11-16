<?php

declare(strict_types=1);

namespace App\Features\Commission;

use App\Features\Notification\PushOutbox;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Cierre mensual de la comisión (regla 18; specs negocio/04, regla 7, y negocio/05).
 *
 *   - Entra lo creado hasta el último segundo del mes; una venta del día 1 es del mes nuevo.
 *   - Cada entrenador con saldo positivo recibe una liquidación y su saldo queda en cero.
 *   - Un saldo negativo o en cero no se liquida: pasa al mes siguiente.
 *   - El cierre **suma** los movimientos guardados; nunca recalcula desde los pedidos.
 *   - Un mes cerrado no cambia: cerrar dos veces responde 409 y no liquida nada dos veces.
 * Lo dispara el cron de cPanel, nunca un programador dentro de la app.
 */
class MonthlyCloseService
{
    private readonly CommissionCalendar $calendar;

    public function __construct()
    {
        $this->calendar = CommissionCalendar::fromEnv();
    }

    /**
     * @return array{period_id:int, period:string, trainers_count:int, orders_count:int, total_amount:float, due_date:string}
     * @throws ApiException 409 si el período ya está cerrado; 422 si el mes no ha terminado
     */
    public function close(string $period): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ApiException::invalid('El período debe tener el formato AAAA-MM.', errors: ['period' => 'Período no válido.']);
        }

        $end = CommissionCalendar::end($period);

        if ($end >= new DateTimeImmutable()) {
            throw ApiException::invalid('Ese mes todavía no termina.', errors: ['period' => 'El mes no ha terminado.']);
        }

        $dueDate = $this->calendar->dueDate($period)->format('Y-m-d');
        $cutoff  = $end->format('Y-m-d H:i:s');
        $pdo     = DB::getInstance();
        $pdo->beginTransaction();

        try {
            // `period` es único: dos cierres simultáneos no pasan de aquí los dos.
            $insert = $pdo->prepare('INSERT IGNORE INTO commission_periods (period, period_start, period_end) VALUES (?, ?, ?)');
            $insert->execute([$period, CommissionCalendar::start($period)->format('Y-m-d'), $end->format('Y-m-d')]);

            if ($insert->rowCount() !== 1) {
                throw ApiException::conflict('period_closed', 'Ese mes ya está cerrado.');
            }

            $periodId = (int) $pdo->lastInsertId();
            $totals   = ['trainers' => 0, 'orders' => 0, 'amount' => 0.0];

            foreach ($this->trainersWithUnsettled($pdo, $cutoff) as $trainerId) {
                $settled = $this->settle($pdo, $periodId, $period, $trainerId, $cutoff, $dueDate);

                if ($settled !== null) {
                    $totals['trainers']++;
                    $totals['orders'] += $settled['orders'];
                    $totals['amount'] += $settled['amount'];
                }
            }

            $pdo->prepare('UPDATE commission_periods SET trainers_count = ?, orders_count = ?, total_amount = ? WHERE id = ?')
                ->execute([$totals['trainers'], $totals['orders'], $totals['amount'], $periodId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return [
            'period_id'      => $periodId,
            'period'         => $period,
            'trainers_count' => $totals['trainers'],
            'orders_count'   => $totals['orders'],
            'total_amount'   => $totals['amount'],
            'due_date'       => $dueDate,
        ];
    }

    /** @return list<int> En orden de id: los bloqueos siempre se toman en el mismo orden. */
    private function trainersWithUnsettled(PDO $pdo, string $cutoff): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT trainer_id FROM commission_movements WHERE settlement_id IS NULL AND created_at <= ? ORDER BY trainer_id'
        );
        $stmt->execute([$cutoff]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array{orders:int, amount:float}|null null si el entrenador no tiene saldo positivo que liquidar.
     */
    private function settle(PDO $pdo, int $periodId, string $period, int $trainerId, string $cutoff, string $dueDate): ?array
    {
        $pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE')->execute([$trainerId]);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(IF(type = 'sale_credit', amount, 0)), 0) AS commission,
                    COALESCE(SUM(IF(type = 'conversion_debit', amount, 0)), 0) AS conversions,
                    COALESCE(SUM(IF(type = 'adjustment', amount, 0)), 0) AS adjustments,
                    COALESCE(SUM(amount), 0) AS total,
                    COUNT(DISTINCT IF(type = 'sale_credit', order_id, NULL)) AS orders
             FROM commission_movements
             WHERE trainer_id = ? AND settlement_id IS NULL AND created_at <= ?"
        );
        $stmt->execute([$trainerId, $cutoff]);
        /** @var array{commission:string, conversions:string, adjustments:string, total:string, orders:int|string} $sums */
        $sums  = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (float) $sums['total'];
        // Se paga al peso (spec negocio/05, S-02).
        $payable = round($total);

        if ($payable <= 0) {
            return null;
        }

        $pdo->prepare(
            "INSERT INTO commission_settlements
                (period_id, trainer_id, orders_count, commission_amount, conversions_amount, adjustments_amount, total_amount, due_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'closed')"
        )->execute([$periodId, $trainerId, (int) $sums['orders'], $sums['commission'], $sums['conversions'], $sums['adjustments'], $payable, $dueDate]);
        $settlementId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE commission_movements SET settlement_id = ? WHERE trainer_id = ? AND settlement_id IS NULL AND created_at <= ?')
            ->execute([$settlementId, $trainerId, $cutoff]);

        // El débito deja el saldo del mes cerrado en cero; lleva la fecha del corte para no mezclarse
        // con las ventas del mes nuevo.
        $pdo->prepare(
            "INSERT INTO commission_movements (trainer_id, settlement_id, type, amount, source_key, created_at)
             VALUES (?, ?, 'settlement_debit', ?, ?, ?)"
        )->execute([$trainerId, $settlementId, -$total, "settlement:{$settlementId}", $cutoff]);

        PushOutbox::enqueue(
            $trainerId,
            'commission_settled',
            'Liquidamos tu comisión',
            sprintf(
                'Tu comisión de %s es de $%s. Te la pagamos el %s.',
                explode(' ', CommissionCalendar::label($period))[0],
                number_format($payable, 0, ',', '.'),
                date('j/n', (int) strtotime($dueDate)),
            ),
            ['route' => '/coach/comision'],
            "settlement:{$settlementId}",
        );

        return ['orders' => (int) $sums['orders'], 'amount' => $payable];
    }
}
