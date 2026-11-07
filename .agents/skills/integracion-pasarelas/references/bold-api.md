# Referencia API de Bold (Botón de Pagos)

## Tabla de Contenidos
1. [Arquitectura General](#arquitectura)
2. [Credenciales y Variables de Entorno](#credenciales)
3. [Firma de Integridad y Configuración de Checkout](#firma-integridad)
4. [Webhook de Confirmación y Verificación HMAC-SHA256](#webhook)
5. [Eventos de Pago y Normalización](#eventos)
6. [Conciliación y Consulta de Estado (API de Notificaciones)](#conciliacion)
7. [Cron Job de Pagos Pendientes](#cron-pendientes)

---

## Arquitectura General {#arquitectura}

Bold utiliza el **Botón de Pagos Bold** (`boldPaymentButton.js`) que se abre en el navegador mediante `new BoldCheckout(...)`:

1. **Preparación en Backend (`POST /hash` o `POST /consume/bold`)**:
   - El backend valida que `orderId` (`id_pago`) y `amount` (`valor`) sean enteros positivos sin decimales y que la moneda sea `COP`.
   - Calcula la firma de integridad SHA-256 usando `BOLD_SECRET_KEY`.
   - Retorna al frontend el `integritySignature` (hash) y la configuración de `BoldCheckout` (con `BOLD_IDENTITY_KEY` como `apiKey`).
2. **Checkout en Frontend**:
   - El frontend carga `https://checkout.bold.co/library/boldPaymentButton.js`, instancia `new BoldCheckout(config)` y ejecuta `checkout.open()`.
3. **Confirmación (Webhook)**:
   - Bold envía un `POST` con el evento (`SALE_APPROVED`, `SALE_REJECTED`, `VOID_APPROVED`, `VOID_REJECTED`) firmado en el header `x-bold-signature`.
   - El backend verifica `HMAC-SHA256(base64_encode($rawBody), BOLD_SECRET_KEY)` antes de procesar el pago.
4. **Conciliación / Respaldo (Pull)**:
   - Si el webhook falla o tarda, el backend (por endpoint de reconciliación o cron job) consulta `GET {BOLD_URL_NOTIFICATIONS}/{orderId}?is_external_reference=true` autenticado con `Authorization: x-api-key {BOLD_IDENTITY_KEY}`.

---

## Credenciales y Variables de Entorno {#credenciales}

| Variable | Uso | Exposición |
|----------|-----|------------|
| `BOLD_IDENTITY_KEY` | Llave de identidad (API Key pública del comercio). Se envía al frontend como `apiKey` en `BoldCheckout` y en el header `Authorization: x-api-key {BOLD_IDENTITY_KEY}` al consultar el API de notificaciones. | Pública (Frontend + Backend) |
| `BOLD_SECRET_KEY` | Llave secreta del comercio. Se usa para generar `integritySignature` (SHA-256) y verificar `x-bold-signature` (HMAC-SHA256). | **Privada (Solo Backend)** |
| `BOLD_URL_NOTIFICATIONS` | Endpoint base de consulta de notificaciones (`https://integrations.api.bold.co/payments/webhook/notifications`). | Backend |
| `BOLD_URL_RESPONSE` | URL de retorno (`redirectionUrl`) a la que Bold redirige al usuario tras completar el flujo. | Frontend / Backend |

---

## Firma de Integridad y Configuración de Checkout {#firma-integridad}

### Fórmula Oficial del Hash de Integridad

Bold exige concatenar **sin separadores** el identificador de orden, el monto entero en pesos, la divisa (`COP`) y la llave secreta (`BOLD_SECRET_KEY`), aplicando `sha256` en minúsculas hexadecimales:

```typescript
import crypto from 'node:crypto';

const cadena = `${orderId}${amount}${currency}${secretKey}`;
const integritySignature = crypto.createHash('sha256').update(cadena, 'utf8').digest('hex');
```

> **Regla crítica**: `$orderId` y `$amount` deben ser enteros positivos sin puntos, comas ni decimales (`^[1-9][0-9]*$`). Si el monto lleva decimales o separadores de miles, Bold rechazará la firma de integridad al abrir la pasarela.

### Objeto de Configuración para `BoldCheckout`

El backend puede devolver solo el hash (`{"status": true, "hash": "..."}`) o el objeto `checkout` completo listo para instanciar en el frontend:

```json
{
  "type": "bold_checkout",
  "gateway": "bold",
  "checkout": {
    "orderId": "100452",
    "currency": "COP",
    "amount": "150000",
    "apiKey": "<BOLD_IDENTITY_KEY>",
    "integritySignature": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "description": "Compra pedido #100452",
    "redirectionUrl": "https://tudominio.com/response/bold?orderId=100452",
    "renderMode": "embedded",
    "customerData": "{\"email\":\"cliente@correo.com\",\"fullName\":\"Juan Perez\",\"phone\":\"3001234567\",\"dialCode\":\"+57\",\"documentNumber\":\"1020304050\",\"documentType\":\"CC\"}",
    "billingAddress": "{\"address\":\"Calle 10 # 20-30\",\"city\":\"Cali\",\"state\":\"Valle del Cauca\",\"country\":\"CO\"}"
  }
}
```

> **Nota sobre `customerData` y `billingAddress`**: La librería `BoldCheckout` requiere que ambos campos sean **strings JSON serializados** (`json_encode(...)` / `JSON.stringify(...)`), no objetos planos.

---

## Webhook de Confirmación y Verificación HMAC-SHA256 {#webhook}

### Algoritmo de Verificación (`x-bold-signature`)

Bold calcula la firma HMAC-SHA256 sobre el **cuerpo crudo codificado en Base64** (`base64_encode($rawBody)`), NO directamente sobre el JSON crudo:

```typescript
import crypto from 'node:crypto';

// 1. Leer el Buffer crudo exacto de la petición (ej. usando express.raw({ type: 'application/json' }))
const encodedBody = rawBodyBuffer.toString('base64');

// 2. Calcular HMAC-SHA256 con BOLD_SECRET_KEY
const expectedSignature = crypto.createHmac('sha256', secretKey).update(encodedBody).digest('hex');

// 3. Comparación en tiempo constante
const receivedSignature = (req.headers['x-bold-signature'] as string || '').toLowerCase().trim();
const isValid = expectedSignature.length === receivedSignature.length &&
  crypto.timingSafeEqual(Buffer.from(expectedSignature, 'utf8'), Buffer.from(receivedSignature, 'utf8'));

if (!isValid) {
  res.status(401).json({ error: 'Firma inválida' });
  return;
}
```

> **Importante**: En producción la verificación de `x-bold-signature` debe estar **siempre activa**. Nunca uses `json_encode($parsedBody)` para recalcular la firma porque cualquier diferencia de espacios o formato invalida el Base64.

### Estructura del Payload del Webhook

```json
{
  "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "type": "SALE_APPROVED",
  "subject": "TRX_123456789",
  "source": "/payments/webhook",
  "spec_version": "1.0",
  "time": 1731000000,
  "data": {
    "payment_id": "TRX_123456789",
    "merchant_id": "MERCHANT_01",
    "created_at": "2025-11-07T14:00:00Z",
    "amount": {
      "total": 150000,
      "currency": "COP",
      "tip": 0
    },
    "card": {
      "capture_mode": "AUTOMATIC",
      "franchise": "VISA",
      "brand": "VISA",
      "cardholder_name": "JUAN PEREZ",
      "terminal_id": "TERM01",
      "masked_pan": "411111******1111",
      "installments": 1,
      "card_type": "CREDIT"
    },
    "user_id": "USR_01",
    "metadata": {
      "reference": "100452"
    },
    "bold_code": "00",
    "payer_email": "cliente@correo.com",
    "payment_method": "CARD_WEB",
    "approval_number": "987654",
    "integration": "BUTTON"
  },
  "datacontenttype": "application/json"
}
```

### Extracción de Campos Clave

| Campo en Bold | Uso en el Sistema |
|---------------|-------------------|
| `type` | Estado del evento (`SALE_APPROVED`, `SALE_REJECTED`, `VOID_APPROVED`, `VOID_REJECTED`) |
| `data.metadata.reference` | ID de la orden local (`orderId` / `id_visita` / `x_id_factura`) |
| `data.payment_id` | ID único de transacción en Bold (`int_ped_numero` / `str_ticketID`) |
| `data.amount.total` | Monto total cobrado |
| `data.amount.currency` | Moneda (validar que sea `COP` cuando venga informada) |
| `data.payment_method` | Medio de pago (`CARD`, `CARD_WEB`, `BOLD_TAP`, `PSE`, `NEQUI`, `BOTON_BANCOLOMBIA`, `QR`) |
| `data.card.brand` / `data.card.franchise` | Franquicia de tarjeta cuando el método es tarjeta |
| `data.card.masked_pan` | Número enmascarado de tarjeta |
| `data.card.installments` | Número de cuotas |

---

## Eventos de Pago y Normalización {#eventos}

| Evento Bold (`type`) | Significado | `x_cod_response` | `x_response` (Canónico) | Acción en Base de Datos |
|----------------------|-------------|------------------|-------------------------|-------------------------|
| `SALE_APPROVED` | Venta aprobada | `1` | `APROBADA` | Actualizar orden a `APROBADA`, descontar saldo/inventario, notificar al cliente. |
| `SALE_REJECTED` | Venta rechazada | `2` | `PAGO RECHAZADO` | Actualizar orden a `PAGO RECHAZADO` (solo si la orden no fue aprobada previamente). |
| `VOID_APPROVED` | Anulación aprobada | `6` (legacy: `Reversada`) o `2` (`PAGO RECHAZADO`) | `Reversada` / `PAGO RECHAZADO` | Marcar la compra como reversada/rechazada. |
| `VOID_REJECTED` | Anulación rechazada | — | *(No-op)* | **Ignorar cambio de estado**: responder `200 OK` con `{"status":true,"message":"RECIBIDO","estado":"VOID_REJECTED"}` y conservar el estado previo de la orden (ej. `APROBADA`). |

### Mapeo de `payment_method` a Franquicia (`x_franchise` / `forma_pago`)

| `data.payment_method` | Franquicia (`x_franchise`) | `forma_pago` (Legacy ERP) |
|-----------------------|----------------------------|---------------------------|
| `CARD`, `CARD_WEB`, `BOLD_TAP` | `data.card.brand` (o `CARD`) | `TARJETA DE CREDITO` |
| `PSE` | `PSE` | `PSE` |
| `NEQUI` | `NEQUI` | `NEQUI` |
| `BOTON_BANCOLOMBIA` | `BANCOLOMBIA` | `BANCOLOMBIA` |
| `QR` | `QR` | `QR` |

---

## Conciliación y Consulta de Estado (API de Notificaciones) {#conciliacion}

Bold expone un endpoint REST para consultar el historial de notificaciones de un pago tanto por la **referencia externa** (`orderId` / `id_visita`) como por el `payment_id` de Bold.

### Request HTTP

- **Por referencia del comercio (`orderId` / `id_visita`)**:
  ```
  GET {BOLD_URL_NOTIFICATIONS}/{orderId}?is_external_reference=true
  Authorization: x-api-key {BOLD_IDENTITY_KEY}
  Accept: application/json
  ```
- **Por `payment_id` de Bold**:
  ```
  GET {BOLD_URL_NOTIFICATIONS}/{paymentId}
  Authorization: x-api-key {BOLD_IDENTITY_KEY}
  Accept: application/json
  ```

### Respuestas del API de Notificaciones

- **HTTP 200**: Retorna `{"notifications": [ ... ]}` donde cada elemento tiene la misma estructura que el payload del webhook (`id`, `type`, `time`, `data`, etc.).
- **HTTP 404**: Bold aún no registra notificaciones para esa referencia (el cliente aún no ha pagado o abandonó el modal). Debe tratarse como **sin novedad (`SIN_NOTIFICACION`)**, no como un error crítico.

### Algoritmo de Selección cuando hay Múltiples Notificaciones

Un mismo pedido (`orderId`) puede tener varios intentos de pago (`payment_id` distintos) o varios eventos sobre un mismo `payment_id` (por ejemplo, `SALE_APPROVED` en `t=100` y luego `VOID_APPROVED` en `t=200`).

Para seleccionar la notificación vigente sin falsos positivos:

1. **Agrupar por `data.payment_id`** y conservar únicamente la notificación con **mayor `time`** dentro de cada `payment_id`. Así, si un pago fue aprobado y luego anulado (`VOID_APPROVED`), su estado vigente es `VOID_APPROVED`.
2. **Evaluar los estados vigentes entre los distintos `payment_id`**:
   - Si alguno tiene `type === 'SALE_APPROVED'`, seleccionar ese (si hay varios, el de mayor `time`).
   - En caso contrario, seleccionar el de mayor `time` global.

```typescript
function selectRelevantBoldNotification(notifications: any[]): any | null {
  const validEvents = ['SALE_APPROVED', 'SALE_REJECTED', 'VOID_APPROVED', 'VOID_REJECTED'];
  const latestByPaymentId = new Map<string, any>();

  for (let i = 0; i < notifications.length; i++) {
    const notification = notifications[i];
    const type = notification.type;
    if (!validEvents.includes(type)) continue;

    const paymentId = notification.data?.payment_id?.trim() || `idx_${i}`;
    const time = Number(notification.time) || 0;

    const existing = latestByPaymentId.get(paymentId);
    if (!existing || time >= (Number(existing.time) || 0)) {
      latestByPaymentId.set(paymentId, notification);
    }
  }

  if (latestByPaymentId.size === 0) return null;

  const candidates = Array.from(latestByPaymentId.values());
  const approved = candidates.filter((n) => n.type === 'SALE_APPROVED');
  const pool = approved.length > 0 ? approved : candidates;

  pool.sort((a, b) => (Number(b.time) || 0) - (Number(a.time) || 0));
  return pool[0] || null;
}
```

---

## Cron Job de Pagos Pendientes {#cron-pendientes}

Para pedidos de Bold que quedaron en estado inicial (`INICIADA`, `ENVIADO` o `PENDIENTE%`):

1. Seleccionar órdenes con `payment_gateway = 'bold'` cuyo estado no sea final (`APROBADA`, `PAGO RECHAZADO`, `RECHAZADA`, `CANCELADA`, `REVERSADA`).
2. Por cada orden, consultar `GET {BOLD_URL_NOTIFICATIONS}/{orderId}?is_external_reference=true`.
3. Si Bold devuelve notificaciones, seleccionar la relevante con `selectRelevantBoldNotification()`.
4. Si el evento es `VOID_REJECTED`, ignorar cambio de estado. Para `SALE_APPROVED`, `SALE_REJECTED` o `VOID_APPROVED`, despachar la actualización de estado idempotente.
5. Incluir una pequeña pausa (`usleep(200000)` o `sleep(1)`) entre órdenes para evitar *rate limiting* del API de Bold.
