<?php
/**
 * Webhook de confirmación — EPAYCO
 *
 * ePayco soporta DOS formatos de envío:
 * 1. Form-encoded (application/x-www-form-urlencoded) — legacy, usa $_REQUEST
 * 2. JSON raw body (application/json) — nuevo, usa php://input
 *
 * Este template detecta automáticamente el formato y extrae los datos.
 * Antes de procesar cualquier dato se debe verificar la firma SHA-256.
 *
 * Requiere: normalizePaymentStatus(), buildPaymentResult()
 * Ver: ../../references/canonical-structure.md
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

// Detectar formato del payload según Content-Type
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$isJson = (stripos($contentType, 'application/json') !== false);

// Función helper para extraer datos de ambos formatos
function getPayloadValue(bool $isJson, string $key, ?array $jsonData = null) {
    return $isJson ? ($jsonData[$key] ?? null) : ($_REQUEST[$key] ?? null);
}

// Si es JSON, decodificar el body
$jsonData = null;
if ($isJson) {
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true) ?? [];

    // Log del payload crudo
    file_put_contents(
        __DIR__ . '/webhook_epayco_log.txt',
        date('c') . ' [JSON] ' . $rawInput . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
} else {
    // Log del payload form-encoded
    file_put_contents(
        __DIR__ . '/webhook_epayco_log.txt',
        date('c') . ' [FORM] ' . http_build_query($_REQUEST) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// Credenciales de la cuenta ePayco (obtener desde el dashboard)
$p_cust_id_cliente = 'YOUR_EPAYCO_CLIENT_ID';
$p_key             = 'YOUR_EPAYCO_SECRET_KEY';

// 1. Extraer campos del payload de ePayco (compatible con ambos formatos)
$xRefPayco        = getPayloadValue($isJson, 'x_ref_payco', $jsonData);
$xTransactionId   = getPayloadValue($isJson, 'x_transaction_id', $jsonData);
$xAmount          = getPayloadValue($isJson, 'x_amount', $jsonData);
$xCurrencyCode    = getPayloadValue($isJson, 'x_currency_code', $jsonData) ?? 'COP';
$xSignature       = getPayloadValue($isJson, 'x_signature', $jsonData);
$invoiceIdRaw     = getPayloadValue($isJson, 'x_id_invoice', $jsonData);
$rawStatus        = getPayloadValue($isJson, 'x_cod_transaction_state', $jsonData); // estado numérico
$xCodRespuesta    = getPayloadValue($isJson, 'x_cod_respuesta', $jsonData);
$xErrorCode       = getPayloadValue($isJson, 'x_errorcode', $jsonData);
$xMotivo          = getPayloadValue($isJson, 'x_response_reason_text', $jsonData);
$xAutorizacion    = getPayloadValue($isJson, 'x_approval_code', $jsonData);
$xCustomerDoctype = getPayloadValue($isJson, 'x_customer_doctype', $jsonData);
$xFranchiseCode   = getPayloadValue($isJson, 'x_franchise', $jsonData);
$xBankName        = getPayloadValue($isJson, 'x_bank_name', $jsonData);
$xCardNumber      = getPayloadValue($isJson, 'x_cardnumber', $jsonData);
$xQuotas          = getPayloadValue($isJson, 'x_quotas', $jsonData) ?? 1;
$xTransactionDate = getPayloadValue($isJson, 'x_transaction_date', $jsonData) ?? date("Y-m-d H:i:s");
$xCustomerIp      = getPayloadValue($isJson, 'x_customer_ip', $jsonData) ?? '0.0.0.0';
$xExtra1          = getPayloadValue($isJson, 'x_extra1', $jsonData) ?? '';
$xExtra2          = getPayloadValue($isJson, 'x_extra2', $jsonData) ?? '';
$xExtra3          = getPayloadValue($isJson, 'x_extra3', $jsonData) ?? '';
$xExtra4          = getPayloadValue($isJson, 'x_extra4', $jsonData) ?? '';

// 2. Verificar firma SHA-256 (OBLIGATORIO — rechazar si no coincide)
$expectedSignature = hash('sha256',
    $p_cust_id_cliente . '^' .
    $p_key             . '^' .
    $xRefPayco         . '^' .
    $xTransactionId    . '^' .
    $xAmount           . '^' .
    $xCurrencyCode
);
if ($xSignature !== $expectedSignature) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Firma no válida']);
    exit;
}

// 3. Validar campos requeridos
if (!$invoiceIdRaw || !$xRefPayco || !$xAmount || $rawStatus === null) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Datos incompletos']);
    exit;
}

$invoiceId = filter_var($invoiceIdRaw, FILTER_VALIDATE_INT);
if ($invoiceId === false) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'x_id_invoice inválido']);
    exit;
}

// 4. Normalizar estado (int → texto canónico)
$status = normalizePaymentStatus('epayco', $rawStatus);

// 5. Resolver nombre de franquicia desde código corto
$franchiseNames = [
    'AM'  => 'Amex',        'BA' => 'Baloto',      'CR' => 'Credencial',
    'DC'  => 'Diners Club', 'EF' => 'Efecty',      'GA' => 'Gana',
    'PR'  => 'Punto Red',   'MC' => 'Mastercard',  'RS' => 'Red Servi',
    'PSE' => 'PSE',         'SP' => 'SafetyPay',   'VS' => 'Visa',
];
$franchise = $franchiseNames[$xFranchiseCode] ?? $xFranchiseCode;

// 6. Obtener registro existente del pedido (implementación propia de cada proyecto)
$savedRecord = YOUR_FETCH_ORDER($invoiceId);
if (!$savedRecord) {
    http_response_code(404);
    echo json_encode(['status' => false, 'message' => 'Pedido no encontrado']);
    exit;
}

// 7. Idempotencia — permitir Reversada aunque el estado previo sea APROBADA
$currentStatus = $savedRecord->str_res_pago->str_estado_pago ?? null;
$isReversal    = $status['response'] === 'Reversada' && $currentStatus === 'APROBADA';

if ($currentStatus === 'APROBADA' && !$isReversal) {
    http_response_code(200);
    echo json_encode(['status' => true, 'message' => 'Ya procesado']);
    exit;
}

// 8. Construir resultado canónico
$result = buildPaymentResult(
    $savedRecord,
    $status,
    (float) $xAmount,
    $xRefPayco,
    $franchise,
    $xCustomerDoctype ?? 'EP',  // str_codigo_transacción usa customer_doctype
    [
        'payment_method_id' => '40',
        'approval_code'     => $xCodRespuesta,       // int_pago_terminado (x_cod_respuesta)
        'ticket_id'         => $xTransactionId,
        'service_code'      => $xFranchiseCode ?? '',
        'bank_code'         => $xAutorizacion ?? '',  // int_codigo_banco usa approval_code real
        'bank_name'         => $xBankName ?? $franchise,
        'card_number'       => $xCardNumber ?? '',
        'quotas'            => (int) $xQuotas,
        'ip'                => $xCustomerIp,
        'date'              => $xTransactionDate,
        'error_code'        => $xErrorCode ?? '00',  // int_error (x_errorcode)
    ]
);

// 9. Persistir $result (implementación propia de cada proyecto)
YOUR_SAVE_ORDER($invoiceId, $result);

// 10. Acciones post-aprobación
if ($status['response'] === 'APROBADA') {
    // notificaciones, fulfillment, etc.
}

http_response_code(200);
echo json_encode(['status' => true, 'estado' => $status['response']]);
exit;
