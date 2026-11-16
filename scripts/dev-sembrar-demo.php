<?php

declare(strict_types=1);

/**
 * Solo para desarrollo local: crea un entrenador de demostración con datos para recorrer la app web,
 * usando la API real del backend local (registro, código SMS, enlaces, compras y aviso de pago de Bold),
 * como lo haría la app. Así los puntos, la comisión y los referidos salen de las reglas, no inventados.
 *
 *   php scripts/dev-sembrar-demo.php
 *
 * Crea, con la contraseña PASSWORD de abajo:
 *   - el entrenador DEMO (entra por /coach/entrar);
 *   - tres clientes suyos con compras pagadas (comisión y puntos para el entrenador);
 *   - un entrenador referido por él, con un cliente que compra: activa los puntos del referido;
 *   - una conversión de comisión a puntos, más una compra después para que quede comisión del mes;
 *   - un canje con puntos y una compra propia sin pagar (para ver «Pagar» en el pedido).
 * Necesita el backend en marcha (`composer run start`) y `SMS_DRIVER=log`: lee los códigos de
 * `logs/sms-dev.log`. Si el entrenador demo ya existe, no hace nada: `scripts/reset-db.sh` vacía la base.
 */

require __DIR__ . '/../load/bootstrap.php';

use App\Features\Referral\ReferralLink;
use App\Infrastructure\Database\DatabaseConfig;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;

const PASSWORD = 'steven123*';

/** Celulares y documentos de las cuentas demo (no son de nadie). */
const TRAINER        = ['full_name' => 'Andrés Demo Coach', 'phone' => '3009000001', 'document_id' => '900000001', 'coupon' => 'DEMOCOACH'];
const CLIENTS        = [
    ['full_name' => 'Laura Demo Cliente', 'phone' => '3009000002', 'document_id' => '900000002'],
    ['full_name' => 'Camilo Demo Cliente', 'phone' => '3009000003', 'document_id' => '900000003'],
    ['full_name' => 'Paula Demo Cliente', 'phone' => '3009000004', 'document_id' => '900000004'],
];
const REFERRED       = ['full_name' => 'Sofía Demo Referida', 'phone' => '3009000005', 'document_id' => '900000005', 'coupon' => 'DEMOSOFIA'];
const REFERRED_CLIENT = ['full_name' => 'Diego Demo Cliente', 'phone' => '3009000006', 'document_id' => '900000006'];

if (PHP_SAPI !== 'cli' || ($_ENV['APP_ENV'] ?? 'production') === 'production' || !in_array($_ENV['DB_HOST'], ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Este script solo corre en local.\n");
    exit(1);
}

if (($_ENV['SMS_DRIVER'] ?? '') !== 'log') {
    fwrite(STDERR, "Necesita SMS_DRIVER=log en el .env: lee los códigos de logs/sms-dev.log.\n");
    exit(1);
}

DB::boot(DatabaseConfig::fromEnv());
$pdo = DB::getInstance();

$exists = $pdo->prepare('SELECT id FROM users WHERE num_cell = ?');
$exists->execute([TRAINER['phone']]);
if ($exists->fetchColumn() !== false) {
    fwrite(STDOUT, "El entrenador demo ya existe (celular " . TRAINER['phone'] . "). Para crearlo de nuevo, vacía la base con scripts/reset-db.sh.\n");
    exit(0);
}

$api = rtrim((string) ($_ENV['DEV_API_URL'] ?? 'http://localhost:8000'), '/') . '/api';

/**
 * Petición JSON a la API local. Si el límite de peticiones responde 429, espera y repite.
 *
 * @param array<string, mixed>|null $body
 * @return array<string, mixed>
 */
function call(string $api, string $method, string $path, ?array $body = null, ?string $token = null): array
{
    for ($attempt = 0; $attempt < 4; $attempt++) {
        try {
            $raw = new CurlHttpClient()->request($api . $path, array_filter([
                'method'  => $method,
                'headers' => array_filter(['Content-Type' => 'application/json', 'Authorization' => $token !== null ? "Bearer {$token}" : null]),
                'payload' => $body,
                'timeout' => 30,
            ], static fn (mixed $value): bool => $value !== null));

            return $raw === '' ? [] : (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpRequestException $e) {
            if ($e->statusCode() !== 429) {
                throw new RuntimeException("{$method} {$path} respondió {$e->statusCode()}: {$e->errorPayload()}", 0, $e);
            }
            fwrite(STDOUT, "  (límite de peticiones: espero un minuto…)\n");
            sleep(61);
        }
    }

    throw new RuntimeException("{$method} {$path}: demasiados intentos.");
}

/** Último código enviado a un celular, del log de SMS de desarrollo. */
function lastCode(string $phone): string
{
    $lines = file(__DIR__ . '/../logs/sms-dev.log', FILE_IGNORE_NEW_LINES) ?: [];
    foreach (array_reverse($lines) as $line) {
        if (str_contains($line, "] {$phone}:") && preg_match('/\b(\d{6})\b/', $line, $match) === 1) {
            return $match[1];
        }
    }

    throw new RuntimeException("No encontré el código SMS de {$phone} en logs/sms-dev.log.");
}

/**
 * Registra y verifica una cuenta, con el enlace del entrenador si lo hay. Devuelve su id y su token.
 *
 * @param array<string, string> $person
 * @param array{kind:string, id:string}|null $referral
 * @return array{id:int, token:string}
 */
function account(string $api, array $person, string $role, ?array $referral = null): array
{
    call($api, 'POST', '/auth/register', array_filter([
        'full_name'        => $person['full_name'],
        'document_type_id' => 1,
        'document_id'      => $person['document_id'],
        'phone'            => $person['phone'],
        'email'            => 'demo' . $person['phone'] . '@premiumfitness.local',
        'password'         => PASSWORD,
        'role'             => $role,
        'coupon_code'      => $person['coupon'] ?? null,
    ]));
    $session = call($api, 'POST', '/auth/verify-phone', ['phone' => $person['phone'], 'code' => lastCode($person['phone']), 'referral' => $referral]);
    fwrite(STDOUT, "  {$role}: {$person['full_name']} ({$person['phone']})\n");

    return ['id' => (int) $session['user']['id'], 'token' => (string) $session['access_token']];
}

function address(string $api, string $token, int $cityId, string $label): int
{
    $created = call($api, 'POST', '/account/addresses', ['name' => $label, 'address' => 'Calle 100 # 15-20, apto 501', 'city_id' => $cityId], $token);

    return (int) ($created['id'] ?? $created['data']['id'] ?? 0);
}

/**
 * Compra con la API y, si `$pay`, simula el aviso de pago aprobado de Bold.
 *
 * @param list<array{code:int, quantity:int}> $items
 */
function purchase(string $api, string $token, int $addressId, array $items, bool $pay = true): int
{
    $orderId = (int) call($api, 'POST', '/checkout', ['items' => $items, 'address_id' => $addressId, 'payment_gateway' => 'bold'], $token)['order_id'];

    if ($pay) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/dev-pagar-pedido.php') . ' ' . $orderId . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException("No se pudo aprobar el pedido {$orderId}: " . implode("\n", $output));
        }
    }
    fwrite(STDOUT, '  pedido #' . $orderId . ($pay ? ' pagado' : ' sin pagar') . "\n");

    return $orderId;
}

$city = $pdo->query("SELECT id FROM cities WHERE name LIKE 'BOGOT%' ORDER BY id LIMIT 1")->fetchColumn()
    ?: $pdo->query('SELECT id FROM cities ORDER BY id LIMIT 1')->fetchColumn();
$cityId = (int) $city;

fwrite(STDOUT, "Cuentas\n");
$coach    = account($api, TRAINER, 'trainer');
$coachRef = ['kind' => 'c', 'id' => ReferralLink::encodeId($coach['id'])];
$clients  = array_map(static fn (array $person): array => account($api, $person, 'client', $coachRef), CLIENTS);
$referred = account($api, REFERRED, 'trainer', ['kind' => 't', 'id' => ReferralLink::encodeId($coach['id'])]);
$referredClient = account($api, REFERRED_CLIENT, 'client', ['kind' => 'c', 'id' => ReferralLink::encodeId($referred['id'])]);

// Productos reales del catálogo, disponibles y con imagen; los más baratos primero para que alcancen los puntos.
$catalog = array_values(array_filter(
    call($api, 'GET', '/products', token: $clients[0]['token'])['data'],
    static fn (array $product): bool => !$product['is_out_of_stock'] && $product['image'] !== null && $product['final_price'] > 0,
));
usort($catalog, static fn (array $a, array $b): int => $a['final_price'] <=> $b['final_price']);
if (count($catalog) < 6) {
    fwrite(STDERR, "El catálogo de la intranet tiene muy pocos productos disponibles.\n");
    exit(1);
}
$pick = static fn (int $i, int $quantity = 1): array => ['code' => (int) $catalog[$i % count($catalog)]['code'], 'quantity' => $quantity];
$mid  = intdiv(count($catalog), 2);

fwrite(STDOUT, "Compras de los clientes\n");
purchase($api, $clients[0]['token'], address($api, $clients[0]['token'], $cityId, 'Casa'), [$pick($mid, 2), $pick($mid + 3)]);
purchase($api, $clients[1]['token'], address($api, $clients[1]['token'], $cityId, 'Oficina'), [$pick($mid + 5, 3)]);
// La primera compra de un cliente de la referida activa los puntos del referido para el entrenador demo.
purchase($api, $referredClient['token'], address($api, $referredClient['token'], $cityId, 'Casa'), [$pick($mid + 1, 2)]);

fwrite(STDOUT, "Conversión de comisión a puntos\n");
$conversion = call($api, 'POST', '/trainer/commission/convert', ['idempotency_key' => 'demo-' . bin2hex(random_bytes(8))], $coach['token']);
fwrite(STDOUT, '  ' . number_format((int) ($conversion['points_granted'] ?? 0), 0, ',', '.') . " puntos\n");

fwrite(STDOUT, "Comisión del mes, después de convertir\n");
purchase($api, $clients[2]['token'], address($api, $clients[2]['token'], $cityId, 'Casa'), [$pick($mid + 2, 2), $pick($mid + 4)]);
purchase($api, $clients[0]['token'], (int) call($api, 'GET', '/account/addresses', token: $clients[0]['token'])['data'][0]['id'], [$pick($mid + 6)]);

fwrite(STDOUT, "Pedidos del entrenador\n");
$coachAddress = address($api, $coach['token'], $cityId, 'Casa');
$redemption   = call($api, 'POST', '/trainer/redemptions', [
    'items'           => [$pick(0)],
    'address_id'      => $coachAddress,
    'idempotency_key' => 'demo-' . bin2hex(random_bytes(8)),
], $coach['token']);
fwrite(STDOUT, '  canje #' . $redemption['order_id'] . "\n");
purchase($api, $coach['token'], $coachAddress, [$pick($mid + 7)], pay: false);

// Un lote vence en 6 días: así Inicio y Puntos muestran el aviso de puntos por vencer.
$pdo->prepare(
    "UPDATE point_lots SET expires_at = DATE_ADD(NOW(), INTERVAL 6 DAY)
     WHERE trainer_id = ? AND source = 'client_sale' AND status = 'available' AND remaining_points > 0 ORDER BY id LIMIT 1"
)->execute([$coach['id']]);

$dashboard = call($api, 'GET', '/trainer/dashboard', token: $coach['token']);
fwrite(STDOUT, "\nListo. Entra por /coach/entrar con el celular " . TRAINER['phone'] . " y la contraseña de PASSWORD en este script.\n");
fwrite(STDOUT, 'Clientes y referida: los celulares de CLIENTS, REFERRED y REFERRED_CLIENT, con la misma contraseña.' . "\n");
fwrite(STDOUT, 'Resumen del inicio: ' . json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
