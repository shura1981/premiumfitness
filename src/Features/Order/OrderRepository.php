<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;
use PDO;
use PDOException;

/**
 * Repositorio de la feature Orders.
 *
 * Flujo soportado:
 *  1. `create()` inserta la orden con los campos iniciales del carrito
 *     (`user_id`, `amount`) + sus `orders_items` en una transacción.
 *  2. `markAsPaid()` se llama desde el webhook de la pasarela: actualiza
 *     `payment_state_json` y `status_id`.
 *  3. `updateSapOrderCode()` y `updateTrackingNumber()` son setters puntuales
 *     para sincronización con SAP y con el courier.
 */
class OrderRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    private static function selectListBase(): string
    {
        $paymentMethod = PaymentMethod::SQL;

        return <<<SQL
        SELECT o.order_id, o.user_id, o.agent_id, o.amount, o.order_type, o.redemption_points,
               {$paymentMethod} AS payment_method, o.payment_gateway, o.payment_state_json,
               o.sap_order_code, o.tracking_number, o.carrier_name,
               o.status_id, o.address_id, o.order_notes,
               o.created_at, o.updated_at, o.approved_at,
               s.value AS status_value,
               u.full_name AS user_full_name,
               u.email AS user_email,
               u.num_cell AS user_num_cell,
               u.document_id AS user_document_id,
               u.document_type_id AS user_document_type_id,
               dt.name AS user_document_type_name,
               a.address AS delivery_address,
               a.address_name AS delivery_address_name,
               c.id AS delivery_city_id,
               c.name AS delivery_city_name,
               c.department AS delivery_department,
               c.city_dept AS delivery_city_dept,
               c.dan_code AS delivery_dan_code,
               alm.locality_id AS delivery_locality_id,
               l.name AS delivery_locality_name
        FROM orders o
        LEFT JOIN status_orders s ON s.status_id = o.status_id
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN document_types dt ON dt.id = u.document_type_id
        LEFT JOIN addresses a ON a.id = o.address_id
        LEFT JOIN cities c ON c.id = a.city_id
        LEFT JOIN address_locality_map alm ON alm.address_id = a.id
        LEFT JOIN localities l ON l.id = alm.locality_id
        SQL;
    }

    private static function selectDetailBase(): string
    {
        $employees     = IntranetTables::fromEnv()->employees;
        $paymentMethod = PaymentMethod::SQL;

        return <<<SQL
        SELECT o.order_id, o.user_id, o.agent_id, o.amount, o.order_type, o.redemption_points,
               {$paymentMethod} AS payment_method, o.payment_gateway, o.payment_state_json,
               o.sap_order_code, o.tracking_number, o.carrier_name,
               o.status_id, o.address_id, o.order_notes,
               o.created_at, o.updated_at, o.approved_at,
               s.value AS status_value,
               u.full_name AS user_full_name,
               u.email AS user_email,
               u.num_cell AS user_num_cell,
               u.document_id AS user_document_id,
               u.document_type_id AS user_document_type_id,
               dt.name AS user_document_type_name,
               a.address AS delivery_address,
               a.address_name AS delivery_address_name,
               c.id AS delivery_city_id,
               c.name AS delivery_city_name,
               c.department AS delivery_department,
               c.city_dept AS delivery_city_dept,
               c.dan_code AS delivery_dan_code,
               alm.locality_id AS delivery_locality_id,
               l.name AS delivery_locality_name,
               e.usuario AS agent_name,
               e.foto AS agent_photo,
               e.email AS agent_email,
               e.celular AS agent_cellphone,
               e.cargo AS agent_role,
               e.departamento AS agent_department
        FROM orders o
        LEFT JOIN status_orders s ON s.status_id = o.status_id
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN document_types dt ON dt.id = u.document_type_id
        LEFT JOIN addresses a ON a.id = o.address_id
        LEFT JOIN cities c ON c.id = a.city_id
        LEFT JOIN address_locality_map alm ON alm.address_id = a.id
        LEFT JOIN localities l ON l.id = alm.locality_id
        LEFT JOIN {$employees} e ON e.id_usuario = o.agent_id
        SQL;
    }

    /**
     * Crea la orden + sus items en una sola transacción.
     *
     * @param array{
     *     user_id: int,
     *     amount: int|float|string,
     *     payment_gateway: 'bold'|'epayco'|'addi'|'sistecredito',
     *     agent_id?: int|null,
     *     status_id?: int|null,
     *     address_id?: int|null,
     *     order_notes?: string|null,
     *     items: list<array{
     *         product_sku: int,
     *         product_full_price: int|float|string,
     *         sap_code?: int|null,
     *         quantity: int,
     *         unit_price: int|float|string,
     *         discount_percentage?: int|float|string,
     *         line_total: int|float|string
     *     }>
     * } $data
     *
     * @throws \InvalidArgumentException Si no hay items.
     */
    public function create(array $data): int
    {
        if (empty($data['items'])) {
            throw new \InvalidArgumentException('Order must contain at least one item.');
        }

        $startedTransaction = !$this->pdo->inTransaction();

        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO orders (user_id, amount, payment_gateway, agent_id, status_id, address_id, order_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['user_id'],
                $data['amount'],
                $data['payment_gateway'],
                $data['agent_id'] ?? null,
                $data['status_id'] ?? null,
                $data['address_id'] ?? null,
                $data['order_notes'] ?? null,
            ]);

            $orderId = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO orders_items
                    (order_id, product_sku, product_full_price, sap_code, quantity, unit_price, discount_percentage, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($data['items'] as $item) {
                $itemStmt->execute([
                    $orderId,
                    $item['product_sku'],
                    $item['product_full_price'],
                    $item['sap_code'] ?? null,
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount_percentage'] ?? 0,
                    $item['line_total'],
                ]);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $orderId;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** Pedido con ese id de pago (`orders.payment_code`, P-24); null si no es de PremiumFitness o no existe. */
    public function findOrderIdByPaymentCode(string $paymentCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT order_id FROM orders WHERE payment_code = ?');
        $stmt->execute([trim($paymentCode)]);
        $orderId = $stmt->fetchColumn();

        return $orderId === false ? null : (int) $orderId;
    }

    public function findById(int $orderId): OrderModel|false
    {
        /** @var OrderModel|false $order */
        $order = $this->fetchOne(
            self::selectDetailBase() . ' WHERE o.order_id = ?',
            OrderModel::class,
            [$orderId]
        );

        if ($order === false) {
            return false;
        }

        $order->items = $this->findItemsByOrderId($orderId);

        return $order;
    }

    /**
     * @return array{data: list<OrderModel>, total: int, page: int, per_page: int, total_pages: int, next_page: int|null}
     */
    /**
     * Lista todas las órdenes con filtros opcionales.
     *
        * @param array{
        *     status?: string,
        *     status_id?: int,
        *     sap_pending?: int,
        *     q?: string,
        *     date_from?: string,
        *     date_to?: string,
        *     sort_order?: 'ASC'|'DESC',
        *     sort_by?: string
        * } $filters
     * @return array{data: list<OrderModel>, total: int, page: int, per_page: int, total_pages: int, next_page: int|null}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $where  = 'WHERE 1=1';
        $params = [];

        $countWhere  = 'WHERE 1=1';
        $countParams = [];

        // Filtro por estado (match parcial case-insensitive)
        if (!empty($filters['status'])) {
            $where        .= ' AND s.value LIKE ?';
            $params[]      = '%' . $filters['status'] . '%';
            $countWhere   .= ' AND status_id IN (SELECT status_id FROM status_orders WHERE value LIKE ?)';
            $countParams[] = '%' . $filters['status'] . '%';
        }

        // Filtro por status_id exacto
        if (isset($filters['status_id'])) {
            $where .= ' AND o.status_id = ?';
            $params[] = (int) $filters['status_id'];
            $countWhere .= ' AND status_id = ?';
            $countParams[] = (int) $filters['status_id'];
        }

        // Filtro operativo: pedidos sin procesar en SAP (NULL o 0).
        if (isset($filters['sap_pending']) && (int) $filters['sap_pending'] === 1) {
            $where .= ' AND (o.sap_order_code IS NULL OR o.sap_order_code = 0)';
            $countWhere .= ' AND (sap_order_code IS NULL OR sap_order_code = 0)';
        }

        // Búsqueda libre (id, cliente, documento, email, guía, SAP, dirección, ciudad, departamento)
        if (!empty($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $term = '%' . trim($filters['q']) . '%';

            $where .= ' AND (
                CAST(o.order_id AS CHAR) LIKE ?
                OR u.full_name LIKE ?
                OR u.document_id LIKE ?
                OR u.email LIKE ?
                OR o.tracking_number LIKE ?
                OR CAST(o.sap_order_code AS CHAR) LIKE ?
                OR a.address LIKE ?
                OR c.name LIKE ?
                OR c.department LIKE ?
            )';
            $params = [...$params, $term, $term, $term, $term, $term, $term, $term, $term, $term];

            $countWhere .= ' AND (
                CAST(order_id AS CHAR) LIKE ?
                OR tracking_number LIKE ?
                OR CAST(sap_order_code AS CHAR) LIKE ?
                OR user_id IN (
                    SELECT id
                    FROM users
                    WHERE full_name LIKE ? OR document_id LIKE ? OR email LIKE ?
                )
                OR address_id IN (
                    SELECT a.id
                    FROM addresses a
                    JOIN cities c ON c.id = a.city_id
                    WHERE a.address LIKE ? OR c.name LIKE ? OR c.department LIKE ?
                )
            )';
            $countParams = [...$countParams, $term, $term, $term, $term, $term, $term, $term, $term, $term];
        }

        // Filtro por fecha desde
        if (!empty($filters['date_from'])) {
            $where        .= ' AND DATE(o.created_at) >= ?';
            $params[]      = $filters['date_from'];
            $countWhere   .= ' AND DATE(created_at) >= ?';
            $countParams[] = $filters['date_from'];
        }

        // Filtro por fecha hasta
        if (!empty($filters['date_to'])) {
            $where        .= ' AND DATE(o.created_at) <= ?';
            $params[]      = $filters['date_to'];
            $countWhere   .= ' AND DATE(created_at) <= ?';
            $countParams[] = $filters['date_to'];
        }

        // Dirección: por defecto DESC
        $sortOrder = 'DESC';
        if (!empty($filters['sort_order']) && strtoupper($filters['sort_order']) === 'ASC') {
            $sortOrder = 'ASC';
        }

        // Columna de ordenamiento: validar contra whitelist
        $sortColumn = self::SORT_COLUMNS[$filters['sort_by'] ?? 'order_id'] ?? 'o.order_id';

        $sql = self::selectListBase() . " {$where} ORDER BY {$sortColumn} {$sortOrder}";

        return $this->paginated($sql, $params, $page, $perPage, 'orders', $countWhere, $countParams);
    }

    /**
     * Lista para reportes operativos: solo pedidos aprobados y procesados en SAP,
     * incluyendo datos completos del agente desde SELECT_DETAIL_BASE.
     *
     * @param array{
     *     status_id?: int,
     *     q?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     sort_order?: 'ASC'|'DESC',
     *     sort_by?: string
     * } $filters
     * @return array{data: list<OrderModel>, total: int, page: int, per_page: int, total_pages: int, next_page: int|null}
     */
    public function findProcessedForReports(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $where = "WHERE s.value = 'APROBADA' AND o.sap_order_code IS NOT NULL AND o.sap_order_code > 0";
        $params = [];

        $countWhere = "WHERE status_id IN (SELECT status_id FROM status_orders WHERE value = 'APROBADA')
            AND sap_order_code IS NOT NULL
            AND sap_order_code > 0";
        $countParams = [];

        if (isset($filters['status_id'])) {
            $where .= ' AND o.status_id = ?';
            $params[] = (int) $filters['status_id'];
            $countWhere .= ' AND status_id = ?';
            $countParams[] = (int) $filters['status_id'];
        }

        if (!empty($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $term = '%' . trim($filters['q']) . '%';

            $where .= ' AND (
                CAST(o.order_id AS CHAR) LIKE ?
                OR u.full_name LIKE ?
                OR u.document_id LIKE ?
                OR u.email LIKE ?
                OR o.tracking_number LIKE ?
                OR CAST(o.sap_order_code AS CHAR) LIKE ?
                OR a.address LIKE ?
                OR c.name LIKE ?
                OR c.department LIKE ?
                OR e.usuario LIKE ?
                OR e.email LIKE ?
            )';
            $params = [...$params, $term, $term, $term, $term, $term, $term, $term, $term, $term, $term, $term];

            $countWhere .= ' AND (
                CAST(order_id AS CHAR) LIKE ?
                OR tracking_number LIKE ?
                OR CAST(sap_order_code AS CHAR) LIKE ?
                OR user_id IN (
                    SELECT id
                    FROM users
                    WHERE full_name LIKE ? OR document_id LIKE ? OR email LIKE ?
                )
                OR address_id IN (
                    SELECT a.id
                    FROM addresses a
                    JOIN cities c ON c.id = a.city_id
                    WHERE a.address LIKE ? OR c.name LIKE ? OR c.department LIKE ?
                )
                OR agent_id IN (
                    SELECT id_usuario
                    FROM ' . IntranetTables::fromEnv()->employees . '
                    WHERE usuario LIKE ? OR email LIKE ?
                )
            )';
            $countParams = [...$countParams, $term, $term, $term, $term, $term, $term, $term, $term, $term, $term, $term];
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND DATE(o.created_at) >= ?';
            $params[] = $filters['date_from'];
            $countWhere .= ' AND DATE(created_at) >= ?';
            $countParams[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND DATE(o.created_at) <= ?';
            $params[] = $filters['date_to'];
            $countWhere .= ' AND DATE(created_at) <= ?';
            $countParams[] = $filters['date_to'];
        }

        $sortOrder = 'DESC';
        if (!empty($filters['sort_order']) && strtoupper($filters['sort_order']) === 'ASC') {
            $sortOrder = 'ASC';
        }

        $sortColumn = self::SORT_COLUMNS[$filters['sort_by'] ?? 'order_id'] ?? 'o.order_id';

        $sql = self::selectDetailBase() . " {$where} ORDER BY {$sortColumn} {$sortOrder}";

        return $this->paginated($sql, $params, $page, $perPage, 'orders', $countWhere, $countParams);
    }

    /**
     * Exporta filas de reporte (una fila por producto) sin paginación.
     *
     * @param array{
     *     status_id?: int,
     *     q?: string,
     *     date_from?: string,
     *     date_to?: string
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function exportProcessedRowsForReports(array $filters = []): array
    {
        $where = "WHERE s.value = 'APROBADA' AND o.sap_order_code IS NOT NULL AND o.sap_order_code > 0";
        $params = [];

        if (isset($filters['status_id'])) {
            $where .= ' AND o.status_id = ?';
            $params[] = (int) $filters['status_id'];
        }

        if (!empty($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $term = '%' . trim($filters['q']) . '%';

            $where .= ' AND (
                CAST(o.order_id AS CHAR) LIKE ?
                OR u.full_name LIKE ?
                OR u.document_id LIKE ?
                OR u.email LIKE ?
                OR o.tracking_number LIKE ?
                OR CAST(o.sap_order_code AS CHAR) LIKE ?
                OR a.address LIKE ?
                OR c.name LIKE ?
                OR c.department LIKE ?
                OR e.usuario LIKE ?
                OR e.email LIKE ?
                OR oi.product_name LIKE ?
                OR CAST(oi.product_sku AS CHAR) LIKE ?
            )';
            $params = [
                ...$params,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
                $term,
            ];
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND DATE(o.created_at) >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND DATE(o.created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        $sql = "SELECT
                    o.order_id AS pedido_id,
                    CASE
                        WHEN oi.item_id IS NULL THEN 'Sin productos'
                        WHEN oi.product_name = '' THEN CONCAT('SKU ', oi.product_sku)
                        ELSE oi.product_name
                    END AS producto,
                    oi.product_sku AS sku_producto,
                    oi.quantity AS cantidad,
                    ROUND(oi.unit_price) AS valor_unitario,
                    ROUND(oi.discount_percentage) AS descuento_porcentaje,
                    ROUND(oi.line_total) AS valor_linea,
                    u.full_name AS cliente,
                    u.email AS email_cliente,
                    e.usuario AS agente,
                    a.address AS direccion_entrega,
                    a.address_name AS direccion_alias,
                    c.name AS ciudad,
                    c.department AS departamento,
                    ROUND(o.amount) AS total_pedido,
                    " . PaymentMethod::SQL . " AS forma_pago,
                    oi.redemption_points AS puntos_linea,
                    o.redemption_points AS puntos_pedido,
                    s.value AS estado,
                    ROUND(o.sap_order_code) AS sap_order_code,
                    o.tracking_number AS tracking_number,
                    DATE_FORMAT(o.created_at, '%Y-%m-%d') AS fecha_creacion,
                    TIME_FORMAT(o.created_at, '%H:%i:%s') AS hora_creacion
                FROM orders o
                LEFT JOIN status_orders s ON s.status_id = o.status_id
                LEFT JOIN users u ON u.id = o.user_id
                LEFT JOIN addresses a ON a.id = o.address_id
                LEFT JOIN cities c ON c.id = a.city_id
                LEFT JOIN " . IntranetTables::fromEnv()->employees . " e ON e.id_usuario = o.agent_id
                LEFT JOIN orders_items oi ON oi.order_id = o.order_id
                {$where}
                ORDER BY o.order_id DESC, oi.item_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @var array<string, string> Columnas permitidas para ordenar (whitelist). */
    private const array SORT_COLUMNS = [
        'order_id'        => 'o.order_id',
        'created_at'      => 'o.created_at',
        'amount'          => 'o.amount',
        'payment_gateway' => 'o.payment_gateway',
        'status'          => 's.value',
    ];

    /**
     * Lista órdenes de un usuario con filtros opcionales.
     *
        * @param array{
        *     status?: string,
        *     status_id?: int,
        *     sap_pending?: int,
        *     q?: string,
        *     date_from?: string,
        *     date_to?: string,
        *     sort_order?: 'ASC'|'DESC',
        *     sort_by?: string
        * } $filters
     * @return array{data: list<OrderModel>, total: int, page: int, per_page: int, total_pages: int, next_page: int|null}
     */
    public function findAllByUser(int $userId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $where  = 'WHERE o.user_id = ?';
        $params = [$userId];

        $countWhere  = 'WHERE user_id = ?';
        $countParams = [$userId];

        // Filtro por estado (match parcial case-insensitive)
        if (!empty($filters['status'])) {
            $where       .= ' AND s.value LIKE ?';
            $params[]     = '%' . $filters['status'] . '%';
            $countWhere  .= ' AND status_id IN (SELECT status_id FROM status_orders WHERE value LIKE ?)';
            $countParams[] = '%' . $filters['status'] . '%';
        }

        // Filtro por status_id exacto
        if (isset($filters['status_id'])) {
            $where .= ' AND o.status_id = ?';
            $params[] = (int) $filters['status_id'];
            $countWhere .= ' AND status_id = ?';
            $countParams[] = (int) $filters['status_id'];
        }

        // Filtro operativo: pedidos sin procesar en SAP (NULL o 0).
        if (isset($filters['sap_pending']) && (int) $filters['sap_pending'] === 1) {
            $where .= ' AND (o.sap_order_code IS NULL OR o.sap_order_code = 0)';
            $countWhere .= ' AND (sap_order_code IS NULL OR sap_order_code = 0)';
        }

        // Búsqueda libre en pedidos del usuario
        if (!empty($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $term = '%' . trim($filters['q']) . '%';

            $where .= ' AND (
                CAST(o.order_id AS CHAR) LIKE ?
                OR o.tracking_number LIKE ?
                OR CAST(o.sap_order_code AS CHAR) LIKE ?
                OR a.address LIKE ?
                OR c.name LIKE ?
                OR c.department LIKE ?
            )';
            $params = [...$params, $term, $term, $term, $term, $term, $term];

            $countWhere .= ' AND (
                CAST(order_id AS CHAR) LIKE ?
                OR tracking_number LIKE ?
                OR CAST(sap_order_code AS CHAR) LIKE ?
                OR address_id IN (
                    SELECT a.id
                    FROM addresses a
                    JOIN cities c ON c.id = a.city_id
                    WHERE a.address LIKE ? OR c.name LIKE ? OR c.department LIKE ?
                )
            )';
            $countParams = [...$countParams, $term, $term, $term, $term, $term, $term];
        }

        // Filtro por fecha desde
        if (!empty($filters['date_from'])) {
            $where       .= ' AND DATE(o.created_at) >= ?';
            $params[]     = $filters['date_from'];
            $countWhere  .= ' AND DATE(created_at) >= ?';
            $countParams[] = $filters['date_from'];
        }

        // Filtro por fecha hasta
        if (!empty($filters['date_to'])) {
            $where       .= ' AND DATE(o.created_at) <= ?';
            $params[]     = $filters['date_to'];
            $countWhere  .= ' AND DATE(created_at) <= ?';
            $countParams[] = $filters['date_to'];
        }

        // Dirección: por defecto DESC
        $sortOrder = 'DESC';
        if (!empty($filters['sort_order']) && strtoupper($filters['sort_order']) === 'ASC') {
            $sortOrder = 'ASC';
        }

        // Columna de ordenamiento: validar contra whitelist
        $sortColumn = self::SORT_COLUMNS[$filters['sort_by'] ?? 'order_id'] ?? 'o.order_id';

        $sql = self::selectDetailBase() . " {$where} ORDER BY {$sortColumn} {$sortOrder}";

        return $this->paginated(
            $sql,
            $params,
            $page,
            $perPage,
            'orders',
            $countWhere,
            $countParams
        );
    }

    /**
     * Detalle completo de una orden de un usuario específico.
     * Incluye los items con nombre del producto.
     */
    public function findByIdAndUser(int $orderId, int $userId): OrderModel|false
    {
        /** @var OrderModel|false $order */
        $order = $this->fetchOne(
            self::selectDetailBase() . ' WHERE o.order_id = ? AND o.user_id = ?',
            OrderModel::class,
            [$orderId, $userId]
        );

        if ($order === false) {
            return false;
        }

        $order->items = $this->findItemsByOrderId($orderId);

        return $order;
    }

    /** @return list<OrderItemModel> */
    public function findItemsByOrderId(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT i.item_id, i.order_id, i.product_sku, i.product_full_price, i.sap_code, i.quantity,
                    i.unit_price, i.discount_percentage, i.line_total,
                    i.product_name, i.tax_rate, i.tax_base, i.tax_amount,
                    i.commission_percentage, i.commission_amount, i.redemption_points,
                    p.presentation AS product_presentation,
                    p.ruta AS product_slug,
                    p.category AS product_category_name
             FROM orders_items i
             LEFT JOIN ' . IntranetTables::fromEnv()->products . ' p ON p.codigo = i.product_sku
             WHERE i.order_id = ?
             ORDER BY i.item_id',
            OrderItemModel::class,
            [$orderId]
        );
    }

    /**
     * Webhook de pasarela: actualiza estado de pago + status_id.
     *
     * @param array<string, mixed>|string $paymentState Objeto JSON o string JSON ya serializado.
     */
    public function markPaymentState(int $orderId, array|string $paymentState, int $statusId): bool
    {
        $json = is_string($paymentState)
            ? $paymentState
            : json_encode($paymentState, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->execute(
            'UPDATE orders SET payment_state_json = ?, status_id = ? WHERE order_id = ?',
            [$json, $statusId, $orderId]
        );
    }

    public function updateSapOrderCode(int $orderId, int $sapOrderCode): bool
    {
        return $this->execute(
            'UPDATE orders SET sap_order_code = ? WHERE order_id = ?',
            [$sapOrderCode, $orderId]
        );
    }

    /**
     * Actualiza el código SAP y asegura la asignación del agente que procesa.
     * Solo permite actualizar cuando la orden está libre o ya pertenece al mismo agente.
     */
    public function updateSapOrderCodeByAgent(int $orderId, int $sapOrderCode, int $agentId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders
             SET sap_order_code = ?, agent_id = ?
             WHERE order_id = ? AND (agent_id IS NULL OR agent_id = ?)'
        );
        $stmt->execute([$sapOrderCode, $agentId, $orderId, $agentId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Actualiza el tracking junto con el carrier (obligatorio).
     * El carrier_name solo existe cuando hay guia; ambos se setean juntos.
     *
     * @param 'SERVIENTREGA'|'MELONN'|'ENVIA' $carrierName
     */
    public function updateTrackingNumber(int $orderId, string $trackingNumber, string $carrierName): bool
    {
        return $this->execute(
            'UPDATE orders SET tracking_number = ?, carrier_name = ? WHERE order_id = ?',
            [$trackingNumber, $carrierName, $orderId]
        );
    }

    public function updateStatus(int $orderId, int $statusId): bool
    {
        return $this->execute(
            'UPDATE orders SET status_id = ? WHERE order_id = ?',
            [$statusId, $orderId]
        );
    }

    public function updateAgentId(int $orderId, int $agentId): bool
    {
        return $this->execute(
            'UPDATE orders SET agent_id = ? WHERE order_id = ?',
            [$agentId, $orderId]
        );
    }

    /**
     * Asigna el agente solo si la orden sigue sin tomar.
     * Evita sobrescribir la asignación de otro agente en condiciones de carrera.
     */
    public function updateAgentIdIfUnassigned(int $orderId, int $agentId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET agent_id = ? WHERE order_id = ? AND agent_id IS NULL'
        );
        $stmt->execute([$agentId, $orderId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Libera la orden únicamente si pertenece al agente indicado
     * y todavía no se ha procesado en SAP.
     */
    public function releaseAgentIdIfOwnerAndPending(int $orderId, int $agentId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET agent_id = NULL
             WHERE order_id = ? AND agent_id = ? AND sap_order_code IS NULL'
        );
        $stmt->execute([$orderId, $agentId]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $orderId): bool
    {
        return $this->execute('DELETE FROM orders WHERE order_id = ?', [$orderId]);
    }

    /**
     * Helper de paginación.
     *
     * @param list<mixed> $params
     * @param list<mixed> $countParams
     * @return array{data: list<OrderModel>, total: int, page: int, per_page: int, total_pages: int, next_page: int|null}
     */
    private function paginated(
        string $sql,
        array $params,
        int $page,
        int $perPage,
        string $table,
        string $countWhere = '',
        array $countParams = []
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM {$table} " . $countWhere;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare($sql . ' LIMIT ? OFFSET ?');
        $bindings = [...$params, $perPage, $offset];
        $stmt->execute($bindings);
        /** @var list<OrderModel> $items */
        $items = $stmt->fetchAll(PDO::FETCH_CLASS, OrderModel::class);

        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

        return [
            'data'        => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'next_page'   => $page < $totalPages ? $page + 1 : null,
        ];
    }
}
