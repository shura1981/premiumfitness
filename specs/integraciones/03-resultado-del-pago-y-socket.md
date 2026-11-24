# Resultado del pago y estado en tiempo real con el socket

Pedido de Steven (24 nov 2026). La app web preguntaba cada 3 segundos si el pedido ya estaba aprobado y no usaba el socket de elinut (`socket.elitenutapp.com`), que es para eso. Debe funcionar como el sitio de Nutramerican y como radical:

- «el webhook es el único que cambia el estado del pago, lo que hace el frontend es con el id de pago comprobar con el backend el estado y mostrar» (páginas `ecommerceNutra/response-bold`, `response-addy` y `response-sistecredito` del sitio, que consultan una vez `state-transaction` con el id de pago).
- «el cliente no se va quedar esperando en la página de respuesta, lo más probable es que navegue a la app y vea la sección de pedidos, en radical ahí también cambia el estado con el socket».
- «en radical cuando se procesa una orden o se pone un número de guía se usa el socket».

## Objetivo

Que el cliente o el entrenador vea el resultado de su pago al volver de la pasarela y que, esté donde esté en la app, el pedido cambie de estado en cuanto la pasarela avise al backend, sin consultas repetidas.

## Alcance

- **Respuesta de la pasarela:** el webhook guarda en el pedido el estado (`status_id`) y lo que mandó la pasarela (`payment_state_json`), como en radical. Un pedido = un pago, con el id `pf-<pedido>` (`orders.payment_code`, spec `backend/03` P-24). El estado del pedido lo cambia solo el webhook.
- **Página de respuesta:** cada pasarela devuelve al cliente a su dirección de la app, con el id de pago en el mismo parámetro que usa en radical y en el sitio: `/pago/resultado/bold?orderId=pf-<pedido>`, `/pago/resultado/addi?id_order=pf-<pedido>` y `/pago/resultado/sistecredito?orderId=pf-<pedido>` (con `/coach` delante para el entrenador). La página consulta una vez `GET /api/payments/pf-<pedido>` y muestra estado, referencia, número de transacción, total y fecha. Si el pago está aprobado, vacía el carrito; si no se aprobó, lleva al carrito para comprar de nuevo (otro pedido).
- **Socket en la app web:** mientras hay sesión, la app web se conecta al gateway (proyecto `premiumfitness`) y registra su conexión en el backend (`PUT /api/account/socket`). El backend le avisa a esa conexión cada cambio de su pedido y la app lo actualiza en la página de respuesta, en «Mis pedidos» y en el detalle:
  - `purchase.status.changed`: el webhook cambió el estado del pago (aprobado, rechazado…);
  - `order.updated`: el call center registró el pedido de SAP («Preparando») o la guía («En camino»), o se canceló o devolvió.
- **Socket en el admin** (proyecto `premiumfitness-admin`, a todos los agentes conectados), como en radical:
  - `order.created`: pedido nuevo (compra creada o canje);
  - `order.status.changed`: el webhook cambió el estado del pago;
  - `order.processed`: se registró el pedido de SAP (la orden sale de la cola de procesar);
  - `order.tracking.updated`: se registró la guía.
- **Sin consultas repetidas:** se retira la consulta cada 3 segundos de la página de respuesta.

## Fuera de alcance

- Las notificaciones push (Firebase) de «Preparando» y «En camino»: son otra tarea del spec `backend/03`. El socket solo actualiza la app abierta.
- Las páginas de respuesta HTML del backend heredadas de radical (`/response/bold`): no se usan, porque la app web tiene la suya.
- Configurar el gateway de sockets: lo hace quien administra `socket.elitenutapp.com` (ver «Configuración»).

## Criterios de aceptación

1. Un pago cambia de estado solo con el webhook de la pasarela; la página de respuesta y el socket solo muestran.
2. La página de respuesta hace una sola consulta al backend con el id de pago; no consulta cada pocos segundos.
3. `GET /api/payments/pf-<pedido>` responde 404 si el pedido no es de quien consulta.
4. Al llegar el webhook, al registrar SAP o la guía, o al cancelar o devolver, la app conectada actualiza el pedido sin recargar en la página de respuesta, en «Mis pedidos» y en el detalle.
5. El admin recibe el pedido nuevo, el cambio de pago, el pedido procesado en SAP y la guía sin recargar.
6. Si el socket no está disponible, la app sigue funcionando: el estado se ve al abrir o recargar la pantalla.

## Configuración

- **Gateway (`socket.elitenutapp.com`, variable `GATEWAY_PROJECTS`):** proyecto `premiumfitness` con su clave (32 o más caracteres) y `cors` con los dominios de la app web (`https://premiumfitness.nutramerican.com` y `http://localhost:4200`).
- **Backend (`.env`):** `SOCKET_NOTIFICATION_URL`, `SOCKET_GATEWAY_PROJECT_ID_CLIENT=premiumfitness` y `SOCKET_NOTIFICATION_TOKEN` con la clave de ese proyecto.
- **App web (`environment`):** `socket: { url, project: 'premiumfitness' }`.

## Supuestos y preguntas abiertas

| # | Punto | Estado |
| --- | --- | --- |
| R-01 | El webhook es el único que cambia el estado del pago; la app solo consulta con el id de pago y muestra. | Confirmado (24 nov 2026, Steven) |
| R-02 | El estado en «Mis pedidos» y en el detalle también se actualiza con el socket, como en radical. | Confirmado (24 nov 2026, Steven) |
| R-03 | Si el cliente llega a la página de respuesta antes que el webhook, la página muestra «pendiente» y se actualiza sola con el socket. | Derivado |
| R-06 | Un pedido = un pago, como en radical: no existe «pagar de nuevo» sobre un pedido ya creado. Si el pago no se aprueba, el cliente compra de nuevo desde el carrito y sale otro pedido. | Confirmado (24 nov 2026, Steven) |
| R-05 | El socket avisa también cuando el call center procesa la orden (SAP) y pone la guía, al cliente y al admin. | Confirmado (24 nov 2026, Steven) |
| R-04 | ~~Medio de pago mostrado: el que informa Bold; en Addi y Sistecrédito, el nombre de la pasarela.~~ **Descartado (24 nov 2026):** era un dato inventado; la página muestra la pasarela y lo que mandó queda completo en `payment_state_json`. | Descartado |

## Comparación con radical (24 nov 2026)

Módulo `src/Features/PaymentGateway/` de este repo frente al de `backend-radical-nutrition`, después de la reestructuración. Sirve para revisar que no haya diferencias sin motivo.

| Archivo | Frente a radical | Motivo |
| --- | --- | --- |
| `Result/PaymentResult.php`, `Normalizer/*`, `Events/PaymentEvent.php`, `Events/PaymentEventHandlerRunner.php`, `Gateways/Bold/BoldSignatureVerifier.php`, `Gateways/Addi/AddiCheckoutService.php`, `AddiTokenService.php`, los handlers de correo y de la cola | Iguales | — |
| `Gateways/*/…WebhookProcessor.php` (los tres) | Solo cambia cómo se encuentra el pedido: por `orders.payment_code` en vez de convertir el id a número | Prefijo `pf-` (P-24) |
| `Gateways/Bold/BoldCheckoutService.php` | El id de la orden admite letras y guion (`pf-12`) | Prefijo `pf-` |
| `Gateways/Bold/BoldReconciliationService.php`, `BoldPendingCronService.php` | Consultan a Bold con `pf-<pedido>` en vez del número | Prefijo `pf-` |
| `Events/PaymentEventDispatcher.php` | Al aprobar llama a `PurchaseApproval` (comisión, puntos, referido y valor pagado) en vez de solo marcar el estado | Lógica de negocio de PremiumFitness |
| `Events/Handlers/EmitClientPurchaseStatusHandler.php` | Añade `order_status` (el estado como lo muestra la app) | La app web usa sus propios nombres de estado |
| `Events/Handlers/BroadcastAdminOrderStatusHandler.php` | Mismo evento; usa la interfaz `RealtimeGateway` | Para poder probarlo sin el gateway |
| `Events/Handlers/SendSmsHandler.php` | Solo el nombre de la marca en el texto | — |
| `PaymentGatewayController.php` | Sin `consume*` ni páginas `response*`; un id ajeno responde 200 sin tocar nada | P-02 (la app no envía valores); la respuesta está en la app web |
| `PaymentCode.php`, `ForeignPaymentReferenceException.php`, `PaymentStatusController.php` | Nuevos | Prefijo `pf-` y consulta del pago por su id |
| `ApplyCreditBalanceHandler`, `BroadcastCreditBalanceAppliedHandler` | No están | PremiumFitness no tiene saldo a favor |
| `Validators/*ConsumeValidator.php` | No están | No existen los endpoints `consume/*` (P-02) |
| `Logging/PaymentGatewayLogService.php` | No está (se quitó el 16 nov) | **Por confirmar con Steven:** radical guarda en `logs/payment-gateway/` los fallos al abrir una pasarela |
| `Gateways/Sistecredito/SistecreditoCheckoutService.php`, `SistecreditoQueryService.php` | Distintos desde el 16 nov: el cuerpo de la orden y las cabeceras se armaron con la forma de la integración del sitio | **Por confirmar con Steven:** no se revisaron en esta reestructuración |

## Plan

1. Base: columna `orders.payment_code` (`pf-<pedido>`, única). No hay tabla de intentos.
2. Backend: los webhooks buscan el pedido por `payment_code` y guardan en el pedido, como radical; `GET /api/payments/{pf-N}` lee el pedido y `PUT /api/account/socket` registra la conexión; el aviso `purchase.status.changed` lleva el pedido y el estado tal como lo muestra la app.
3. App web: servicio de socket (`socket.io-client`, MIT), eventos de pedidos que escuchan la página de respuesta, «Mis pedidos», el detalle y el carrito; nueva página de respuesta sin consultas repetidas. En el modo de API simulada, la pasarela simulada emite el mismo evento.

## Checklist

- [~] Columnas del resultado del intento en `payment_attempts`. **Eliminadas el 24 nov 2026** con la tabla: la respuesta de la pasarela queda en el pedido (`payment_state_json`), como en radical
- [x] El webhook guarda en el pedido el estado y lo que mandó la pasarela (`status_id`, `payment_state_json`), como radical
- [x] `GET /api/payments/{pf-N}` busca el pedido por `orders.payment_code`, solo para su dueño
- [x] `PUT /api/account/socket` para registrar la conexión del usuario
- [x] `purchase.status.changed` con el pedido y el estado de la app
- [x] `order.updated` al cliente al registrar SAP o la guía y al cancelar o devolver
- [x] Admin: `order.created` al crear una compra o un canje, `order.processed` al registrar SAP (también desde el endpoint sin agente) y `order.tracking.updated` al registrar la guía
- [x] Pruebas del backend: resultado del intento antes y después del webhook, solo el dueño, registro de la conexión, avisos al admin y al dueño (con un canal que los registra) y medio de pago de Bold
- [x] App web: conexión al socket y registro de la conexión
- [x] App web: «Mis pedidos», detalle, carrito y página de respuesta se actualizan con los eventos
- [x] Admin: la lista de pedidos y el reporte de seguimiento se actualizan con la guía
- [x] App web: página de respuesta con una sola consulta por el id de pago, sin consultas repetidas
- [x] Cada pasarela devuelve a su página de respuesta con su parámetro: `/pago/resultado/bold?orderId=`, `/addi?id_order=` y `/sistecredito?orderId=`
- [x] Pruebas de la app web: la página de respuesta consulta una vez, no vuelve a consultar sola en un minuto y consulta otra vez solo con el aviso del socket (verificado que falla si se agrega una consulta cada 3 segundos)
- [x] Contrato en `specs/backend/03-api-de-premiumfitness.plan.md`
- [ ] Registrar los proyectos `premiumfitness` y `premiumfitness-admin` en el gateway y probar en el navegador (lo configura Steven)
- [x] Las pruebas del cierre mensual (`ScheduledJobsTest`, `AdminBusinessTest`) agotaron los meses libres de la base local de tanto correrlas: Steven pidió recrear la base (`scripts/reset-db.sh`, 24 nov 2026). Después pasan las 506 pruebas
- [x] Reestructuración como radical (24 nov 2026, spec `backend/03`): sin tabla de intentos ni «pagar de nuevo»; id de pago `pf-<pedido>` en `orders.payment_code`; una página de respuesta por pasarela
- [ ] Probar con las llaves reales en modo de pruebas un pago aprobado y uno rechazado por cada pasarela: que Addi y Sistecrédito devuelvan al cliente con su parámetro y que cada webhook llegue (Steven)
