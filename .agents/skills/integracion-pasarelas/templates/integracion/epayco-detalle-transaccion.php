<?php
/**
 * Plantilla: Detalle de transacción y reversión en ePayco
 *
 * Endpoints expuestos:
 *   POST /api/epayco/transaction/detail
 *   POST /api/epayco/transaction/reversion
 *   GET  /api/epayco/transactions
 *
 * Middleware requerido: BasicAuth (valida Authorization: AUTHENTICATION_BASIC)
 *
 * NOTA IMPORTANTE:
 * Los endpoints /transaction y /transaction/detail de ePayco usan método GET
 * con body JSON — patrón no estándar pero requerido por su API.
 * Se implementa con CURLOPT_CUSTOMREQUEST => 'GET' + CURLOPT_POSTFIELDS.
 *
 * Ver: ../../references/epayco-api.md
 * Ver: ../../references/env-variables.md#epayco
 * Requiere: epayco-crear-sesion.php (función obtenerTokenEpayco)
 */

declare(strict_types=1);

// Reutilizar obtenerTokenEpayco() de la plantilla de sesión
// require_once __DIR__ . '/epayco-crear-sesion.php';

/** Base URL de la API de ePayco. */
if (!defined('EPAYCO_BASE_URL')) {
    define('EPAYCO_BASE_URL', 'https://apify.epayco.co');
}

// ── Helper cURL con GET + body ────────────────────────────────────────────────

/**
 * Ejecuta una petición GET con body JSON hacia ePayco.
 * Este patrón no estándar es requerido por los endpoints /transaction y /transaction/detail.
 *
 * @param  string $endpoint  Ruta relativa (ej: '/transaction/detail')
 * @param  array  $body      Payload a enviar como JSON
 * @param  string $token     Bearer token vigente
 * @return array             Respuesta decodificada como array
 * @throws RuntimeException
 */
function epaycoGetConBody(string $endpoint, array $body, string $token): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL             => EPAYCO_BASE_URL . $endpoint,
        CURLOPT_CUSTOMREQUEST   => 'GET',          // ← GET no estándar
        CURLOPT_POSTFIELDS      => json_encode($body),
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 10,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_HTTPHEADER      => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new \RuntimeException('cURL error en ' . $endpoint . ': ' . $curlError);
    }

    if ($httpCode === 401) {
        throw new \RuntimeException('Token Bearer expirado o inválido (HTTP 401)');
    }

    if ($httpCode !== 200) {
        throw new \RuntimeException('Error en ' . $endpoint . '. HTTP ' . $httpCode . ': ' . $response);
    }

    $data = json_decode($response, true);

    if ($data === null) {
        throw new \RuntimeException('Respuesta JSON inválida en ' . $endpoint . ': ' . $response);
    }

    return $data;
}

// ── Detalle de transacción ────────────────────────────────────────────────────

/**
 * Obtiene el detalle completo de una transacción ePayco.
 *
 * @param  int    $referencePayco  Referencia numérica de ePayco
 * @param  string $token           Bearer token vigente
 * @return array                   Datos de la transacción (incluye campo 'log' con los x_* params)
 * @throws RuntimeException
 */
function obtenerDetalleTransaccion(int $referencePayco, string $token): array
{
    $body = ['filter' => ['referencePayco' => $referencePayco]];
    $data = epaycoGetConBody('/transaction/detail', $body, $token);

    // ePayco anida los datos bajo data
    return $data['data'] ?? $data;
}

/**
 * Maneja la petición POST /api/epayco/transaction/detail.
 */
function handleDetalleTransaccion(array $requestBody): array
{
    try {
        if (empty($requestBody['filter']['referencePayco'])) {
            throw new \InvalidArgumentException("El campo 'filter.referencePayco' es obligatorio.");
        }

        $token  = obtenerTokenEpayco();
        $detalle = obtenerDetalleTransaccion((int) $requestBody['filter']['referencePayco'], $token);

        return ['success' => true, 'data' => $detalle];
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (\RuntimeException $e) {
        http_response_code(500);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ── Listar transacciones ──────────────────────────────────────────────────────

/**
 * Lista transacciones con filtros y paginación.
 * Al menos uno de los filtros (referencePayco, referenceClient,
 * transactionInitialDate, transactionEndDate) debe estar presente.
 *
 * @param  array  $filtros      Criterios de búsqueda
 * @param  int    $page         Página (default 1)
 * @param  int    $limit        Registros por página (default 50)
 * @param  string $token        Bearer token vigente
 * @return array                Respuesta con pagination, data[] y aggregations
 * @throws InvalidArgumentException|RuntimeException
 */
function listarTransacciones(array $filtros, int $page, int $limit, string $token): array
{
    $filtrosPermitidos = ['referencePayco', 'referenceClient', 'transactionInitialDate', 'transactionEndDate'];
    $filtrosActivos    = array_intersect_key($filtros, array_flip($filtrosPermitidos));

    if (empty($filtrosActivos)) {
        throw new \InvalidArgumentException(
            'Se requiere al menos uno de: ' . implode(', ', $filtrosPermitidos)
        );
    }

    $body = [
        'filter'     => $filtrosActivos,
        'pagination' => ['page' => $page, 'limit' => $limit],
    ];

    return epaycoGetConBody('/transaction', $body, $token);
}

/**
 * Maneja la petición GET /api/epayco/transactions.
 *
 * @param  array  $queryParams  Parámetros de la query string
 */
function handleListarTransacciones(array $queryParams): array
{
    try {
        $filtros = [
            'referencePayco'        => $queryParams['referencePayco'] ?? null,
            'referenceClient'       => $queryParams['referenceClient'] ?? null,
            'transactionInitialDate'=> $queryParams['transactionInitialDate'] ?? null,
            'transactionEndDate'    => $queryParams['transactionEndDate'] ?? null,
        ];
        $filtros = array_filter($filtros, fn($v) => $v !== null && $v !== '');

        $page  = (int) ($queryParams['page']  ?? 1);
        $limit = (int) ($queryParams['limit'] ?? 50);

        $token  = obtenerTokenEpayco();
        $result = listarTransacciones($filtros, $page, $limit, $token);

        return ['success' => true, ...$result];
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (\RuntimeException $e) {
        http_response_code(500);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ── Reversión de transacción ──────────────────────────────────────────────────

/**
 * Revierte una transacción aprobada en ePayco.
 *
 * @param  int    $referencePayco  Referencia numérica de la transacción a revertir
 * @param  string $token           Bearer token vigente
 * @return array                   Respuesta cruda de ePayco
 * @throws RuntimeException
 */
function revertirTransaccion(int $referencePayco, string $token): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => EPAYCO_BASE_URL . '/transaction/reversion',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['referencePayco' => $referencePayco]),
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
        throw new \RuntimeException('cURL error al revertir transacción: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new \RuntimeException('Error al revertir transacción. HTTP ' . $httpCode . ': ' . $response);
    }

    return json_decode($response, true) ?? [];
}

/**
 * Maneja la petición POST /api/epayco/transaction/reversion.
 */
function handleReversionTransaccion(array $requestBody): array
{
    try {
        if (empty($requestBody['referencePayco'])) {
            throw new \InvalidArgumentException("El campo 'referencePayco' es obligatorio.");
        }

        $token    = obtenerTokenEpayco();
        $resultado = revertirTransaccion((int) $requestBody['referencePayco'], $token);

        return ['success' => true, 'data' => $resultado];
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (\RuntimeException $e) {
        http_response_code(500);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ── Ejemplos de uso ──────────────────────────────────────────────────────────
/*
──────────────────────────────────────────────
DETALLE DE TRANSACCIÓN
POST /api/epayco/transaction/detail
Authorization: {AUTHENTICATION_BASIC}
Content-Type: application/json

{ "filter": { "referencePayco": 346217247 } }

Respuesta:
{
  "success": true,
  "data": {
    "referencePayco": 346217247,
    "amount": 199900,
    "status": "Aceptada",
    "paymentMethod": "VISA",
    "log": {
      "x_ref_payco": "346217247",
      "x_transaction_id": "...",
      "x_amount": "199900",
      "x_signature": "...",
      "x_cod_respuesta": "1",
      "x_id_invoice": "1123125",
      ...
    },
    "extras": { "extra1": "...", "extra2": "..." },
    ...
  }
}

──────────────────────────────────────────────
LISTAR TRANSACCIONES
GET /api/epayco/transactions?referenceClient=1123125&page=1&limit=50
Authorization: {AUTHENTICATION_BASIC}

Respuesta:
{
  "success": true,
  "pagination": { "totalCount": 2, "limit": 50, "page": 1 },
  "data": [
    { "referencePayco": 346217247, "amount": 199900, "status": "Aceptada" }
  ],
  "aggregations": { "status": [...], "transactionType": [...], "franchises": [...] }
}

──────────────────────────────────────────────
REVERSIÓN
POST /api/epayco/transaction/reversion
Authorization: {AUTHENTICATION_BASIC}
Content-Type: application/json

{ "referencePayco": 346217247 }
*/
