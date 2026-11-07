# Integración ePayco — Flujo Completo

ePayco es una pasarela de pago colombiana para cobros con tarjeta, PSE y otros métodos. La API usada es **APIFY** (`apify.epayco.co`). El microservicio actúa como proxy: recibe del frontend, gestiona el token Bearer y reenvía las peticiones a ePayco.

---

## Arquitectura

```
Frontend (pagos.nutramerican.com)
        │  Basic Auth (AUTHENTICATION_BASIC)
        ▼
Microservicio PHP (epayco.nutramerican.com)
        │  Bearer {token}  (cache 15 min en archivo JSON)
        ▼
apify.epayco.co
        │  webhook server-to-server (x_* campos)
        ▼
Endpoint de confirmación (nutramerican.com/api/confirmation.php)
```

---

## Variables de entorno requeridas

Ver [env-variables.md#epayco](./env-variables.md#epayco).

---

## Autenticación de dos niveles

### Nivel 1 — Proteger la API interna con Basic Auth

```php
// Middleware BasicAuth: todas las rutas /api/epayco
// El cliente envía:
Authorization: <valor de AUTHENTICATION_BASIC>

// El middleware compara literalmente:
if ($_SERVER['HTTP_AUTHORIZATION'] !== $_ENV['AUTHENTICATION_BASIC']) {
    // 401 Unauthorized
}
```

`AUTHENTICATION_BASIC` es un string Base64 fijo definido en `.env`. No es un par usuario:contraseña estándar; es un token opaco.

### Nivel 2 — Bearer token hacia ePayco {#token}

El token Bearer se obtiene una vez y se cachea en `json/token/token_epayco.json` durante **15 minutos** (900 segundos).

```json
{
  "token": "eyJhbGciOiJSUzI1NiIsInR5cCI...",
  "timestamp": 1713898800
}
```

**Flujo de obtención / refresco:**

```
obtenerTokenEpayco()
    ├── ¿Existe json/token/token_epayco.json?
    │       NO → generarNuevoToken()
    │       SÍ → ¿(time() - timestamp) < 900?
    │               SÍ → retornar token cacheado
    │               NO → generarNuevoToken()
    └── generarNuevoToken()
            POST https://apify.epayco.co/login
            Headers:
              Authorization: Basic base64(PUBLIC_KEY:PRIVATE_KEY)
              EntityClientId: 'base64(P_CUST_ID_CLIENTE)'   ← comillas simples son intencionales
              Content-Type: application/json
            Body: vacío
            → { "token": "eyJ..." }
            → guardar en json/token/token_epayco.json con timestamp actual
```

> **Importante:** `EntityClientId` lleva comillas simples literales alrededor del valor Base64. Ejemplo: `'ODkyMzU='`. Omitirlas causa 401.

**Errores del token:**

| Código HTTP | Excepción | Causa |
|-------------|-----------|-------|
| 401 / 403 | `TokenInvalidException` | Credenciales inválidas |
| Otro != 200 | `ResponseException` | Error de red o servidor |
| Error cURL | `ResponseException` | Timeout, DNS, etc. |
| JSON sin `token` | `InvalidJsonException` | Respuesta inesperada |

**Siempre usar `HandleTokenService::obtenerTokenEpayco()`** — nunca llamar a `obtenerToken()` directamente.

---

## Configuración cURL común

Todas las peticiones a ePayco comparten esta configuración base:

```php
curl_setopt_array($ch, [
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
]);
```

---

## Endpoints consumidos de ePayco

| # | URL | Método | Auth | Propósito |
|---|-----|--------|------|-----------|
| 1 | `https://apify.epayco.co/login` | `POST` | `Basic base64(pub:priv)` + `EntityClientId` | Obtener Bearer token |
| 2 | `https://apify.epayco.co/payment/session/create` | `POST` | `Bearer {token}` | Crear sesión de checkout |
| 3 | `https://apify.epayco.co/transaction` | `GET` + body JSON | `Bearer {token}` | Listar transacciones |
| 4 | `https://apify.epayco.co/transaction/detail` | `GET` + body JSON | `Bearer {token}` | Detalle de transacción |
| 5 | `https://apify.epayco.co/transaction/reversion` | `POST` | `Bearer {token}` | Revertir transacción |

> **Patrón no estándar:** Los endpoints `/transaction` y `/transaction/detail` usan `GET` con body JSON. En cURL se implementa con `CURLOPT_CUSTOMREQUEST => 'GET'` + `CURLOPT_POSTFIELDS`.

---

## Endpoint 1 — Crear sesión de pago

**Ruta interna:** `POST /api/epayco/session/create`

**Campos requeridos en el body:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `checkout_version` | string | Versión del checkout (ej: `"2"`) |
| `name` | string | Nombre del comercio |
| `currency` | string | Código de moneda (ej: `"COP"`) |
| `amount` | int | Monto en centavos (ej: `199900`) |
| `description` | string | Descripción del pago |
| `invoice` | string | Número de factura / orden |
| `country` | string | Código de país (`"CO"`) |
| `response` | string | URL de retorno al usuario tras el pago |
| `confirmation` | string | URL del webhook server-to-server de ePayco |

**Campos opcionales:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `lang` | string | Idioma del checkout (`"ES"`) |
| `taxBase` | int | Base gravable |
| `tax` | int | IVA |
| `extras` | object | Hasta `extra10` para datos personalizados |
| `billing` | object | Datos del comprador: `email`, `name`, `address`, `typeDoc`, `numberDoc`, `callingCode`, `mobilePhone` |
| `methodsDisable` | array | Métodos de pago a deshabilitar |

**Respuesta:**

```json
{ "sessionId": "abc123def456..." }
```

El frontend usa el `sessionId` para redirigir al usuario al checkout de ePayco.

**Validaciones internas:** `currency`, `amount`, `name` y `checkout_version` son obligatorios.

---

## Endpoint 2 — Listar transacciones

**Ruta interna:** `GET /api/epayco/transactions`

**Query params (al menos uno obligatorio):**

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `referencePayco` | int | Referencia numérica de ePayco |
| `referenceClient` | string | ID de factura/visita |
| `transactionInitialDate` | string | Fecha inicio `YYYY-MM-DD` |
| `transactionEndDate` | string | Fecha fin `YYYY-MM-DD` |
| `page` | int | Página (default: 1) |
| `limit` | int | Registros por página (default: 50) |

**Body enviado a ePayco:**

```json
{
  "filter": {
    "referencePayco": 346217247,
    "referenceClient": "12345",
    "transactionInitialDate": "2026-01-01",
    "transactionEndDate": "2026-01-31"
  },
  "pagination": { "page": 1, "limit": 50 }
}
```

**Respuesta normalizada (`ResponseTransactionList`):**

```json
{
  "pagination": { "totalCount": 10, "limit": 50, "page": 1 },
  "data": [
    {
      "referencePayco": 346217247,
      "amount": 199900,
      "status": "Aceptada",
      ...
    }
  ],
  "aggregations": {
    "status": [...],
    "transactionType": [...],
    "franchises": [...]
  }
}
```

---

## Endpoint 3 — Detalle de transacción

**Ruta interna:** `POST /api/epayco/transaction/detail`

**Request body:**

```json
{ "filter": { "referencePayco": 346217247 } }
```

**Respuesta normalizada (`ResponseTransactionDetail`):**

Incluye un objeto `data` con 30+ campos, entre ellos:

| Campo | Descripción |
|-------|-------------|
| `referencePayco` | Referencia de ePayco |
| `amount` | Monto |
| `status` | Estado (`Aceptada`, `Rechazada`, etc.) |
| `paymentMethod` | Método de pago |
| `log` | Objeto con todos los `x_*` params del webhook (`x_ref_payco`, `x_transaction_id`, `x_amount`, `x_signature`, `x_cod_respuesta`, etc.) |
| `extras` | `extra1` … `extra10` |
| `allLogs` | Historial de estados |
| `movements` | Movimientos contables |

---

## Endpoint 4 — Reversión de transacción

**Ruta interna:** `POST /api/epayco/transaction/reversion`

**Request body:**

```json
{ "referencePayco": 346217247 }
```

**Respuesta:** JSON crudo de ePayco decodificado como array.

---

## Endpoint 5 — Cron job: verificar pendientes {#cron}

**Ruta interna:** `GET /api/epayco/cron-job-pending?fecha=YYYY-MM-DD`

Si no se pasa `fecha`, el controller usa `date('Y-m-d')` (hoy).

### Flujo completo

```
1. PendingPayService: SELECT id_visita FROM tb_visitas
   WHERE Pedidos IS NOT NULL
   AND fecha BETWEEN ? AND ?
   AND JSON_EXTRACT(jsonZonaPagos, '$.str_res_pago.str_estado_pago') = 'ENVIADO'

2. Para cada id_visita:
   a. Listar transacciones ePayco con referenceClient = id_visita
   b. ¿Existe alguna con status "Aceptada"?
      NO  → siguiente id_visita
      SÍ  → GET /transaction/detail con referencePayco de esa transacción
   c. Construir link de confirmación con ~30 parámetros extraídos del campo `log`
   d. POST al endpoint de confirmación del e-commerce
   e. Guardar respuesta en logs/responseConfirmation/confirmations.log
   f. sleep(3) para throttling
```

### Link de confirmación generado

```
https://www.nutramerican.com/api_MegaplexStar/api/confirmation.php
  ?x_ref_payco=...
  &x_transaction_id=...
  &x_amount=...
  &x_currency_code=...
  &x_signature=...
  &x_cod_respuesta=...
  &x_id_invoice=...
  &x_customer_email=...
  ... (~30 parámetros en total)
```

Los parámetros se extraen del objeto `data.log` de la respuesta de `/transaction/detail`.

### Script del cron (producción)

```bash
# cPanel — ejecutar cada N minutos
php /home3/crisenri/epayco.nutramerican.com/cronJob/checkPending.php
```

El script hace un `GET` interno a `http://epayco.nutramerican.com/api/epayco/cron-job-pending` con el header `Authorization: {AUTHENTICATION_BASIC}` y guarda la respuesta en `cronJob/log/query-{millis}.json`.

---

## Sistema de logging

| Archivo | Contenido | Función |
|---------|-----------|---------|
| `logs/errors/{servicio}.log` | Errores: mensaje, fecha, código, response crudo | `saveLogQueryState()` |
| `logs/responseConfirmation/confirmations.log` | Respuestas del endpoint de confirmación | `saveConfirmationLog()` |
| `cronJob/log/query-{millis}.json` | Respuesta completa del cron job | `checkPending.php` |

> Usar siempre rutas absolutas con `__DIR__` al escribir logs — las rutas relativas dependen del CWD y rompen el cron job.

---

## Sistema de excepciones ePayco

| Excepción | Hereda de | Código HTTP | Cuándo se lanza |
|-----------|-----------|:-----------:|-----------------|
| `EpaycoException` | `Exception` | 500 | Base para errores ePayco |
| `TokenInvalidException` | `EpaycoException` | 401 | Token vencido o credenciales inválidas |
| `ResponseException` | `EpaycoException` | 500 | Error en respuesta de ePayco o cURL |
| `ValidationInvalidException` | `Exception` | 400 | Campos requeridos faltantes |
| `InvalidJsonException` | `Exception` | 400 | JSON de respuesta inválido |

`EpaycoException` expone `getResponseRequest()` con la respuesta cruda de la API.

---

## Webhook de confirmación ePayco

ePayco envía el webhook en dos formatos posibles:

| Formato | Content-Type | Lectura |
|---------|--------------|---------|
| Form-encoded (legacy) | `application/x-www-form-urlencoded` | `$_REQUEST['x_campo']` |
| JSON (nuevo) | `application/json` | `json_decode(file_get_contents('php://input'))` |

### Campos principales del webhook (`x_*`)

| Campo | Descripción |
|-------|-------------|
| `x_ref_payco` | Referencia numérica de la transacción |
| `x_transaction_id` | ID único de transacción |
| `x_amount` | Monto |
| `x_currency_code` | Moneda (`COP`) |
| `x_signature` | Firma SHA-256 de verificación |
| `x_cod_transaction_state` | Estado numérico (1=Aceptada, 2=Rechazada, 3=Pendiente, 4=Fallida) |
| `x_cod_respuesta` | Código de respuesta |
| `x_id_invoice` | Número de factura |
| `x_response_reason_text` | Texto del motivo |
| `x_approval_code` | Código de autorización |
| `x_franchise` | Franquicia de la tarjeta |
| `x_extra1` … `x_extra10` | Datos adicionales |

### Verificación de firma (OBLIGATORIA)

```php
$expectedSignature = hash('sha256',
    $p_cust_id_cliente
    . '^' . $p_key
    . '^' . $xRefPayco
    . '^' . $xTransactionId
    . '^' . $xAmount
    . '^' . $xCurrencyCode
    . '^' . $xCodTransactionState
);

if (!hash_equals($expectedSignature, $xSignature)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Firma inválida']));
}
```

### Mapeo de estados numéricos

| `x_cod_transaction_state` | Estado | `$codResponse` | `$response` |
|:-------------------------:|--------|:--------------:|-------------|
| 1 | Aceptada | 1 | `APROBADA` |
| 2 | Rechazada | 2 | `PAGO RECHAZADO` |
| 3 | Pendiente | 3 | `PENDIENTE` |
| 4 | Fallida | 4 | `ERROR` |
| 6 | Reversada | 6 | `REVERSADA` |
| 7 | Retenida | 7 | `RETENIDA` |
| 8 | Iniciada | 8 | `INICIADA` |
| 9 | Expirada | 9 | `EXPIRADA` |
| 10 | Abandonada | 10 | `ABANDONADA` |
| 11 | Cancelada | 11 | `CANCELADA` |

### Responder siempre `200 OK`

```php
// Siempre responder 200 al webhook, aunque el procesamiento interno falle
// Un código != 200 provoca reintentos indefinidos de ePayco
http_response_code(200);
echo json_encode(['status' => 'ok']);
```

---

## Gotchas específicos de ePayco

- **`EntityClientId` requiere comillas simples** — el header debe enviarse como `'ODkyMzU='` (con comillas simples literales). Sin ellas, ePayco rechaza la petición con 401.
- **`/transaction` y `/transaction/detail` son GET con body** — patrón no estándar. En cURL: `CURLOPT_CUSTOMREQUEST => 'GET'` + `CURLOPT_POSTFIELDS => json_encode($body)`.
- **El TTL del token es 15 min, no más** — si se cachea por más tiempo, ePayco rechazará las peticiones con 401 sin mensaje claro.
- **Usar rutas absolutas en `file_put_contents`** — el cron job se ejecuta desde un directorio diferente; las rutas relativas fallan silenciosamente.
- **Nunca omitir verificación de firma en el webhook** — campos `x_*` pueden ser manipulados; solo `hash_equals` garantiza autenticidad.
- **Idempotencia en el webhook** — loguear el `x_ref_payco` procesado y verificar antes de confirmar; ePayco puede reintentar el webhook.
- **El cron no pasa `fecha`** — el parámetro es opcional; si no se envía, el controller usa `date('Y-m-d')`.
- **sleep(3) en el cron** — throttling intencional entre llamadas para no saturar la API de ePayco.
