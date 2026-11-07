---
name: integracion-pasarelas
description: 'Expert guide for integrating Colombian payment gateways Bold (Botón de Pagos), Addi (BNPL), Sistecredito (Credinet), and ePayco via API in Node.js / TypeScript (Express), and handling their payment confirmation webhooks. Use when asked to create a payment session, generate a Bold SHA-256 integrity hash, verify x-bold-signature HMAC-SHA256 webhooks, reconcile Bold notifications, obtain a redirect URL, query or revert a transaction, handle pending transactions, implement webhooks, normalize gateway responses, map payloads to a canonical structure, manage JWT/Bearer token caching, configure environment variables, or build/replicate the HTML response page shown to the user after a transaction (response page, página de respuesta, thank-you page) for Bold, Addi, Sistecredito, or ePayco in Node.js, TypeScript or Angular.'
license: Complete terms in LICENSE.txt
---

# Integración de Pasarelas Colombianas: Bold, Addi, Sistecredito & ePayco (Node.js + TypeScript)

Esta skill cubre el ciclo completo de integración con **Bold** (Botón de Pagos con tarjetas, PSE, Nequi, Botón Bancolombia y QR), **Addi** (crédito BNPL), **Sistecrédito / Credinet** (crédito revolvente) y **ePayco** (pasarela legacy) en **Node.js, Express y TypeScript**:

- Generación de firma de integridad SHA-256 (`integritySignature`) y configuración para `BoldCheckout` en frontend
- Creación de sesiones de pago (integración API saliente con `fetch`) y obtención de URLs de redirección
- Consulta y conciliación de estado de transacciones (API de notificaciones de Bold, Sistecrédito y ePayco)
- Recepción, validación criptográfica (`x-bold-signature` HMAC-SHA256 sobre Base64 con `crypto.timingSafeEqual`) y normalización de webhooks de confirmación (server-to-server) en Express
- Gestión de tokens Bearer con caché en memoria (patrón ePayco) y OAuth 2.0 (Addi)
- Cron jobs y scripts de verificación para resolver transacciones pendientes
- Variables de entorno, headers, payloads y tipados TypeScript estrictos

## When to Use This Skill

- Usuario pide integrar **Bold**, **Addi**, **Sistecrédito** o **ePayco** en un backend Node.js / TypeScript (Express) o frontend Angular/TS
- Usuario necesita generar el hash de integridad SHA-256 de Bold o abrir `new BoldCheckout(...)` con `boldPaymentButton.js`
- Usuario necesita verificar la firma `x-bold-signature` (HMAC-SHA256 sobre `rawBody.toString('base64')`) en el webhook de Bold
- Usuario necesita conciliar pagos de Bold consultando `{BOLD_URL_NOTIFICATIONS}/{id}?is_external_reference=true` con `x-api-key`
- Usuario necesita crear una sesión/orden de pago y obtener la URL de redirección (Addi / Sistecrédito)
- Usuario pregunta qué variables de entorno se necesitan para cada pasarela (`BOLD_IDENTITY_KEY`, `BOLD_SECRET_KEY`, etc.)
- Usuario necesita consultar o revertir una transacción en Bold, ePayco o Sistecrédito
- Usuario pide implementar el webhook de confirmación en Express para cualquiera de las pasarelas
- Usuario necesita normalizar o mapear los payloads a una estructura canónica unificada (`CanonicalPaymentResult`)
- Usuario necesita implementar un script/cron que resuelva transacciones pendientes (Bold o ePayco)
- Usuario necesita crear o replicar la página HTML de respuesta (thank-you page) que ve el cliente al regresar de la pasarela

## Arquitectura General

```
Frontend (Angular / Web)  →  Backend API (Node.js / Express / TypeScript)  →  Pasarela Externa (Bold / Addi / Sistecrédito)
                                              ↑                                           |
                                       Auth Middleware                                    |
                                    (JWT / Bearer token)                           Callback/Webhook
                                                                                          ↓
                                                                                /api/webhooks/{gateway}
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
| `references/canonical-structure.md` | Estructura canónica TypeScript + funciones mapper (`normalizePaymentStatus`, `buildPaymentResult`) |
| `references/paginas-respuesta.md` | Documentación completa de las páginas de respuesta HTML |
| `templates/integracion/bold-generar-hash.ts` | Plantilla TypeScript: generar hash SHA-256 y configuración `BoldCheckout` |
| `templates/integracion/bold-conciliar-pago.ts` | Plantilla TypeScript: conciliación y selección de notificaciones en Bold |
| `templates/integracion/sistecredito-crear-orden.ts` | Plantilla TypeScript: crear orden Sistecrédito |
| `templates/integracion/sistecredito-consultar-orden.ts` | Plantilla TypeScript: consultar estado Sistecrédito |
| `templates/integracion/addi-obtener-url.ts` | Plantilla TypeScript: obtener URL Addi |
| `templates/integracion/epayco-crear-sesion.ts` | Plantilla TypeScript: crear sesión ePayco |
| `templates/integracion/epayco-detalle-transaccion.ts` | Plantilla TypeScript: detalle y reversión ePayco |
| `templates/integracion/helpers/createPaymentRequestBody.ts` | Helper TypeScript: construir body Credinet |
| `templates/webhooks/webhook-bold.ts` | Plantilla Express/TS webhook Bold (con verificación `x-bold-signature`) |
| `templates/webhooks/webhook-sistecredito.ts` | Plantilla Express/TS webhook Sistecrédito |
| `templates/webhooks/webhook-addi.ts` | Plantilla Express/TS webhook Addi |
| `templates/webhooks/webhook-epayco.ts` | Plantilla Express/TS webhook ePayco |
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
| Firma de Webhook | Header `x-bold-signature`: HMAC-SHA256 sobre Base64 del rawBody | Sin firma criptográfica | Sin firma criptográfica | Campo `x_signature` SHA-256 en el payload |
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
3. Generar hash de integridad SHA-256 y objeto `checkout` → `templates/integracion/bold-generar-hash.ts`
4. Abrir el modal en el frontend con `new BoldCheckout(config).open()` → `references/frontend-integration.md`
5. Implementar webhook con verificación `x-bold-signature` → `templates/webhooks/webhook-bold.ts`
6. Implementar conciliación y script de pendientes → `templates/integracion/bold-conciliar-pago.ts`

### Flujo Sistecrédito

1. Leer referencia de variables de entorno → `references/env-variables.md#sistecredito`
2. Usar helper para construir el body → `templates/integracion/helpers/createPaymentRequestBody.ts`
3. Crear la orden → `templates/integracion/sistecredito-crear-orden.ts`
4. Consultar estado (si se necesita polling) → `templates/integracion/sistecredito-consultar-orden.ts`
5. Implementar webhook → `templates/webhooks/webhook-sistecredito.ts`

### Flujo Addi

1. Leer referencia de variables de entorno → `references/env-variables.md#addi`
2. Implementar obtención de token OAuth → `references/addi-api.md#autenticacion`
3. Obtener URL de redirección (manejando HTTP 302 con `{ redirect: 'manual' }`) → `templates/integracion/addi-obtener-url.ts`
4. Implementar webhook → `templates/webhooks/webhook-addi.ts`

### Flujo ePayco (Legacy)

1. Leer referencia de variables de entorno → `references/env-variables.md#epayco`
2. Implementar gestión del token Bearer con caché → `references/epayco-api.md#token`
3. Crear sesión de checkout → `templates/integracion/epayco-crear-sesion.ts`
4. Consultar detalle / revertir transacción → `templates/integracion/epayco-detalle-transaccion.ts`
5. Implementar webhook de confirmación con verificación SHA-256 → `templates/webhooks/webhook-epayco.ts`

### Flujo Páginas de Respuesta

1. Leer la documentación completa → `references/paginas-respuesta.md`
2. Elegir la plantilla correspondiente:
   - Bold → `templates/paginas-respuesta/response-bold.html`
   - Addi → `templates/paginas-respuesta/response-addi.html`
   - Sistecrédito → `templates/paginas-respuesta/response-sistecredito.html`
   - ePayco → `templates/paginas-respuesta/response-epayco.html`
3. Identificar el query param de entrada (`orderId` / `bold-order-id` para Bold, `id_order` para Addi, `orderId` para Sistecrédito, `ref_payco` para ePayco).
4. El script frontend consulta `/api/state-transaction?id_visita={id}` en el backend Express y pinta el DOM.

### Flujo de Integración Frontend (Angular)

1. Leer la guía completa de consumo frontend → `references/frontend-integration.md`
2. **Para Bold:**
   - Inyectar `<script defer src="https://checkout.bold.co/library/boldPaymentButton.js"></script>`.
   - Declarar tipado global `declare const BoldCheckout: any;`.
   - Instanciar `new BoldCheckout(gateway.checkout)` (con `customerData` y `billingAddress` como strings JSON) y llamar `.open()`.
3. **Para Addi y Sistecrédito:** Redirigir vía `window.location.assign(gateway.redirect_url)`.
4. **Para ePayco (Legacy):** Inyectar `checkout-v2.js` e inicializar `ePayco.checkout.configure(gateway.checkout).open()`.

---

## Normalización de Estados

Todas las pasarelas deben mapear sus estados al formato canónico (`x_cod_response` y `x_response`):

| Pasarela | Estado bruto | `code` | `response` |
|----------|-------------|:------:|------------|
| Bold | `SALE_APPROVED` | 1 | `APROBADA` |
| Bold | `SALE_REJECTED` | 2 | `PAGO RECHAZADO` |
| Bold | `VOID_APPROVED` | 6 | `Reversada` |
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

> Ver tipos y mapper completo en [canonical-structure.md](./references/canonical-structure.md).

---

## Gotchas & Soluciones

- **Bold: Firma HMAC sobre Base64 del rawBody Buffer** — `x-bold-signature` requiere el buffer crudo (`rawBody.toString('base64')`). Nunca aplicar `JSON.stringify(req.body)` porque altera espacios y rompe la firma.
- **Bold: `VOID_REJECTED` es No-Op** — Responder `200 OK` inmediatamente sin alterar el pedido.
- **Bold: Monto y OrderId enteros** — `orderId` y `amount` deben ser enteros positivos sin decimales para calcular `integritySignature`.
- **Addi: HTTP 302** — Al usar Node.js `fetch`, pasar `{ redirect: 'manual' }` y extraer la URL de `res.headers.get('location')`.
- **Sistecrédito: 4 headers obligatorios** — Enviar `SCOrigen`, `country`, `Ocp-Apim-Subscription-Key`, `ApplicationKey`, `ApplicationToken`.
- **Idempotencia en Webhooks** — Validar contra la base de datos si la orden ya está aprobada para evitar duplicidad.
