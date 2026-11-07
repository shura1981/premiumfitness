# Integración Sistecrédito (Credinet) — Referencia Completa

API Base: `https://api.credinet.co`

---

## Endpoints

| Método | URL | Descripción |
|--------|-----|-------------|
| `POST` | `https://api.credinet.co/pay/create` | Crear una orden de pago y obtener ID de transacción |
| `GET` | `https://api.credinet.co/pay/GetTransactionResponse?transactionId={id}` | Consultar estado de transacción y obtener URL de pago |

---

## Flujo Completo: Crear Orden {#crear-orden}

```
E-commerce → API Intermediaria → Credinet
                    ↓ POST /pay/create
                    ↓ GET  /pay/GetTransactionResponse
                    ↓
            ← { idTransaccion, paymentRedirectUrl }
                    ↓
E-commerce  redirige al usuario a paymentRedirectUrl
```

### 1. Request del e-commerce hacia la API intermediaria

**Endpoint:** `POST /siste-credito/crear-orden`  
**Header:** `Authorization: {KEY_HEADER}`  
**Content-Type:** `application/json`

```json
{
  "invoice": "1112",
  "description": "PAGO DEL PEDIDO Nº 1112",
  "value": 128642,
  "docType": "CC",
  "document": "12345678",
  "mode": "1",
  "urlResponse": "(opcional — sobreescribe la URL por defecto)"
}
```

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `invoice` | string | ✅ | Número de factura/pedido interno |
| `description` | string | ✅ | Descripción del pago (visible al usuario) |
| `value` | int | ✅ | Monto en COP sin decimales |
| `docType` | string | ✅ | Tipo de documento del cliente: `CC`, `CE`, `NIT`, `TI`, `PP` |
| `document` | string | ✅ | Número de documento del cliente |
| `mode` | string | ✅ | `"1"` = flujo e-commerce; cualquier otro = flujo link de pago |
| `urlResponse` | string | ❌ | URL de redirección personalizada; sobreescribe `URL_RESPONSE_SISTECREDITO` |

### 2. Body construido y enviado a Credinet

Construido por `createPaymentRequestBody()` (ver plantilla `helpers/createPaymentRequestBody.ts`):

```json
{
  "invoice": "1112",
  "description": "PAGO DEL PEDIDO Nº 1112",
  "paymentMethod": { "paymentMethodId": 2 },
  "currency": "COP",
  "value": 128642,
  "sandbox": { "isActive": false, "status": "Approved" },
  "urlResponse": "https://tienda.com/response-sistecredito/index.html",
  "urlConfirmation": "https://api.tudominio.com/api/webhooks/sistecredito",
  "methodConfirmation": "POST",
  "client": { "docType": "CC", "document": "12345678" },
  "extraData": { "Authentication": "" }
}
```

> **`paymentMethodId` siempre es `2`** — es el método de pago Sistecrédito en Credinet. No cambiar.  
> **`sandbox.isActive` siempre `false` en producción** — con `true` cualquier transacción es aprobada sin validar crédito real.

### 3. Headers hacia Credinet

```
SCOrigen: Production
country: co
Content-Type: application/json
Ocp-Apim-Subscription-Key: {SUBSCRIPTION_KEY}
ApplicationKey: {APPLICATION_KEY}
ApplicationToken: {APPLICATION_TOKEN}
SCLocation: 0,0
```

### 4. Respuesta de Credinet (POST /pay/create)

```json
{
  "data": {
    "_id": "67abcdef1234567890abcdef",
    "paymentMethodResponse": {
      "paymentRedirectUrl": ""
    }
  }
}
```

> El `_id` es el `idTransaccion` que se usará en todos los pasos siguientes.  
> `paymentRedirectUrl` **puede llegar vacía** en esta respuesta. Ver sección de reintento.

### 5. Consulta de estado (GET /pay/GetTransactionResponse)

Inmediatamente después de crear la orden, se consulta para obtener la URL definitiva:

```
GET https://api.credinet.co/pay/GetTransactionResponse?transactionId={_id}
Headers: (mismos 4 headers de autenticación de Credinet)
```

#### Respuesta con URL disponible

```json
{
  "data": {
    "_id": "67abcdef1234567890abcdef",
    "invoice": "1112",
    "value": 128642,
    "currency": "COP",
    "statusResponse": "Pending",
    "paymentMethodResponse": {
      "paymentRedirectUrl": "https://credinet.co/pago/...",
      "transactionId": "SC-123456",
      "statusResponse": "Pending",
      "approvalCode": null
    }
  }
}
```

### 6. Lógica de reintento cuando `paymentRedirectUrl` llega vacía

```typescript
let paymentRedirectUrl = extraerUrlDeRespuesta(responseTransaccion.data);

if (!paymentRedirectUrl) {
  await new Promise((resolve) => setTimeout(resolve, 5000)); // esperar 5 segundos
  responseTransaccion = await queryIdTransaction(idTransaccion);
  paymentRedirectUrl = extraerUrlDeRespuesta(responseTransaccion.data);

  if (!paymentRedirectUrl) {
    throw new Error('Error al obtener la url de redirección');
  }
}
```

### 7. Respuesta al e-commerce

```json
{
  "idTransaccion": "67abcdef1234567890abcdef",
  "paymentRedirectUrl": "https://credinet.co/pago/..."
}
```

---

## Flujo: Consultar Estado de Transacción {#consultar-orden}

**Endpoint:** `GET /siste-credito/consultar-orden?idTransaccion={id}`  
**Header:** `Authorization: {KEY_HEADER}`

Llama internamente a `GET /pay/GetTransactionResponse` y normaliza la respuesta.

### Respuesta normalizada al e-commerce

```json
{
  "data": {
    "x_cod_response": 1,
    "x_transaction_date": "2024-10-15 14:30:00",
    "x_response": "APROBADA",
    "x_response_reason_text": "El valor solicitado fue aprobado",
    "x_transaction_id": "SC-123456",
    "x_bank_name": "SISTECREDITO",
    "x_approval_code": "ABC123",
    "x_id_invoice": "1112",
    "x_amount": 128642,
    "x_currency_code": "COP",
    "urlPayment": "https://credinet.co/pago/..."
  }
}
```

---

## Normalización de Estados de Credinet {#estados}

| `statusResponse` de Credinet | `x_response` canónico | `x_cod_response` | Significado |
|------------------------------|----------------------|:----------------:|-------------|
| `Approved` | `APROBADA` | 1 | Crédito aprobado al 100% |
| `Rejected` | `PAGO RECHAZADO` | 2 | Cliente no aprobado para crédito |
| `Pending` | `PENDIENTE POR CR` | 3 | En proceso de validación |
| `Failed` | `ERROR CR` | 4 | Error en plataforma Sistecrédito |
| `PendingForPaymentMethod` | `PENDIENTE POR MÉTODO DE PAGO` | 10 | En validación de método de pago |
| `Cancelled` | `Cancelada` | 11 | Declinada por el cliente |
| *(desconocido)* | `DESCONOCIDO` | 0 | Estado no reconocido — loguear |

> **Los valores de `statusResponse` en Credinet usan PascalCase** (`Approved`, `Rejected`, `Pending`…), no mayúsculas. El mapper debe comparar exactamente este casing.

---

## Webhook de Confirmación (Server-to-Server) {#webhook}

Credinet hace un `POST` al `urlConfirmation` enviado en el body de creación.

### Payload recibido

```json
{
  "Invoice": "1112",
  "Value": 128642,
  "Currency": "COP",
  "StoreId": "STORE_ID",
  "PaymentMethodResponse": {
    "TransactionId": "SC-123456",
    "StatusResponse": "Approved",
    "ApprovalCode": "ABC123",
    "AuthorizationCode": "AUTH456"
  }
}
```

| Campo | Ruta en JSON | Descripción |
|-------|-------------|-------------|
| Invoice ID interno | `Invoice` (raíz) | Tu número de factura/pedido |
| Monto | `Value` (raíz) | Monto en COP |
| ID transacción externa | `PaymentMethodResponse.TransactionId` | ID de Credinet |
| Estado | `PaymentMethodResponse.StatusResponse` | Estado en PascalCase |
| Código aprobación | `PaymentMethodResponse.ApprovalCode` | Código de aprobación |
| Código autorización | `PaymentMethodResponse.AuthorizationCode` | Código de autorización |

> Ver plantilla completa en `templates/webhooks/webhook-sistecredito.ts`

---

## Sistema de Logging

| Archivo | Contenido |
|---------|-----------|
| `log/request_body.json` | Body enviado a Credinet |
| `log/response_body.json` | Respuesta decodificada de Credinet |
| `log/curl_debug.json` | Info completa cURL (URL, HTTP code, headers, raw response) |
| `log/curl_error.log` | Errores de cURL (append) |
| `log/http_error.log` | Errores HTTP no-2xx (append) |
| `log/empty_response.log` | Respuestas vacías (append) |
| `log/json_error.log` | Errores de parsing JSON (append) |
| `log/structure_error.log` | Respuestas sin campo `data` esperado (append) |
| `log/id_error.log` | Fallos al extraer `_id` de la transacción (append) |

---

## Cadena de Validaciones y Errores

```
1. Middleware tokenHeader → 401 si Authorization no coincide con KEY_HEADER
2. validarCamposPost() → InvalidArgumentException si falta campo obligatorio
3. cURL exec falla → Exception "Error de cURL: {mensaje}"
4. HTTP code != 2xx → Exception "Error HTTP {code}. Respuesta: {primeros 500 chars}"
5. Respuesta vacía → Exception "Respuesta vacía del servidor"
6. JSON inválido → Exception "Error al decodificar JSON"
7. Sin campo 'data' en respuesta → Exception "no contiene el campo 'data'"
8. Sin _id en data → Exception "Error al obtener el id"
9. paymentRedirectUrl vacía → sleep(5) → reintento → Exception si persiste
```
