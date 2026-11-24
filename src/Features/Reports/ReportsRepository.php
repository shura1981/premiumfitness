<?php

declare(strict_types=1);

namespace App\Features\Reports;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;
use PDO;

class ReportsRepository extends AbstractRepository
{
    /**
     * El catálogo de la intranet (solo lectura) con los nombres de columna que usan los reportes.
     * Sin los productos en promoción, que no se venden en la app (spec catalogo/01).
     */
    /**
     * Solo ventas: compras de clientes y de entrenadores. Un canje se paga con puntos, no es una venta
     * y nunca entra en estos reportes: su `amount` es 0 y sus líneas guardan el precio completo en
     * `line_total`, que no se cobró.
     */
    private const string SALE = "o.order_type IN ('client_purchase', 'trainer_purchase')";

    private static function catalog(): string
    {
        return '(SELECT codigo AS sku, producto AS name, category AS category_name, valor + 0 AS price,
            agotado + 0 AS is_out_of_stock, COALESCE(activo, 0) + 0 AS is_active
         FROM ' . IntranetTables::fromEnv()->products . ' WHERE promo = 0)';
    }

    /**
     * Unidades y valor vendidos de cada producto entre :date_from y :date_to: solo líneas de ventas
     * aprobadas. Las condiciones van aquí, no en el JOIN con el catálogo: antes estaban en el JOIN con
     * el estado y se sumaban también los pedidos pendientes, rechazados y de cualquier fecha.
     */
    private static function approvedSalesByProduct(): string
    {
        return "(SELECT oi.product_sku, SUM(oi.quantity) AS total_sold, SUM(oi.line_total) AS revenue
                 FROM orders_items oi
                 JOIN orders o ON o.order_id = oi.order_id
                 JOIN status_orders s ON s.status_id = o.status_id
                 WHERE s.value = 'APROBADA' AND " . self::SALE . "
                   AND DATE(o.created_at) >= :date_from
                   AND DATE(o.created_at) <= :date_to
                 GROUP BY oi.product_sku)";
    }

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function dashboard(): DashboardKpiModel
    {
        $sql = 'SELECT
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM orders o WHERE ' . self::SALE . ') AS total_orders,
            COALESCE((SELECT SUM(o.amount)
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = \'APROBADA\' AND ' . self::SALE . '), 0) AS revenue_approved,
            COALESCE((SELECT SUM(o.amount) FROM orders o WHERE ' . self::SALE . '), 0) AS revenue_total,
            (SELECT COUNT(*)
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value LIKE \'PENDIENTE%\' AND ' . self::SALE . ') AS pending_orders,
            (SELECT COUNT(*)
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE (s.value LIKE \'%RECHAZAD%\' OR s.value LIKE \'%CANCELAD%\') AND ' . self::SALE . ') AS rejected_orders,
            (SELECT COUNT(*)
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = \'APROBADA\' AND ' . self::SALE . ') AS approved_orders,
            (SELECT COUNT(*) FROM ' . self::catalog() . ' p WHERE p.is_active = 1) AS total_products,
            (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)) AS new_users_this_month,
            (SELECT COUNT(*) FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = \'APROBADA\' AND ' . self::SALE . '
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)) AS orders_this_month,
            COALESCE((SELECT SUM(o.amount) FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = \'APROBADA\' AND ' . self::SALE . '
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)), 0) AS revenue_this_month,
            COALESCE((SELECT ROUND(AVG(o.amount))
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = \'APROBADA\' AND ' . self::SALE . '), 0) AS avg_ticket';

        /** @var DashboardKpiModel|false $result */
        $result = $this->fetchOne($sql, DashboardKpiModel::class);

        return $result !== false ? $result : new DashboardKpiModel();
    }

    // =========================================================================
    // SALES — TIME SERIES
    // =========================================================================

    /**
     * Ventas mensuales (APROBADA).
     * @return list<SalesByPeriodModel>
     */
    public function salesMonthly(int $year): array
    {
        return $this->fetchAll(
            "SELECT DATE_FORMAT(o.created_at, '%m') AS period,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.amount), 0) AS revenue
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = 'APROBADA' AND " . self::SALE . " AND YEAR(o.created_at) = :year
             GROUP BY DATE_FORMAT(o.created_at, '%m')
             ORDER BY period",
            SalesByPeriodModel::class,
            ['year' => $year],
        );
    }

    /**
     * Ventas diarias (APROBADA).
     * @return list<SalesByPeriodModel>
     */
    public function salesDaily(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAll(
            "SELECT DATE(o.created_at) AS period,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.amount), 0) AS revenue
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = 'APROBADA' AND " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY DATE(o.created_at)
             ORDER BY period",
            SalesByPeriodModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo],
        );
    }

    // =========================================================================
    // SALES — BY GATEWAY
    // =========================================================================

    /**
     * @return list<SalesByGatewayModel>
     */
    public function salesByGateway(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAll(
            "SELECT COALESCE(o.payment_gateway, '') AS payment_gateway,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.amount), 0) AS revenue,
                    COALESCE(AVG(o.amount), 0) AS avg_ticket
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = 'APROBADA' AND " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY o.payment_gateway
             ORDER BY revenue DESC",
            SalesByGatewayModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo],
        );
    }

    // =========================================================================
    // SALES — BY STATUS
    // =========================================================================

    /**
     * @return list<SalesByStatusModel>
     */
    public function salesByStatus(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAll(
            "SELECT s.value AS status_value, COUNT(*) AS order_count
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY s.value
             ORDER BY order_count DESC",
            SalesByStatusModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo],
        );
    }

    // =========================================================================
    // SALES — BY HOUR
    // =========================================================================

    /**
     * @return list<SalesByPeriodModel>
     */
    public function salesByHour(string $date): array
    {
        return $this->fetchAll(
            "SELECT HOUR(o.created_at) AS period,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.amount), 0) AS revenue
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = 'APROBADA' AND " . self::SALE . " AND DATE(o.created_at) = :date
             GROUP BY HOUR(o.created_at)
             ORDER BY period",
            SalesByPeriodModel::class,
            ['date' => $date],
        );
    }

    // =========================================================================
    // RANKINGS — TOP PRODUCTS
    // =========================================================================

    /**
     * @return list<TopProductModel>
     */
    public function topProducts(string $dateFrom, string $dateTo, int $limit = 10): array
    {
        return $this->fetchAll(
            "SELECT oi.product_sku,
                    MAX(oi.product_name) AS product_name,
                    -- Un producto que ya no está en el catálogo (o sin categoría) sale sin categoría, no rompe el reporte.
                    COALESCE(MAX(p.category_name), '') AS category_name,
                    SUM(oi.quantity) AS total_sold,
                    COALESCE(SUM(oi.line_total), 0) AS revenue
             FROM orders_items oi
             JOIN orders o ON oi.order_id = o.order_id
             JOIN status_orders s ON o.status_id = s.status_id
             LEFT JOIN " . self::catalog() . " p ON oi.product_sku = p.sku
             WHERE s.value = 'APROBADA' AND " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY oi.product_sku
             ORDER BY revenue DESC
             LIMIT :limit",
            TopProductModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit],
        );
    }

    // =========================================================================
    // RANKINGS — TOP CUSTOMERS
    // =========================================================================

    /**
     * @return list<TopCustomerModel>
     */
    public function topCustomers(string $dateFrom, string $dateTo, int $limit = 10): array
    {
        return $this->fetchAll(
            "SELECT o.user_id,
                    u.full_name,
                    u.email,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.amount), 0) AS total_spent,
                    COALESCE(AVG(o.amount), 0) AS avg_ticket
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             JOIN users u ON o.user_id = u.id
             WHERE s.value = 'APROBADA' AND " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY o.user_id, u.full_name, u.email
             ORDER BY total_spent DESC
             LIMIT :limit",
            TopCustomerModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit],
        );
    }

    // =========================================================================
    // RANKINGS — TOP CATEGORIES
    // =========================================================================

    /**
     * @return list<TopCategoryModel>
     */
    public function topCategories(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAll(
            "SELECT CRC32(COALESCE(p.category_name, '')) AS category_id,
                    COALESCE(p.category_name, '') AS category_name,
                    COUNT(DISTINCT oi.product_sku) AS product_count,
                    COALESCE(SUM(oi.line_total), 0) AS revenue
             FROM orders_items oi
             JOIN orders o ON oi.order_id = o.order_id
             JOIN status_orders s ON o.status_id = s.status_id
             JOIN " . self::catalog() . " p ON oi.product_sku = p.sku
             WHERE s.value = 'APROBADA' AND " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY p.category_name
             ORDER BY revenue DESC",
            TopCategoryModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo],
        );
    }

    // =========================================================================
    // GEO — BY CITY
    // =========================================================================

    /**
     * @return list<SalesByLocationModel>
     */
    public function salesByCity(string $dateFrom, string $dateTo, int $limit = 20): array
    {
        return $this->fetchAll(
            "SELECT c.id AS city_id,
                    COALESCE(c.name, 'Sin ciudad') AS city,
                    COALESCE(c.department, 'Sin departamento') AS department,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.amount), 0) AS revenue
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             LEFT JOIN addresses a ON o.address_id = a.id
             LEFT JOIN cities c ON a.city_id = c.id
             WHERE s.value = 'APROBADA' AND " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY c.id, c.name, c.department
             ORDER BY revenue DESC
             LIMIT :limit",
            SalesByLocationModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit],
        );
    }

    /**
     * @return list<SalesByLocationModel>
     */
    public function salesByDepartment(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAll(
            "SELECT COALESCE(c.department, 'Sin departamento') AS department,
                    COALESCE(MIN(c.name), '') AS city,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.amount), 0) AS revenue
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             LEFT JOIN addresses a ON o.address_id = a.id
             LEFT JOIN cities c ON a.city_id = c.id
             WHERE s.value = 'APROBADA' AND " . self::SALE . "
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY c.department
             ORDER BY revenue DESC",
            SalesByLocationModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo],
        );
    }

    // =========================================================================
    // GEO — BY DEPARTMENT
    // =========================================================================

    // =========================================================================
    // CUSTOMERS
    // =========================================================================

    public function newVsReturning(string $dateFrom, string $dateTo): CustomerStatsModel
    {
        /** @var CustomerStatsModel|false $result */
        $result = $this->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN order_count_total = 1 THEN 1 ELSE 0 END), 0) AS new_customers,
                    COALESCE(SUM(CASE WHEN order_count_total > 1 THEN 1 ELSE 0 END), 0) AS returning_customers,
                    COUNT(*) AS total
             FROM (
                 SELECT o.user_id, COUNT(*) AS order_count_total
                 FROM orders o
                 JOIN status_orders s ON o.status_id = s.status_id
                 WHERE s.value = 'APROBADA' AND " . self::SALE . "
                   AND DATE(o.created_at) >= :date_from
                   AND DATE(o.created_at) <= :date_to
                 GROUP BY o.user_id
             ) sub",
            CustomerStatsModel::class,
            ['date_from' => $dateFrom, 'date_to' => $dateTo],
        );

        return $result !== false ? $result : new CustomerStatsModel();
    }

    /**
     * @return list<array{bucket: string, customer_count: int}>
     */
    public function customerFrequency(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT CASE WHEN o.order_count_total = 1 THEN '1'
                            WHEN o.order_count_total BETWEEN 2 AND 3 THEN '2-3'
                            WHEN o.order_count_total BETWEEN 4 AND 6 THEN '4-6'
                            ELSE '7+'
                        END AS bucket,
                        COUNT(*) AS customer_count
                 FROM (
                     SELECT user_id, COUNT(*) AS order_count_total
                     FROM orders o
                     JOIN status_orders s ON o.status_id = s.status_id
                     WHERE s.value = 'APROBADA' AND " . self::SALE . "
                       AND DATE(o.created_at) >= :date_from
                       AND DATE(o.created_at) <= :date_to
                     GROUP BY user_id
                 ) o
                 GROUP BY bucket
                 ORDER BY FIELD(bucket, '1', '2-3', '4-6', '7+')";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // PRODUCT PERFORMANCE
    // =========================================================================

    /**
     * @return array{data: list<array>, total: int, page: int, per_page: int}
     */
    public function productPerformance(string $dateFrom, string $dateTo, int $page = 1, int $perPage = 20): array
    {
        $baseSelect = 'p.sku, p.name AS product_name, p.category_name,
                       p.price, p.is_out_of_stock, p.is_active,
                       COALESCE(v.total_sold, 0) AS total_sold,
                       COALESCE(v.revenue, 0) AS revenue';
        $baseFrom = 'FROM ' . self::catalog() . ' p LEFT JOIN ' . self::approvedSalesByProduct() . ' v ON v.product_sku = p.sku';

        $countSql = "SELECT COUNT(*) {$baseFrom}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT {$baseSelect} {$baseFrom} ORDER BY revenue DESC LIMIT {$offset}, {$perPage}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{in_stock: int, out_of_stock: int, total: int}
     */
    public function stockStatus(): array
    {
        $stmt = $this->pdo->query(
            "SELECT SUM(CASE WHEN is_out_of_stock = 0 AND is_active = 1 THEN 1 ELSE 0 END) AS in_stock,
                    SUM(CASE WHEN is_out_of_stock = 1 AND is_active = 1 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS total
             FROM " . self::catalog() . ' p'
        );

        /** @var array{in_stock: int, out_of_stock: int, total: int} $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result;
    }

    // =========================================================================
    // DISCOUNT IMPACT
    // =========================================================================

    /**
     * @return array{with_discount_revenue: int, without_discount_revenue: int}
     */
    public function discountImpact(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT COALESCE(SUM(CASE WHEN oi.discount_percentage > 0 THEN oi.line_total ELSE 0 END), 0) AS with_discount_revenue,
                       COALESCE(SUM(CASE WHEN oi.discount_percentage = 0 THEN oi.line_total ELSE 0 END), 0) AS without_discount_revenue
                FROM orders_items oi
                JOIN orders o ON oi.order_id = o.order_id
                JOIN status_orders s ON o.status_id = s.status_id
                WHERE s.value = 'APROBADA' AND " . self::SALE . "
                  AND DATE(o.created_at) >= :date_from
                  AND DATE(o.created_at) <= :date_to";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);

        /** @var array{with_discount_revenue: int, without_discount_revenue: int} $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result;
    }

    // =========================================================================
    // CARRIER DISTRIBUTION
    // =========================================================================

    /**
     * @return list<array{carrier_name: string, order_count: int}>
     */
    public function carrierDistribution(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(o.carrier_name, 'Sin asignar') AS carrier_name, COUNT(*) AS order_count
             FROM orders o
             JOIN status_orders s ON o.status_id = s.status_id
             WHERE s.value = 'APROBADA'
               AND DATE(o.created_at) >= :date_from
               AND DATE(o.created_at) <= :date_to
             GROUP BY o.carrier_name
             ORDER BY order_count DESC"
        );
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // DOWNLOAD — ORDERS EXPORT
    // =========================================================================

    /**
     * @return list<array>
     */
    public function ordersDownload(string $dateFrom, string $dateTo, string $status = '', string $gateway = '', int $limit = 5000): array
    {
        $where = "WHERE s.value = 'APROBADA' AND " . self::SALE;
        $params = [];

        if ($dateFrom !== '') {
            $where .= ' AND DATE(o.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= ' AND DATE(o.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if ($status !== '') {
            $where .= ' AND s.value LIKE :status_val';
            $params['status_val'] = '%' . $status . '%';
        }
        if ($gateway !== '') {
            $where .= ' AND o.payment_gateway = :gateway';
            $params['gateway'] = $gateway;
        }

        $params['limit'] = $limit;
        $sql = "SELECT o.order_id, o.user_id, u.full_name, u.email, o.amount, o.order_type, o.redemption_points,
                       o.payment_gateway, s.value AS status, o.sap_order_code,
                       o.tracking_number, o.carrier_name, o.created_at,
                       COALESCE(c.name, '') AS city, COALESCE(c.department, '') AS department
                FROM orders o
                JOIN status_orders s ON o.status_id = s.status_id
                JOIN users u ON o.user_id = u.id
                LEFT JOIN addresses a ON o.address_id = a.id
                LEFT JOIN cities c ON a.city_id = c.id
                {$where}
                ORDER BY o.order_id DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array>
     */
    public function productsDownload(string $dateFrom, string $dateTo, int $limit = 5000): array
    {
        $sql = "SELECT p.sku, p.name, p.category_name, p.price,
                       p.is_out_of_stock, p.is_active,
                       COALESCE(v.total_sold, 0) AS total_sold,
                       COALESCE(v.revenue, 0) AS revenue
                FROM " . self::catalog() . " p
                LEFT JOIN " . self::approvedSalesByProduct() . " v ON v.product_sku = p.sku
                ORDER BY revenue DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array>
     */
    public function usersDownload(string $dateFrom, string $dateTo, string $q = '', int $limit = 5000): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if ($dateFrom !== '') {
            $where .= ' AND DATE(u.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo !== '') {
            $where .= ' AND DATE(u.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        if ($q !== '') {
            $where .= ' AND (u.full_name LIKE :q1 OR u.email LIKE :q2 OR u.document_id LIKE :q3)';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
            $params['q3'] = '%' . $q . '%';
        }

        $sql = "SELECT u.id, u.document_id, dt.name AS document_type_name,
                   u.full_name, u.num_cell, u.email, u.created_at, u.updated_at,
                   COALESCE(a.address_name, '') AS primary_address_name,
                   COALESCE(a.address, '') AS address,
                   COALESCE(c.name, '') AS city,
                   COALESCE(c.department, '') AS department,
                   COALESCE(l.name, '') AS locality
                FROM users u
                LEFT JOIN document_types dt ON u.document_type_id = dt.id
            LEFT JOIN address_user_map aum ON aum.user_id = u.id AND aum.is_main = 1
            LEFT JOIN addresses a ON a.id = aum.address_id
            LEFT JOIN cities c ON c.id = a.city_id
            LEFT JOIN address_locality_map alm ON alm.address_id = a.id
            LEFT JOIN localities l ON l.id = alm.locality_id
                {$where}
                ORDER BY u.id DESC
                LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // ORDERS WITH AGENTS
    // =========================================================================

    /**
     * Órdenes aprobadas con sap_order_code poblado, unidas con tb_empleados.
     *
     * @return list<array>
     */
    public function approvedOrdersWithAgents(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT o.order_id, o.user_id, u.full_name AS user_full_name, u.email AS user_email,
                       o.amount, o.order_type, o.redemption_points, o.payment_gateway, o.sap_order_code,
                       o.tracking_number, o.carrier_name, s.value AS status_value, o.created_at,
                       o.agent_id, e.usuario AS agent_usuario, e.email AS agent_email,
                       e.cargo AS agent_cargo, e.departamento AS agent_departamento,
                       e.foto AS agent_foto
                FROM orders o
                JOIN status_orders s ON o.status_id = s.status_id
                JOIN users u ON o.user_id = u.id
                LEFT JOIN " . IntranetTables::fromEnv()->employees . " e ON o.agent_id = e.id_usuario
                WHERE s.value = 'APROBADA'
                  AND o.sap_order_code IS NOT NULL
                  AND o.sap_order_code > 0
                  AND DATE(o.created_at) >= :date_from
                  AND DATE(o.created_at) <= :date_to
                ORDER BY o.order_id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // USERS
    // =========================================================================

    /**
     * @return array{data: list<array>, total: int, page: int, per_page: int}
     */
    public function usersList(int $page = 1, int $perPage = 20, string $q = ''): array
    {
        $where  = '';
        $params = [];

        if ($q !== '') {
            $where   = 'WHERE u.full_name LIKE :q1 OR u.email LIKE :q2 OR u.document_id LIKE :q3';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
            $params['q3'] = '%' . $q . '%';
        }

        $countSql = "SELECT COUNT(*) FROM users u {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT u.id, u.document_id, dt.name AS document_type_name, u.full_name,
                       u.num_cell, u.email, u.created_at, u.updated_at
                FROM users u
                LEFT JOIN document_types dt ON u.document_type_id = dt.id
                {$where}
                ORDER BY u.id DESC
                LIMIT {$offset}, {$perPage}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
