<?php

declare(strict_types=1);

use Tests\Support\Seed;

/*
 | Esquema de PremiumFitness (specs/diseño/01-DER.md, «Controles de integridad»).
 | Comprueba que la migración crea las tablas y que la base rechaza lo que las reglas prohíben,
 | aunque el código tenga un error.
 */

/** @return list<string> */
function schemaTables(): array
{
    $stmt = Seed::pdo()->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

    return $stmt !== false ? array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];
}

/** @return list<string> */
function schemaColumns(string $table): array
{
    $stmt = Seed::pdo()->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);

    return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Columna `Extra` del EXPLAIN de una consulta. */
function explainExtra(string $sql, int $trainerId): string
{
    $stmt = Seed::pdo()->prepare('EXPLAIN ' . $sql);
    $stmt->execute([$trainerId]);

    /** @var array{Extra?: string|null}|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? '' : (string) ($row['Extra'] ?? '');
}

it('crea las tablas nuevas del DER', function (): void {
    expect(schemaTables())->toContain(
        'trainers',
        'client_referrals',
        'trainer_referrals',
        'refresh_tokens',
        'push_devices',
        'push_notifications',
        'product_discount_rules',
        'adjustments',
        'audit_logs',
        'commission_periods',
        'commission_settlements',
        'commission_conversions',
        'commission_movements',
        'point_lots',
        'point_movements',
        'diet_access_periods',
    );
});

it('añade las columnas nuevas a las tablas heredadas', function (string $table, array $columns): void {
    expect(schemaColumns($table))->toContain(...$columns);
})->with([
    'users' => ['users', ['phone_verified_at', 'role', 'status']],
    'verification_codes' => ['verification_codes', ['purpose', 'destination', 'attempts']],
    'orders' => ['orders', [
        'order_type', 'payment_reference', 'subtotal_full', 'discount_amount', 'tax_base', 'tax_amount',
        'reward_percentage', 'reward_points', 'redemption_points', 'approved_at',
    ]],
    'orders_items' => ['orders_items', [
        'product_name', 'tax_rate', 'tax_base', 'tax_amount', 'commission_percentage', 'commission_amount',
        'redemption_points',
    ]],
    'orders_coupons' => ['orders_coupons', ['trainer_id']],
]);

it('un usuario nuevo nace como cliente sin verificar', function (): void {
    $pdo = Seed::pdo();
    $pdo->prepare(
        'INSERT INTO users (document_id, document_type_id, full_name, num_cell, email, password) VALUES (?, 1, ?, ?, ?, ?)'
    )->execute(['T' . random_int(100_000_000, 999_999_999), 'Sin Verificar', Seed::phone(), uniqid('nuevo_', true) . '@example.com', 'x']);

    $stmt = $pdo->prepare('SELECT role, status FROM users WHERE id = ?');
    $stmt->execute([(int) $pdo->lastInsertId()]);

    expect($stmt->fetch(PDO::FETCH_ASSOC))->toBe(['role' => 'client', 'status' => 'pending']);
});

it('un cliente solo puede tener un entrenador', function (): void {
    $client = Seed::user();
    $insert = Seed::pdo()->prepare("INSERT INTO client_referrals (client_id, trainer_id, source) VALUES (?, ?, 'link')");
    $insert->execute([$client, Seed::trainer()]);

    expect(fn () => $insert->execute([$client, Seed::trainer()]))->toThrow(PDOException::class, 'uk_client_referrals_client');
});

it('un entrenador solo puede ser referido por otro una vez, y nunca por sí mismo', function (): void {
    $referred = Seed::trainer();
    $insert   = Seed::pdo()->prepare(
        'INSERT INTO trainer_referrals (referrer_id, referred_id, referral_points) VALUES (?, ?, 50000)'
    );
    $insert->execute([Seed::trainer(), $referred]);

    expect(fn () => $insert->execute([Seed::trainer(), $referred]))
        ->toThrow(PDOException::class, 'uk_trainer_referrals_referred');

    $alone = Seed::trainer();
    expect(fn () => $insert->execute([$alone, $alone]))->toThrow(PDOException::class, 'ck_trainer_referrals_distinct');
});

it('no acredita dos veces el mismo origen de puntos', function (): void {
    $trainer = Seed::trainer();
    $insert  = Seed::pdo()->prepare(
        "INSERT INTO point_lots (trainer_id, source, initial_points, remaining_points, expires_at, source_key)
         VALUES (?, 'client_sale', 100, 100, DATE_ADD(NOW(), INTERVAL 6 MONTH), ?)"
    );
    $key = 'test:' . bin2hex(random_bytes(12));
    $insert->execute([$trainer, $key]);

    expect(fn () => $insert->execute([$trainer, $key]))->toThrow(PDOException::class, 'uk_point_lots_source');
});

it('un lote no puede quedar con más puntos de los que tuvo ni en negativo', function (int $remaining): void {
    $lotId  = Seed::pointLot(Seed::trainer(), 100);
    $update = Seed::pdo()->prepare('UPDATE point_lots SET remaining_points = ? WHERE id = ?');

    expect(fn () => $update->execute([$remaining, $lotId]))->toThrow(PDOException::class, 'ck_point_lots_points');
})->with([101, -1]);

it('no registra dos veces el mismo movimiento de comisión', function (): void {
    $trainer = Seed::trainer();
    $insert  = Seed::pdo()->prepare(
        "INSERT INTO commission_movements (trainer_id, type, amount, source_key) VALUES (?, 'sale_credit', 1000, ?)"
    );
    $key = 'test:' . bin2hex(random_bytes(12));
    $insert->execute([$trainer, $key]);

    expect(fn () => $insert->execute([$trainer, $key]))->toThrow(PDOException::class, 'uk_commission_movements_source');
});

it('no repite la referencia de pago en dos pedidos', function (): void {
    $user      = Seed::user();
    $reference = 'test-' . bin2hex(random_bytes(8));
    $update    = Seed::pdo()->prepare('UPDATE orders SET payment_reference = ? WHERE order_id = ?');
    $update->execute([$reference, Seed::order($user)]);

    $second = Seed::order($user);
    expect(fn () => $update->execute([$reference, $second]))->toThrow(PDOException::class, 'uk_orders_payment_reference');
});

it('un refresh token es de un usuario de la web o de un agente del admin, no de los dos', function (): void {
    $insert = Seed::pdo()->prepare(
        'INSERT INTO refresh_tokens (user_id, agent_id, app, family_id, token_hash, expires_at)
         VALUES (?, ?, ?, UUID(), ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    );
    $hash = static fn (): string => hash('sha256', random_bytes(16));
    $user = Seed::user();

    $insert->execute([$user, null, 'web', $hash()]);
    $insert->execute([null, 9001, 'admin', $hash()]);

    expect(fn () => $insert->execute([$user, null, 'admin', $hash()]))->toThrow(PDOException::class, 'ck_refresh_tokens_owner')
        ->and(fn () => $insert->execute([null, null, 'web', $hash()]))->toThrow(PDOException::class, 'ck_refresh_tokens_owner')
        ->and(fn () => $insert->execute([$user, 9001, 'web', $hash()]))->toThrow(PDOException::class, 'ck_refresh_tokens_owner');
});

it('una liquidación por entrenador y período', function (): void {
    $pdo    = Seed::pdo();
    $period = '29' . random_int(10, 99) . '-' . str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT IGNORE INTO commission_periods (period, period_start, period_end) VALUES (?, ?, ?)')
        ->execute([$period, $period . '-01', $period . '-28']);
    $find = $pdo->prepare('SELECT id FROM commission_periods WHERE period = ?');
    $find->execute([$period]);
    $periodId = (int) $find->fetchColumn();

    $trainer = Seed::trainer();
    $insert  = $pdo->prepare(
        'INSERT INTO commission_settlements (period_id, trainer_id, total_amount, due_date) VALUES (?, ?, 1000, ?)'
    );
    $insert->execute([$periodId, $trainer, $period . '-04']);

    expect(fn () => $insert->execute([$periodId, $trainer, $period . '-04']))
        ->toThrow(PDOException::class, 'uk_commission_settlements_period_trainer')
        ->and(fn () => $pdo->prepare('INSERT INTO commission_periods (period, period_start, period_end) VALUES (?, ?, ?)')
            ->execute([$period, $period . '-01', $period . '-28']))
        ->toThrow(PDOException::class, 'uk_commission_periods_period');
});

it('los saldos del entrenador se resuelven solo con el índice (spec negocio/03)', function (string $sql): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 100);

    expect(explainExtra($sql, $trainer))->toContain('Using index');
})->with([
    'puntos vigentes por origen' => [
        "SELECT source, SUM(remaining_points) FROM point_lots
         WHERE trainer_id = ? AND status = 'available' AND expires_at > NOW() GROUP BY source",
    ],
    'histórico por origen' => ['SELECT source, SUM(initial_points) FROM point_lots WHERE trainer_id = ? GROUP BY source'],
    'referidos pendientes' => [
        'SELECT COUNT(*), COALESCE(SUM(referral_points), 0) FROM trainer_referrals
         WHERE referrer_id = ? AND activated_at IS NULL',
    ],
    'saldo de comisión' => ['SELECT COALESCE(SUM(amount), 0) FROM commission_movements WHERE trainer_id = ?'],
]);
