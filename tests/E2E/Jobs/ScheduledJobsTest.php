<?php

declare(strict_types=1);

use App\Features\Commission\CloseReport;
use App\Features\Commission\MonthlyCloseService;
use App\Features\Notification\FcmPushChannel;
use App\Features\Notification\PushOutbox;
use App\Features\Notification\PushSender;
use App\Shared\Exceptions\ApiException;
use App\Shared\Exceptions\HttpRequestException;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Tareas programadas: cierre mensual, vencimiento de puntos y envío de push
 | (specs negocio/04, negocio/05, negocio/03 e integraciones/01).
 */

function cron(string $path, array $body = [], bool $withToken = true): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('POST', '/api/cron-job/' . $path, $body, $withToken ? ['X-Cron-Job-Token' => $_ENV['CRON_JOB_TOKEN']] : []));
}

/**
 * Un mes pasado que todavía no está cerrado, para que cada prueba cierre su propio período.
 * Las pruebas dejan sus datos en la base: por eso se busca el primero libre en vez de uno fijo.
 */
function freshPeriod(): string
{
    $used = array_flip(array_column(Shop::rows('SELECT period FROM commission_periods'), 'period'));

    for ($date = new DateTimeImmutable('1971-01-01'); $date < new DateTimeImmutable('2020-01-01'); $date = $date->modify('+1 month')) {
        if (!isset($used[$date->format('Y-m')])) {
            return $date->format('Y-m');
        }
    }

    throw new RuntimeException('No quedan períodos libres: recrea la base con scripts/reset-db.sh.');
}

function settlementOf(int $trainerId, string $period): ?array
{
    return Shop::rows(
        'SELECT s.* FROM commission_settlements s JOIN commission_periods p ON p.id = s.period_id WHERE s.trainer_id = ? AND p.period = ?',
        [$trainerId, $period]
    )[0] ?? null;
}

beforeEach(function (): void {
    // Cada prueba parte sin comisión antigua sin liquidar de otras pruebas.
    Seed::pdo()->exec('DELETE FROM commission_movements WHERE settlement_id IS NULL AND created_at < DATE_FORMAT(NOW(), "%Y-%m-01")');
});

it('los crons exigen su token', function (string $path): void {
    expect(cron($path, withToken: false)->getStatusCode())->toBeIn([401, 403])
        ->and(AppFactory::dispatch(AppFactory::request('POST', '/api/cron-job/' . $path, [], JwtFactory::adminHeader()))->getStatusCode())->toBeIn([401, 403]);
})->with(['commission/close', 'points/expire', 'push/send']);

// ── Cierre mensual ────────────────────────────────────────────────────────

it('liquida la comisión del mes: ventas menos lo convertido, más los ajustes, y deja el saldo en cero', function (): void {
    $period  = freshPeriod();
    $trainer = Seed::trainer();
    Seed::commission($trainer, 100000, $period . '-05 10:00:00');
    Seed::commission($trainer, 50000.4, $period . '-20 10:00:00');
    Seed::pdo()->prepare("INSERT INTO commission_movements (trainer_id, type, amount, source_key, created_at) VALUES (?, 'conversion_debit', -30000, ?, ?), (?, 'adjustment', -5000, ?, ?)")
        ->execute([$trainer, uniqid('t:'), $period . '-10 10:00:00', $trainer, uniqid('t:'), $period . '-25 10:00:00']);

    $result     = new MonthlyCloseService()->close($period);
    $settlement = settlementOf($trainer, $period);
    $balance    = Shop::rows('SELECT COALESCE(SUM(amount), 0) b FROM commission_movements WHERE trainer_id = ?', [$trainer])[0]['b'];

    expect($settlement)->toMatchArray([
        'commission_amount' => '150000.40', 'conversions_amount' => '-30000.00', 'adjustments_amount' => '-5000.00',
        // Comisión ± ajustes − convertida = total; se paga al peso.
        'total_amount' => '115000.00', 'status' => 'closed', 'paid_at' => null,
    ])
        ->and($result)->toMatchArray(['period' => $period, 'trainers_count' => 1, 'total_amount' => 115000.0])
        ->and((float) $balance)->toBe(0.0)
        ->and(Shop::rows('SELECT COUNT(*) n FROM commission_movements WHERE trainer_id = ? AND settlement_id IS NULL', [$trainer])[0]['n'])->toBe(0)
        ->and(Shop::rows("SELECT type FROM push_notifications WHERE user_id = ? AND type = 'commission_settled'", [$trainer]))->toHaveCount(1);
});

it('cerrar dos veces el mismo mes responde 409 y no liquida nada dos veces', function (): void {
    $period  = freshPeriod();
    $trainer = Seed::trainer();
    Seed::commission($trainer, 80000, $period . '-15 12:00:00');

    $first  = cron('commission/close', ['period' => $period, 'skip_email' => true]);
    $second = cron('commission/close', ['period' => $period, 'skip_email' => true]);

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(409)
        ->and(AppFactory::json($second)['code'])->toBe('period_closed')
        ->and(Shop::rows('SELECT COUNT(*) n FROM commission_periods WHERE period = ?', [$period])[0]['n'])->toBe(1)
        ->and(Shop::rows('SELECT COUNT(*) n FROM commission_settlements WHERE trainer_id = ?', [$trainer])[0]['n'])->toBe(1)
        ->and(Shop::rows("SELECT COUNT(*) n FROM commission_movements WHERE trainer_id = ? AND type = 'settlement_debit'", [$trainer])[0]['n'])->toBe(1);
});

it('una venta del día 1 a las 00:30 queda para el mes nuevo', function (): void {
    $period  = freshPeriod();
    $next    = date('Y-m', (int) strtotime($period . '-01 +1 month'));
    $trainer = Seed::trainer();
    Seed::commission($trainer, 70000, date('Y-m-t', (int) strtotime($period . '-01')) . ' 23:59:59');
    Seed::commission($trainer, 40000, $next . '-01 00:30:00');

    new MonthlyCloseService()->close($period);

    expect(settlementOf($trainer, $period)['total_amount'])->toBe('70000.00')
        ->and((float) Shop::rows('SELECT COALESCE(SUM(amount), 0) b FROM commission_movements WHERE trainer_id = ?', [$trainer])[0]['b'])->toBe(40000.0);
});

it('un saldo negativo no se paga y se descuenta del mes siguiente', function (): void {
    $period  = freshPeriod();
    $next    = date('Y-m', (int) strtotime($period . '-01 +1 month'));
    $trainer = Seed::trainer();
    Seed::pdo()->prepare("INSERT INTO commission_movements (trainer_id, type, amount, source_key, created_at) VALUES (?, 'adjustment', -20000, ?, ?)")
        ->execute([$trainer, uniqid('t:'), $period . '-12 10:00:00']);

    new MonthlyCloseService()->close($period);
    expect(settlementOf($trainer, $period))->toBeNull();

    Seed::commission($trainer, 90000, $next . '-12 10:00:00');
    new MonthlyCloseService()->close($next);

    expect(settlementOf($trainer, $next))->toMatchArray(['commission_amount' => '90000.00', 'adjustments_amount' => '-20000.00', 'total_amount' => '70000.00']);
});

it('no cierra un mes que no ha terminado ni un período mal escrito', function (string $period): void {
    expect(fn () => new MonthlyCloseService()->close($period))->toThrow(ApiException::class);
})->with([fn (): string => date('Y-m'), '2026-13', 'noviembre']);

it('lo liquidado ya no se puede convertir, y la conversión vuelve a abrirse tras el cierre', function (): void {
    $period  = date('Y-m', (int) strtotime('first day of last month'));
    $trainer = Seed::trainer();
    Seed::reopenPeriod($period);
    Seed::commission($trainer, 60000, 'first day of last month noon');
    $headers = JwtFactory::authHeader($trainer);
    $view    = fn (): array => AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/commission', headers: $headers)));

    expect($view()['can_convert'])->toBeFalse();

    cron('commission/close', ['skip_email' => true]);
    $after = $view();

    expect($after['balance'])->toBe(0)
        ->and($after['blocked_message'])->toBeNull()
        ->and($after['settlements'][0])->toMatchArray(['total_amount' => 60000, 'status' => 'liquidada', 'receipt_url' => null]);
});

it('genera el Excel del cierre fuera de la carpeta pública, con sus hojas y sin fórmulas inyectadas', function (): void {
    $period  = freshPeriod();
    $trainer = Seed::trainer();
    Seed::pdo()->prepare("UPDATE users SET full_name = '=HYPERLINK(\"http://malo\")' WHERE id = ?")->execute([$trainer]);
    Seed::commission($trainer, 123456, $period . '-08 09:00:00');

    $response = cron('commission/close', ['period' => $period, 'skip_email' => true]);
    $periodId = AppFactory::json($response)['period_id'];
    $file     = new CloseReport()->pathOf($periodId);

    // El Excel se generó; el único aviso posible es que faltan los festivos de ese año de prueba.
    expect(AppFactory::json($response)['warnings'])->not->toContain('report_failed')
        ->and($file)->not->toBeNull()
        ->and(realpath((string) $file))->toContain('/storage/private/reports/');

    $reader = new Reader();
    $reader->open((string) $file);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $sheets[$sheet->getName()] = array_map(static fn ($row): array => $row->toArray(), iterator_to_array($sheet->getRowIterator(), false));
    }
    $reader->close();

    expect(array_keys($sheets))->toBe(['Resumen', 'Liquidaciones', 'Detalle', 'Productos', 'IVA', 'Puntos', 'Canjes'])
        ->and($sheets['Liquidaciones'][1][1])->toBe("'=HYPERLINK(\"http://malo\")")
        ->and($sheets['Liquidaciones'][1][8])->toBe(123456);

    // `force` regenera el Excel sin cambiar ningún importe.
    $before = settlementOf($trainer, $period);
    $forced = cron('commission/close', ['period' => $period, 'skip_email' => true, 'force' => true]);

    expect($forced->getStatusCode())->toBe(200)
        ->and(settlementOf($trainer, $period))->toBe($before)
        ->and(new CloseReport()->pathOf($periodId))->not->toBe($file)
        ->and(is_file((string) $file))->toBeFalse();
});

// ── Vencimiento de puntos ─────────────────────────────────────────────────

it('vence los lotes cuya fecha pasó, deja el movimiento y no repite al correr de nuevo', function (): void {
    $trainer = Seed::trainer();
    $expired = Seed::pointLot($trainer, 40000, 'client_sale', '-1 hour');
    $alive   = Seed::pointLot($trainer, 10000, 'client_sale', '+3 months');

    $first  = AppFactory::json(cron('points/expire'));
    $second = AppFactory::json(cron('points/expire'));

    expect($first['expired_lots'])->toBeGreaterThanOrEqual(1)
        ->and($second['expired_lots'])->toBe(0)
        ->and(Shop::rows('SELECT id, remaining_points, status FROM point_lots WHERE trainer_id = ? ORDER BY id', [$trainer]))->toBe([
            ['id' => $expired, 'remaining_points' => 0, 'status' => 'expired'],
            ['id' => $alive, 'remaining_points' => 10000, 'status' => 'available'],
        ])
        ->and(Shop::rows('SELECT type, points FROM point_movements WHERE point_lot_id = ?', [$expired]))->toBe([['type' => 'expiration', 'points' => -40000]]);
});

it('avisa una sola vez de los puntos que vencen pronto', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 5000, 'client_sale', '+3 days');
    Seed::pointLot($trainer, 9000, 'client_sale', '+60 days');

    cron('points/expire');
    cron('points/expire');

    expect(Shop::rows("SELECT body FROM push_notifications WHERE user_id = ? AND type = 'points_expiring'", [$trainer]))->toHaveCount(1);
});

// ── Push ──────────────────────────────────────────────────────────────────

/** Canal de FCM de mentira: anota lo enviado y falla con los tokens que se le indiquen. */
function fakeFcm(array $failures = []): FcmPushChannel
{
    return new class ($failures) extends FcmPushChannel {
        /** @var list<array{token:string, title:string}> */
        public array $sent = [];

        public function __construct(private array $failures)
        {
            parent::__construct('proyecto', __FILE__, 'https://app.test');
        }

        public function send(string $deviceToken, string $title, string $body, array $data): void
        {
            if (isset($this->failures[$deviceToken])) {
                throw new HttpRequestException('FCM error', $this->failures[$deviceToken][0], $this->failures[$deviceToken][1]);
            }

            $this->sent[] = ['token' => $deviceToken, 'title' => $title];
        }
    };
}

it('registra y quita el dispositivo de quien tiene la sesión', function (): void {
    $user    = Seed::user();
    $headers = JwtFactory::authHeader($user);
    $token   = 'fcm-' . bin2hex(random_bytes(20));

    $register = AppFactory::dispatch(AppFactory::request('POST', '/api/account/push-devices', ['token' => $token, 'platform' => 'web'], $headers));
    AppFactory::dispatch(AppFactory::request('POST', '/api/account/push-devices', ['token' => $token, 'platform' => 'web'], $headers));
    $devices = Shop::rows('SELECT user_id, platform FROM push_devices WHERE token = ?', [$token]);

    // Otra cuenta no puede borrar el dispositivo de esta.
    AppFactory::dispatch(AppFactory::request('DELETE', '/api/account/push-devices', ['token' => $token], JwtFactory::authHeader(Seed::user())));
    $stillThere = Shop::rows('SELECT COUNT(*) n FROM push_devices WHERE token = ?', [$token])[0]['n'];
    $delete     = AppFactory::dispatch(AppFactory::request('DELETE', '/api/account/push-devices', ['token' => $token], $headers));

    expect($register->getStatusCode())->toBe(204)
        ->and($devices)->toBe([['user_id' => $user, 'platform' => 'web']])
        ->and($stillThere)->toBe(1)
        ->and($delete->getStatusCode())->toBe(204)
        ->and(Shop::rows('SELECT COUNT(*) n FROM push_devices WHERE token = ?', [$token])[0]['n'])->toBe(0);
});

it('envía cada notificación pendiente una vez, a todos los dispositivos del usuario', function (): void {
    Seed::pdo()->exec("UPDATE push_notifications SET status = 'sent' WHERE status = 'pending'");
    $user = Seed::user();
    Seed::pdo()->prepare("INSERT INTO push_devices (user_id, platform, token) VALUES (?, 'web', ?), (?, 'web', ?)")
        ->execute([$user, 'tok-a-' . $user, $user, 'tok-b-' . $user]);
    PushOutbox::enqueue($user, 'points_earned', 'Ganaste puntos', 'Texto', ['route' => '/coach/puntos'], 'test:' . uniqid());

    $channel = fakeFcm();
    $first   = new PushSender($channel)->sendPending();
    $second  = new PushSender($channel)->sendPending();

    expect($first)->toMatchArray(['sent' => 1, 'failed' => 0, 'configured' => true])
        ->and($second['sent'])->toBe(0)
        ->and(array_column($channel->sent, 'token'))->toBe(['tok-a-' . $user, 'tok-b-' . $user]);
});

it('borra el dispositivo que FCM marca como inválido y reintenta hasta el máximo', function (): void {
    Seed::pdo()->exec("UPDATE push_notifications SET status = 'sent' WHERE status = 'pending'");
    $user = Seed::user();
    $dead = 'tok-dead-' . $user;
    $down = 'tok-down-' . $user;
    Seed::pdo()->prepare("INSERT INTO push_devices (user_id, platform, token) VALUES (?, 'web', ?), (?, 'web', ?)")->execute([$user, $dead, $user, $down]);
    $key = 'test:' . uniqid();
    PushOutbox::enqueue($user, 'points_earned', 'Título', 'Texto', ['route' => '/'], $key);

    $channel = fakeFcm([$dead => [404, '{"error":{"status":"UNREGISTERED"}}'], $down => [503, 'unavailable']]);
    $sender  = new PushSender($channel);
    $max     = (int) $_ENV['PUSH_MAX_ATTEMPTS'];

    for ($i = 0; $i < $max + 2; $i++) {
        $sender->sendPending();
        // La espera entre reintentos crece; aquí se adelanta el reloj.
        Seed::pdo()->prepare('UPDATE push_notifications SET created_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE source_key = ?')->execute([$key]);
    }

    expect(array_column(Shop::rows('SELECT token FROM push_devices WHERE user_id = ?', [$user]), 'token'))->toBe([$down])
        ->and(Shop::rows('SELECT status, attempts, last_error FROM push_notifications WHERE source_key = ?', [$key]))->toBe([
            ['status' => 'failed', 'attempts' => $max, 'last_error' => 'FCM 503'],
        ]);
});

it('sin las credenciales de Firebase no toca la cola', function (): void {
    $user = Seed::user();
    $key  = 'test:' . uniqid();
    PushOutbox::enqueue($user, 'points_earned', 'Título', 'Texto', ['route' => '/'], $key);

    $body = AppFactory::json(cron('push/send'));

    if (!is_file(__DIR__ . '/../../../' . $_ENV['FIREBASE_CREDENTIALS_PATH'])) {
        expect($body)->toBe(['sent' => 0, 'failed' => 0, 'skipped' => 0, 'configured' => false])
            ->and(Shop::rows('SELECT status, attempts FROM push_notifications WHERE source_key = ?', [$key]))->toBe([['status' => 'pending', 'attempts' => 0]]);
    } else {
        expect($body['configured'])->toBeTrue();
    }
});
