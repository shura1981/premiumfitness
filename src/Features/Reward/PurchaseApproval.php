<?php

declare(strict_types=1);

namespace App\Features\Reward;

use App\Features\Commission\CommissionMovementRepository;
use App\Features\Notification\PushOutbox;
use App\Features\Point\PointLotRepository;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use PDO;
use Throwable;

/**
 * Aprobación del pago de un pedido (reglas 1, 6, 15 y 17; specs negocio/01 y negocio/02).
 *
 * Todo ocurre en una sola transacción, con el pedido y los entrenadores bloqueados:
 *   1. el pedido pasa a aprobado, si lo pagado cubre su valor;
 *   2. el entrenador del cliente recibe su comisión y sus puntos, con los valores guardados en el pedido;
 *   3. si esta es la primera compra aprobada de un cliente del entrenador B, se activan los puntos
 *      por referido de quien refirió a B.
 * Las claves únicas (`source_key`, `payment_reference`) hacen que repetir el aviso de la pasarela
 * no acredite nada dos veces.
 */
class PurchaseApproval
{
    private readonly PointLotRepository $lots;
    private readonly CommissionMovementRepository $commissions;
    private readonly BusinessConfig $business;

    public function __construct()
    {
        $this->lots        = new PointLotRepository();
        $this->commissions = new CommissionMovementRepository();
        $this->business    = BusinessConfig::fromEnv();
    }

    /**
     * @param array<string, mixed> $paymentState Lo que informó la pasarela.
     * @return bool true si el pedido se aprobó ahora; false si ya estaba aprobado.
     * @throws AmountMismatchException si lo pagado no cubre el pedido
     */
    public function approve(int $orderId, int $approvedStatusId, string $gateway, string $transactionId, float $paidAmount, array $paymentState): bool
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();

        try {
            $order = $this->lockOrder($pdo, $orderId);

            if ($order === null || $order['approved_at'] !== null || $order['order_type'] === 'redemption') {
                $pdo->commit();

                return false;
            }

            // El valor lo fija el backend al crear el pedido: un pago menor no lo aprueba.
            if (round($paidAmount) < round((float) $order['amount'])) {
                throw new AmountMismatchException(sprintf('Order %d: paid %.2f, expected %.2f.', $orderId, $paidAmount, (float) $order['amount']));
            }

            $pdo->prepare(
                'UPDATE orders SET status_id = ?, payment_state_json = ?, approved_at = NOW(), payment_reference = ? WHERE order_id = ?'
            )->execute([
                $approvedStatusId,
                json_encode($paymentState, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                // Único: un mismo pago de la pasarela no aprueba dos pedidos.
                $transactionId !== '' ? mb_substr($gateway . ':' . $transactionId, 0, 100) : null,
                $orderId,
            ]);

            if ($order['order_type'] === 'client_purchase') {
                $this->grantRewards($pdo, $orderId, $order);
            }

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function lockOrder(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT order_id, user_id, order_type, amount, shipping_amount, approved_at, reward_points FROM orders WHERE order_id = ? FOR UPDATE'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        return $order === false ? null : $order;
    }

    /** @param array<string, mixed> $order */
    private function grantRewards(PDO $pdo, int $orderId, array $order): void
    {
        // El pedido guarda a qué entrenador se atribuye: es el que tenía el cliente al comprar.
        $coupon = $pdo->prepare('SELECT trainer_id FROM orders_coupons WHERE order_id = ?');
        $coupon->execute([$orderId]);
        $trainerId = $coupon->fetchColumn();

        if ($trainerId === false || $trainerId === null) {
            return;
        }

        $trainerId = (int) $trainerId;
        // Los puntos del referido de A los activa una compra de al menos TRAINER_REFERRAL_MIN_PURCHASE en productos (regla 6, D-05).
        $referral  = $this->activatesReferral($order) ? $this->pendingReferralOf($pdo, $trainerId) : null;

        // Se bloquean los entrenadores en orden de id para que dos aprobaciones no se crucen.
        $toLock = array_values(array_unique(array_filter([$trainerId, $referral['referrer_id'] ?? null])));
        sort($toLock);
        $lock = $pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE');
        foreach ($toLock as $id) {
            $lock->execute([$id]);
        }

        $this->creditCommission($pdo, $orderId, $trainerId);
        $this->creditPurchasePoints($orderId, $trainerId, (int) ($order['reward_points'] ?? 0));

        if ($referral !== null) {
            $this->activateReferral($pdo, $orderId, $trainerId, $referral['id']);
        }
    }

    private function creditCommission(PDO $pdo, int $orderId, int $trainerId): void
    {
        // Se suma lo guardado en cada línea; no se recalcula.
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(commission_amount), 0) AS amount, COALESCE(SUM(tax_base), 0) AS tax_base
             FROM orders_items WHERE order_id = ? AND commission_amount IS NOT NULL'
        );
        $stmt->execute([$orderId]);
        /** @var array{amount:string, tax_base:string} $totals */
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        $amount = (float) $totals['amount'];

        if ($amount <= 0) {
            return;
        }

        $created = $this->commissions->record($trainerId, 'sale_credit', $amount, "order:{$orderId}:commission", [
            'order_id' => $orderId,
            'tax_base' => (float) $totals['tax_base'],
        ]);

        if ($created) {
            PushOutbox::enqueue(
                $trainerId,
                'commission_earned',
                'Ganaste comisión',
                sprintf('Un cliente tuyo compró: ganaste $%s de comisión.', number_format($amount, 0, ',', '.')),
                ['route' => '/coach/comision', 'order_id' => $orderId],
                "order:{$orderId}:commission",
            );
        }
    }

    private function creditPurchasePoints(int $orderId, int $trainerId, int $points): void
    {
        $lotId = $this->lots->credit($trainerId, 'client_sale', $points, $this->business->pointsExpirationMonths, "order:{$orderId}:points", ['order_id' => $orderId]);

        if ($lotId !== null) {
            PushOutbox::enqueue(
                $trainerId,
                'points_earned',
                'Ganaste puntos',
                sprintf('Ganaste %s puntos por la compra de un cliente.', number_format($points, 0, ',', '.')),
                ['route' => '/coach/puntos', 'order_id' => $orderId],
                "order:{$orderId}:points",
            );
        }
    }

    /**
     * Lo pagado por los productos (con descuento e IVA, sin flete) llega al mínimo que activa los puntos del
     * referido. Una compra menor no los activa: el referido sigue pendiente hasta una que sí llegue.
     *
     * @param array<string, mixed> $order
     */
    private function activatesReferral(array $order): bool
    {
        $products = (float) $order['amount'] - (float) ($order['shipping_amount'] ?? 0);

        return $products >= $this->business->trainerReferralMinPurchase;
    }

    /** @return array{id:int, referrer_id:int}|null El referido pendiente del entrenador B, si lo tiene. */
    private function pendingReferralOf(PDO $pdo, int $referredId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, referrer_id FROM trainer_referrals WHERE referred_id = ? AND activated_at IS NULL');
        $stmt->execute([$referredId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int) $row['id'], 'referrer_id' => (int) $row['referrer_id']];
    }

    /**
     * Primera compra aprobada de un cliente de B: A recibe los puntos que valía el referido cuando
     * B se registró (regla 15). `activation_order_id` es único. La clave del lote lleva el pedido que lo activa:
     * si esa venta se reversa, el referido vuelve a quedar pendiente y otra venta lo activa con un lote nuevo (S-06).
     */
    private function activateReferral(PDO $pdo, int $orderId, int $referredId, int $referralId): void
    {
        $stmt = $pdo->prepare('SELECT referrer_id, referral_points FROM trainer_referrals WHERE id = ? AND activated_at IS NULL FOR UPDATE');
        $stmt->execute([$referralId]);
        $referral = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($referral === false) {
            return;
        }

        $pdo->prepare('UPDATE trainer_referrals SET activated_at = NOW(), activation_order_id = ? WHERE id = ?')
            ->execute([$orderId, $referralId]);

        $referrerId = (int) $referral['referrer_id'];
        $points     = (int) $referral['referral_points'];
        $lotId      = $this->lots->credit($referrerId, 'trainer_referral', $points, $this->business->pointsExpirationMonths, "referral:{$referralId}:order:{$orderId}", [
            'trainer_referral_id' => $referralId,
        ]);

        if ($lotId !== null) {
            PushOutbox::enqueue(
                $referrerId,
                'referral_activated',
                'Tu referido ya vendió',
                sprintf('Un entrenador que referiste hizo su primera venta: ganaste %s puntos.', number_format($points, 0, ',', '.')),
                ['route' => '/coach/referidos', 'trainer_id' => $referredId],
                "referral:{$referralId}:order:{$orderId}",
            );
        }
    }
}
