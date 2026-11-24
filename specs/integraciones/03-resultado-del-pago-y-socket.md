# Resultado del pago y estado en tiempo real con el socket

Pedido de Steven (24 nov 2026). La app web preguntaba cada 3 segundos si el pedido ya estaba aprobado y no usaba el socket de elinut (`socket.elitenutapp.com`), que es para eso. Debe funcionar como el sitio de Nutramerican y como radical:

- «el webhook es el único que cambia el estado del pago, lo que hace el frontend es con el id de pago comprobar con el backend el estado y mostrar» (páginas `ecommerceNutra/response-bold`, `response-addy` y `response-sistecredito` del sitio, que consultan una vez `state-transaction` con el id de pago).
- «el cliente no se va quedar esperando en la página de respuesta, lo más probable es que navegue a la app y vea la sección de pedidos, en radical ahí también cambia el estado con el socket».
- «en radical cuando se procesa una orden o se pone un número de guía se usa el socket».

## Objetivo

Que el cliente o el entrenador vea el resultado de su pago al volver de la pasarela y que, esté donde esté en la app, el pedido cambie de estado en cuanto la pasarela avise al backend, sin consultas repetidas.

## Alcance

- **Intento de pago:** el webhook guarda en el intento (`payment_attempts`, id `pf-<n>`, P-24) lo que reportó la pasarela: estado, número de transacción, medio de pago, valor pagado y fecha del aviso. El estado del pedido lo sigue cambiando solo el webhook.
- **Página de respuesta:** las tres pasarelas devuelven al cliente a `/pago/resultado?pago=pf-<n>` (o `/coach/pago/resultado` para el entrenador). La página consulta una vez `GET /api/payments/pf-<n>` y muestra estado, referencia, número de transacción, medio de pago, total y fecha. Si el pago está aprobado, vacía el carrito.
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
3. `GET /api/payments/pf-<n>` responde 404 si el pago no es de quien consulta.
4. Al llegar el webhook, al registrar SAP o la guía, o al cancelar o devolver, la app conectada actualiza el pedido sin recargar en la página de respuesta, en «Mis pedidos» y en el detalle.
5. El admin recibe el pedido nuevo, el cambio de pago, el pedido procesado en SAP y la guía sin recargar.
6. Si el socket no está disponible, la app sigue funcionando: el estado se ve al abrir o recargar la pantalla.

## Configuración

- **Gateway (`socket.elitenutapp.com`, variable `GATEWAY_PROJECTS`):** proyecto `premiumfitness` con su clave (32 o más caracteres) y `cors` con los dominios de la app web (`https://premiumfitness.nutramerican.com` y `http://localhost:4200`).
- **Backend (`.env`):** `SOCKET_NOTIFICATION_URL`, `SOCKET_GATEWAY_PROJECT_ID_CLIENT=premiumfitness` y `SOCKET_NOTIFICATION_TOKEN` con la clave de ese proyecto.
- **App web (`environment`):** `socketUrl` y `socketProject: 'premiumfitness'`.

## Supuestos y preguntas abiertas

| # | Punto | Estado |
| --- | --- | --- |
| R-01 | El webhook es el único que cambia el estado del pago; la app solo consulta con el id de pago y muestra. | Confirmado (24 nov 2026, Steven) |
| R-02 | El estado en «Mis pedidos» y en el detalle también se actualiza con el socket, como en radical. | Confirmado (24 nov 2026, Steven) |
| R-03 | Si el cliente llega a la página de respuesta antes que el webhook, la página muestra «pendiente» y se actualiza sola con el socket. | Derivado |
| R-05 | El socket avisa también cuando el call center procesa la orden (SAP) y pone la guía, al cliente y al admin. | Confirmado (24 nov 2026, Steven) |
| R-04 | Medio de pago mostrado: el que informa Bold (`data.payment_method`: tarjeta, PSE, Nequi…); en Addi y Sistecrédito, el nombre de la pasarela. | Derivado |

## Plan

1. Base: columnas del resultado en `payment_attempts` (en el `.sql` y en la base local, sin reset).
2. Backend: `PaymentResult` lleva la referencia del intento y el medio de pago; el despachador del webhook guarda el resultado en el intento; `GET /api/payments/{pf-n}` y `PUT /api/account/socket`; el aviso `purchase.status.changed` lleva la referencia y el estado tal como lo muestra la app.
3. App web: servicio de socket (`socket.io-client`, MIT), eventos de pedidos que escuchan la página de respuesta, «Mis pedidos», el detalle y el carrito; nueva página de respuesta sin consultas repetidas. En el modo de API simulada, la pasarela simulada emite el mismo evento.

## Checklist

- [x] Columnas del resultado del intento en `payment_attempts` (`.sql`, base local y DER)
- [x] El webhook guarda en el intento el estado, la transacción, el medio, el valor pagado y la fecha
- [x] `GET /api/payments/{pf-n}`, solo para el dueño del pedido
- [x] `PUT /api/account/socket` para registrar la conexión del usuario
- [x] `purchase.status.changed` con la referencia y el estado de la app
- [x] `order.updated` al cliente al registrar SAP o la guía y al cancelar o devolver
- [x] Admin: `order.created` al crear una compra o un canje, `order.processed` al registrar SAP (también desde el endpoint sin agente) y `order.tracking.updated` al registrar la guía
- [x] Pruebas del backend: resultado del intento antes y después del webhook, solo el dueño, registro de la conexión, avisos al admin y al dueño (con un canal que los registra) y medio de pago de Bold
- [x] App web: conexión al socket y registro de la conexión
- [x] App web: «Mis pedidos», detalle, carrito y página de respuesta se actualizan con los eventos
- [x] Admin: la lista de pedidos y el reporte de seguimiento se actualizan con la guía
- [x] App web: página de respuesta con una sola consulta por el id de pago, sin consultas repetidas
- [x] Las tres pasarelas devuelven a `/pago/resultado?pago=pf-<n>`
- [x] Pruebas de la app web: la página de respuesta consulta una vez, no vuelve a consultar sola en un minuto y consulta otra vez solo con el aviso del socket (verificado que falla si se agrega una consulta cada 3 segundos)
- [x] Contrato en `specs/backend/03-api-de-premiumfitness.plan.md`
- [ ] Registrar los proyectos `premiumfitness` y `premiumfitness-admin` en el gateway y probar en el navegador (lo configura Steven)
- [x] Las pruebas del cierre mensual (`ScheduledJobsTest`, `AdminBusinessTest`) agotaron los meses libres de la base local de tanto correrlas: Steven pidió recrear la base (`scripts/reset-db.sh`, 24 nov 2026). Después pasan las 506 pruebas
