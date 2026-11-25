<?php

declare(strict_types=1);

namespace App\Features\Adjustment;

use App\Features\Audit\AuditLog;
use App\Features\Commission\CommissionMovementRepository;
use App\Features\Notification\PushOutbox;
use App\Features\Order\StatusOrderRepository;
use App\Features\Point\PointLotRepository;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;
use App\Shared\Exceptions\ApiException;
use PDO;
use Throwable;
use App\Features\Order\OrderRealtime;

/**
 * Ajustes por cancelación o devolución de un pedido aprobado (RF-17; PRD §7.4).
 *
 *   - Compra de un cliente: se revierte al entrenador la comisión y los puntos de ese pedido, total
 *     o en proporción a lo devuelto, sin exceder lo que el pedido le dio.
 *       · La comisión se descuenta con un movimiento de ajuste; si el mes ya se liquidó, queda
 *         negativa y se descuenta del siguiente (spec negocio/04, regla 8).
 *       · De los puntos solo se retira lo que quede en el lote de ese pedido. Lo ya usado no genera
 *         deuda: esa política está pendiente (PRD §7.4).
 *   - Canje cancelado: se devuelven los puntos en un lote nuevo.
 *   - Un pedido admite un solo ajuste. El ajuste nunca borra ni cambia los movimientos originales.
 */
class AdjustmentService
{
    public const array TYPES = ['cancellation', 'full_return', 'partial_return'];

    /**
     * Estado de un pedido aprobado o de un canje que un gerente cancela (`cancellation`). No es `CANCELADA`:
     * ese estado lo mandan las pasarelas para un pago que nunca se aprobó, sin dinero ni puntos de por medio.
     * `REVERSADA` ya está en `status_orders` (id 5) y ninguna de las tres pasarelas lo manda.
     */
    public const string STATUS_REVERTED = 'REVERSADA';

    /** Estado de un pedido con devolución total. La parcial deja el pedido «APROBADA». */
    public const string STATUS_RETURNED = 'DEVOLUCIÓN';

    private const array GATEWAY_NAMES = ['bold' => 'Bold', 'addi' => 'Addi', 'sistecredito' => 'Sistecrédito'];

    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::getInstance();
    }

    /** @return list<array<string, mixed>> Contrato `Adjustment[]`. */
    public function listFor(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.type, a.reason, a.created_at, a.agent_id, o.payment_gateway, e.usuario AS agent_name,
                    (SELECT COALESCE(SUM(m.amount), 0) FROM commission_movements m WHERE m.adjustment_id = a.id) AS commission_amount,
                    (SELECT COALESCE(SUM(pm.points), 0) FROM point_movements pm WHERE pm.adjustment_id = a.id) AS points
             FROM adjustments a
             JOIN orders o ON o.order_id = a.order_id
             LEFT JOIN ' . IntranetTables::fromEnv()->employees . ' e ON e.id_usuario = a.agent_id
             WHERE a.order_id = ? ORDER BY a.id'
        );
        $stmt->execute([$orderId]);
        $trainerName = $this->order($orderId)['trainer_name'];

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'type'       => (string) $row['type'],
            'reason'     => (string) $row['reason'],
            // Sin agente: lo registró la pasarela con su webhook (Bold anuló el pago).
            'agent_name' => $row['agent_id'] === null
                ? 'Automático (' . (self::GATEWAY_NAMES[(string) $row['payment_gateway']] ?? (string) $row['payment_gateway']) . ')'
                : (string) ($row['agent_name'] ?? ''),
            'created_at' => date(DATE_ATOM, (int) strtotime((string) $row['created_at'])),
            'effect'     => ['commission_amount' => (float) $row['commission_amount'], 'points' => (int) $row['points'], 'trainer_name' => $trainerName],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Lo que revertiría un ajuste, sin aplicarlo. Los valores van en negativo cuando se le quitan
     * al entrenador y en positivo cuando se le devuelven (canje cancelado).
     *
     * @return array{commission_amount:float, points:int, trainer_name:?string}
     * @throws ApiException
     */
    public function preview(int $orderId, string $type, mixed $items = null): array
    {
        $order = $this->order($orderId);

        return ['trainer_name' => $order['trainer_name']] + $this->effect($order, $type, $items);
    }

    /**
     * @param int|null $agentId Agente que lo registra; null si lo registra la pasarela (`byGateway`).
     * @return int Id del ajuste.
     * @throws ApiException
     */
    public function create(int $orderId, ?int $agentId, string $type, string $reason, mixed $items = null, ?string $gateway = null): int
    {
        $this->pdo->beginTransaction();

        try {
            // El pedido bloqueado: dos agentes no ajustan el mismo pedido a la vez.
            $this->pdo->prepare('SELECT order_id FROM orders WHERE order_id = ? FOR UPDATE')->execute([$orderId]);
            $order = $this->order($orderId);

            $existing = $this->pdo->prepare('SELECT 1 FROM adjustments WHERE order_id = ?');
            $existing->execute([$orderId]);

            if ($existing->fetchColumn() !== false) {
                throw ApiException::conflict('already_adjusted', 'Este pedido ya tiene un ajuste registrado.');
            }

            $effect = $this->effect($order, $type, $items);

            $this->pdo->prepare('INSERT INTO adjustments (order_id, agent_id, type, reason) VALUES (?, ?, ?, ?)')
                ->execute([$orderId, $agentId, $order['order_type'] === 'redemption' ? 'restitution' : $type, $reason]);
            $adjustmentId = (int) $this->pdo->lastInsertId();
            $trainerId    = $order['trainer_id'];

            if ($trainerId !== null) {
                $this->pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE')->execute([$trainerId]);

                if ($effect['commission_amount'] < 0) {
                    new CommissionMovementRepository()->record($trainerId, 'adjustment', $effect['commission_amount'], "adjustment:{$adjustmentId}:commission", [
                        'order_id' => $orderId, 'adjustment_id' => $adjustmentId,
                    ]);
                }

                if ($effect['points'] < 0 && $order['lot_id'] !== null) {
                    $this->retirePoints($order['lot_id'], -$effect['points'], $orderId, $adjustmentId);
                }

                if ($effect['points'] > 0) {
                    new PointLotRepository()->credit($trainerId, 'restitution', $effect['points'], BusinessConfig::fromEnv()->pointsExpirationMonths, "adjustment:{$adjustmentId}:restitution", [
                        'order_id' => $orderId, 'adjustment_id' => $adjustmentId,
                    ]);
                }

                PushOutbox::enqueue(
                    $trainerId,
                    'order_adjusted',
                    $order['order_type'] === 'redemption' ? 'Te devolvimos tus puntos' : 'Ajuste por una devolución',
                    $order['order_type'] === 'redemption'
                        ? sprintf('Cancelamos tu canje #%d y te devolvimos %s puntos.', $orderId, number_format($effect['points'], 0, ',', '.'))
                        : sprintf('El pedido #%d de un cliente tuvo una devolución: ajustamos tu comisión y tus puntos.', $orderId),
                    ['route' => $order['order_type'] === 'redemption' ? '/coach/puntos' : '/coach/comision', 'order_id' => $orderId],
                    "adjustment:{$adjustmentId}",
                );
            }

            if ($type !== 'partial_return') {
                $status = $type === 'cancellation' ? self::STATUS_REVERTED : self::STATUS_RETURNED;
                $this->pdo->prepare('UPDATE orders SET status_id = ? WHERE order_id = ?')
                    ->execute([new StatusOrderRepository()->findOrCreateIdByValue($status), $orderId]);
            }

            $audit = ['adjustment_id' => $adjustmentId, 'type' => $type, 'reason' => $reason, 'items' => $items, 'effect' => $effect];
            $agentId !== null
                ? AuditLog::byAgent($agentId, 'order.adjustment', 'order', $orderId, $audit)
                : AuditLog::byGateway((string) $gateway, 'order.adjustment', 'order', $orderId, $audit);

            $this->pdo->commit();
            // Cancelación o devolución: el dueño y el admin ven el cambio sin recargar (spec integraciones/03).
            (new OrderRealtime())->statusChanged($orderId);

            return $adjustmentId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array{commission_amount:float, points:int}
     * @throws ApiException
     */
    private function effect(array $order, string $type, mixed $items): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: ['type' => 'Elige el tipo de ajuste.']);
        }

        if ($order['approved_at'] === null) {
            throw ApiException::conflict('order_not_approved', 'Solo se ajustan pedidos con el pago aprobado.');
        }

        if ($order['order_type'] === 'redemption') {
            if ($type === 'partial_return') {
                throw ApiException::invalid('Un canje se cancela completo.', errors: ['type' => 'Un canje no admite devolución parcial.']);
            }

            return ['commission_amount' => 0.0, 'points' => (int) $order['redemption_points']];
        }

        // Compra del entrenador o de un cliente sin entrenador: no hay recompensas que revertir.
        if ($order['trainer_id'] === null) {
            return ['commission_amount' => 0.0, 'points' => 0];
        }

        $share      = $type === 'partial_return' ? $this->returnedShare($order, $items) : ['commission' => (float) $order['commission_total'], 'ratio' => 1.0];
        $points     = (int) round((int) $order['reward_points'] * $share['ratio']);
        // Solo lo que quede en el lote de este pedido: lo ya usado no genera deuda.
        $retirable  = min($points, (int) ($order['lot_remaining'] ?? 0));

        return ['commission_amount' => -round($share['commission'], 2) + 0.0, 'points' => -$retirable];
    }

    /**
     * Parte del pedido que se devuelve: la comisión de las unidades devueltas y su peso sobre lo
     * pagado por los productos (para los puntos).
     *
     * @param array<string, mixed> $order
     * @return array{commission:float, ratio:float}
     * @throws ApiException
     */
    private function returnedShare(array $order, mixed $items): array
    {
        if (!is_array($items) || $items === []) {
            throw ApiException::invalid('Indica qué productos se devuelven.', errors: ['items' => 'Elige al menos un producto.']);
        }

        $lines = $this->pdo->prepare('SELECT item_id, quantity, line_total, commission_amount FROM orders_items WHERE order_id = ?');
        $lines->execute([$order['order_id']]);
        $byId  = array_column($lines->fetchAll(PDO::FETCH_ASSOC), null, 'item_id');
        $paid  = array_sum(array_map(static fn (array $line): float => (float) $line['line_total'], $byId));

        $commission = 0.0;
        $returned   = 0.0;
        $seen       = [];

        foreach ($items as $item) {
            $itemId   = is_array($item) ? filter_var($item['item_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $quantity = is_array($item) ? filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT) : false;
            $line     = $itemId === false ? null : ($byId[$itemId] ?? null);

            if ($line === null || isset($seen[$itemId]) || $quantity === false || $quantity < 1 || $quantity > (int) $line['quantity']) {
                throw ApiException::invalid('Revisa los productos de la devolución.', errors: ['items' => 'La cantidad devuelta no puede superar la comprada.']);
            }

            $seen[$itemId] = true;
            $fraction      = $quantity / (int) $line['quantity'];
            $commission   += (float) ($line['commission_amount'] ?? 0) * $fraction;
            $returned     += (float) $line['line_total'] * $fraction;
        }

        return ['commission' => $commission, 'ratio' => $paid > 0 ? $returned / $paid : 0.0];
    }

    private function retirePoints(int $lotId, int $points, int $orderId, int $adjustmentId): void
    {
        $stmt = $this->pdo->prepare("SELECT remaining_points FROM point_lots WHERE id = ? AND status = 'available' FOR UPDATE");
        $stmt->execute([$lotId]);
        $remaining = $stmt->fetchColumn();
        $taken     = $remaining === false ? 0 : min($points, (int) $remaining);

        if ($taken <= 0) {
            return;
        }

        $left = (int) $remaining - $taken;
        $this->pdo->prepare("UPDATE point_lots SET remaining_points = ?, status = IF(? = 0, 'used', status) WHERE id = ?")->execute([$left, $left, $lotId]);
        $this->pdo->prepare(
            "INSERT INTO point_movements (point_lot_id, order_id, adjustment_id, type, points, source_key) VALUES (?, ?, ?, 'adjustment', ?, ?)"
        )->execute([$lotId, $orderId, $adjustmentId, -$taken, "adjustment:{$adjustmentId}:points"]);
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException 404
     */
    private function order(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.order_id, o.user_id, o.order_type, o.approved_at, o.reward_points, o.redemption_points,
                    oc.trainer_id AS coupon_trainer_id, t.full_name AS coupon_trainer_name, b.full_name AS buyer_name,
                    l.id AS lot_id, IF(l.status = 'available' AND l.expires_at > NOW(), l.remaining_points, 0) AS lot_remaining,
                    (SELECT COALESCE(SUM(i.commission_amount), 0) FROM orders_items i WHERE i.order_id = o.order_id) AS commission_total
             FROM orders o
             JOIN users b ON b.id = o.user_id
             LEFT JOIN orders_coupons oc ON oc.order_id = o.order_id
             LEFT JOIN users t ON t.id = oc.trainer_id
             LEFT JOIN point_lots l ON l.order_id = o.order_id AND l.source = 'client_sale'
             WHERE o.order_id = ?"
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order === false) {
            throw ApiException::notFound('No encontramos ese pedido.');
        }

        // En un canje, el entrenador afectado es quien canjeó.
        $isRedemption = $order['order_type'] === 'redemption';

        return [
            'trainer_id'   => $isRedemption ? (int) $order['user_id'] : ($order['coupon_trainer_id'] === null ? null : (int) $order['coupon_trainer_id']),
            'trainer_name' => $isRedemption ? (string) $order['buyer_name'] : $order['coupon_trainer_name'],
            'lot_id'       => $order['lot_id'] === null ? null : (int) $order['lot_id'],
        ] + $order;
    }
}
