<?php

declare(strict_types=1);

namespace App\Features\Adjustment;

use App\Features\Audit\AuditLog;
use App\Features\Commission\CommissionMovementRepository;
use App\Features\Notification\PushOutbox;
use App\Features\Order\StatusOrderRepository;
use App\Features\Point\PointLotRepository;
use App\Features\Point\PointSpender;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;
use App\Shared\Exceptions\ApiException;
use PDO;
use Throwable;
use App\Features\Order\OrderRealtime;

/**
 * Reversión de un pedido ya pagado (RF-17; spec backend/03, P-26 y P-29): el cliente pide cancelarlo, el
 * comercio le devuelve el dinero por fuera de la app y el pedido queda «REVERSADA». No hay devoluciones
 * totales ni parciales: un pedido se reversa completo.
 *
 *   - Compra de un cliente: se revierte al entrenador la comisión y los puntos de ese pedido, sin exceder
 *     lo que el pedido le dio.
 *       · La comisión se descuenta con un movimiento de ajuste; si el mes ya se liquidó, queda
 *         negativa y se descuenta del siguiente (spec negocio/04, regla 8).
 *       · Los puntos se retiran completos (P-17): primero lo que quede en el lote de ese pedido y, lo que
 *         el entrenador ya usó, de sus otros puntos vigentes por FIFO. Si no le alcanzan, se retira lo
 *         que tenga y no queda deuda.
 *       · Si esa venta activó los 50.000 puntos del referido de A, se le retiran a A con la misma regla y el
 *         referido vuelve a quedar pendiente (spec negocio/02, S-06).
 *   - Canje reversado: se devuelven los puntos en un lote nuevo.
 *   - Un pedido admite un solo ajuste. El ajuste nunca borra ni cambia los movimientos originales.
 */
class AdjustmentService
{
    /** El único ajuste: «Reversar» en el admin. */
    public const array TYPES = ['cancellation'];

    /**
     * Estado de un pedido pagado o de un canje que se reversa (`cancellation`). No es `CANCELADA`:
     * ese estado lo mandan las pasarelas para un pago que nunca se aprobó, sin dinero ni puntos de por medio.
     * `REVERSADA` ya está en `status_orders` (id 5) y ninguna de las tres pasarelas lo manda.
     */
    public const string STATUS_REVERTED = 'REVERSADA';

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
                    (SELECT COALESCE(SUM(pm.points), 0) FROM point_movements pm JOIN point_lots pl ON pl.id = pm.point_lot_id
                     WHERE pm.adjustment_id = a.id AND pl.trainer_id <=> :trainer_id) AS points
             FROM adjustments a
             JOIN orders o ON o.order_id = a.order_id
             LEFT JOIN ' . IntranetTables::fromEnv()->employees . ' e ON e.id_usuario = a.agent_id
             WHERE a.order_id = :order_id ORDER BY a.id'
        );
        $order = $this->order($orderId);
        $stmt->execute(['trainer_id' => $order['trainer_id'], 'order_id' => $orderId]);
        $trainerName = $order['trainer_name'];

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
     * Lo que revertiría la reversión, sin aplicarla. Los valores van en negativo cuando se le quitan
     * al entrenador y en positivo cuando se le devuelven (canje reversado).
     *
     * @return array{commission_amount:float, points:int, trainer_name:?string}
     * @throws ApiException
     */
    public function preview(int $orderId, string $type): array
    {
        $order = $this->order($orderId);

        return ['trainer_name' => $order['trainer_name']] + $this->effect($order, $type);
    }

    /**
     * @param int|null $agentId Agente que lo registra; null si lo registra la pasarela (`byGateway`).
     * @return int Id del ajuste.
     * @throws ApiException
     */
    public function create(int $orderId, ?int $agentId, string $type, string $reason, ?string $gateway = null): int
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

            if ($order['trainer_id'] !== null) {
                // Antes de calcular el efecto: los puntos que se retiran dependen de su saldo.
                $this->pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE')->execute([$order['trainer_id']]);
            }

            $effect = $this->effect($order, $type);

            $this->pdo->prepare('INSERT INTO adjustments (order_id, agent_id, type, reason) VALUES (?, ?, ?, ?)')
                ->execute([$orderId, $agentId, $order['order_type'] === 'redemption' ? 'restitution' : $type, $reason]);
            $adjustmentId = (int) $this->pdo->lastInsertId();
            $trainerId    = $order['trainer_id'];
            $referralPoints = 0;

            if ($trainerId !== null) {
                if ($effect['commission_amount'] < 0) {
                    new CommissionMovementRepository()->record($trainerId, 'adjustment', $effect['commission_amount'], "adjustment:{$adjustmentId}:commission", [
                        'order_id' => $orderId, 'adjustment_id' => $adjustmentId,
                    ]);
                }

                if ($effect['points'] < 0) {
                    $this->retirePoints($trainerId, $order['lot_id'], -$effect['points'], $orderId, $adjustmentId);
                }

                $referralPoints = $order['order_type'] === 'redemption' ? 0 : $this->revertReferral($orderId, $adjustmentId);

                if ($effect['points'] > 0) {
                    new PointLotRepository()->credit($trainerId, 'restitution', $effect['points'], BusinessConfig::fromEnv()->pointsExpirationMonths, "adjustment:{$adjustmentId}:restitution", [
                        'order_id' => $orderId, 'adjustment_id' => $adjustmentId,
                    ]);
                }

                PushOutbox::enqueue(
                    $trainerId,
                    'order_adjusted',
                    $order['order_type'] === 'redemption' ? 'Te devolvimos tus puntos' : 'Pedido reversado',
                    $order['order_type'] === 'redemption'
                        ? sprintf('Reversamos tu canje #%d y te devolvimos %s puntos.', $orderId, number_format($effect['points'], 0, ',', '.'))
                        : sprintf('El pedido #%d de un cliente fue reversado: ajustamos tu comisión y tus puntos.', $orderId),
                    ['route' => $order['order_type'] === 'redemption' ? '/coach/puntos' : '/coach/comision', 'order_id' => $orderId],
                    "adjustment:{$adjustmentId}",
                );
            }

            $this->pdo->prepare('UPDATE orders SET status_id = ? WHERE order_id = ?')
                ->execute([new StatusOrderRepository()->findOrCreateIdByValue(self::STATUS_REVERTED), $orderId]);

            $audit = ['adjustment_id' => $adjustmentId, 'type' => $type, 'reason' => $reason, 'effect' => $effect + ['referral_points' => -$referralPoints]];
            $agentId !== null
                ? AuditLog::byAgent($agentId, 'order.adjustment', 'order', $orderId, $audit)
                : AuditLog::byGateway((string) $gateway, 'order.adjustment', 'order', $orderId, $audit);

            $this->pdo->commit();
            // Reversión: el dueño y el admin ven el cambio sin recargar (spec integraciones/03).
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
    private function effect(array $order, string $type): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: ['type' => 'El único ajuste es reversar el pedido.']);
        }

        if ($order['approved_at'] === null) {
            throw ApiException::conflict('order_not_approved', 'Solo se ajustan pedidos con el pago aprobado.');
        }

        if ($order['order_type'] === 'redemption') {
            return ['commission_amount' => 0.0, 'points' => (int) $order['redemption_points']];
        }

        // Compra del entrenador o de un cliente sin entrenador: no hay recompensas que revertir.
        if ($order['trainer_id'] === null) {
            return ['commission_amount' => 0.0, 'points' => 0];
        }

        // Los puntos del pedido, completos, hasta donde alcancen sus puntos vigentes: no queda deuda (P-17).
        $retirable = min((int) $order['reward_points'], new PointLotRepository()->availablePoints((int) $order['trainer_id']));

        return ['commission_amount' => -round((float) $order['commission_total'], 2) + 0.0, 'points' => -$retirable];
    }

    /**
     * La venta reversada activó los puntos del referido de A (spec negocio/02, S-06; backend/03, P-17): se le
     * retiran completos, como los de la venta (primero lo que quede en ese lote y lo ya usado de sus otros
     * puntos vigentes por FIFO, sin deuda), y el referido vuelve a quedar pendiente: la próxima compra
     * aprobada de un cliente de B lo activa con un lote nuevo. Así A nunca cobra dos veces el mismo referido.
     *
     * @return int Puntos retirados a A; 0 si la venta no activó ningún referido o A no tenía puntos.
     */
    private function revertReferral(int $orderId, int $adjustmentId): int
    {
        $find = $this->pdo->prepare('SELECT referrer_id FROM trainer_referrals WHERE activation_order_id = ?');
        $find->execute([$orderId]);
        $referrerId = $find->fetchColumn();

        if ($referrerId === false) {
            return 0;
        }

        // Mismo orden de bloqueo que la aprobación del pago: primero los entrenadores (B ya está), luego el referido.
        $this->pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE')->execute([(int) $referrerId]);
        $referral = $this->pdo->prepare('SELECT id FROM trainer_referrals WHERE activation_order_id = ? FOR UPDATE');
        $referral->execute([$orderId]);
        $referralId = $referral->fetchColumn();

        if ($referralId === false) {
            return 0;
        }

        $data = $this->pdo->prepare(
            'SELECT tr.referral_points, (SELECT MAX(l.id) FROM point_lots l WHERE l.trainer_referral_id = tr.id) AS lot_id
             FROM trainer_referrals tr WHERE tr.id = ?'
        );
        $data->execute([(int) $referralId]);
        /** @var array{referral_points:int|string, lot_id:int|string|null} $row */
        $row = $data->fetch(PDO::FETCH_ASSOC);

        // Los 50.000 completos, hasta donde alcancen sus puntos vigentes: no queda deuda (P-17, como los de la venta).
        $points = min((int) $row['referral_points'], new PointLotRepository()->availablePoints((int) $referrerId));
        $this->retirePoints((int) $referrerId, $row['lot_id'] === null ? null : (int) $row['lot_id'], $points, $orderId, $adjustmentId, 'referral');
        $this->pdo->prepare('UPDATE trainer_referrals SET activated_at = NULL, activation_order_id = NULL WHERE id = ?')->execute([(int) $referralId]);

        PushOutbox::enqueue(
            (int) $referrerId,
            'referral_reverted',
            'Tu referido vuelve a estar pendiente',
            sprintf('Se reversó la venta que activó tu referido: retiramos %s puntos. Se activan otra vez con la próxima compra de uno de sus clientes.', number_format($points, 0, ',', '.')),
            ['route' => '/coach/referidos'],
            "adjustment:{$adjustmentId}:referral",
        );

        return $points;
    }

    /**
     * Retira los puntos de una venta reversada: primero del lote de ese pedido y el resto, lo que el
     * entrenador ya usó, de sus otros lotes vigentes por FIFO. `$points` nunca supera su saldo vigente.
     *
     * @param 'points'|'referral' $what Los puntos de la venta (entrenador del pedido) o los del referido (A).
     */
    private function retirePoints(int $trainerId, ?int $lotId, int $points, int $orderId, int $adjustmentId, string $what = 'points'): void
    {
        $key          = "adjustment:{$adjustmentId}:{$what}";
        $fromOrderLot = $lotId === null || $points <= 0 ? 0 : $this->retireFromOrderLot($lotId, $points, $orderId, $adjustmentId, $key);

        PointSpender::spend($trainerId, $points - $fromOrderLot, 'adjustment', $key, [
            'order_id' => $orderId, 'adjustment_id' => $adjustmentId,
        ]);
    }

    /** @return int Puntos retirados del lote que originó los puntos. */
    private function retireFromOrderLot(int $lotId, int $points, int $orderId, int $adjustmentId, string $key): int
    {
        $stmt = $this->pdo->prepare("SELECT remaining_points FROM point_lots WHERE id = ? AND status = 'available' AND expires_at > NOW() FOR UPDATE");
        $stmt->execute([$lotId]);
        $remaining = $stmt->fetchColumn();
        $taken     = $remaining === false ? 0 : min($points, (int) $remaining);

        if ($taken <= 0) {
            return 0;
        }

        $left = (int) $remaining - $taken;
        $this->pdo->prepare("UPDATE point_lots SET remaining_points = ?, status = IF(? = 0, 'used', status) WHERE id = ?")->execute([$left, $left, $lotId]);
        $this->pdo->prepare(
            "INSERT INTO point_movements (point_lot_id, order_id, adjustment_id, type, points, source_key) VALUES (?, ?, ?, 'adjustment', ?, ?)"
        )->execute([$lotId, $orderId, $adjustmentId, -$taken, $key]);

        return $taken;
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
                    l.id AS lot_id,
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
