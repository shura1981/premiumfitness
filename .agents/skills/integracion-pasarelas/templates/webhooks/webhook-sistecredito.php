<?php
/**
 * Webhook de confirmación — SISTECREDITO
 *
 * Sistecrédito envía un JSON body via POST.
 * Los datos de la transacción están anidados en PaymentMethodResponse.
 *
 * Requiere: normalizePaymentStatus(), buildPaymentResult()
 * Ver: ../../references/canonical-structure.md
 * Ver: ../../references/sistecredito-api.md#webhook
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
// file_put_contents(__DIR__ . '/webhook_sistecredito_log.txt', date('c') . ' ' . $rawInput . PHP_EOL, FILE_APPEND | LOCK_EX);

if (empty($data) || empty($data['PaymentMethodResponse'])) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Payload inválido']);
    exit;
}

// 2. Extraer campos del payload de Sistecrédito
$pmr           = $data['PaymentMethodResponse'];
$invoiceIdRaw  = $data['Invoice']          ?? null;  // ID interno del pedido
$transactionId = $pmr['TransactionId']     ?? null;  // ID externo de Sistecrédito
$approvedAmount= $data['Value']            ?? null;
$rawStatus     = $pmr['StatusResponse']    ?? null;  // estado PascalCase: "Approved", "Rejected"…
$approvalCode  = $pmr['ApprovalCode']      ?? null;
$authCode      = $pmr['AuthorizationCode'] ?? null;
$storeId       = $data['StoreId']          ?? null;
$currency      = $data['Currency']         ?? 'COP';

// 3. Validar campos requeridos
if (!$invoiceIdRaw || !$transactionId || !$approvedAmount || !$rawStatus) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Datos incompletos']);
    exit;
}

$invoiceId = filter_var($invoiceIdRaw, FILTER_VALIDATE_INT);
if ($invoiceId === false) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Invoice inválido']);
    exit;
}

// 4. Normalizar estado (PascalCase → texto canónico)
// Importante: Credinet usa PascalCase ("Approved", "Rejected", "Pending"…), no mayúsculas
$status = normalizePaymentStatus('sistecredito', $rawStatus);

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
    'SISTECREDITO',
    'SC',
    [
        'payment_method_id' => '40',
        'approval_code'     => $approvalCode,
        'ticket_id'         => $authCode,
        'service_code'      => $storeId ?? '',
        'card_number'       => 'TARJETA SISTECREDITO',
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
