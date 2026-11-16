<?php

declare(strict_types=1);

namespace App\Features\Trainer;

use App\Features\Commission\CommissionCalendar;
use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use DateTimeImmutable;
use PDO;

/**
 * Ventas del entrenador para el módulo de comisión (spec negocio/06). Solo lectura y solo suma lo
 * guardado: una venta es un pedido de cliente aprobado, atribuido al entrenador, con comisión neta
 * (abono menos ajustes) mayor que cero. Su valor es lo que pagó el cliente sin el envío.
 */
class TrainerSalesRepository extends AbstractRepository
{
    /**
     * Ventas del entrenador con su comisión neta, entre dos fechas de aprobación (o todas).
     * Se usa como CTE `sales`; sus parámetros van primero en cada consulta.
     */
    private const string SALES = "
        WITH sales AS (
            SELECT o.order_id, o.address_id, o.approved_at,
                   o.amount - o.shipping_amount AS amount,
                   SUM(cm.amount) AS commission
            FROM orders o
            JOIN orders_coupons oc ON oc.order_id = o.order_id AND oc.trainer_id = ?
            JOIN commission_movements cm
                 ON cm.order_id = o.order_id AND cm.trainer_id = oc.trainer_id AND cm.type IN ('sale_credit', 'adjustment')
            WHERE o.order_type = 'client_purchase' AND o.approved_at IS NOT NULL
              AND o.approved_at BETWEEN ? AND ?
            GROUP BY o.order_id, o.address_id, o.approved_at, o.amount, o.shipping_amount
            HAVING SUM(cm.amount) > 0
        )";

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /**
     * @return array{sales:int, commission:float, amount:float, cities:int, products:int, units:int}
     */
    public function totals(int $trainerId, string $period): array
    {
        $row = $this->one(
            self::SALES . "
            SELECT COUNT(*) AS sales, COALESCE(SUM(s.commission), 0) AS commission, COALESCE(SUM(s.amount), 0) AS amount,
                   COUNT(DISTINCT a.city_id) AS cities
            FROM sales s LEFT JOIN addresses a ON a.id = s.address_id",
            $this->range($trainerId, $period),
        );
        $items = $this->one(
            self::SALES . '
            SELECT COUNT(DISTINCT i.product_sku) AS products, COALESCE(SUM(i.quantity), 0) AS units
            FROM sales s JOIN orders_items i ON i.order_id = s.order_id',
            $this->range($trainerId, $period),
        );

        return [
            'sales'      => (int) $row['sales'],
            'commission' => (float) $row['commission'],
            'amount'     => (float) $row['amount'],
            'cities'     => (int) $row['cities'],
            'products'   => (int) $items['products'],
            'units'      => (int) $items['units'],
        ];
    }

    /** Ventas aprobadas en los 7 días calendario que terminan hoy. */
    public function lastSevenDays(int $trainerId): int
    {
        $from = new DateTimeImmutable('today')->modify('-6 days')->format('Y-m-d 00:00:00');
        $to   = new DateTimeImmutable('today')->format('Y-m-d 23:59:59');

        return (int) $this->one(self::SALES . ' SELECT COUNT(*) AS sales FROM sales', [$trainerId, $from, $to])['sales'];
    }

    /** @return list<array{date:string, sales:int, commission:float, amount:float}> Días con ventas, del más reciente al más antiguo. */
    public function days(int $trainerId, string $period): array
    {
        $rows = $this->all(
            self::SALES . '
            SELECT DATE(s.approved_at) AS day, COUNT(*) AS sales, SUM(s.commission) AS commission, SUM(s.amount) AS amount
            FROM sales s GROUP BY DATE(s.approved_at) ORDER BY day DESC',
            $this->range($trainerId, $period),
        );

        return array_map(static fn (array $row): array => [
            'date'       => (string) $row['day'],
            'sales'      => (int) $row['sales'],
            'commission' => (float) $row['commission'],
            'amount'     => (float) $row['amount'],
        ], $rows);
    }

    /** @return list<array{city:string, department:string, sales:int, commission:float, amount:float}> De mayor a menor comisión. */
    public function cities(int $trainerId, string $period): array
    {
        $rows = $this->all(
            self::SALES . '
            SELECT c.name AS city, c.department, COUNT(*) AS sales, SUM(s.commission) AS commission, SUM(s.amount) AS amount
            FROM sales s JOIN addresses a ON a.id = s.address_id JOIN cities c ON c.id = a.city_id
            GROUP BY c.id, c.name, c.department ORDER BY commission DESC, c.name',
            $this->range($trainerId, $period),
        );

        return array_map(static fn (array $row): array => [
            'city'       => (string) $row['city'],
            'department' => (string) $row['department'],
            'sales'      => (int) $row['sales'],
            'commission' => (float) $row['commission'],
            'amount'     => (float) $row['amount'],
        ], $rows);
    }

    /** @return list<array{code:int, name:string, units:int, share:float}> De más a menos unidades; `share` en % de las unidades. */
    public function products(int $trainerId, string $period): array
    {
        $rows = $this->all(
            self::SALES . '
            SELECT i.product_sku AS code, MAX(i.product_name) AS name, SUM(i.quantity) AS units
            FROM sales s JOIN orders_items i ON i.order_id = s.order_id
            GROUP BY i.product_sku ORDER BY units DESC, name',
            $this->range($trainerId, $period),
        );
        $total = array_sum(array_map(static fn (array $row): int => (int) $row['units'], $rows));

        return array_map(static fn (array $row): array => [
            'code'  => (int) $row['code'],
            'name'  => (string) $row['name'],
            'units' => (int) $row['units'],
            'share' => $total > 0 ? round((int) $row['units'] * 100 / $total, 1) : 0.0,
        ], $rows);
    }

    /** @return list<array{period:string, sales:int, commission:float, amount:float}> Cada mes con ventas, del más reciente al más antiguo. */
    public function months(int $trainerId): array
    {
        $rows = $this->all(
            self::SALES . "
            SELECT DATE_FORMAT(s.approved_at, '%Y-%m') AS period, COUNT(*) AS sales, SUM(s.commission) AS commission, SUM(s.amount) AS amount
            FROM sales s GROUP BY period ORDER BY period DESC",
            [$trainerId, '1970-01-01 00:00:00', '9999-12-31 23:59:59'],
        );

        return array_map(static fn (array $row): array => [
            'period'     => (string) $row['period'],
            'sales'      => (int) $row['sales'],
            'commission' => (float) $row['commission'],
            'amount'     => (float) $row['amount'],
        ], $rows);
    }

    /** @return list<int|string> Entrenador y límites del mes (hora de Bogotá, como el cierre). */
    private function range(int $trainerId, string $period): array
    {
        return [
            $trainerId,
            CommissionCalendar::start($period)->format('Y-m-d H:i:s'),
            CommissionCalendar::end($period)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<int|string> $params
     * @return array<string, mixed>
     */
    private function one(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
