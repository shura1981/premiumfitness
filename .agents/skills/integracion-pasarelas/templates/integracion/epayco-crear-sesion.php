<?php
/**
 * Plantilla: Crear sesión de pago en ePayco
 *
 * Endpoint expuesto: POST /api/epayco/session/create
 * Middleware requerido: BasicAuth (valida Authorization: AUTHENTICATION_BASIC)
 *
 * Flujo:
 * 1. Recibe datos del e-commerce (invoice, amount, currency, etc.)
 * 2. Valida campos obligatorios
 * 3. Obtiene/refresca el Bearer token (caché 15 min en archivo JSON)
 * 4. POST a https://apify.epayco.co/payment/session/create
 * 5. Retorna { sessionId }
 *
 * El frontend usa el sessionId para redirigir al usuario al checkout de ePayco.
 *
 * Ver: ../../references/epayco-api.md
 * Ver: ../../references/env-variables.md#epayco
 */

declare(strict_types=1);

// ── Configuración ────────────────────────────────────────────────────────────

/** Ruta absoluta al caché del token. */
const TOKEN_FILE = __DIR__ . '/../../../../json/token/token_epayco.json';

/** TTL del Bearer token de ePayco en segundos (15 minutos). */
const TOKEN_TTL = 900;

/** Base URL de la API de ePayco. */
const EPAYCO_BASE_URL = 'https://apify.epayco.co';

// ── Helpers de token ─────────────────────────────────────────────────────────

/**
 * Construye los headers de autenticación para obtener el token Bearer.
 * El header EntityClientId lleva comillas simples literales — es intencional.
 */
function obtenerHeadersLogin(): array
{
    $basicAuth      = base64_encode($_ENV['PUBLIC_KEY'] . ':' . $_ENV['PRIVATE_KEY']);
    $entityClientId = '\'' . base64_encode($_ENV['P_CUST_ID_CLIENTE']) . '\'';

    return [
        'Authorization: Basic ' . $basicAuth,
        'EntityClientId: '       . $entityClientId,
        'Content-Type: application/json',
    ];
}

/**
 * Solicita un nuevo Bearer token a ePayco y lo guarda en el archivo de caché.
 *
 * @throws RuntimeException Si la petición falla o la respuesta no contiene el token.
 */
function generarNuevoToken(): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => EPAYCO_BASE_URL . '/login',
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => obtenerHeadersLogin(),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new \RuntimeException('cURL error al obtener token: ' . $curlError);
    }

    if (in_array($httpCode, [401, 403], true)) {
        throw new \RuntimeException('Credenciales ePayco inválidas (HTTP ' . $httpCode . ')');
    }

    if ($httpCode !== 200) {
        throw new \RuntimeException('Error al obtener token ePayco. HTTP ' . $httpCode . ': ' . $response);
    }

    $data = json_decode($response, true);

    if (!isset($data['token']) || empty($data['token'])) {
        throw new \RuntimeException('Respuesta de login sin campo "token": ' . $response);
    }

    $cache = ['token' => $data['token'], 'timestamp' => time()];
    file_put_contents(TOKEN_FILE, json_encode($cache, JSON_PRETTY_PRINT));

    return $data['token'];
}

/**
 * Retorna el Bearer token vigente, solicitando uno nuevo si el caché expiró.
 * Siempre usar esta función — nunca llamar a generarNuevoToken() directamente.
 */
function obtenerTokenEpayco(): string
{
    if (file_exists(TOKEN_FILE)) {
        $cache = json_decode(file_get_contents(TOKEN_FILE), true);

        if (isset($cache['token'], $cache['timestamp'])
            && (time() - (int) $cache['timestamp']) < TOKEN_TTL
        ) {
            return $cache['token'];
        }
    }

    return generarNuevoToken();
}

// ── Validación ───────────────────────────────────────────────────────────────

/**
 * Valida que los campos obligatorios para crear la sesión estén presentes.
 *
 * @throws InvalidArgumentException
 */
function validarCamposCrearSesion(array $data): void
{
    $required = ['checkout_version', 'name', 'currency', 'amount'];

    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            throw new \InvalidArgumentException("El campo '{$field}' es obligatorio.");
        }
    }
}

// ── Llamada a ePayco ─────────────────────────────────────────────────────────

/**
 * Crea la sesión de pago en ePayco.
 *
 * @param  array  $payload  Body completo validado del request.
 * @param  string $token    Bearer token vigente.
 * @return string           sessionId retornado por ePayco.
 * @throws RuntimeException Si la petición falla o no retorna sessionId.
 */
function crearSesionEpayco(array $payload, string $token): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => EPAYCO_BASE_URL . '/payment/session/create',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new \RuntimeException('cURL error al crear sesión: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new \RuntimeException('Error al crear sesión ePayco. HTTP ' . $httpCode . ': ' . $response);
    }

    $data = json_decode($response, true);

    // ePayco puede anidar el sessionId bajo data.sessionId o directamente en sessionId
    $sessionId = $data['data']['sessionId'] ?? $data['sessionId'] ?? null;

    if (empty($sessionId)) {
        throw new \RuntimeException('Respuesta sin sessionId: ' . $response);
    }

    return $sessionId;
}

// ── Controller / Entry point ─────────────────────────────────────────────────

/**
 * Maneja la petición POST /api/epayco/session/create.
 *
 * @param  array $requestBody  Datos decodificados del body JSON del request.
 * @return array               Respuesta con sessionId o error.
 */
function handleCrearSesion(array $requestBody): array
{
    try {
        validarCamposCrearSesion($requestBody);

        $token     = obtenerTokenEpayco();
        $sessionId = crearSesionEpayco($requestBody, $token);

        return [
            'success'   => true,
            'sessionId' => $sessionId,
        ];
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (\RuntimeException $e) {
        http_response_code(500);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ── Ejemplo de body request ──────────────────────────────────────────────────
/*
POST /api/epayco/session/create
Authorization: {AUTHENTICATION_BASIC}
Content-Type: application/json

{
    "checkout_version": "2",
    "name": "Nutramerican Pharma",
    "currency": "COP",
    "amount": 199900,
    "description": "Pago del pedido N 1123125",
    "lang": "ES",
    "invoice": "1123125",
    "country": "CO",
    "taxBase": 0,
    "tax": 0,
    "response": "https://www.pagos.nutramerican.com/checkout/respuesta.html",
    "confirmation": "https://www.nutramerican.com/api/confirmation.php",
    "extras": { "extra1": "dato1", "extra2": "dato2" },
    "billing": {
        "email": "cliente@email.com",
        "name": "Juan Pérez",
        "address": "Calle 123 # 45-67",
        "typeDoc": "CC",
        "numberDoc": "12345678",
        "callingCode": "+57",
        "mobilePhone": "3001234567"
    },
    "methodsDisable": []
}

Respuesta:
{
    "success": true,
    "sessionId": "abc123def456..."
}
*/
