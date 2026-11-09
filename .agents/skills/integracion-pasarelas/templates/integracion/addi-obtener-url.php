<?php
/**
 * Plantilla: Obtener URL de redirección de crédito Addi
 *
 * Endpoint expuesto: POST /addi/obtener-url
 * Middleware requerido: tokenHeader (valida Authorization: KEY_HEADER)
 *
 * Flujo:
 * 1. Recibe datos completos del pedido desde el e-commerce
 * 2. Obtiene token OAuth 2.0 de auth.addi.com
 * 3. POST pass-through a api.addi.com/v1/online-applications con Bearer token
 * 4. Addi responde con HTTP 302 + header Location
 * 5. Retorna { "url": "https://app.addi.com/..." }
 *
 * CRÍTICO: Addi responde HTTP 302 — la URL está en CURLINFO_REDIRECT_URL,
 * NO en el body. CURLOPT_FOLLOWLOCATION debe estar en false para capturarla.
 *
 * Ver: ../../references/addi-api.md
 * Ver: ../../references/env-variables.md#addi
 */

declare(strict_types=1);

/**
 * Obtiene un Bearer token OAuth 2.0 de Addi.
 * El token tiene expiración (campo expires_in en segundos).
 *
 * @return string  access_token JWT
 * @throws Exception  Si cURL falla o el HTTP code no es 200
 */
function obtenerTokenAddi(): string
{
    $payload = json_encode([
        'audience'      => $_ENV['ADDI_AUDIENCE'],
        'grant_type'    => $_ENV['GRANT_TYPE'],       // "client_credentials"
        'client_id'     => $_ENV['CLIENT_ID'],
        'client_secret' => $_ENV['CLIENT_SECRET'],
    ]);

    $timeout = (int) $_ENV['TIMEOUT_CURL_TOKEN'];

    $curl = curl_init($_ENV['URL_GET_TOKEN']);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response  = curl_exec($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    curl_close($curl);

    if ($response === false) {
        throw new \Exception("Error en cURL al obtener token Addi: {$curlError} (Código: {$curlErrno})");
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200) {
        $errDesc = $decoded['error_description'] ?? $decoded['error'] ?? "HTTP {$httpCode}";
        throw new \Exception("Error al obtener el token de Addi: " . $errDesc);
    }

    if (empty($decoded['access_token'])) {
        throw new \Exception("La respuesta de autenticación no contiene access_token");
    }

    return (string) $decoded['access_token'];
}

/**
 * Envía la solicitud de crédito a Addi y obtiene la URL de redirección.
 * El body se envía tal cual (pass-through); solo se inyecta el Bearer token.
 *
 * @param string $jsonBody  Body JSON exacto recibido del e-commerce
 * @return string  URL de redirección (vacía si Addi no retornó Location)
 * @throws Exception  Si cURL falla o el token no se pudo obtener
 */
function obtenerUrlAddi(string $jsonBody): string
{
    $token   = obtenerTokenAddi();
    $timeout = (int) $_ENV['TIMEOUT_CURL_TOKEN'];

    $curl = curl_init($_ENV['URL_GET_REDIRECTION']);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        // CRÍTICO: false para capturar el header Location del HTTP 302
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response  = curl_exec($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $location  = curl_getinfo($curl, CURLINFO_REDIRECT_URL); // URL del header Location
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    curl_close($curl);

    if ($response === false) {
        throw new \Exception("Error en cURL al llamar Addi: {$curlError} (Código: {$curlErrno})");
    }

    // Addi responde 302 con Location; cualquier otro código es inesperado pero no fatal
    return (string) ($location ?? '');
}

// ─── Punto de entrada del endpoint ───────────────────────────────────────────
// Adaptar según el framework usado (Slim, Laravel, vanilla PHP, etc.)

try {
    // Leer body JSON tal cual llega del e-commerce (pass-through)
    $rawBody = file_get_contents('php://input');

    // Validación básica: debe ser JSON válido con orderId
    $bodyDecoded = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($bodyDecoded['orderId'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Body inválido o falta el campo orderId']);
        exit;
    }

    $url = obtenerUrlAddi($rawBody);

    header('Content-Type: application/json');
    // El httpCode de Addi (302) se retorna al e-commerce tal cual en la impl. original
    // Si se quiere normalizar a 200, eliminar la línea de status
    echo json_encode(['url' => $url], JSON_UNESCAPED_SLASHES);

} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Ha ocurrido un error', 'error' => $e->getMessage()]);
}
