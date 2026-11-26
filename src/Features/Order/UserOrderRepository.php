<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;
use PDO;

/**
 * Pedidos de un usuario, para la app web. Toda consulta filtra por el dueño.
 */
class UserOrderRepository extends AbstractRepository
{
    private const string ORDER_COLUMNS = '
        o.order_id, o.created_at, o.approved_at, o.amount, o.order_type, o.payment_gateway, o.subtotal_full,
        o.discount_amount, o.tax_amount, o.shipping_amount, o.redemption_points, o.sap_order_code,
        o.tracking_number, o.carrier_name, o.address_id, s.value AS status_value';

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<array<string, mixed>> Los más recientes primero, con el número de líneas y la primera. */
    public function allOf(int $userId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ',
                    (SELECT COALESCE(SUM(i.quantity), 0) FROM orders_items i WHERE i.order_id = o.order_id) AS items_count,
                    (SELECT i.product_name FROM orders_items i WHERE i.order_id = o.order_id ORDER BY i.item_id LIMIT 1) AS first_item
             FROM orders o
             LEFT JOIN status_orders s ON s.status_id = o.status_id
             WHERE o.user_id = ?
             ORDER BY o.order_id DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findOf(int $userId, int $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ORDER_COLUMNS . ',
                    a.address_name, a.address, c.name AS city_name, c.department AS department_name
             FROM orders o
             LEFT JOIN status_orders s ON s.status_id = o.status_id
             LEFT JOIN addresses a ON a.id = o.address_id
             LEFT JOIN cities c ON c.id = a.city_id
             WHERE o.order_id = ? AND o.user_id = ?'
        );
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        return $order === false ? null : $order;
    }

    /**
     * Pedido de ese usuario con ese id de pago (`orders.payment_code`), para la página de respuesta.
     *
     * @return array<string, mixed>|null
     */
    public function findByPaymentCode(int $userId, string $paymentCode): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.order_id, o.payment_code, o.payment_gateway, o.amount, o.created_at, o.approved_at,
                    o.payment_reference, o.payment_state_json, o.sap_order_code, o.tracking_number,
                    s.value AS status_value
             FROM orders o
             LEFT JOIN status_orders s ON s.status_id = o.status_id
             WHERE o.payment_code = ? AND o.user_id = ?'
        );
        $stmt->execute([trim($paymentCode), $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        return $order === false ? null : $order;
    }

    /** @return list<array<string, mixed>> Las líneas, con la imagen vigente del catálogo si aún existe. */
    public function itemsOf(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.product_sku, i.product_name, i.quantity, i.product_full_price, i.unit_price,
                    i.discount_percentage, i.line_total, i.redemption_points, p.imageswebp
             FROM orders_items i
             LEFT JOIN ' . IntranetTables::fromEnv()->products . ' p ON p.codigo = i.product_sku
             WHERE i.order_id = ?
             ORDER BY i.item_id'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
