<?php

declare(strict_types=1);

namespace App\Features\Reports;

use App\Features\Adjustment\AdjustmentService;
use App\Features\Order\PaymentMethod;
use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

/**
 * Reporte de canjes con puntos (spec negocio/07). Solo pedidos de canje, nunca ventas, por fecha de
 * aprobación. Efectivo es un canje «APROBADA»; uno «REVERSADA» (cancelado por un gerente) devolvió sus
 * puntos y va aparte. Los
 * puntos son los guardados en el pedido y en cada línea: no se recalculan.
 */
final class RedemptionReportRepository extends AbstractRepository
{
    private const string RANGE = "o.order_type = 'redemption' AND DATE(o.approved_at) BETWEEN :date_from AND :date_to";
    private const string EFFECTIVE = "s.value = 'APROBADA'";
    private const string CANCELLED = "s.value = '" . AdjustmentService::STATUS_REVERTED . "'";

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /**
     * @return array{redemptions:int, points:int, units:int, trainers:int, cancelled:int, cancelled_points:int}
     */
    public function totals(string $dateFrom, string $dateTo): array
    {
        $orders = $this->one(
            'SELECT COALESCE(SUM(' . self::EFFECTIVE . '), 0) AS redemptions,
                    COALESCE(SUM(IF(' . self::EFFECTIVE . ', o.redemption_points, 0)), 0) AS points,
                    COUNT(DISTINCT IF(' . self::EFFECTIVE . ', o.user_id, NULL)) AS trainers,
                    COALESCE(SUM(' . self::CANCELLED . '), 0) AS cancelled,
                    COALESCE(SUM(IF(' . self::CANCELLED . ', o.redemption_points, 0)), 0) AS cancelled_points
             FROM orders o JOIN status_orders s ON s.status_id = o.status_id
             WHERE ' . self::RANGE,
            $dateFrom,
            $dateTo,
        );
        $units = $this->one(
            'SELECT COALESCE(SUM(i.quantity), 0) AS units
             FROM orders o JOIN status_orders s ON s.status_id = o.status_id JOIN orders_items i ON i.order_id = o.order_id
             WHERE ' . self::RANGE . ' AND ' . self::EFFECTIVE,
            $dateFrom,
            $dateTo,
        );

        return [
            'redemptions'      => (int) $orders['redemptions'],
            'points'           => (int) $orders['points'],
            'units'            => (int) $units['units'],
            'trainers'         => (int) $orders['trainers'],
            'cancelled'        => (int) $orders['cancelled'],
            'cancelled_points' => (int) $orders['cancelled_points'],
        ];
    }

    /** @return list<array{date:string, redemptions:int, points:int}> Canjes efectivos por día. */
    public function days(string $dateFrom, string $dateTo): array
    {
        return array_map(static fn (array $row): array => [
            'date'        => (string) $row['date'],
            'redemptions' => (int) $row['redemptions'],
            'points'      => (int) $row['points'],
        ], $this->all(
            'SELECT DATE(o.approved_at) AS date, COUNT(*) AS redemptions, SUM(o.redemption_points) AS points
             FROM orders o JOIN status_orders s ON s.status_id = o.status_id
             WHERE ' . self::RANGE . ' AND ' . self::EFFECTIVE . '
             GROUP BY DATE(o.approved_at) ORDER BY date',
            $dateFrom,
            $dateTo,
        ));
    }

    /** @return list<array{code:int, name:string, units:int, points:int}> Productos más canjeados, por puntos. */
    public function products(string $dateFrom, string $dateTo, int $limit = 20): array
    {
        return array_map(static fn (array $row): array => [
            'code'   => (int) $row['code'],
            'name'   => (string) $row['name'],
            'units'  => (int) $row['units'],
            'points' => (int) $row['points'],
        ], $this->all(
            'SELECT i.product_sku AS code, MAX(i.product_name) AS name, SUM(i.quantity) AS units, SUM(i.redemption_points) AS points
             FROM orders o JOIN status_orders s ON s.status_id = o.status_id JOIN orders_items i ON i.order_id = o.order_id
             WHERE ' . self::RANGE . ' AND ' . self::EFFECTIVE . '
             GROUP BY i.product_sku ORDER BY points DESC, units DESC LIMIT ' . max(1, $limit),
            $dateFrom,
            $dateTo,
        ));
    }

    /** @return list<array{id:int, full_name:string, redemptions:int, points:int}> Entrenadores que más canjean. */
    public function trainers(string $dateFrom, string $dateTo, int $limit = 20): array
    {
        return array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'full_name'   => (string) $row['full_name'],
            'redemptions' => (int) $row['redemptions'],
            'points'      => (int) $row['points'],
        ], $this->all(
            'SELECT o.user_id AS id, u.full_name, COUNT(*) AS redemptions, SUM(o.redemption_points) AS points
             FROM orders o JOIN status_orders s ON s.status_id = o.status_id JOIN users u ON u.id = o.user_id
             WHERE ' . self::RANGE . ' AND ' . self::EFFECTIVE . '
             GROUP BY o.user_id, u.full_name ORDER BY points DESC, redemptions DESC LIMIT ' . max(1, $limit),
            $dateFrom,
            $dateTo,
        ));
    }

    /**
     * Todos los canjes del rango, efectivos y cancelados, del más reciente al más antiguo.
     *
     * @return list<array{order_id:int, approved_at:string, trainer_id:int, trainer_name:string, city:string, units:int, points:int, status:string, sap_order_code:int|null, tracking_number:string|null}>
     */
    public function redemptions(string $dateFrom, string $dateTo, int $limit = 2000): array
    {
        return array_map(static fn (array $row): array => [
            'order_id'        => (int) $row['order_id'],
            'approved_at'     => (string) $row['approved_at'],
            'trainer_id'      => (int) $row['trainer_id'],
            'trainer_name'    => (string) $row['trainer_name'],
            'city'            => (string) $row['city'],
            'units'           => (int) $row['units'],
            'points'          => (int) $row['points'],
            'status'          => (string) $row['status'],
            'sap_order_code'  => $row['sap_order_code'] === null ? null : (int) $row['sap_order_code'],
            'tracking_number' => $row['tracking_number'] === null ? null : (string) $row['tracking_number'],
        ], $this->all(
            "SELECT o.order_id, o.approved_at, o.user_id AS trainer_id, u.full_name AS trainer_name,
                    COALESCE(c.name, '') AS city, o.redemption_points AS points, s.value AS status,
                    o.sap_order_code, o.tracking_number,
                    (SELECT COALESCE(SUM(i.quantity), 0) FROM orders_items i WHERE i.order_id = o.order_id) AS units
             FROM orders o
             JOIN status_orders s ON s.status_id = o.status_id
             JOIN users u ON u.id = o.user_id
             LEFT JOIN addresses a ON a.id = o.address_id
             LEFT JOIN cities c ON c.id = a.city_id
             WHERE " . self::RANGE . '
             ORDER BY o.approved_at DESC, o.order_id DESC LIMIT ' . max(1, $limit),
            $dateFrom,
            $dateTo,
        ));
    }

    /**
     * Una fila por producto canjeado, para Excel. El precio completo y el IVA son de referencia: el canje
     * los guarda, pero no entró dinero.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(string $dateFrom, string $dateTo, int $limit = 10000): array
    {
        return $this->all(
            "SELECT o.order_id AS pedido_id,
                    DATE_FORMAT(o.approved_at, '%Y-%m-%d') AS fecha,
                    TIME_FORMAT(o.approved_at, '%H:%i:%s') AS hora,
                    u.full_name AS entrenador,
                    u.document_id AS documento_entrenador,
                    COALESCE(c.name, '') AS ciudad,
                    COALESCE(c.department, '') AS departamento,
                    i.product_sku AS codigo_producto,
                    i.product_name AS producto,
                    i.quantity AS cantidad,
                    i.redemption_points AS puntos_linea,
                    o.redemption_points AS puntos_pedido,
                    " . PaymentMethod::SQL . " AS forma_pago,
                    ROUND(i.unit_price) AS precio_completo_referencia,
                    ROUND(i.line_total) AS valor_linea_referencia,
                    i.tax_base AS base_sin_iva_referencia,
                    i.tax_amount AS iva_referencia,
                    s.value AS estado,
                    o.sap_order_code AS pedido_sap,
                    o.tracking_number AS guia
             FROM orders o
             JOIN status_orders s ON s.status_id = o.status_id
             JOIN users u ON u.id = o.user_id
             JOIN orders_items i ON i.order_id = o.order_id
             LEFT JOIN addresses a ON a.id = o.address_id
             LEFT JOIN cities c ON c.id = a.city_id
             WHERE " . self::RANGE . '
             ORDER BY o.approved_at DESC, o.order_id DESC, i.item_id LIMIT ' . max(1, $limit),
            $dateFrom,
            $dateTo,
        );
    }

    /** @return array<string, mixed> */
    private function one(string $sql, string $dateFrom, string $dateTo): array
    {
        return $this->all($sql, $dateFrom, $dateTo)[0] ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
