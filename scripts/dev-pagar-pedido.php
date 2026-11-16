<?php

declare(strict_types=1);

/**
 * Solo para desarrollo local: simula el aviso de pago aprobado de Bold para un pedido, firmado con
 * el `BOLD_SECRET_KEY` del `.env`, como lo enviaría Bold. Sirve para probar una compra de punta a
 * punta sin pagar de verdad.
 *
 *   php scripts/dev-pagar-pedido.php <id del pedido> [valor pagado]
 *
 * Sin el valor, paga exactamente lo que vale el pedido. Se niega a correr fuera de local.
 */

require __DIR__ . '/../load/bootstrap.php';

use App\Infrastructure\Database\DatabaseConfig;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Http\CurlHttpClient;

if (PHP_SAPI !== 'cli' || ($_ENV['APP_ENV'] ?? 'production') === 'production' || !in_array($_ENV['DB_HOST'], ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Este script solo corre en local.\n");
    exit(1);
}

$orderId = (int) ($argv[1] ?? 0);

if ($orderId <= 0) {
    fwrite(STDERR, "Uso: php scripts/dev-pagar-pedido.php <id del pedido> [valor pagado]\n");
    exit(1);
}

DB::boot(DatabaseConfig::fromEnv());
$stmt = DB::getInstance()->prepare('SELECT amount FROM orders WHERE order_id = ?');
$stmt->execute([$orderId]);
$amount = $stmt->fetchColumn();

if ($amount === false) {
    fwrite(STDERR, "No existe el pedido {$orderId}.\n");
    exit(1);
}

$body = json_encode([
    'id'   => 'evt-local-' . bin2hex(random_bytes(6)),
    'type' => 'SALE_APPROVED',
    'data' => [
        'payment_id' => 'LOCAL-' . bin2hex(random_bytes(8)),
        'amount'     => ['total' => (float) ($argv[2] ?? $amount), 'currency' => 'COP'],
        'metadata'   => ['reference' => (string) $orderId],
    ],
], JSON_THROW_ON_ERROR);

$url = rtrim((string) ($_ENV['DEV_API_URL'] ?? 'http://localhost:8000'), '/') . '/api/payment-gateway/webhook/bold';

// `payload` como texto: la firma cubre exactamente estos bytes.
echo new CurlHttpClient()->request($url, [
    'method'  => 'POST',
    'headers' => [
        'Content-Type'     => 'application/json',
        'x-bold-signature' => hash_hmac('sha256', base64_encode($body), trim((string) $_ENV['BOLD_SECRET_KEY'])),
    ],
    'payload' => $body,
    'timeout' => 10,
]), PHP_EOL;
