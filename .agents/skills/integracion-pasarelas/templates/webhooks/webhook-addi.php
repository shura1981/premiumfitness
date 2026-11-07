<?php
/**
 * Webhook de confirmación — ADDI
 *
 * Addi envía un JSON body via POST.
 * No hay firma criptográfica; proteger por IP whitelist o token en header.
 *
 * Requiere: normalizePaymentStatus(), buildPaymentResult()
 * Ver: ../../references/canonical-structure.md
 * Ver: ../../references/addi-api.md#webhook
 */
declare(strict_types=1);

// Stubs para análisis estático cuando este template se abre fuera del proyecto real.
if (!function_exists('normalizePaymentStatus')) {
    function normalizePaymentStatus(string $gateway, mixed $rawStatus): array
    {
        return [
            'response' => (string) $rawStatus,
            'message' => 'Template placeholder',
            'gateway' => $gateway,
        ];
    }
}

if (!function_exists('buildPaymentResult')) {
    function buildPaymentResult(
        mixed $savedRecord,
        array $status,
        float $amount,
        string $transactionId,
        string $paymentMethod,
        string $paymentCode,
        array $extra = []
    ): array {
        return [
            'saved_record' => $savedRecord,
            'status' => $status,
            'amount' => $amount,
            'transaction_id' => $transactionId,
            'payment_method' => $paymentMethod,
            'payment_code' => $paymentCode,
            'extra' => $extra,
        ];
    }
}

if (!function_exists('YOUR_FETCH_ORDER')) {
    function YOUR_FETCH_ORDER(int $invoiceId): ?object
    {
        return (object) [
            'invoice_id' => $invoiceId,
            'str_res_pago' => (object) [
                'str_estado_pago' => null,
            ],
        ];
    }
}

if (!function_exists('YOUR_SAVE_ORDER')) {
    function YOUR_SAVE_ORDER(int $invoiceId, mixed $result): void
    {
    }
}

header("Content-Type: application/json");
date_default_timezone_set('America/Bogota');

// 1. Recibir payload JSON
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];

// Loguear payload crudo antes de cualquier procesamiento
// file_put_contents(__DIR__ . '/webhook_addi_log.txt', date('c') . ' ' . $rawInput . PHP_EOL, FILE_APPEND | LOCK_EX);

// 2. Extraer campos del payload de Addi
$invoiceIdRaw    = $data['orderId']          ?? null;  // ID interno que enviaste al iniciar el crédito
$transactionId   = $data['applicationId']    ?? null;  // ID externo generado por Addi
$approvedAmount  = $data['approvedAmount']   ?? null;
$rawStatus       = $data['status']           ?? null;  // "APPROVED" | "REJECTED" | "PENDING" | ...
$statusTimestamp = $data['statusTimestamp']  ?? null;
$currency        = $data['currency']         ?? 'COP';

// 3. Validar campos requeridos
if (!$invoiceIdRaw || !$transactionId || !$approvedAmount || !$rawStatus) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Datos incompletos']);
    exit;
}

$invoiceId = filter_var($invoiceIdRaw, FILTER_VALIDATE_INT);
if ($invoiceId === false) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'orderId inválido']);
    exit;
}

// 4. Normalizar estado (strings en inglés → texto canónico)
$status = normalizePaymentStatus('addi', $rawStatus);

// 5. Obtener registro existente del pedido (implementación propia de cada proyecto)
$savedRecord = YOUR_FETCH_ORDER($invoiceId);
if (!$savedRecord) {
    http_response_code(404);
    echo json_encode(['status' => false, 'message' => 'Pedido no encontrado']);
    exit;
}

// 6. Idempotencia: no reprocesar si ya está aprobado
if (($savedRecord->str_res_pago->str_estado_pago ?? null) === 'APROBADA') {
    http_response_code(200);
    echo json_encode(['status' => true, 'message' => 'Ya procesado']);
    exit;
}

// 7. Construir resultado canónico
$result = buildPaymentResult(
    $savedRecord,
    $status,
    (float) $approvedAmount,
    $transactionId,
    'ADDI',
    'AD',
    [
        'payment_method_id' => '100',
        'ticket_id'         => $transactionId,
        'service_code'      => $statusTimestamp ?? '',
        'card_number'       => 'TARJETA ADDI',
        'quotas'            => 1,
    ]
);

// 8. Persistir $result (implementación propia de cada proyecto)
YOUR_SAVE_ORDER($invoiceId, $result);

// 9. Acciones post-aprobación
if ($status['response'] === 'APROBADA') {
    // notificaciones, fulfillment, etc.
}

http_response_code(200);
echo json_encode(['status' => true, 'estado' => $status['response']]);
exit;
