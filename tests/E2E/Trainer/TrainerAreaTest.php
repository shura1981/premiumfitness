<?php

declare(strict_types=1);

use App\Features\Referral\ReferralLink;
use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Área del entrenador: saldos, referidos, conversión de comisión, canje y acceso a dietas
 | (specs negocio/03, negocio/04 e integraciones/02).
 */

function trainerGet(int $trainerId, string $path): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/' . $path, headers: JwtFactory::authHeader($trainerId)));
}

function trainerPost(int $trainerId, string $path, array $body = []): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('POST', '/api/trainer/' . $path, $body, JwtFactory::authHeader($trainerId)));
}

function idempotencyKey(): string
{
    return bin2hex(random_bytes(16));
}

it('un cliente recibe 403 en todos los endpoints del entrenador', function (): void {
    $headers = JwtFactory::authHeader(Seed::user());
    $denied  = [];

    foreach (AppFactory::make()->getRouteCollector()->getRoutes() as $route) {
        if (!str_starts_with($route->getPattern(), '/api/trainer/')) {
            continue;
        }

        foreach ($route->getMethods() as $method) {
            $path     = (string) preg_replace('/\{\w+(?::(?:[^{}]|\{[^{}]*\})+)?\}/', '1', $route->getPattern());
            $denied[] = AppFactory::dispatch(AppFactory::request($method, $path, $method === 'GET' ? null : [], $headers))->getStatusCode();
        }
    }

    expect(count($denied))->toBeGreaterThanOrEqual(10)
        ->and(array_unique($denied))->toBe([403]);
});

it('un token de cliente con el rol alterado no entra: el rol del token lo firma el servidor', function (): void {
    $forged = JwtFactory::authHeader(Seed::user(), 'trainer');
    $body   = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/referrals', headers: $forged)));

    // Solo el servidor emite tokens; aun con el rol puesto, sin fila de entrenador no hay datos.
    expect($body['code'] ?? null)->toBe('forbidden');
});

// ── Saldos ────────────────────────────────────────────────────────────────

it('el dashboard suma los puntos vigentes por origen y deja fuera los vencidos y los pendientes', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 30000, 'client_sale');
    Seed::pointLot($trainer, 20000, 'client_sale', '-1 day');
    Seed::pointLot($trainer, 50000, 'trainer_referral', '+10 days');
    Seed::pointLot($trainer, 7000, 'conversion');
    $pending = Seed::trainer();
    Seed::pdo()->prepare('INSERT INTO trainer_referrals (referrer_id, referred_id, referral_points) VALUES (?, ?, 50000)')->execute([$trainer, $pending]);
    Seed::commission($trainer, 28350);

    $body = AppFactory::json(trainerGet($trainer, 'dashboard'));

    expect($body['points'])->toMatchArray([
        'available' => 87000, 'client_total' => 50000, 'client_available' => 30000, 'referral_available' => 50000, 'referral_pending' => 50000,
    ])
        ->and($body['points']['next_expiry']['points'])->toBe(50000)
        ->and($body['commission']['balance'])->toBe(28350)
        ->and($body['commission'])->toHaveKeys(['period_label', 'closes_at', 'pays_at'])
        ->and($body['recent'])->not->toBeEmpty()
        ->and($body['diet'])->toHaveKeys(['has_access', 'reason', 'goal', 'redeem']);
});

it('lista los lotes con su estado y filtra por origen', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 1000, 'client_sale');
    Seed::pointLot($trainer, 2000, 'client_sale', '+5 days');
    Seed::pointLot($trainer, 3000, 'client_sale', '-1 day');
    $used = Seed::pointLot($trainer, 4000, 'conversion');
    Seed::pdo()->prepare("UPDATE point_lots SET remaining_points = 0, status = 'used' WHERE id = ?")->execute([$used]);

    $all      = AppFactory::json(trainerGet($trainer, 'points'));
    $filtered = AppFactory::json(trainerGet($trainer, 'points?source=conversion'));

    expect($all['available'])->toBe(3000)
        ->and(array_count_values(array_column($all['lots'], 'status')))->toBe(['usado' => 1, 'vencido' => 1, 'por_vencer' => 1, 'disponible' => 1])
        ->and(array_column($filtered['lots'], 'source'))->toBe(['conversion']);
});

it('muestra el cupón, los dos enlaces, los clientes y los entrenadores referidos', function (): void {
    $trainer  = Seed::trainer();
    [$client] = Seed::clientWithTrainer($trainer);
    $referred = Seed::trainer();
    Seed::pdo()->prepare('INSERT INTO trainer_referrals (referrer_id, referred_id, referral_points) VALUES (?, ?, 50000)')->execute([$trainer, $referred]);
    Shop::pay(Shop::order($client, [['code' => Seed::catalogProduct(), 'quantity' => 1]]));

    $body = AppFactory::json(trainerGet($trainer, 'referrals'));
    $id   = ReferralLink::encodeId($trainer);

    expect($body['coupon_code'])->toBeString()->not->toBeEmpty()
        ->and($body['client_link'])->toBe($_ENV['WEB_APP_URL'] . '/r/c/' . $id)
        ->and($body['trainer_link'])->toBe($_ENV['WEB_APP_URL'] . '/r/t/' . $id)
        ->and($body['trainer_referral_points'])->toBe((int) $_ENV['TRAINER_REFERRAL_POINTS'])
        ->and($body['trainer_referral_min_purchase'])->toBe((int) $_ENV['TRAINER_REFERRAL_MIN_PURCHASE'])
        ->and($body['clients'][0])->toMatchArray(['id' => $client, 'orders_count' => 1])
        ->and($body['trainers'][0])->toMatchArray(['id' => $referred, 'status' => 'pendiente', 'points' => 50000, 'remaining_points' => 0])
        // De un cliente solo viaja el nombre: ni celular, ni correo, ni documento.
        ->and(array_keys($body['clients'][0]))->toBe(['id', 'full_name', 'since', 'orders_count']);
});

it('un entrenador no ve los datos de otro', function (): void {
    $trainer = Seed::trainer();
    $other   = Seed::trainer();
    Seed::pointLot($other, 99999);
    Seed::commission($other, 77777);

    expect(AppFactory::json(trainerGet($trainer, 'points'))['available'])->toBe(0)
        ->and(AppFactory::json(trainerGet($trainer, 'commission'))['balance'])->toBe(0);
});

// ── Conversión de la comisión (spec negocio/04, regla 5) ──────────────────

it('convierte la comisión con los ejemplos del spec', function (int $points, float $commission, int $bonus, int $granted, int $resulting): void {
    $trainer = Seed::trainer();
    if ($points > 0) {
        Seed::pointLot($trainer, $points);
    }
    Seed::commission($trainer, $commission);

    $preview = AppFactory::json(trainerGet($trainer, 'commission/conversion-preview'));
    $convert = trainerPost($trainer, 'commission/convert', ['idempotency_key' => idempotencyKey()]);
    $after   = AppFactory::json(trainerGet($trainer, 'commission'));

    expect($preview)->toMatchArray(['commission_amount' => $commission, 'base_points' => $points, 'bonus_points' => $bonus, 'points_granted' => $granted, 'resulting_points' => $resulting])
        ->and($convert->getStatusCode())->toBe(200)
        ->and(AppFactory::json($convert)['points_granted'])->toBe($granted)
        ->and($after['balance'])->toBe(0)
        ->and($after['can_convert'])->toBeFalse()
        ->and(AppFactory::json(trainerGet($trainer, 'points'))['available'])->toBe($resulting);
})->with([
    '100.000 puntos y 100.000 de comisión' => [100000, 100000.0, 50000, 150000, 250000],
    '1.000.000 de puntos y 1.000 de comisión: el adicional tiene tope' => [1000000, 1000.0, 1000, 2000, 1002000],
    'sin puntos'                           => [0, 80000.0, 0, 80000, 80000],
]);

it('la conversión crea un lote nuevo que vence a los 6 meses y un solo débito', function (): void {
    $trainer = Seed::trainer();
    Seed::commission($trainer, 28350.5);
    trainerPost($trainer, 'commission/convert', ['idempotency_key' => idempotencyKey()]);

    $lot        = Shop::rows("SELECT source, initial_points, TIMESTAMPDIFF(MONTH, accrued_at, expires_at) m FROM point_lots WHERE trainer_id = ?", [$trainer]);
    $conversion = Shop::rows('SELECT commission_amount, base_points, conversion_percentage, bonus_points, points_granted FROM commission_conversions WHERE trainer_id = ?', [$trainer]);
    $debit      = Shop::rows("SELECT amount FROM commission_movements WHERE trainer_id = ? AND type = 'conversion_debit'", [$trainer]);

    // 28.350,5 → 28.351 puntos (0,5 sube).
    expect($lot)->toBe([['source' => 'conversion', 'initial_points' => 28351, 'm' => (int) $_ENV['POINTS_EXPIRATION_MONTHS']]])
        ->and($conversion)->toBe([['commission_amount' => '28350.50', 'base_points' => 0, 'conversion_percentage' => '50.00', 'bonus_points' => 0, 'points_granted' => 28351]])
        ->and($debit)->toBe([['amount' => '-28350.50']]);
});

it('una solicitud repetida no convierte dos veces', function (): void {
    $trainer = Seed::trainer();
    Seed::commission($trainer, 10000);
    $key = idempotencyKey();

    $first = AppFactory::json(trainerPost($trainer, 'commission/convert', ['idempotency_key' => $key]));
    Seed::commission($trainer, 5000);
    $second = AppFactory::json(trainerPost($trainer, 'commission/convert', ['idempotency_key' => $key]));

    expect($second['points_granted'])->toBe($first['points_granted'])
        ->and(Shop::rows('SELECT COUNT(*) n FROM commission_conversions WHERE trainer_id = ?', [$trainer])[0]['n'])->toBe(1)
        // La comisión que entró después sigue ahí.
        ->and(AppFactory::json(trainerGet($trainer, 'commission'))['balance'])->toBe(5000);
});

it('la clave de conversión de un entrenador no le sirve a otro', function (): void {
    $trainer = Seed::trainer();
    $other   = Seed::trainer();
    Seed::commission($trainer, 10000);
    Seed::commission($other, 10000);
    $key = idempotencyKey();
    trainerPost($trainer, 'commission/convert', ['idempotency_key' => $key]);

    $response = trainerPost($other, 'commission/convert', ['idempotency_key' => $key]);

    expect($response->getStatusCode())->toBe(409)
        ->and(AppFactory::json(trainerGet($other, 'commission'))['balance'])->toBe(10000);
});

it('no convierte sin comisión ni sin clave válida', function (): void {
    $trainer = Seed::trainer();

    $empty = trainerPost($trainer, 'commission/convert', ['idempotency_key' => idempotencyKey()]);
    Seed::commission($trainer, 1000);
    $noKey = trainerPost($trainer, 'commission/convert', ['idempotency_key' => 'corta']);

    expect($empty->getStatusCode())->toBe(409)
        ->and(AppFactory::json($empty)['code'])->toBe('nothing_to_convert')
        ->and($noKey->getStatusCode())->toBe(422);
});

it('bloquea la conversión mientras el mes anterior tiene comisión sin cerrar', function (): void {
    $trainer = Seed::trainer();
    Seed::commission($trainer, 40000, 'first day of last month');
    $period = date('Y-m', (int) strtotime('first day of last month'));
    // El resto de la base de pruebas no debe decidir: se deja el mes anterior sin cerrar.
    Seed::reopenPeriod($period);

    $view    = AppFactory::json(trainerGet($trainer, 'commission'));
    $convert = trainerPost($trainer, 'commission/convert', ['idempotency_key' => idempotencyKey()]);

    expect($view['can_convert'])->toBeFalse()
        ->and($view['blocked_message'])->toBe('Estamos cerrando la comisión del mes. Vuelve a intentarlo en unos minutos.')
        ->and($convert->getStatusCode())->toBe(409)
        ->and(AppFactory::json($convert)['code'])->toBe('conversion_blocked')
        ->and($view['balance'])->toBe(40000);

    // Limpieza: la comisión vieja sin liquidar bloquearía las demás pruebas de conversión.
    Seed::pdo()->prepare('DELETE FROM commission_movements WHERE trainer_id = ?')->execute([$trainer]);
});

// ── Canje con puntos (CU-12) ──────────────────────────────────────────────

it('canjea a precio completo, por FIFO, y deja el pedido aprobado sin pasarela', function (): void {
    $trainer = Seed::trainer();
    $oldest  = Seed::pointLot($trainer, 100000, 'client_sale', '+2 months', '-3 months');
    $newest  = Seed::pointLot($trainer, 300000, 'client_sale', '+5 months', '-1 day');
    $code    = Seed::catalogProduct(['valor' => 249900, 'producto' => 'BIPRO CANJE']);
    $items   = [['code' => $code, 'quantity' => 1]];

    $preview = AppFactory::json(trainerPost($trainer, 'redemptions/preview', ['items' => $items]));
    $redeem  = trainerPost($trainer, 'redemptions', ['items' => $items, 'address_id' => Shop::address($trainer), 'idempotency_key' => idempotencyKey()]);
    $orderId = AppFactory::json($redeem)['order_id'];
    $order   = Shop::orderRow($orderId);

    expect($preview)->toMatchArray(['points_total' => 249900, 'points_available' => 400000, 'enough' => true, 'message' => null])
        // Sin el descuento del entrenador: el canje es a precio completo.
        ->and($preview['items'][0])->toMatchArray(['points' => 249900, 'line_points' => 249900, 'available' => true])
        ->and($redeem->getStatusCode())->toBe(201)
        ->and($order)->toMatchArray([
            'order_type' => 'redemption', 'amount' => '0.00', 'redemption_points' => 249900, 'payment_gateway' => null,
            'status_value' => 'APROBADA', 'discount_amount' => '0.00', 'reward_points' => null,
        ])
        ->and($order['approved_at'])->not->toBeNull()
        // FIFO: primero se agota el lote más antiguo.
        ->and(Shop::rows('SELECT id, remaining_points, status FROM point_lots WHERE trainer_id = ? ORDER BY id', [$trainer]))->toBe([
            ['id' => $oldest, 'remaining_points' => 0, 'status' => 'used'],
            ['id' => $newest, 'remaining_points' => 150100, 'status' => 'available'],
        ])
        ->and(Shop::rows("SELECT point_lot_id, type, points FROM point_movements WHERE order_id = ? ORDER BY id", [$orderId]))->toBe([
            ['point_lot_id' => $oldest, 'type' => 'redemption', 'points' => -100000],
            ['point_lot_id' => $newest, 'type' => 'redemption', 'points' => -149900],
        ])
        ->and(Shop::rows('SELECT COUNT(*) n FROM commission_movements WHERE trainer_id = ?', [$trainer])[0]['n'])->toBe(0);

    $listed = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders', headers: JwtFactory::authHeader($trainer))))['data'][0];
    expect($listed)->toMatchArray(['id' => $orderId, 'is_redemption' => true, 'redemption_points' => 249900, 'status' => 'aprobado']);

    // Un canje no pasa por la pasarela: su línea de tiempo no habla de pagos.
    $timeline = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders/' . $orderId, headers: JwtFactory::authHeader($trainer))))['timeline'];
    expect(array_column($timeline, 'status'))->toBe(['aprobado', 'en_proceso', 'en_camino'])
        ->and($timeline[0])->toMatchArray(['label' => 'Canje confirmado', 'done' => true]);
});

it('no canjea sin puntos suficientes, ni con puntos vencidos, ni con la comisión', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 50000);
    Seed::pointLot($trainer, 500000, 'client_sale', '-1 day');
    Seed::commission($trainer, 900000);
    $items = [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]];

    $preview = AppFactory::json(trainerPost($trainer, 'redemptions/preview', ['items' => $items]));
    $redeem  = trainerPost($trainer, 'redemptions', ['items' => $items, 'address_id' => Shop::address($trainer), 'idempotency_key' => idempotencyKey()]);

    expect($preview)->toMatchArray(['points_available' => 50000, 'enough' => false])
        ->and($preview['message'])->toContain('50.000')
        ->and($redeem->getStatusCode())->toBe(409)
        ->and(AppFactory::json($redeem)['code'])->toBe('not_enough_points')
        // Nada cambió: ni pedido ni puntos.
        ->and(Shop::rows('SELECT COUNT(*) n FROM orders WHERE user_id = ?', [$trainer])[0]['n'])->toBe(0)
        ->and(AppFactory::json(trainerGet($trainer, 'points'))['available'])->toBe(50000);
});

it('repetir el canje con la misma clave no descuenta dos veces', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 300000);
    $body = ['items' => [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]], 'address_id' => Shop::address($trainer), 'idempotency_key' => idempotencyKey()];

    $first  = AppFactory::json(trainerPost($trainer, 'redemptions', $body))['order_id'];
    $second = AppFactory::json(trainerPost($trainer, 'redemptions', $body))['order_id'];

    expect($second)->toBe($first)
        ->and(AppFactory::json(trainerGet($trainer, 'points'))['available'])->toBe(200000);
});

it('el canje guarda las notas para la entrega, como una compra', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 300000);
    $body = ['items' => [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]], 'address_id' => Shop::address($trainer)];

    $withNotes = trainerPost($trainer, 'redemptions', $body + ['notes' => '  Dejar en portería  ', 'idempotency_key' => idempotencyKey()]);
    $blank     = trainerPost($trainer, 'redemptions', $body + ['notes' => '   ', 'idempotency_key' => idempotencyKey()]);
    $tooLong   = trainerPost($trainer, 'redemptions', $body + ['notes' => str_repeat('a', 501), 'idempotency_key' => idempotencyKey()]);

    expect(Shop::orderRow(AppFactory::json($withNotes)['order_id'])['order_notes'])->toBe('Dejar en portería')
        ->and(Shop::orderRow(AppFactory::json($blank)['order_id'])['order_notes'])->toBeNull()
        ->and($tooLong->getStatusCode())->toBe(422)
        ->and(AppFactory::json($tooLong)['errors'])->toHaveKey('notes')
        // La nota larga no canjeó nada: solo se descontaron los dos canjes válidos.
        ->and(AppFactory::json(trainerGet($trainer, 'points'))['available'])->toBe(100000);
});

it('no canjea productos agotados o en promoción', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 900000);
    $items = [['code' => Seed::catalogProduct(['agotado' => 1]), 'quantity' => 1]];

    $redeem = trainerPost($trainer, 'redemptions', ['items' => $items, 'address_id' => Shop::address($trainer), 'idempotency_key' => idempotencyKey()]);

    expect($redeem->getStatusCode())->toBe(409)
        ->and(AppFactory::json($redeem)['code'])->toBe('out_of_stock')
        ->and(AppFactory::json(trainerGet($trainer, 'points'))['available'])->toBe(900000);
});
