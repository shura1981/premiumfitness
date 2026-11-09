<?php
/**
 * Plantilla: Consultar estado de transacción en Sistecrédito (Credinet)
 *
 * Endpoint expuesto: GET /siste-credito/consultar-orden?idTransaccion={id}
 * Middleware requerido: tokenHeader (valida Authorization: KEY_HEADER)
 *
 * Flujo:
 * 1. Recibe idTransaccion como query param
 * 2. GET a https://api.credinet.co/pay/GetTransactionResponse?transactionId={id}
 * 3. Normaliza el estado y retorna la estructura canónica
 *
 * Ver: ../../references/sistecredito-api.md#consultar-orden
 * Ver: ../../references/sistecredito-api.md#estados
 */

declare(strict_types=1);

/**
 * Normaliza el estado de Credinet al código/texto canónico.
 * Los estados de Credinet usan PascalCase: "Approved", "Rejected", "Pending"…
 *
 * @param string $statusResponse  Valor de paymentMethodResponse.statusResponse
 * @return array{x_cod_response: int, x_response: string}
 */
function normalizarEstadoSistecredito(string $statusResponse): array
{
    $map = [
        'Approved'                => ['x_cod_response' => 1,  'x_response' => 'APROBADA'],
        'Rejected'                => ['x_cod_response' => 2,  'x_response' => 'PAGO RECHAZADO'],
        'Pending'                 => ['x_cod_response' => 3,  'x_response' => 'PENDIENTE POR CR'],
        'Failed'                  => ['x_cod_response' => 4,  'x_response' => 'ERROR CR'],
        'PendingForPaymentMethod' => ['x_cod_response' => 10, 'x_response' => 'PENDIENTE POR MÉTODO DE PAGO'],
        'Cancelled'               => ['x_cod_response' => 11, 'x_response' => 'Cancelada'],
    ];

    return $map[$statusResponse] ?? ['x_cod_response' => 0, 'x_response' => 'DESCONOCIDO'];
}

/**
 * Consulta una transacción en Credinet y retorna la respuesta decodificada.
 *
 * @param string $idTransaccion  El _id de la transacción (retornado al crear la orden)
 * @return array  Array decodificado con campo 'data'
 * @throws Exception
 */
function consultarTransaccionCredinet(string $idTransaccion): array
{
    $url     = $_ENV['URL_GET_QUERY_TRANSACTION'] . '?transactionId=' . urlencode($idTransaccion);
    $timeout = (int) $_ENV['TIMEOUT_CURL_TOKEN'];

    $headers = [
        'SCOrigen: Production',
        'country: co',
        'Content-Type: application/json',
        'Ocp-Apim-Subscription-Key: ' . $_ENV['SUBSCRIPTION_KEY'],
        'ApplicationKey: ' . $_ENV['APPLICATION_KEY'],
        'ApplicationToken: ' . $_ENV['APPLICATION_TOKEN'],
        'SCLocation: 0,0',
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response  = curl_exec($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new \Exception("Error de cURL: " . $curlError);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new \Exception("Error HTTP {$httpCode}");
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception("Error al decodificar JSON: " . json_last_error_msg());
    }
    if (empty($decoded['data'])) {
        throw new \Exception("La respuesta no contiene el campo 'data'");
    }

    return $decoded;
}

/**
 * Construye la respuesta normalizada para el e-commerce desde los datos de Credinet.
 *
 * @param array $data  El campo 'data' de la respuesta de Credinet
 * @return array  Estructura canónica de respuesta
 */
function construirRespuestaNormalizada(array $data): array
{
    $pmr    = $data['paymentMethodResponse'] ?? [];
    $status = normalizarEstadoSistecredito($pmr['statusResponse'] ?? '');

    return [
        'data' => [
            'x_cod_response'        => $status['x_cod_response'],
            'x_transaction_date'    => $data['updatedAt'] ?? date('Y-m-d H:i:s'),
            'x_response'            => $status['x_response'],
            'x_response_reason_text'=> $pmr['statusResponse'] ?? '',
            'x_transaction_id'      => $pmr['transactionId'] ?? '',
            'x_bank_name'           => 'SISTECREDITO',
            'x_approval_code'       => $pmr['approvalCode'] ?? '',
            'x_id_invoice'          => $data['invoice'] ?? '',
            'x_amount'              => $data['value'] ?? 0,
            'x_currency_code'       => $data['currency'] ?? 'COP',
            'urlPayment'            => $pmr['paymentRedirectUrl'] ?? '',
        ]
    ];
}

// ─── Punto de entrada del endpoint ───────────────────────────────────────────
// Adaptar según el framework usado (Slim, Laravel, vanilla PHP, etc.)

try {
    // Leer query param (en Slim: $app->request()->params('idTransaccion'))
    $idTransaccion = $_GET['idTransaccion'] ?? null;

    if (empty($idTransaccion)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'El campo idTransaccion es obligatorio']);
        exit;
    }

    $responseTransaccion = consultarTransaccionCredinet($idTransaccion);
    $resultado           = construirRespuestaNormalizada($responseTransaccion['data']);

    header('Content-Type: application/json');
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);

} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Ha ocurrido un error', 'error' => $e->getMessage()]);
}
