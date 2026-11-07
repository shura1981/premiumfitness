---
name: integracion-pasarelas
description: 'Expert guide for integrating Colombian payment gateways Bold (Botón de Pagos), Addi (BNPL), Sistecredito (Credinet), and ePayco via API, and handling their payment confirmation webhooks. Use when asked to create a payment session, generate a Bold SHA-256 integrity hash, verify x-bold-signature HMAC-SHA256 webhooks, reconcile Bold notifications, obtain a redirect URL, query or revert a transaction, handle pending transactions with a cron job, implement webhooks, normalize gateway responses, map payloads to a canonical structure, manage JWT/Bearer token caching, configure environment variables, or build/replicate the HTML response page shown to the user after a transaction (response page, página de respuesta, thank-you page) for Bold, Addi, Sistecredito, or ePayco in PHP or any backend.'
license: Complete terms in LICENSE.txt
---

# Integración de Pasarelas Colombianas: Bold, Addi, Sistecredito & ePayco

Esta skill cubre el ciclo completo de integración con **Bold** (Botón de Pagos con tarjetas, PSE, Nequi, Botón Bancolombia y QR), **Addi** (crédito BNPL), **Sistecrédito / Credinet** (crédito revolvente) y **ePayco** (pasarela legacy), incluyendo:

- Generación de firma de integridad SHA-256 (`integritySignature`) y configuración para `BoldCheckout`
- Creación de sesiones de pago (integración API saliente) y obtención de URLs de redirección
- Consulta y conciliación de estado de transacciones (API de notificaciones de Bold, Sistecrédito y ePayco)
- Recepción, validación criptográfica (`x-bold-signature` HMAC-SHA256 sobre Base64) y normalización de webhooks de confirmación (server-to-server)
- Gestión de tokens Bearer con caché en archivo (patrón ePayco) y OAuth 2.0 (Addi)
- Cron jobs para resolver transacciones pendientes en Bold y ePayco
- Variables de entorno, headers, payloads y códigos de respuesta exactos

## When to Use This Skill

- Usuario pide integrar **Bold**, **Addi**, **Sistecrédito** o **ePayco** en un backend PHP o frontend JS/TS/Angular
- Usuario necesita generar el hash de integridad SHA-256 de Bold o abrir `new BoldCheckout(...)` con `boldPaymentButton.js`
- Usuario necesita verificar la firma `x-bold-signature` (HMAC-SHA256 sobre `base64_encode($rawBody)`) en el webhook de Bold
- Usuario necesita conciliar pagos de Bold consultando `{BOLD_URL_NOTIFICATIONS}/{id}?is_external_reference=true` con `x-api-key`
- Usuario necesita crear una sesión/orden de pago y obtener la URL de redirección (Addi / Sistecrédito)
- Usuario pregunta qué variables de entorno se necesitan para cada pasarela (`BOLD_IDENTITY_KEY`, `BOLD_SECRET_KEY`, etc.)
- Usuario necesita consultar o revertir una transacción en Bold, ePayco o Sistecrédito
- Usuario pide implementar el webhook de confirmación de cualquiera de las pasarelas
- Usuario necesita normalizar o mapear los payloads a una estructura canónica unificada (`x_*` / `PaymentResult`)
- Usuario necesita implementar un cron job que resuelva transacciones pendientes (Bold o ePayco)
- Usuario necesita crear o replicar la página HTML de respuesta (thank-you page) que ve el cliente al regresar de la pasarela

## Arquitectura General

```
E-commerce  →  Backend / API Intermediaria (PHP)  →  Pasarela Externa (Bold / Addi / Sistecrédito)
                       ↑                                        |
                   Middleware                                   |
              (JWT / tokenHeader)                        Callback/Webhook
                                                                ↓
                                                    endpoint_confirmacion.php
```

## Estructura de la Skill

| Carpeta / Archivo | Contenido |
|-------------------|-----------|
| `references/env-variables.md` | Todas las variables `.env` para Bold, Sistecrédito, Addi y ePayco |
| `references/bold-api.md` | Flujo completo de integración Bold (hash SHA-256, `BoldCheckout`, webhook HMAC, conciliación y cron) |
| `references/sistecredito-api.md` | Flujo completo de integración Sistecrédito |
| `references/addi-api.md` | Flujo completo de integración Addi |
| `references/epayco-api.md` | Flujo completo de integración ePayco (token, endpoints, cron) |
| `references/frontend-integration.md` | Guía de integración Frontend (Angular/TS y Vanilla JS) para Bold, Addi, Sistecrédito y ePayco |
| `references/canonical-structure.md` | Estructura canónica de resultado + funciones mapper (`normalizePaymentStatus`, `buildPaymentResult`) |
| `references/paginas-respuesta.md` | Documentación completa de las páginas de respuesta HTML |
| `templates/integracion/bold-generar-hash.php` | Plantilla: generar hash SHA-256 y configuración `BoldCheckout` |
| `templates/integracion/bold-conciliar-pago.php` | Plantilla: conciliación y selección de notificaciones en Bold |
| `templates/integracion/sistecredito-crear-orden.php` | Plantilla: crear orden Sistecrédito |
| `templates/integracion/sistecredito-consultar-orden.php` | Plantilla: consultar estado Sistecrédito |
| `templates/integracion/addi-obtener-url.php` | Plantilla: obtener URL Addi |
| `templates/integracion/epayco-crear-sesion.php` | Plantilla: crear sesión ePayco |
| `templates/integracion/epayco-detalle-transaccion.php` | Plantilla: detalle y reversión ePayco |
| `templates/integracion/helpers/createPaymentRequestBody.php` | Helper: construir body Credinet |
| `templates/webhooks/webhook-bold.php` | Plantilla webhook Bold (con verificación `x-bold-signature`) |
| `templates/webhooks/webhook-sistecredito.php` | Plantilla webhook Sistecrédito |
| `templates/webhooks/webhook-addi.php` | Plantilla webhook Addi |
| `templates/webhooks/webhook-epayco.php` | Plantilla webhook ePayco |
| `templates/paginas-respuesta/response-bold.html` | Plantilla HTML página de respuesta Bold |
| `templates/paginas-respuesta/response-addi.html` | Plantilla HTML página de respuesta Addi |
| `templates/paginas-respuesta/response-sistecredito.html` | Plantilla HTML página de respuesta Sistecrédito |
| `templates/paginas-respuesta/response-epayco.html` | Plantilla HTML página de respuesta ePayco |

---

## Comparativa Rápida: Las Cuatro Pasarelas

| Aspecto | Bold (Botón de Pagos) | Sistecrédito (Credinet) | Addi | ePayco (Legacy) |
|---------|-----------------------|------------------------|------|-----------------|
| API externa | `integrations.api.bold.co` | `api.credinet.co` | `api.addi.com` | `apify.epayco.co` |
| Script Frontend | `checkout.bold.co/library/boldPaymentButton.js` | N/A (Redirección HTTP) | N/A (Redirección HTTP) | `checkout.epayco.co/checkout-v2.js` |
| Auth hacia proveedor | Header `Authorization: x-api-key {BOLD_IDENTITY_KEY}` | Headers estáticos (`Ocp-Apim-Subscription-Key`, `ApplicationKey`, `ApplicationToken`) | OAuth 2.0 Client Credentials (`POST /oauth/token`) | Bearer token con caché de 15 min (`POST /login`) |
| Inicio del pago | Backend genera `integritySignature` (`sha256(orderId.amount.COP.secretKey)`) y frontend abre `new BoldCheckout(config).open()` | `POST /pay/create` → retorna `paymentRedirectUrl` | `POST /v1/online-applications` → header `Location` (HTTP 302) | `POST /payment/session/create` → retorna `sessionId` |
| Consulta / Conciliación | `GET /payments/webhook/notifications/{id}?is_external_reference=true` | `GET /pay/GetTransactionResponse?transactionId=` | No implementado | `GET /transaction` con body JSON |
| Firma de Webhook | Header `x-bold-signature`: `hash_hmac('sha256', base64_encode($rawBody), BOLD_SECRET_KEY)` | Sin firma criptográfica | Sin firma criptográfica | Campo `x_signature` SHA-256 en el payload |
| Campo orden local | `data.metadata.reference` | `Invoice` (raíz) | `orderId` | `x_id_invoice` |
| Campo monto | `data.amount.total` | `Value` (raíz) | `approvedAmount` | `x_amount` |
| Campo estado | `type` (`SALE_APPROVED`, `SALE_REJECTED`, `VOID_APPROVED`, `VOID_REJECTED`) | `PaymentMethodResponse.StatusResponse` | `status` | `x_cod_transaction_state` (numérico) |
| Campo transaction ID | `data.payment_id` | `PaymentMethodResponse.TransactionId` | `applicationId` | `x_ref_payco` |
| Query params en retorno | `?orderId={id}&bold-order-id={id}&bold-tx-status={status}` | `?orderId={id}` | `?id_order={id}` | `?ref_payco={ref}` |
| Cron job pendientes | Sí — consulta notificaciones por `orderId` | No | No | Sí — compara BD vs ePayco |

---

## Flujos de Trabajo

### Flujo Bold

1. Leer referencia de variables de entorno → `references/env-variables.md#bold`
2. Leer especificación técnica de Bold → `references/bold-api.md`
3. Generar hash de integridad SHA-256 y objeto `checkout` → `templates/integracion/bold-generar-hash.php`
4. Abrir el modal en el frontend con `new BoldCheckout(config).open()` → `references/frontend-integration.md`
5. Implementar webhook con verificación `x-bold-signature` → `templates/webhooks/webhook-bold.php`
6. Implementar endpoint de conciliación y cron job de pendientes → `templates/integracion/bold-conciliar-pago.php`

### Flujo Sistecrédito

1. Leer referencia de variables de entorno → `references/env-variables.md#sistecredito`
2. Usar helper para construir el body → `templates/integracion/helpers/createPaymentRequestBody.php`
3. Crear la orden → `templates/integracion/sistecredito-crear-orden.php`
4. Consultar estado (si se necesita polling) → `templates/integracion/sistecredito-consultar-orden.php`
5. Implementar webhook → `templates/webhooks/webhook-sistecredito.php`

### Flujo Addi

1. Leer referencia de variables de entorno → `references/env-variables.md#addi`
2. Implementar obtención de token OAuth → `references/addi-api.md#autenticacion`
3. Obtener URL de redirección → `templates/integracion/addi-obtener-url.php`
4. Implementar webhook → `templates/webhooks/webhook-addi.php`

### Flujo ePayco (Legacy)

1. Leer referencia de variables de entorno → `references/env-variables.md#epayco`
2. Implementar gestión del token Bearer con caché → `references/epayco-api.md#token`
3. Crear sesión de checkout → `templates/integracion/epayco-crear-sesion.php`
4. Consultar detalle / revertir transacción → `templates/integracion/epayco-detalle-transaccion.php`
5. Implementar webhook de confirmación → `templates/webhooks/webhook-epayco.php`
6. (Opcional) Implementar cron job de pendientes → `references/epayco-api.md#cron`

### Flujo Páginas de Respuesta

> Aplica cuando se necesita crear o replicar la página HTML que el usuario ve al regresar de la pasarela.

1. Leer la documentación completa → `references/paginas-respuesta.md`
2. Elegir la plantilla correspondiente a la pasarela:
   - Bold → `templates/paginas-respuesta/response-bold.html`
   - Addi → `templates/paginas-respuesta/response-addi.html`
   - Sistecrédito → `templates/paginas-respuesta/response-sistecredito.html`
   - ePayco → `templates/paginas-respuesta/response-epayco.html`
3. Identificar el query param de entrada (`orderId` / `bold-order-id` para Bold, `id_order` para Addi, `orderId` para Sistecrédito, `ref_payco` para ePayco).
4. Verificar el endpoint de consulta de estado (`state-transaction`) y ajustar la URL según el entorno.

### Flujo de Integración Frontend (Angular/React/Vanilla)

1. Leer la guía completa de consumo frontend → `references/frontend-integration.md`
2. **Para Bold:**
   - Inyectar `<script defer src="https://checkout.bold.co/library/boldPaymentButton.js"></script>`.
   - Declarar tipado global `window.BoldCheckout`.
   - Instanciar `new window.BoldCheckout(gateway.checkout)` (asegurando que `customerData` y `billingAddress` sean strings JSON) y llamar `.open()`.
3. **Para Addi y Sistecrédito:** Implementar redirección simple `window.location.assign(gateway.redirect_url)`.
4. **Para ePayco (Legacy):** Inyectar `checkout-v2.js` e inicializar `ePayco.checkout.configure(gateway.checkout).open()`.

---

## Normalización de Estados

Todas las pasarelas deben producir los mismos `$codResponse` (int) y `$response` (string):

| Pasarela | Estado bruto | `$codResponse` | `$response` |
|----------|-------------|:--------------:|-------------|
| Bold | `SALE_APPROVED` | 1 | `APROBADA` |
| Bold | `SALE_REJECTED` | 2 | `PAGO RECHAZADO` |
| Bold | `VOID_APPROVED` | 6 (o 2) | `Reversada` / `PAGO RECHAZADO` |
| Bold | `VOID_REJECTED` | — | *(No-op: conserva el estado previo)* |
| Sistecredito | `Approved` | 1 | `APROBADA` |
| Sistecredito | `Rejected` | 2 | `PAGO RECHAZADO` |
| Sistecredito | `Pending` | 3 | `PENDIENTE POR CR` |
| Sistecredito | `Failed` | 4 | `ERROR CR` |
| Sistecredito | `PendingForPaymentMethod` | 10 | `PENDIENTE POR MÉTODO DE PAGO` |
| Sistecredito | `Cancelled` | 11 | `Cancelada` |
| Addi | `APPROVED` | 1 | `APROBADA` |
| Addi | `REJECTED` | 2 | `PAGO RECHAZADO` |
| Addi | `PENDING` | 3 | `PENDIENTE POR CR` |
| Addi | `INTERNAL_ERROR` | 4 | `ERROR CR` |
| Addi | `ABANDONED` | 10 | `Abandonada` |
| Addi | `DECLINED` | 11 | `Cancelada` |

> La función `normalizePaymentStatus()` completa está en `references/canonical-structure.md`.

---

## Gotchas

- **Bold: Firma HMAC sobre Base64 del body crudo** — `x-bold-signature` se calcula como `hash_hmac('sha256', base64_encode($rawBody), $secretKey)`. Calcular el HMAC directamente sobre `$rawBody` sin `base64_encode()` o re-serializar el JSON (`json_encode($parsed)`) siempre fallará la verificación.
- **Bold: `VOID_REJECTED` es un No-Op** — Cuando una anulación es rechazada (`VOID_REJECTED`), el pago original sigue aprobado. El webhook debe responder `200 OK` sin modificar el estado de la orden.
- **Bold: Monto y OrderId enteros sin decimales** — Para calcular `integritySignature` (`sha256(orderId . amount . currency . secretKey)`), tanto `orderId` como `amount` deben ser enteros positivos sin puntos ni decimales (`^[1-9][0-9]*$`).
- **Bold: `customerData` y `billingAddress` deben ser JSON strings** — El constructor `new BoldCheckout(...)` espera que `customerData` y `billingAddress` sean cadenas serializadas con `JSON.stringify` / `json_encode`, no objetos planos.
- **Bold: Selección de notificación por `payment_id`** — Al conciliar contra `{BOLD_URL_NOTIFICATIONS}/{orderId}?is_external_reference=true`, agrupa primero por `data.payment_id` quedándote con el evento de mayor `time` de cada intento (para que un `VOID_APPROVED` posterior invalide un `SALE_APPROVED` previo del mismo intento) y luego prioriza cualquier intento vigente con `SALE_APPROVED`.
- **Sistecrédito requiere 4 headers propios** — `SCOrigen`, `country`, `Ocp-Apim-Subscription-Key`, `ApplicationKey`, `ApplicationToken`. Sin alguno de ellos la API retorna 401 silencioso.
- **`paymentMethodId` siempre es `2`** para Sistecrédito — no es configurable; se hardcodea al construir el body con `createPaymentRequestBody()`.
- **La URL de pago puede llegar vacía en la primera respuesta** de Sistecrédito — implementar reintento con `sleep(5)` antes de lanzar excepción.
- **Addi retorna HTTP 302**, no 200 — la URL de redirección está en `CURLINFO_REDIRECT_URL`, NO en el body de la respuesta. Con `CURLOPT_FOLLOWLOCATION => true` el campo Location se pierde; mantener en `false` o capturar con `curl_getinfo($ch, CURLINFO_REDIRECT_URL)`.
- **El token OAuth de Addi es dinámico** — obtenerse en cada request o cachearse respetando `expires_in`.
- **Nunca omitir idempotencia en webhooks** — las pasarelas reintentan en errores de red; procesar `APROBADA` dos veces genera pedidos duplicados o doble descuento de saldo.
- **Siempre responder `200 OK` al webhook** cuando el evento fue recibido válidamente — códigos 5xx provocan reintentos de la pasarela.

---

## Troubleshooting

| Problema | Solución |
|----------|----------|
| Bold: `x-bold-signature` no coincide (400) | Verificar que se aplique `base64_encode($rawBody)` sobre el body crudo exacto antes de `hash_hmac('sha256', ..., BOLD_SECRET_KEY)` |
| Bold: Modal muestra error de firma de integridad | Verificar que `amount` no tenga decimales y que el orden sea exactamente `orderId . amount . 'COP' . BOLD_SECRET_KEY` |
| Bold: API de notificaciones retorna 404 | Es normal cuando el usuario aún no ha completado el pago; tratar 404 como `SIN_NOTIFICACION` |
| Bold: API de notificaciones retorna 401/403 | Verificar que el header sea `Authorization: x-api-key {BOLD_IDENTITY_KEY}` (con el prefijo `x-api-key`, no `Bearer`) |
| Sistecrédito retorna 401 | Verificar que los 3 headers de auth estén presentes: `Ocp-Apim-Subscription-Key`, `ApplicationKey`, `ApplicationToken` |
| `paymentRedirectUrl` llega vacía siempre | Implementar reintento `sleep(5)` + re-consultar con `queryIdTransaction()` |
| Addi: `$location` es null o vacío | Verificar `CURLOPT_FOLLOWLOCATION => false`; la URL está en `CURLINFO_REDIRECT_URL` antes del redirect |
| Pedido procesado dos veces | Agregar verificación de idempotencia: si la orden ya está `APROBADA`, retornar `200` sin reprocesar |
| ePayco: `POST /login` retorna 401 | `PUBLIC_KEY` o `PRIVATE_KEY` incorrectos; verificar que `EntityClientId` tenga comillas simples: `'base64(ID)'` |

---

## References

- [Variables de entorno — Bold, Sistecrédito, Addi y ePayco](./references/env-variables.md)
- [Integración Bold — flujo completo (hash, webhook, conciliación, cron)](./references/bold-api.md)
- [Integración Sistecrédito — flujo completo](./references/sistecredito-api.md)
- [Integración Addi — flujo completo](./references/addi-api.md)
- [Integración ePayco — flujo completo](./references/epayco-api.md)
- [Integración Frontend (Angular/React/Vanilla) — consumo de la API](./references/frontend-integration.md)
- [Estructura canónica de resultado + funciones mapper](./references/canonical-structure.md)
- [Plantilla: generar hash y checkout Bold](./templates/integracion/bold-generar-hash.php)
- [Plantilla: conciliar pagos Bold](./templates/integracion/bold-conciliar-pago.php)
- [Plantilla: crear orden Sistecrédito](./templates/integracion/sistecredito-crear-orden.php)
- [Plantilla: consultar orden Sistecrédito](./templates/integracion/sistecredito-consultar-orden.php)
- [Plantilla: obtener URL Addi](./templates/integracion/addi-obtener-url.php)
- [Plantilla: crear sesión ePayco](./templates/integracion/epayco-crear-sesion.php)
- [Plantilla: detalle y reversión ePayco](./templates/integracion/epayco-detalle-transaccion.php)
- [Helper: createPaymentRequestBody](./templates/integracion/helpers/createPaymentRequestBody.php)
- [Webhook Bold](./templates/webhooks/webhook-bold.php)
- [Webhook Sistecrédito](./templates/webhooks/webhook-sistecredito.php)
- [Webhook Addi](./templates/webhooks/webhook-addi.php)
- [Webhook ePayco](./templates/webhooks/webhook-epayco.php)
- [Páginas de respuesta — documentación](./references/paginas-respuesta.md)
- [Plantilla respuesta Bold](./templates/paginas-respuesta/response-bold.html)
- [Plantilla respuesta Addi](./templates/paginas-respuesta/response-addi.html)
- [Plantilla respuesta Sistecrédito](./templates/paginas-respuesta/response-sistecredito.html)
- [Plantilla respuesta ePayco](./templates/paginas-respuesta/response-epayco.html)
