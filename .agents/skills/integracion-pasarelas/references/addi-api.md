# Integración Addi — Referencia Completa

API Base producción: `https://api.addi.com`  
Auth producción: `https://auth.addi.com`

---

## Endpoints

| Método | URL | Descripción |
|--------|-----|-------------|
| `POST` | `https://auth.addi.com/oauth/token` | Obtener Bearer token OAuth 2.0 |
| `POST` | `https://api.addi.com/v1/online-applications` | Crear aplicación de crédito y obtener URL de redirección |

Endpoints staging:

| Método | URL |
|--------|-----|
| `POST` | `https://auth.addi-staging.com/oauth/token` |
| `POST` | `https://api.addi-staging.com/v1/online-applications` |

---

## Flujo Completo: Obtener URL de Redirección {#flujo}

```
E-commerce → API Intermediaria
                    ↓ POST /oauth/token  → auth.addi.com
                    ← { access_token }
                    ↓ POST /v1/online-applications (Bearer token)  → api.addi.com
                    ← HTTP 302 + Location header
                    ↓ captura CURLINFO_REDIRECT_URL
                    ← { "url": "https://app.addi.com/..." }
E-commerce redirige al usuario a la URL
```

---

## Autenticación OAuth 2.0 {#autenticacion}

Addi usa **OAuth 2.0 Client Credentials**. El token se obtiene en cada request y tiene expiración.

### Request de autenticación

```
POST https://auth.addi.com/oauth/token
Content-Type: application/json
```

```json
{
  "audience": "https://api.addi.com",
  "grant_type": "client_credentials",
  "client_id": "{CLIENT_ID}",
  "client_secret": "{CLIENT_SECRET}"
}
```

> Los valores provienen de las variables de entorno `ADDI_AUDIENCE`, `GRANT_TYPE`, `CLIENT_ID`, `CLIENT_SECRET`.

### Respuesta

```json
{
  "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6...",
  "token_type": "Bearer",
  "expires_in": 86400
}
```

> El token es un JWT Bearer. Se usa como `Authorization: Bearer {access_token}` en el siguiente request.  
> El campo `expires_in` está en segundos. Si se implementa cache, no reutilizar más allá de ese tiempo.

---

## Crear Aplicación de Crédito (Obtener URL) {#crear-aplicacion}

### Request del e-commerce hacia la API intermediaria

**Endpoint:** `POST /addi/obtener-url`  
**Header:** `Authorization: {KEY_HEADER}`  
**Content-Type:** `application/json`

El body se envía **pass-through** a Addi. La API intermediaria **no transforma los datos**, solo inyecta el token Bearer.

```json
{
  "orderId": "653449",
  "totalAmount": "255000.0",
  "shippingAmount": "50000.0",
  "totalTaxesAmount": "100000.0",
  "currency": "COP",
  "items": [
    {
      "sku": "PD-122354",
      "name": "Proteína chocolate",
      "quantity": "5",
      "unitPrice": 9950,
      "tax": 0,
      "pictureUrl": "https://tienda.com/imagen.jpg",
      "category": "nutrition",
      "brand": "MiMarca"
    }
  ],
  "client": {
    "idType": "CC",
    "idNumber": "12345678",
    "firstName": "JUAN",
    "lastName": "PEREZ",
    "email": "juan@ejemplo.com",
    "cellphone": "3001234567",
    "cellphoneCountryCode": "+57",
    "address": {
      "lineOne": "Calle 123 # 45-67",
      "city": "Bogotá D.C",
      "country": "CO"
    }
  },
  "shippingAddress": {
    "lineOne": "Calle 123 # 45-67",
    "city": "Bogotá D.C",
    "country": "CO"
  },
  "billingAddress": {
    "lineOne": "Calle 123 # 45-67",
    "city": "Bogotá D.C",
    "country": "CO"
  },
  "pickUpAddress": {
    "lineOne": "Calle 123 # 45-67",
    "city": "Bogotá D.C",
    "country": "CO"
  },
  "allyUrlRedirection": {
    "logoUrl": "https://tienda.com/logo.png",
    "callbackUrl": "https://api.tudominio.com/api/webhooks/addi",
    "redirectionUrl": "https://tienda.com/response-addi/index.html?id_order=653449"
  },
  "geoLocation": {
    "latitude": "4.624335",
    "longitude": "-74.063644"
  }
}
```

### Campos del body

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `orderId` | string | ✅ | ID del pedido en el sistema del comercio |
| `totalAmount` | string | ✅ | Monto total en COP (como string con decimal) |
| `shippingAmount` | string | ✅ | Monto del envío |
| `totalTaxesAmount` | string | ✅ | Total de impuestos |
| `currency` | string | ✅ | Siempre `"COP"` |
| `items` | array | ✅ | Lista de productos del pedido |
| `items[].sku` | string | ✅ | SKU del producto |
| `items[].name` | string | ✅ | Nombre del producto |
| `items[].quantity` | string | ✅ | Cantidad (como string) |
| `items[].unitPrice` | number | ✅ | Precio unitario en COP |
| `items[].tax` | number | ✅ | IVA del producto |
| `items[].pictureUrl` | string | ❌ | URL de imagen del producto |
| `items[].category` | string | ❌ | Categoría del producto |
| `items[].brand` | string | ❌ | Marca del producto |
| `client.idType` | string | ✅ | Tipo de documento: `CC`, `CE`, `PP`, `NIT` |
| `client.idNumber` | string | ✅ | Número de documento |
| `client.firstName` | string | ✅ | Nombres del cliente |
| `client.lastName` | string | ✅ | Apellidos del cliente |
| `client.email` | string | ✅ | Correo electrónico |
| `client.cellphone` | string | ✅ | Teléfono móvil (sin indicativo) |
| `client.cellphoneCountryCode` | string | ✅ | Indicativo del país: `"+57"` para Colombia |
| `client.address` | object | ✅ | Dirección del cliente |
| `shippingAddress` | object | ✅ | Dirección de envío |
| `billingAddress` | object | ✅ | Dirección de facturación |
| `pickUpAddress` | object | ❌ | Dirección de recogida |
| `allyUrlRedirection.logoUrl` | string | ✅ | URL del logo del comercio |
| `allyUrlRedirection.callbackUrl` | string | ✅ | URL del webhook de confirmación |
| `allyUrlRedirection.redirectionUrl` | string | ✅ | URL de redirección post-pago (incluir `id_order`) |
| `geoLocation` | object | ❌ | Coordenadas geográficas del comercio |

### Request enviado a Addi (con Bearer token inyectado)

```
POST https://api.addi.com/v1/online-applications
Content-Type: application/json
Authorization: Bearer eyJhbGci...
```

Body: idéntico al recibido del e-commerce (pass-through).

### Respuesta de Addi

Addi responde con **HTTP 302** y el header `Location` apuntando a la URL de la aplicación de crédito.

```
HTTP/1.1 302 Found
Location: https://app.addi.com/application/abc123...
```

> **No hay body en la respuesta de Addi.** La URL está únicamente en el header `Location`.  
> Se captura con `curl_getinfo($ch, CURLINFO_REDIRECT_URL)` — **no** con `CURLOPT_FOLLOWLOCATION`.

### Respuesta al e-commerce

```json
{ "url": "https://app.addi.com/application/abc123..." }
```

---

## Webhook de Confirmación (Server-to-Server) {#webhook}

Addi hace un `POST` al `callbackUrl` enviado en `allyUrlRedirection`.

### Payload recibido

```json
{
  "orderId": "653449",
  "applicationId": "app-addi-xyz-123",
  "status": "APPROVED",
  "approvedAmount": 255000,
  "currency": "COP",
  "statusTimestamp": "2024-10-15T14:30:00Z"
}
```

| Campo JSON | Descripción |
|-----------|-------------|
| `orderId` | Tu ID de pedido interno (el que enviaste en la creación) |
| `applicationId` | ID externo generado por Addi — es el transaction ID |
| `status` | Estado en mayúsculas inglesas |
| `approvedAmount` | Monto aprobado en COP |
| `currency` | Moneda (`COP`) |
| `statusTimestamp` | Timestamp ISO 8601 del cambio de estado |

### Estados posibles de Addi

| `status` | `$codResponse` | `$response` canónico |
|----------|:--------------:|---------------------|
| `APPROVED` | 1 | `APROBADA` |
| `REJECTED` | 2 | `PAGO RECHAZADO` |
| `PENDING` | 3 | `PENDIENTE POR CR` |
| `INTERNAL_ERROR` | 4 | `ERROR CR` |
| `ABANDONED` | 10 | `Abandonada` |
| `DECLINED` | 11 | `Cancelada` |

> Ver plantilla completa en `templates/webhooks/webhook-addi.ts`

---

## Cadena de Validaciones y Errores

```
1. Middleware de autenticación → 401 si Authorization no coincide
2. fetch falla en getAccessToken() → Error al obtener token
3. HTTP code != 200 en token → Error: {error_description}
4. fetch falla en getRedirectionUrl() → HTTP 500 + JSON error
5. Header Location vacío → { url: "" }
```
