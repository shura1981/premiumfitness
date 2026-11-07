<?php
/**
 * Plantilla: Crear orden de pago en Sistecrédito (Credinet)
 *
 * Endpoint expuesto: POST /siste-credito/crear-orden
 * Middleware requerido: tokenHeader (valida Authorization: KEY_HEADER)
 *
 * Flujo:
 * 1. Recibe datos del e-commerce
 * 2. Valida campos obligatorios
 * 3. Construye el body para Credinet via createPaymentRequestBody()
 * 4. POST a https://api.credinet.co/pay/create
 * 5. GET a https://api.credinet.co/pay/GetTransactionResponse
 * 6. Retorna { idTransaccion, paymentRedirectUrl }
 *
 * Ver: ../../references/sistecredito-api.md
 * Ver: ../../references/env-variables.md#sistecredito
 * Requiere: helpers/createPaymentRequestBody.php
 */

declare(strict_types=1);

require_once 'helpers/createPaymentRequestBody.php';

/**
 * Construye los headers de autenticación hacia Credinet.
 * Requiere las variables de entorno SUBSCRIPTION_KEY, APPLICATION_KEY, APPLICATION_TOKEN.
 */
function obtenerHeadersCredinet(): array
{
    return [
        'SCOrigen: Production',
        'country: co',
        'Content-Type: application/json',
        'Ocp-Apim-Subscription-Key: ' . $_ENV['SUBSCRIPTION_KEY'],
        'ApplicationKey: ' . $_ENV['APPLICATION_KEY'],
        'ApplicationToken: ' . $_ENV['APPLICATION_TOKEN'],
        'SCLocation: 0,0',
    ];
}

/**
 * Valida que los campos requeridos estén presentes en el array.
 * Lanza InvalidArgumentException si falta alguno.
 */
function validarCamposCrearOrden(array $data): void
{
    $required = ['invoice', 'description', 'value', 'docType', 'document', 'mode'];
    foreach ($required as $field) {
        if (empty($data[$field]) && $data[$field] !== 0) {
            throw new \InvalidArgumentException("El campo '{$field}' es obligatorio.");
        }
    }
}

/**
 * Consulta el estado de una transacción en Credinet.
 *
 * @param string $idTransaccion  El _id retornado por POST /pay/create
 * @return array  Array decodificado con la respuesta de Credinet
 * @throws Exception
 */
function queryIdTransaction(string $idTransaccion): array
{
    $url     = $_ENV['URL_GET_QUERY_TRANSACTION'] . '?transactionId=' . urlencode($idTransaccion);
    $timeout = (int) $_ENV['TIMEOUT_CURL_TOKEN'];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => obtenerHeadersCredinet(),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response  = curl_exec($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new \Exception("Error de cURL al consultar transacción: " . $curlError);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new \Exception("Error HTTP {$httpCode} al consultar transacción");
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception("Error al decodificar JSON de consulta: " . json_last_error_msg());
    }
    if (empty($decoded['data'])) {
        throw new \Exception("La respuesta de consulta no contiene el campo 'data'");
    }

    return $decoded;
}

/**
 * Extrae la paymentRedirectUrl del campo data retornado por Credinet.
 */
function extraerPaymentRedirectUrl(array $data): string
{
    return $data['paymentMethodResponse']['paymentRedirectUrl']
        ?? $data['data']['paymentMethodResponse']['paymentRedirectUrl']
        ?? '';
}

/**
 * Crea una orden de pago en Sistecrédito y retorna el ID de transacción.
 *
 * @param array $data  Datos validados del request del e-commerce
 * @return string  idTransaccion (_id generado por Credinet)
 * @throws Exception
 */
function crearOrdenSistecredito(array $data): string
{
    $urlCreate    = $_ENV['URL_POST_CREATE_ORDER'];
    $timeout      = (int) $_ENV['TIMEOUT_CURL_TOKEN'];
    $urlResponse  = $data['mode'] === '1'
        ? $_ENV['URL_RESPONSE_SISTECREDITO']
        : $_ENV['URL_RESPONSE_SISTECREDITO_LINKPAGO'];

    // urlResponse en el body sobreescribe la URL por defecto
    if (!empty($data['urlResponse'])) {
        $urlResponse = $data['urlResponse'];
    }

    $requestBody = createPaymentRequestBody(
        invoice:            $data['invoice'],
        description:        $data['description'],
        paymentMethodId:    2,          // siempre 2 para Sistecrédito
        currency:           'COP',
        value:              (int) $data['value'],
        sandboxIsActive:    false,      // siempre false en producción
        sandboxStatus:      'Approved',
        urlResponse:        $urlResponse,
        urlConfirmation:    $_ENV['URL_CONFIRMATION_SITECREDITO'],
        methodConfirmation: 'POST',
        docType:            $data['docType'],
        document:           $data['document']
    );

    // Log del request hacia Credinet (opcional — útil para debugging)
    // file_put_contents('log/request_body.json', $requestBody);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL             => $urlCreate,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_ENCODING        => '',
        CURLOPT_MAXREDIRS       => 10,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_CONNECTTIMEOUT  => 30,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST   => 'POST',
        CURLOPT_POSTFIELDS      => $requestBody,
        CURLOPT_HTTPHEADER      => obtenerHeadersCredinet(),
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_USERAGENT       => 'MiAPI/1.0',
    ]);

    $response  = curl_exec($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new \Exception("Error de cURL: " . $curlError);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new \Exception("Error HTTP {$httpCode}. Respuesta: " . substr($response, 0, 500));
    }
    if (empty($response)) {
        throw new \Exception("Respuesta vacía del servidor. HTTP Code: {$httpCode}");
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception("Error al decodificar JSON: " . json_last_error_msg());
    }
    if (empty($decoded['data'])) {
        throw new \Exception("La respuesta no contiene el campo 'data'");
    }

    $idTransaccion = $decoded['data']['_id'] ?? null;
    if (empty($idTransaccion)) {
        throw new \Exception("Error al obtener el id de transacción");
    }

    return (string) $idTransaccion;
}

// ─── Punto de entrada del endpoint ───────────────────────────────────────────
// Adaptar según el framework usado (Slim, Laravel, vanilla PHP, etc.)

try {
    // Leer body JSON del request
    $body = file_get_contents('php://input');
    $data = json_decode($body, true) ?? [];

    // Validar campos obligatorios
    validarCamposCrearOrden($data);

    // 1. Crear orden en Credinet → obtener idTransaccion
    $idTransaccion = crearOrdenSistecredito($data);

    // 2. Consultar transacción → obtener paymentRedirectUrl
    $responseTransaccion    = queryIdTransaction($idTransaccion);
    $paymentRedirectUrl     = extraerPaymentRedirectUrl($responseTransaccion['data']);

    // 3. Si la URL llega vacía → reintento con sleep(5)
    if (empty($paymentRedirectUrl)) {
        sleep(5);
        $responseTransaccion = queryIdTransaction($idTransaccion);
        $paymentRedirectUrl  = extraerPaymentRedirectUrl($responseTransaccion['data']);

        if (empty($paymentRedirectUrl)) {
            throw new \Exception("Error al obtener la url de redirección tras reintento");
        }
    }

    // 4. Responder al e-commerce
    header('Content-Type: application/json');
    echo json_encode(
        ['idTransaccion' => $idTransaccion, 'paymentRedirectUrl' => $paymentRedirectUrl],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Ha ocurrido un error', 'error' => $e->getMessage()]);
}
