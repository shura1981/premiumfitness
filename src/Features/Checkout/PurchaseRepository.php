<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use Throwable;

/**
 * Guarda un pedido con todo lo que aplicó al comprar (precio, descuento, IVA, comisión y puntos),
 * para que un cambio posterior del catálogo o de las variables no altere el histórico.
 */
class PurchaseRepository extends AbstractRepository
{
    /** Estado de un pedido recién creado, a la espera del pago (el de radical). */
    private const int INITIAL_STATUS_ID = 14;

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /**
     * @param 'client_purchase'|'trainer_purchase' $orderType
     * @param array{code:string, discount_percentage:float}|null $coupon Cupón del entrenador del cliente.
     */
    public function create(int $userId, string $orderType, Quote $quote, int $addressId, string $gateway, ?string $notes, ?array $coupon): int
    {
        $this->pdo->beginTransaction();

        try {
            $this->execute(
                'INSERT INTO orders
                    (user_id, order_type, amount, subtotal_full, discount_amount, tax_base, tax_amount, shipping_amount,
                     reward_percentage, reward_points, payment_gateway, address_id, order_notes, status_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId, $orderType, $quote->total, $quote->subtotalFull, $quote->discountAmount, $quote->taxBase,
                    $quote->taxAmount, $quote->shippingAmount, $quote->rewardPercentage, $quote->rewardPoints,
                    $gateway, $addressId, $notes, self::INITIAL_STATUS_ID,
                ]
            );
            $orderId = (int) $this->pdo->lastInsertId();

            $item = $this->pdo->prepare(
                'INSERT INTO orders_items
                    (order_id, product_sku, product_name, product_full_price, sap_code, quantity, unit_price,
                     discount_percentage, line_total, tax_rate, tax_base, tax_amount, commission_percentage, commission_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($quote->lines as $line) {
                $item->execute([
                    $orderId, $line->code, $line->name, $line->unitPrice, $line->sapCode, $line->quantity,
                    $line->finalUnitPrice, $line->discountPercentage, $line->lineTotal, $line->taxRate, $line->taxBase,
                    $line->taxAmount, $line->commissionPercentage, $line->commissionAmount,
                ]);
            }

            // La atribución queda en el pedido: si el cliente cambia de entrenador, esta venta no cambia.
            if ($quote->trainerId !== null && $coupon !== null) {
                $this->execute(
                    'INSERT INTO orders_coupons (order_id, trainer_id, coupon_code, discount_percentage, discount_amount)
                     VALUES (?, ?, ?, ?, ?)',
                    [$orderId, $quote->trainerId, $coupon['code'], $coupon['discount_percentage'], $quote->discountAmount]
                );
            }

            $this->pdo->commit();

            return $orderId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
