<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\DatabaseConfig;
use PDO;

/**
 * Real INSERT helpers. Every helper uses unique values (uniqid) to avoid
 * collisions across runs. Tests are self-contained; there is no cleanup
 * strategy (user restores the DB from backup afterwards).
 */
final class Seed
{
    public static function pdo(): PDO
    {
        // Ensure DB is booted even if AppFactory wasn't used.
        DB::boot(DatabaseConfig::fromEnv());

        return DB::getInstance();
    }

    public static function user(): int
    {
        $pdo  = self::pdo();
        $suffix = uniqid('', true);

        $stmt = $pdo->prepare(
            "INSERT INTO users
                (document_id, document_type_id, full_name, num_cell, email, password, role, status, phone_verified_at)
             VALUES (?, 1, ?, ?, ?, ?, 'client', 'active', NOW())"
        );
        $stmt->execute([
            'T' . random_int(100_000_000, 999_999_999) . random_int(1000, 9999),
            'Test User ' . $suffix,
            self::phone(),
            'test_' . $suffix . '@example.com',
            self::passwordHash(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Contraseña de todas las cuentas de prueba. */
    public const PASSWORD = 'test1234';

    /** Celular válido que no se repite (el celular es único). */
    public static function phone(): string
    {
        return '3' . str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
    }

    /** El hash se calcula una vez: Argon2 es lento a propósito. */
    public static function passwordHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(self::PASSWORD, PASSWORD_ARGON2ID);
    }

    /** Celular de un usuario creado con `user()` o `trainer()`. */
    public static function phoneOf(int $userId): string
    {
        $stmt = self::pdo()->prepare('SELECT num_cell FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Pedido pendiente de pago. Sin `$items` lleva un producto real del catálogo local de la intranet
     * (tests/fixtures/intranet-pruebas.sql).
     *
     * @param list<array<string, mixed>> $items
     */
    public static function order(int $userId, array $items = [], ?float $amount = null, string $paymentGateway = 'bold'): int
    {
        $pdo = self::pdo();

        if ($items === []) {
            $items = [[
                'product_sku' => 100507,
                'product_name' => 'BIPRO CLASSIC VAINILLA 2LB',
                'quantity' => 1,
                'unit_price' => 249000,
                'line_total' => 249000,
            ]];
        }

        $amount ??= array_reduce($items, static fn (float $total, array $item): float => $total + $item['line_total'], 0.0);

        $pdo->prepare('INSERT INTO orders (user_id, amount, payment_gateway) VALUES (?, ?, ?)')
            ->execute([$userId, $amount, $paymentGateway]);

        $orderId = (int) $pdo->lastInsertId();
        // Como el checkout: el id de pago es el número del pedido con el prefijo (P-24).
        $pdo->prepare('UPDATE orders SET payment_code = ? WHERE order_id = ?')
            ->execute([\App\Features\PaymentGateway\PaymentCode::forOrder($orderId), $orderId]);
        $stmt    = $pdo->prepare(
            'INSERT INTO orders_items
                (order_id, product_sku, product_name, product_full_price, sap_code, quantity, unit_price, discount_percentage, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($items as $it) {
            $stmt->execute([
                $orderId,
                $it['product_sku'],
                $it['product_name'] ?? 'Producto ' . $it['product_sku'],
                $it['product_full_price'] ?? $it['unit_price'],
                $it['sap_code'] ?? null,
                $it['quantity'],
                $it['unit_price'],
                $it['discount_percentage'] ?? 0,
                $it['line_total'],
            ]);
        }

        return $orderId;
    }

    /**
     * Creates an order and marks it with the canonical PaymentStatus::TEXT_APROBADA
     * value used by the PaymentGateway pipeline. Useful to test idempotency
     * (status APROBADA cannot be overridden by later events).
     */
    public static function approvedOrderWithCanonicalApprobada(int $userId): int
    {
        $pdo = self::pdo();
        $orderId = self::order($userId);

        $stmt = $pdo->prepare(
            "SELECT status_id FROM status_orders WHERE value = 'APROBADA' LIMIT 1"
        );
        $stmt->execute();
        $statusId = $stmt->fetchColumn();

        if ($statusId === false) {
            $pdo->prepare("INSERT INTO status_orders (value) VALUES ('APROBADA')")->execute();
            $statusId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE orders SET status_id = ? WHERE order_id = ?')
            ->execute([$statusId, $orderId]);

        return $orderId;
    }

    /**
     * Creates an order and immediately marks it as APPROVED.
     * `created_at` is forced to NOW() + 1 second to guarantee it falls
     * strictly after any credit balance created within the same second.
     *
     * Useful for testing credit balance applicability rules.
     */
    public static function approvedOrder(int $userId): int
    {
        $pdo = self::pdo();

        $orderId = self::order($userId);

        // Resolve (or create) the APPROVED status_id.
        $stmt = $pdo->prepare(
            "SELECT status_id FROM status_orders WHERE value = 'APPROVED' LIMIT 1"
        );
        $stmt->execute();
        $statusId = $stmt->fetchColumn();

        if ($statusId === false) {
            $pdo->prepare("INSERT INTO status_orders (value) VALUES ('APPROVED')")->execute();
            $statusId = (int) $pdo->lastInsertId();
        }

        // Add 1 second so the order is strictly after any balance created in the same second.
        $pdo->prepare(
            'UPDATE orders
             SET status_id = ?, created_at = DATE_ADD(NOW(), INTERVAL 1 SECOND)
             WHERE order_id = ?'
        )->execute([$statusId, $orderId]);

        return $orderId;
    }

    /**
     * Entrenador activo: usuario con rol `trainer` y su fila en `trainers`, con un cupón único.
     */
    public static function trainer(): int
    {
        $pdo    = self::pdo();
        $userId = self::user();

        $pdo->prepare("UPDATE users SET role = 'trainer' WHERE id = ?")->execute([$userId]);
        $pdo->prepare('INSERT INTO trainers (user_id, coupon_code) VALUES (?, ?)')
            ->execute([$userId, 'T' . strtoupper(bin2hex(random_bytes(6)))]);

        return $userId;
    }

    /**
     * Lote de puntos disponible de un entrenador. Devuelve su id.
     */
    public static function pointLot(
        int $trainerId,
        int $points,
        string $source = 'client_sale',
        string $expiresAt = '+6 months',
        string $accruedAt = 'now',
    ): int {
        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO point_lots (trainer_id, source, initial_points, remaining_points, accrued_at, expires_at, source_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $trainerId,
            $source,
            $points,
            $points,
            date('Y-m-d H:i:s', (int) strtotime($accruedAt)),
            date('Y-m-d H:i:s', (int) strtotime($expiresAt)),
            'test:' . bin2hex(random_bytes(12)),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Conexión a la intranet de las pruebas (`crisenri_intranet_pruebas`), donde crean sus productos. */
    public static function intranetPdo(): PDO
    {
        static $pdo = null;

        return $pdo ??= TestIntranet::connect(TestIntranet::DATABASE);
    }

    /**
     * Producto en la tabla de la intranet (copia local). Por defecto se vende en la app:
     * activo, sin promoción, con precio. Devuelve su código.
     *
     * @param array<string, mixed> $overrides Columnas de tb_productos_megaplexstars.
     */
    public static function catalogProduct(array $overrides = []): int
    {
        $code = $overrides['codigo'] ?? random_int(800_000_000, 899_999_999);
        $row  = $overrides + [
            'codigo'       => $code,
            'orden'        => 9000,
            'producto'     => 'PRODUCTO DE PRUEBA ' . $code,
            'valor'        => 100000,
            'category'     => 'PRUEBAS',
            'presentation' => '1 lb. Prueba',
            'grupo'        => '',
            'sap_code'     => $code,
            'sap_name'     => 'Artículo SAP ' . $code,
            'activo'       => 1,
            'promo'        => 0,
            'agotado'      => 0,
        ];

        $columns = implode(', ', array_map(static fn (string $column): string => "`{$column}`", array_keys($row)));
        $marks   = implode(', ', array_fill(0, count($row), '?'));

        self::executeTyped(
            self::intranetPdo()->prepare("INSERT INTO tb_productos_megaplexstars ({$columns}) VALUES ({$marks})"),
            array_values($row),
        );

        return (int) $code;
    }

    /**
     * Enlaza los enteros como enteros: una columna bit(1) no acepta el texto "1".
     *
     * @param list<mixed> $values
     */
    private static function executeTyped(\PDOStatement $stmt, array $values): void
    {
        foreach ($values as $index => $value) {
            $type = match (true) {
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                $value === null                 => PDO::PARAM_NULL,
                default                         => PDO::PARAM_STR,
            };
            $stmt->bindValue($index + 1, is_bool($value) ? (int) $value : $value, $type);
        }

        $stmt->execute();
    }

    /** Cambia columnas de un producto de prueba (precio, agotado, promo…). */
    public static function updateCatalogProduct(int $code, array $changes): void
    {
        $set = implode(', ', array_map(static fn (string $column): string => "`{$column}` = ?", array_keys($changes)));

        self::executeTyped(
            self::intranetPdo()->prepare("UPDATE tb_productos_megaplexstars SET {$set} WHERE codigo = ?"),
            [...array_values($changes), $code],
        );
    }

    /** Asocia un cliente a un entrenador, como si hubiera llegado por su enlace. */
    public static function linkClient(int $clientId, int $trainerId): void
    {
        self::pdo()->prepare("INSERT INTO client_referrals (client_id, trainer_id, source) VALUES (?, ?, 'link')")
            ->execute([$clientId, $trainerId]);
    }

    /** Cliente activo ya asociado a un entrenador. Devuelve [clienteId, entrenadorId]. */
    public static function clientWithTrainer(?int $trainerId = null): array
    {
        $trainerId ??= self::trainer();
        $clientId    = self::user();
        self::linkClient($clientId, $trainerId);

        return [$clientId, $trainerId];
    }

    /**
     * Estrategia vigente de un producto. Un valor null usa el valor por defecto.
     */
    public static function discountRule(int $productSku, ?float $client = null, ?float $trainer = null, ?float $commission = null, string $validFrom = '-1 day', ?string $validTo = null, bool $active = true): int
    {
        self::pdo()->prepare(
            'INSERT INTO product_discount_rules
                (product_sku, client_discount_percentage, trainer_discount_percentage, commission_percentage, valid_from, valid_to, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $productSku, $client, $trainer, $commission,
            date('Y-m-d H:i:s', (int) strtotime($validFrom)),
            $validTo === null ? null : date('Y-m-d H:i:s', (int) strtotime($validTo)),
            $active ? 1 : 0,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /** Abono de comisión de un entrenador, como el de una venta. */
    public static function commission(int $trainerId, float $amount, ?string $createdAt = null): void
    {
        self::pdo()->prepare(
            "INSERT INTO commission_movements (trainer_id, type, amount, source_key, created_at) VALUES (?, 'sale_credit', ?, ?, ?)"
        )->execute([$trainerId, $amount, 'test:' . bin2hex(random_bytes(12)), date('Y-m-d H:i:s', (int) strtotime($createdAt ?? 'now'))]);
    }

    /**
     * Deja un período sin cerrar, borrando su cierre y sus liquidaciones de pruebas anteriores
     * (las pruebas no limpian la base). Solo para probar el cierre y el bloqueo de la conversión.
     */
    public static function reopenPeriod(string $period): void
    {
        $pdo = self::pdo();
        $ids = 'SELECT s.id FROM commission_settlements s JOIN commission_periods p ON p.id = s.period_id WHERE p.period = ?';

        $pdo->prepare("DELETE FROM commission_movements WHERE settlement_id IN ({$ids})")->execute([$period]);
        $pdo->prepare('DELETE s FROM commission_settlements s JOIN commission_periods p ON p.id = s.period_id WHERE p.period = ?')->execute([$period]);
        $pdo->prepare('DELETE FROM commission_periods WHERE period = ?')->execute([$period]);
    }
}
