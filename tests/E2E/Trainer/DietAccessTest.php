<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Acceso a la app de dietas (spec integraciones/02).
 */

function dietCheck(?string $document, ?string $apiKey = null, ?string $ip = null): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/integrations/diet-access',
        $document === null ? [] : ['document' => $document],
        ['X-Api-Key' => $apiKey ?? $_ENV['DIET_ACCESS_API_KEY']],
        ip: $ip,
    ));
}

function documentOf(int $userId): string
{
    return (string) Shop::rows('SELECT document_id FROM users WHERE id = ?', [$userId])[0]['document_id'];
}

function dietStatus(int $trainerId): array
{
    return AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/diet-access', headers: JwtFactory::authHeader($trainerId))));
}

function dietRedeem(int $trainerId): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('POST', '/api/trainer/diet-access/redeem', [], JwtFactory::authHeader($trainerId)));
}

/** Deja al entrenador con el mes gratis ya vencido. */
function expireTrial(int $trainerId): void
{
    Seed::pdo()->prepare('UPDATE trainers SET diet_first_access_at = DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE user_id = ?')->execute([$trainerId]);
}

it('exige la credencial de servicio y el documento', function (): void {
    $document = documentOf(Seed::trainer());

    expect(dietCheck($document, 'credencial-incorrecta-de-treinta-y-dos-caracteres')->getStatusCode())->toBe(401)
        ->and(dietCheck($document, '')->getStatusCode())->toBe(401)
        ->and(dietCheck(null)->getStatusCode())->toBe(422)
        // Un token de la app web no sirve como credencial.
        ->and(AppFactory::dispatch(AppFactory::request('POST', '/api/integrations/diet-access', ['document' => $document], JwtFactory::authHeader(Seed::trainer())))->getStatusCode())->toBe(401);
});

it('la primera consulta inicia el mes gratis y repetirla no lo reinicia', function (): void {
    $trainer  = Seed::trainer();
    $document = documentOf($trainer);

    expect(dietStatus($trainer))->toMatchArray(['has_access' => false, 'reason' => 'none', 'trial_available' => true]);

    $first = AppFactory::json(dietCheck($document));
    $start = Shop::rows('SELECT diet_first_access_at FROM trainers WHERE user_id = ?', [$trainer])[0]['diet_first_access_at'];
    Seed::pdo()->prepare('UPDATE trainers SET diet_first_access_at = DATE_SUB(diet_first_access_at, INTERVAL 10 DAY) WHERE user_id = ?')->execute([$trainer]);
    $again = AppFactory::json(dietCheck(' ' . substr($document, 0, 3) . '.' . substr($document, 3) . ' '));

    expect($first)->toMatchArray(['document' => $document, 'has_access' => true, 'status' => 'active', 'reason' => 'trial', 'renew_url' => null])
        ->and(array_keys($first))->toBe(['has_access', 'status', 'reason', 'valid_until', 'message', 'document', 'renew_url', 'checked_at'])
        ->and($start)->not->toBeNull()
        ->and(strtotime($first['valid_until']) - strtotime($start))->toBe((int) $_ENV['DIET_FREE_DAYS'] * 86400)
        // 10 días después sigue en su mes gratis y la fecha de inicio no cambia.
        ->and($again['reason'])->toBe('trial')
        ->and(strtotime($again['valid_until']))->toBe(strtotime($first['valid_until']) - 10 * 86400)
        ->and(dietStatus($trainer))->toMatchArray(['has_access' => true, 'reason' => 'trial', 'trial_available' => false]);
});

it('la respuesta no trae nombre, puntos ni saldos', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 123456);
    $json = (string) dietCheck(documentOf($trainer))->getBody();
    $name = (string) Shop::rows('SELECT full_name FROM users WHERE id = ?', [$trainer])[0]['full_name'];

    expect($json)->not->toContain($name, '123456', 'points', 'goal');
});

it('no da acceso a un cliente, a un documento desconocido ni a un entrenador suspendido', function (callable $document): void {
    $body = AppFactory::json(dietCheck($document()));

    expect($body)->toMatchArray(['has_access' => false, 'status' => 'not_found', 'reason' => 'none', 'renew_url' => null]);
})->with([
    'cliente'      => [fn (): string => documentOf(Seed::user())],
    'desconocido'  => [fn (): string => '9' . random_int(100000000, 999999999)],
    'suspendido'   => [function (): string {
        $trainer = Seed::trainer();
        Seed::pdo()->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$trainer]);

        return documentOf($trainer);
    }],
]);

it('pasado el mes gratis, sin meta ni canje, no hay acceso y dice dónde recuperarlo', function (): void {
    $trainer = Seed::trainer();
    expireTrial($trainer);

    $body = AppFactory::json(dietCheck(documentOf($trainer)));

    expect($body)->toMatchArray(['has_access' => false, 'status' => 'inactive', 'reason' => 'none', 'renew_url' => $_ENV['DIET_ACCESS_RENEW_URL']])
        // Sin acceso, la respuesta vale hasta el fin del día.
        ->and($body['valid_until'])->toBe(date('Y-m-d') . 'T23:59:59-05:00');
});

it('tiene acceso todo el mes si el mes anterior cumplió la meta con compras de sus clientes', function (): void {
    $trainer = Seed::trainer();
    expireTrial($trainer);
    $goal = (int) $_ENV['DIET_ACCESS_POINTS_GOAL'];

    // Los puntos por referido y los de una conversión no cuentan para la meta.
    Seed::pointLot($trainer, $goal, 'trainer_referral', '+5 months', 'first day of last month noon');
    Seed::pointLot($trainer, $goal, 'conversion', '+5 months', 'first day of last month noon');
    Seed::pointLot($trainer, $goal - 1, 'client_sale', '+5 months', 'first day of last month noon');
    expect(AppFactory::json(dietCheck(documentOf($trainer)))['has_access'])->toBeFalse();

    Seed::pointLot($trainer, 1, 'client_sale', '+5 months', 'last day of last month noon');
    $body   = AppFactory::json(dietCheck(documentOf($trainer)));
    $status = dietStatus($trainer);

    expect($body)->toMatchArray(['has_access' => true, 'status' => 'active', 'reason' => 'sales_goal'])
        ->and($body['valid_until'])->toBe(date('Y-m-t') . 'T23:59:59-05:00')
        ->and($status['goal'])->toBe(['points_required' => $goal, 'points_last_month' => $goal, 'points_this_month' => 0])
        ->and($status['redeem']['available'])->toBeFalse();
});

it('canjea el acceso del mes por puntos, una sola vez', function (): void {
    $trainer = Seed::trainer();
    expireTrial($trainer);
    $cost = (int) $_ENV['DIET_ACCESS_POINTS_COST'];
    Seed::pointLot($trainer, $cost + 10000);

    $before = dietStatus($trainer);
    $redeem = dietRedeem($trainer);
    $again  = dietRedeem($trainer);
    $period = Shop::rows('SELECT period, source, points_spent, points_goal FROM diet_access_periods WHERE trainer_id = ?', [$trainer]);

    expect($before['redeem'])->toBe(['available' => true, 'points_cost' => $cost, 'points_available' => $cost + 10000])
        ->and($redeem->getStatusCode())->toBe(200)
        ->and(AppFactory::json($redeem))->toMatchArray(['has_access' => true, 'reason' => 'points'])
        ->and(AppFactory::json($redeem)['redeem'])->toMatchArray(['available' => false, 'points_available' => 10000])
        // Repetir no cobra de nuevo.
        ->and($again->getStatusCode())->toBe(200)
        ->and(AppFactory::json($again)['redeem']['points_available'])->toBe(10000)
        ->and($period)->toBe([['period' => date('Y-m'), 'source' => 'points', 'points_spent' => $cost, 'points_goal' => (int) $_ENV['DIET_ACCESS_POINTS_GOAL']]])
        ->and(AppFactory::json(dietCheck(documentOf($trainer))))->toMatchArray(['has_access' => true, 'reason' => 'points']);
});

it('no canjea sin puntos suficientes ni cuando ya tiene acceso todo el mes', function (): void {
    $withoutPoints = Seed::trainer();
    expireTrial($withoutPoints);
    Seed::pointLot($withoutPoints, (int) $_ENV['DIET_ACCESS_POINTS_COST'] - 1);

    $withGoal = Seed::trainer();
    expireTrial($withGoal);
    Seed::pointLot($withGoal, (int) $_ENV['DIET_ACCESS_POINTS_GOAL'], 'client_sale', '+5 months', 'first day of last month noon');

    $poor    = dietRedeem($withoutPoints);
    $covered = dietRedeem($withGoal);

    expect($poor->getStatusCode())->toBe(409)
        ->and(AppFactory::json($poor)['code'])->toBe('not_enough_points')
        ->and(Shop::rows('SELECT COUNT(*) n FROM diet_access_periods WHERE trainer_id = ?', [$withoutPoints])[0]['n'])->toBe(0)
        ->and($covered->getStatusCode())->toBe(409)
        ->and(AppFactory::json($covered)['code'])->toBe('already_active')
        ->and(dietStatus($withGoal)['redeem']['points_available'])->toBe((int) $_ENV['DIET_ACCESS_POINTS_GOAL']);
});

it('limita las consultas por documento aunque cambie la IP', function (): void {
    $document = documentOf(Seed::trainer());

    for ($i = 0; $i < 30; $i++) {
        dietCheck($document);
    }

    expect(dietCheck($document)->getStatusCode())->toBe(429);
});

it('avisa una vez cuando el mes gratis está por vencer y cuando el acceso se desactiva', function (): void {
    $ending = Seed::trainer();
    Seed::pdo()->prepare('UPDATE trainers SET diet_first_access_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE user_id = ?')
        ->execute([(int) $_ENV['DIET_FREE_DAYS'] - 2, $ending]);

    $farFromEnding = Seed::trainer();
    Seed::pdo()->prepare('UPDATE trainers SET diet_first_access_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE user_id = ?')->execute([$farFromEnding]);

    // Terminó hace 12 horas: ayer tenía acceso y hoy no.
    $off = Seed::trainer();
    Seed::pdo()->prepare('UPDATE trainers SET diet_first_access_at = DATE_SUB(DATE_SUB(NOW(), INTERVAL ? DAY), INTERVAL 12 HOUR) WHERE user_id = ?')
        ->execute([(int) $_ENV['DIET_FREE_DAYS'], $off]);

    $neverEntered = Seed::trainer();
    $token        = ['X-Cron-Job-Token' => $_ENV['CRON_JOB_TOKEN']];

    $noToken = AppFactory::dispatch(AppFactory::request('POST', '/api/cron-job/diet-access/notify'));
    AppFactory::dispatch(AppFactory::request('POST', '/api/cron-job/diet-access/notify', headers: $token));
    AppFactory::dispatch(AppFactory::request('POST', '/api/cron-job/diet-access/notify', headers: $token));

    $types = fn (int $trainer): array => array_column(Shop::rows("SELECT type FROM push_notifications WHERE user_id = ? AND type LIKE 'diet_access_%'", [$trainer]), 'type');

    expect($noToken->getStatusCode())->toBeIn([401, 403])
        ->and($types($ending))->toBe(['diet_access_ending'])
        ->and($types($farFromEnding))->toBe([])
        ->and($types($off))->toBe(['diet_access_deactivated'])
        ->and($types($neverEntered))->toBe([]);
});
