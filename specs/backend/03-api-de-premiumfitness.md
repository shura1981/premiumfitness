# 03 — Backend: API de PremiumFitness

**Estado:** en curso · **Creado:** 15 de noviembre de 2026

## Petición original

> Termina los frontends y luego el backend.

Son los pasos 2 a 11 de «Lo que sigue» del spec [01-fork-base-del-backend.md](01-fork-base-del-backend.md).

## Objetivo

Que la app web y el admin funcionen contra el backend real, con las reglas de PremiumFitness: sesión segura, catálogo de la intranet, referidos, compras con descuento e IVA, puntos, comisión, canje, acceso a dietas, push y las pantallas nuevas del admin.

## Alcance

- Un contrato único de la API para los tres repos: [03-api-de-premiumfitness.plan.md](03-api-de-premiumfitness.plan.md).
- La implementación por fases, cada una con sus pruebas (checklist).
- El cierre de las rutas de radical que no piden sesión o que dejan ver datos de otros.

Las reglas de negocio no se repiten aquí: están en `docs/reglas-de-negocio.md` y en los specs `seguridad/01`, `catalogo/01`, `negocio/01` a `05`, `integraciones/01` y `02`.

## Fuera de alcance

- Desplegar en el hosting y programar los crons en cPanel.
- Las credenciales reales de SMS, pasarelas, SAP y Firebase: se configuran en el `.env` del hosting.
- El backend y la app de dietas.

## Criterios de aceptación

1. Los criterios de cada spec de negocio, seguridad, catálogo e integraciones se cumplen con pruebas.
2. Ninguna ruta de `/api` responde datos sin su credencial, salvo las públicas del contrato. Hay una prueba que recorre todas las rutas registradas y lo comprueba.
3. Un usuario no puede leer ni cambiar datos de otro: los endpoints de la app web no reciben el id del usuario por la URL.
4. La app web funciona contra el backend local sin la API simulada: registro, login, tienda, compra, pedidos, área del entrenador y canje.
5. `composer run lint` y `composer run test` pasan.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| P-01 | Los errores de la API nueva usan `{ message, code, errors }`, lo que ya espera la app web. Las rutas heredadas del admin conservan `{ error }` hasta adaptarlas. | Derivado |
| P-02 | Las pasarelas son **Bold, Addi y Sistecrédito**. En las tres, el backend arma el pago con el pedido guardado y el pedido lo aprueba el webhook de la pasarela, solo si lo pagado cubre su valor. El aviso de Bold trae firma; los de Addi y Sistecrédito no, y se aceptan como en radical: **el call center confirma cada pago en la plataforma de la pasarela**. | Confirmado (16 nov 2026, Steven) |
| P-03 | **El flete se cobra según la ciudad, como en la tienda de radical** (`assets/js/shipping-calculator.js`), pero lo calcula el backend: la app nunca envía el valor. Radical lo calculaba en el navegador y aceptaba el que llegara. (1) Tarifa de Servientrega de `tb_servientrega` (intranet, solo lectura) con la fórmula del webservice `/fletes` del sitio de Nutramerican: tipo 3, 4.900; tipo 4, 11.400; tipo 1 y 2, costo + 0,5 % de 300.000 + 2.000, y 12.500 si no pasa de 11.000. Se cotiza desde las seis bodegas (`SHIPPING_ORIGINS`) y se toma la más barata, como el webservice. (2) Bogotá: 11.300, gratis desde 170.000. Barranquilla, Bucaramanga, Cali, Cúcuta, Medellín y Pereira: 9.900, gratis desde 1.000.000. Los demás municipios nunca tienen envío gratis. (3) Ningún flete cobrado baja de 9.900. Los mínimos se comparan con lo pagado por los productos (con descuento e IVA). Todos los valores van en variables `SHIPPING_*`. El flete se guarda en `orders.shipping_amount` y no genera puntos ni comisión. Sin dirección, la vista previa no tiene flete (`shipping_amount: null`). Si Servientrega no llega a la ciudad, no se puede comprar con esa dirección (409 `shipping_unavailable`), como en radical. Diferencias a propósito con radical: Bogotá y las ciudades principales se reconocen por el nombre exacto (radical buscaba el texto dentro del nombre: Calima pagaba como Cali), y la tarifa se busca por el nombre de la ciudad, sin el departamento y con las letras con tilde o Ñ como comodín, porque la intranet tiene algunas mal escritas («EL PIÐON») (con la búsqueda de radical, 138 de 1.178 ciudades no encontraban tarifa; así quedan 5: San Andrés, Sevilla (Valle), Albán (Nariño), Barbosa (Santander) y Riosucio (Caldas)). | Confirmado (16 nov 2026, Steven) |
| P-25 | **El canje con puntos no paga envío**: el entrenador ya paga el producto a precio completo. Puede cambiar más adelante; si el canje pagara flete, tendría que ser en puntos (regla 16: no hay canje mixto). | Confirmado (16 nov 2026, Steven) |
| P-26 | **Estados de la reversión (RF-17).** «CANCELADA» es de las pasarelas: un pago que nunca se aprobó, sin dinero, puntos ni comisión. Cuando el cliente pide cancelar un pedido ya pagado (caso raro, pero debe existir) o se reversa un canje, el pedido queda «REVERSADA», que ya estaba en `status_orders` (id 5) y ninguna pasarela manda; se revierten la comisión y los puntos de ese pedido, y el dinero lo devuelve el comercio por fuera de la app. Al cliente, «REVERSADA» se le muestra como cancelado; en el admin tiene su propio color. | Confirmado (24 nov 2026, Steven) |
| P-27 | **Estado de pasarela que no está en la tabla de traducción:** se guarda con el nombre que mandó la pasarela (por ejemplo `EXPIRED`) y se crea en `status_orders` si no existe, en vez de `DESCONOCIDO`. Nunca aprueba el pedido. Si el nombre coincide con un estado que pone la app (APROBADA o REVERSADA; la tabla no distingue mayúsculas) o viene vacío, queda `DESCONOCIDO`. Se corta a 50 caracteres. Al cliente se le muestra como pendiente. | Confirmado (24 nov 2026, Steven) |
| P-28 | **Bold anula un pago aprobado (`VOID_APPROVED`):** el pedido pasa solo a «REVERSADA» con el mismo ajuste de cancelación que registra un gerente: se revierten la comisión y los puntos de ese pedido. El ajuste queda sin agente (`adjustments.agent_id` NULL) y el admin lo muestra como «Automático (Bold)»; la auditoría guarda que lo hizo la pasarela. Un pedido admite un solo ajuste: si ya tenía uno, la anulación no revierte otra vez. Un pedido que ya se aprobó (`approved_at`) no vuelve a cambiar de estado con otros avisos de la pasarela, aunque se haya reversado. La anulación de un pago que nunca se aprobó sigue dejándolo «PAGO RECHAZADO». | Confirmado (25 nov 2026, Steven) |
| P-29 | **Nadie cambia a mano el estado de un pago.** Lo cambia solo la pasarela con su webhook. La única excepción es «REVERSADA»: el cliente pide cancelar un pedido ya pagado, el comercio le devuelve el dinero por fuera de la app y un gerente registra en el admin el ajuste «Reversar», que deja el pedido «REVERSADA» y le quita al entrenador los puntos y la comisión. En Bold, la reversión se hace con su endpoint, que dispara el webhook `VOID_APPROVED` (P-28). Se retiró `PATCH /api/admin/orders/{id}/status`, heredada de radical, que dejaba poner cualquier estado. | Confirmado (25 nov 2026, Steven) |
| P-30 | **No hay devoluciones.** El único ajuste es «Reversar» (`type: cancellation`): el pedido completo, una sola vez. Se retiraron la devolución total (estado «DEVOLUCIÓN») y la parcial (por productos), que salían del PRD (RF-17) y no existen en el negocio ni en `docs/requerimientos.md`. | Confirmado (25 nov 2026, Steven) |
| P-31 | **Una reversión en los reportes de ventas, como radical.** Los reportes cuentan solo los pedidos «APROBADA», así que un pedido «REVERSADA» sale entero de las ventas del día en que se vendió, aunque ese mes ya se haya cerrado; en el reporte por estado aparece como «REVERSADA». No hay línea de «reversiones» en el mes en que se reversa. La comisión no cambia: se descuenta en el mes de la reversión y el Excel de un cierre no se modifica. | Confirmado (25 nov 2026, Steven: «la A, como radical. No es frecuente que se reversen pedidos») |
| P-32 | **Fecha de una venta en los reportes, como radical:** la de creación del pedido (`created_at`). La comisión y el cierre usan la del pago aprobado (`approved_at`), así que un pedido creado el último día del mes y pagado el primero del siguiente (o un crédito de Addi o Sistecrédito que tarda en aprobarse) cae en meses distintos en las ventas y en la comisión. | Confirmado (25 nov 2026, Steven: «la A, como radical») |
| P-33 | **«Ingresos» en los reportes, como radical:** lo cobrado al cliente (`orders.amount`): productos con descuento e IVA más el flete, la misma cifra que cobra la pasarela. En SAP el flete también se registra como un producto. Los reportes por producto suman `line_total` (con IVA, sin flete). El IVA, el flete y la base quedan aparte en el pedido (`tax_amount`, `shipping_amount`, `tax_base`). | Confirmado (25 nov 2026, Steven: «la A, como radical, por que igual en sap se registra el flete como un producto») |
| P-34 | **Rótulos del dashboard del admin.** Los datos son los de radical y no cambian; cambian los rótulos que no decían lo que cuentan: «Total histórico» muestra los pedidos aprobados con su dinero (antes mezclaba pedidos de todos los estados con el dinero de los aprobados), «Pendientes envío» pasa a «Pagos pendientes» (cuenta los estados PENDIENTE…) y «Cancelados» a «Rechazados o cancelados». Los REVERSADA no tienen ficha: se ven en el reporte por estado. | Confirmado (25 nov 2026, Steven: «sí, hazlo así») |
| P-35 | **Autorreferidos y cuentas duplicadas (D-05).** Lo que ya bloquea el sistema: un rol por cuenta; celular (verificado por SMS), documento y correo únicos; el cupón solo para clientes; el referido solo con una cuenta nueva y nunca a sí mismo; la compra propia de B no activa los puntos de A. Contra cuentas falsas con datos de otra persona, que ningún sistema detecta sin validar identidad: (1) **no** se bloquean compras por dirección ni por datos parecidos, porque muchos entrenadores reciben en el gimnasio los pedidos de sus clientes; (2) los 50.000 puntos de A los activa una compra de al menos `TRAINER_REFERRAL_MIN_PURCHASE` (149.000) en productos, sin flete (regla 6); (3) los términos y condiciones prohíben las cuentas falsas o con datos de otra persona y permiten suspender al entrenador (`PATCH /api/admin/trainers/{id}/status`, ya existe). | Confirmado (26 nov 2026, Steven: «Si apruebo… El monto mínimo 149 mil que igual puedo cambiar en la variable de entorno») |
| P-13 | El precio con descuento de cada unidad se redondea al peso (0,5 sube): el peso no tiene centavos y la pasarela cobra enteros. La base y el IVA de la línea sí llevan dos decimales. | Derivado |
| P-14 | La venta se atribuye al entrenador que tenía el cliente al crear el pedido (queda en `orders_coupons`), aunque cambie antes de pagarse. | Derivado |
| P-15 | Un pago por menos del valor del pedido no lo aprueba. Un mismo pago de la pasarela no puede aprobar dos pedidos (`orders.payment_reference` única). | Derivado |
| P-04 | El registro pide tipo y número de documento: la app de dietas consulta el acceso por documento (spec `integraciones/02`) y SAP lo necesita para facturar. El documento es único. | Derivado |
| P-05 | Las rutas de radical que PremiumFitness no usa se quitan de la API ahora; sus clases se borran en la fase que adapta cada parte. | Derivado |
| P-06 | El cupón del entrenador se genera al registrarse: letras de su nombre y un número, único. El admin no lo edita en esta entrega. | Derivado |
| P-07 | Un ajuste parcial revierte la comisión y los puntos de las líneas devueltas, en proporción a la cantidad. Un pedido admite un solo ajuste. | Derivado: PRD §7.4 |
| P-16 | El canje no cobra envío. | Derivado: confirmar |
| P-17 | **Puntos al reversar una venta.** Se retiran completos los puntos que el pedido le dio al entrenador: primero lo que quede en el lote de ese pedido y lo que ya usó, de sus otros puntos vigentes por FIFO (el más antiguo primero). Si no le alcanzan, se retira lo que tenga y no queda deuda. Si esa venta activó los 50.000 puntos del referido de A, a A se le retiran con la misma regla (lo que quede en ese lote y lo ya usado de sus otros puntos, sin deuda) y el referido vuelve a quedar pendiente: la próxima venta aprobada de un cliente de B lo activa con un lote nuevo. Así nadie cobra dos veces el mismo referido. La comisión se descuenta completa (regla 18). Los términos y condiciones deben explicar esta regla a los entrenadores (spec `negocio/04`). | Confirmado (25 nov 2026, Steven: «sí, la B y lo del PRD»; para el uso parcial de los 50.000: «la B») |
| P-18 | Un canje reversado devuelve los puntos en un lote nuevo, con la vigencia normal (`POINTS_EXPIRATION_MONTHS`). | Derivado: el PRD §7.4 deja la vigencia por definir |
| P-19 | ~~Un pedido pendiente se puede volver a pagar durante un día.~~ **Descartado (24 nov 2026, Steven):** era una propuesta que nadie pidió y radical no la tiene. Como en radical, si el pago no se aprueba el cliente vuelve al checkout y se crea un pedido nuevo; el anterior queda pendiente. No existe «pagar de nuevo» sobre un pedido ya creado. | Confirmado (24 nov 2026, Steven) |
| P-21 | Los cargos que gestionan entrenadores, estrategias, cierre y ajustes son ROOT, GERENTE COMERCIAL y ADMINISTRADOR (`ADMIN_MANAGER_ROLES`), los mismos que ya ven el dashboard y los reportes en el admin. El resto de agentes ve pedidos y reportes como en radical. | Derivado: confirmar qué cargo tiene financiero (PD-22) |
| P-22 | En el admin no se puede marcar un pedido como «APROBADA» a mano: lo aprueba el aviso de la pasarela, que es el que acredita comisión y puntos. | Derivado: confirmar si el call center recibe pagos por fuera de la pasarela |
| P-23 | El seguimiento de un pedido (compra o canje) es: pago aprobado («Canje confirmado» en un canje), «Preparando tu pedido» cuando el call center registra el pedido de SAP y «En camino» (`en_camino`) cuando registra la guía. Ahí termina: **no hay «Entregado»**. Los estados de `status_orders` son los del pago: `ENVIADO` es un pago enviado a la pasarela que aún no tiene respuesta (aprobado, rechazado o cancelado), no un pedido despachado. Difiere de la propuesta del PRD §9, que incluía «entregado». | Confirmado (16 nov 2026, Steven) |
| P-24 | **El id de pago es `pf-<número del pedido>`** (`pf-1250`), guardado en `orders.payment_code` (único) al crear el pedido. Un pedido = un pago = un id, como en radical; la única diferencia es el prefijo. Ese id va a la pasarela (`orderId` en Bold y Addi, `Invoice` en Sistecrédito) y con él el webhook busca el pedido (`WHERE payment_code = ?`). El prefijo existe porque las cuentas de las pasarelas ya están en producción y se comparten con otros proyectos: con el número solo, un pago de otro proyecto chocaría con un pedido de aquí. Un id que no es de PremiumFitness se responde con 200 y no toca nada. Reemplaza al diseño del 16 nov (tabla `payment_attempts`, un id por intento), que se elimina. | Confirmado (24 nov 2026, Steven) |
| P-20 | La lista de festivos de `NON_BUSINESS_DAYS` la carga quien despliega y se actualiza cada año; el cierre avisa (`holidays_missing_<año>`) si faltan los del año en que se paga. | Derivado |
| P-08 | Proveedor de SMS: el de radical (Relax Global), con sus variables (`SMS_API_URL`, `SMS_API_TOKEN`). | Confirmado (25 nov 2026, D-10, Steven: «sí, déjalo con Relax Global como radical») |
| P-09 | En desarrollo, `SMS_DRIVER=log` escribe el SMS en `logs/sms-dev.log` en vez de enviarlo; en producción solo se admite el envío real. | Derivado |
| P-10 | Si dos pestañas renuevan la sesión a la vez con la misma cookie, la segunda recibe su access token sin rotar de nuevo (margen de 10 segundos). Pasado ese margen, reutilizar un refresh token revoca la sesión. | Derivado |
| P-11 | Un celular registrado que nunca se verificó no queda bloqueado: quien se registre de nuevo con él y lo verifique se queda con la cuenta. | Derivado |
| P-12 | Los códigos SMS y los tokens de recuperación se guardan con HMAC-SHA-256 y la clave `JWT_REFRESH_SECRET`: un volcado de la base no permite probar los 6 dígitos. | Derivado |

## Hallazgos de seguridad en el código heredado

Al revisar el fork (15 nov 2026) se encontró que la API de radical:

1. **Rutas sin sesión:** lista, crea, cambia y borra usuarios (`/api/users`), direcciones, productos, categorías, ciudades y bodegas sin pedir sesión.
2. **Datos de otros:** con la sesión de cualquier usuario deja ver los pedidos y saldos de otros (`/api/users/{id}/orders`) y cambiar el estado, el código SAP o la guía de cualquier pedido.
3. **Aprobar pedidos sin pagar:**
   - `POST /api/webhooks/orders` cambia el estado de cualquier pedido sin firma ni sesión;
   - los avisos de pago de Addi y Sistecrédito (`/api/payment-gateway/webhook/*`) aprueban el pedido que diga el cuerpo, sin verificar que los envía la pasarela;
   - `POST /api/payment-gateway/consume/bold` firma el valor que envíe la app, y al llegar el aviso de Bold no se compara lo pagado con el valor del pedido: se puede pagar $1 por cualquier pedido.
4. **Servidor de desarrollo:** `server.php` entregaba cualquier archivo del proyecto pedido por URL (`.env` incluido). Solo afecta a `composer run start`.

En PremiumFitness todo quedó corregido y con pruebas (criterios 2 y 3; P-02 y P-15), salvo los avisos de Addi y Sistecrédito, que siguen sin verificar quién los envía por decisión de P-02: un aviso aprueba el pedido solo si el valor cubre el pedido, y el call center confirma el pago en la plataforma. **`backend-radical-nutrition` tiene los puntos 1 a 3 en producción.** No se toca desde este repo.

## Plan

Contratos: [03-api-de-premiumfitness.plan.md](03-api-de-premiumfitness.plan.md). Orden de trabajo: el del checklist. Cada fase deja `composer run lint` y `composer run test` pasando antes de empezar la siguiente.

## Checklist

### Autenticación (spec `seguridad/01`)

- [x] Access token con `sub`, `role` y `app`; refresh token rotativo en cookie por app, con detección de reutilización
- [x] Login, renovación y logout de la app web
- [x] Registro con verificación del celular por SMS (código de 6 dígitos con hash e intentos)
- [x] Recuperación de la contraseña por SMS
- [x] Login del admin con cookie `pf_rt_admin` y sesión de 12 horas
- [x] Límites por IP y por celular
- [x] Retirar las rutas de radical sin sesión y probar que todo `/api` exige credencial (`tests/E2E/Security/RoutesRequireCredentialsTest.php`)
- [x] `users.num_cell` único y actualizar `firebase/php-jwt` a la 7 (B-10)
- [x] Limpieza diaria de refresh tokens y códigos (`POST /cron-job/auth/cleanup`)
- [x] Reemplazar el checkout y las pasarelas heredadas: `POST /checkout` calcula y firma el pago en el servidor; se retiraron `/checkout/finalize` y `/payment-gateway/consume/*`
- [x] Habilitar Addi y Sistecrédito (P-02): `GatewayPayment` arma su pago con el pedido guardado y devuelve `redirect_url`, que vuelve a `/pago/resultado/addi` o `/pago/resultado/sistecredito`; webhooks `/payment-gateway/webhook/addi` y `/sistecredito`, con pruebas de aprobación, rechazo, valor que no cubre el pedido y aviso repetido. El servicio de Sistecrédito de radical leía la respuesta fuera de `data` y le faltaban headers: se corrige con la integración de producción (api.megaplexstars) (16 nov 2026)
- [~] Id de pago por intento (P-24 del 16 nov): tabla `payment_attempts`. **Reemplazado el 24 nov 2026** por `orders.payment_code` (un pedido = un pago, como radical); ver «Reestructuración de pagos como radical», abajo
- [x] Quitar los logs en disco de las pasarelas (`PaymentGatewayLogService`): lo que informa la pasarela queda en el pedido al recibir el webhook (16 nov 2026)
- [x] Flete por ciudad (P-03): feature `Shipping` (`ShippingCalculator`, `ShippingRateRepository`, `ShippingConfig`) con la tarifa de `tb_servientrega` y las reglas de radical en variables `SHIPPING_*`; `POST /checkout/preview` acepta `address_id` y `POST /checkout` cobra el flete de la ciudad de la dirección o responde 409 `shipping_unavailable`. Reemplaza `SHIPPING_FEE` y `SHIPPING_FREE_THRESHOLD`. Con pruebas de la fórmula, la bodega más barata, Bogotá, ciudades principales, mínimo, ciudades sin envío y el «?» de `cities` (16 nov 2026)
- [x] Corregir en `cities` los nombres con «?» en lugar de la letra con tilde o la Ñ («NARI?O», «OCA?A»), heredados del `.sql` de radical: 84 ciudades corregidas en `crisenri_premiumfitness.sql` con los nombres de `tb_servientrega` (22 palabras: NARIÑO, OCAÑA, CHACHAGÜI, BAHÍA MÁLAGA…), sin cambiar los ids (16 nov 2026)
- [x] Los reportes de ventas del admin cuentan solo ventas (23 nov 2026, pedido de Steven): `ReportsRepository::SALE` (`order_type` de compra de cliente o de entrenador) en el dashboard, ventas mensuales, diarias, por hora, por pasarela, por estado, por ciudad y departamento, productos y categorías más vendidos, clientes principales, clientes nuevos y frecuencia, rendimiento e impacto de descuentos de productos y la descarga de pedidos y de productos. Un canje tiene `amount` 0, pero sus líneas guardan en `line_total` el precio completo, que no se cobró: los reportes por producto lo contaban como venta. Con pruebas que comparan los números exactos antes y después de un canje y de una venta (`tests/E2E/Reports/SalesReportsTest.php`)
- [x] La forma de pago queda explícita en todo lo que usa el call center y en los reportes (24 nov 2026, pedido de Steven): `PaymentMethod::SQL` da «Puntos» en un canje y «Dinero (Bold)», «Dinero (Addi)» o «Dinero (Sistecrédito)» en una compra. Va como `payment_method` en la lista y el detalle de `/admin/orders`, en la descarga de pedidos y en el reporte de operaciones, y como `forma_pago`, `puntos_linea` y `puntos_pedido` en el Excel del reporte de pedidos (`/admin/reports/orders/export`). Las líneas del canje conservan su precio completo e IVA en `line_total`, `tax_base` y `tax_amount` (Steven: «entre más información se tenga mejor»); los reportes de ventas no las leen. Con pruebas
- [x] Avisar al admin en tiempo real cuando se crea un canje (`order.status.changed`, como al aprobar una compra): la lista del call center lo muestra sin recargar (24 nov 2026)
- [x] Las pruebas ya no llaman al gateway de sockets real: `tests/bootstrap.php` apunta `SOCKET_NOTIFICATION_URL` a `http://127.0.0.1:9`, que rechaza la conexión al instante, y el aviso (no crítico) se descarta. Antes, el `.env` local hacía que cada compra aprobada y cada canje de las pruebas llamara a `socket.elitenutapp.com` (24 nov 2026)
- [x] Corregir el rendimiento de productos y su descarga: la condición «APROBADA» y el rango de fechas estaban en el JOIN con el estado, no en el de las líneas, y sumaban pedidos pendientes, rechazados y de cualquier fecha (heredado de radical). Ahora suman solo líneas de ventas aprobadas del rango
- [x] Quitar las devoluciones total y parcial (P-30): el ajuste solo es «Reversar». Backend (tipos, cálculo por productos, estado DEVOLUCIÓN), `.sql` y base local, admin (formulario sin tipo ni productos, textos «Reversar») y pruebas
- [x] Retirar el cambio manual de estado de un pedido (`PATCH /api/admin/orders/{id}/status`, P-29): ruta, método, validador y su uso en el admin. Con prueba: responde 404/405 y el pedido no cambia
- [x] Anulación de Bold sobre un pedido aprobado: queda «REVERSADA» con su ajuste automático, sin agente (P-28). Con pruebas: reversión de comisión y puntos, sin repetir, sin pisar un ajuste del gerente, y un aviso tardío no cambia el estado de un pedido ya ajustado
- [x] Un estado de pasarela que no está en la tabla de traducción se guarda con su nombre (P-27), con pruebas por pasarela y la del caso «aprobada» sin pagar
- [x] Estado de un pedido o canje que cancela un gerente: «REVERSADA», distinto del «CANCELADA» de las pasarelas (P-26); reporte de canjes y admin al día, con pruebas
- [x] Decidir cómo afecta una reversión los reportes de ventas (P-31): como radical, el pedido reversado sale de las ventas del día en que se vendió. Sin cambios de código
- [x] Decidir la fecha de una venta en los reportes (P-32): como radical, la de creación del pedido (`created_at`). Sin cambios de código
- [x] Decidir qué es «ingresos» en los reportes (P-33): como radical, lo cobrado, con IVA y flete. Sin cambios de código
- [x] Dashboard del admin (P-34): rótulos corregidos en `premiumfitness-admin` (`dashboard-page.html`), sin cambios en el backend. La ficha KPI deja que un rótulo largo pase a dos líneas en vez de cortarlo. 118 pruebas del admin y build OK
- [x] Reporte de canjes con puntos (pedidos, puntos y productos canjeados), aparte de los de ventas: spec `negocio/07` (24 nov 2026)
- [ ] Ejecutar en la intranet (`crisenri_intranet`, desde phpMyAdmin) `docs/database/intranet/2026-11-16-corrige-enes-tb-servientrega.sql`: corrige «EL PEÐOL (NAR)», «EL PEÐON (SAN)» y «EL PIÐON (MAG)» a «Ñ» en `tb_servientrega` y `tb_servientrega_v2`. PremiumFitness no escribe en la intranet: lo ejecuta quien la administra. Mientras tanto, la búsqueda de la tarifa ya tolera la «Ð»
- [ ] Probar un pago real de Bold, Addi y Sistecrédito con las llaves de prueba de PremiumFitness y una URL pública para los webhooks
  > Falta: las llaves (`BOLD_*`, `ADDI_*`, `SISTECREDITO_*` del `.env`) y las URL de webhook y retorno de cada pasarela apuntando al hosting.

Resultado de la fase (15 nov 2026): 293 pruebas pasan, 62 de ellas nuevas de sesión, registro, referidos por enlace y cupón, recuperación y sesión del admin. Probado también a mano contra el servidor local, con la cookie real.

### Catálogo (spec `catalogo/01`)

- [x] `ProductCatalog`, `IntranetProductCatalog`, `CachedProductCatalog` y `VatPolicy`
- [x] `GET /products` y `GET /products/:code` con el precio según el rol
- [x] Quitar las tablas y features de productos de radical (`premiumfitness_03_catalogo_y_compras.sql`); los reportes del admin leen el catálogo de la intranet

### Entrenadores y referidos (specs `negocio/01` y `02`)

- [x] Perfil del entrenador, cupón y enlaces (el entrenador puede elegir su cupón al registrarse; si no, se genera)
- [x] Vínculo cliente → entrenador por enlace o cupón (al verificar el celular o al iniciar sesión)
- [x] Referido A → B y sus puntos pendientes (queda registrado; se activa con la primera compra, fase de compras)
- [x] Estrategias por producto: se aplican al precio, al pedido y a la comisión (su administración va en «Admin»)

### Compras (specs `negocio/01` y `catalogo/01`)

- [x] Checkout con descuento por rol, IVA por línea y valores guardados en el pedido
- [x] Aprobación del pago: comisión, puntos y activación del referido, una sola vez y en una transacción
- [x] Pedidos del usuario y su detalle
- [x] Cuenta: perfil, contraseña y direcciones
  - Las direcciones tienen endpoints, pero aún no pruebas propias (solo se usan desde las pruebas de compra).

### Puntos y comisión (specs `negocio/03`, `04` y `05`)

- [x] Lotes, saldos, consumo FIFO y vencimiento
- [x] Dashboard, puntos y referidos del entrenador
- [x] Comisión: movimientos, conversión y vista del entrenador
- [x] Cierre mensual, Excel, correo y pago con comprobante
  - El correo a financiero se probó hasta generar el mensaje; el envío real por SMTP depende de las credenciales del hosting.

### Canje, dietas y push

- [x] Canje de productos con puntos (CU-12)
  - Guarda las notas para la entrega en el pedido, como una compra (`notes?`, hasta 500 caracteres; 16 nov 2026).
- [x] Seguimiento sin «Entregado» y con `en_camino` en lugar de `enviado`, que se confundía con el `ENVIADO` de las pasarelas (P-23). Se borra `OrderRepository::countSummaryByUser`, de la cuenta minorista de radical, que contaba `ENVIADO` como despachado (16 nov 2026)
- [ ] Avisar por push cuando el pedido avanza a «Preparando tu pedido» (pedido de SAP) y «En camino» (guía)
- [x] Acceso a dietas: estado, canje y endpoint por documento (spec `integraciones/02`)
- [x] Dispositivos y outbox de push con FCM HTTP v1 (spec `integraciones/01`)
  - La cola, los reintentos y el borrado de tokens inválidos tienen pruebas con un canal simulado. El envío real a Firebase no se ha probado: falta la cuenta de servicio en `storage/private/`.
- [x] Avisos de que el acceso a dietas vence en 3 días o se desactivó (`POST /cron-job/diet-access/notify`, diario)

### Admin

- [x] Entrenadores: lista, detalle y suspensión
- [x] Estrategias por producto: crear, editar y desactivar
- [x] Ajustes por cancelación y devolución (ver P-07 y P-17)
  - Desde el 25 nov 2026 el único ajuste es «Reversar» (P-30)
- [x] Reversión con los puntos ya usados (P-17, 25 nov 2026): retira lo que falte de los otros lotes vigentes por FIFO, sin deuda. Con pruebas: 9.000 puntos con 2.000 en el lote del pedido salen 2.000 de él y 7.000 de los otros lotes, del más antiguo; sin saldo suficiente retira lo que hay y no toca lotes vencidos
- [x] Reversión de la venta que activó un referido (P-17 y S-06 de `negocio/02`): a A se le retiran los 50.000 con la regla de los puntos de la venta (lo que quede en el lote y lo ya usado de sus otros lotes por FIFO, sin deuda) y el referido vuelve a pendiente; otra venta lo activa con un lote nuevo. `point_lots.trainer_referral_id` deja de ser único (`ix_point_lots_trainer_referral`, en el `.sql` y en la base local), la clave del lote lleva el pedido (`referral:<id>:order:<pedido>`) y la lista de referidos muestra solo el lote de la activación vigente. Sin esos cambios, la segunda activación no daba puntos (el lote se descartaba en silencio) y el referido salía dos veces. Push `referral_reverted` a A. Con pruebas (sin usar, uso parcial y sin saldo suficiente), verificadas deshaciendo cada cambio
- [x] D-05 (P-35, 26 nov 2026): compra mínima `TRAINER_REFERRAL_MIN_PURCHASE` para activar los puntos del referido (spec `negocio/02`). Al reversar, los entrenadores se bloquean en orden de id, como al aprobar el pago, para que una reversión y una aprobación simultáneas no se bloqueen entre sí. `GET /trainer/referrals` envía `trainer_referral_min_purchase` y la pantalla Referidos de la app web dice «ganas 50.000 pts cuando uno de sus clientes haga una compra de $149.000 o más» (antes: «cuando su primer cliente compre»)
- [x] Auditoría de las operaciones sensibles (`audit_logs`: estado del entrenador, estrategias, pagos, correcciones, ajustes y descarga del Excel)
- [x] Tomar, liberar y procesar pedidos a nombre del agente de la sesión: `take`, `release` y `sap-order-code/by-agent` ignoran el `agent_id` del cuerpo. `agent-id` (reasignar a otro agente) lo sigue recibiendo.
- [x] Permisos del admin por cargo: entrenadores, estrategias, cierre y registrar ajustes exigen un cargo de `ADMIN_MANAGER_ROLES` (ver P-21)

### Cierre

- [x] Conectar la app web y el admin al backend local y recorrer los flujos sin la API simulada (16 nov 2026). Salieron 3 fallas del backend, ya corregidas y con prueba: la lista de pedidos del admin fallaba con un canje, la corrección de un pago no leía el formulario y el agente local no tenía un cargo válido.
- [x] Actualizar AGENTS.md y `codebase-standards.md` con lo que cambió
- [x] Script `scripts/dev-sembrar-demo.php`: entrenador demo con clientes, compras, referido, conversión, canje y un pedido sin pagar, creado con la API local (16 nov 2026)

### Reestructuración de pagos como radical (24 nov 2026)

Pedido de Steven: «el sistema de pagos está malo, vamos a reestructurarlo… te mostré cómo funciona radical». El módulo vuelve al diseño de radical (un pedido = un pago), con el prefijo en el id de pago (P-24). La lógica de negocio no se toca.

- [x] Columna `orders.payment_code` (`pf-<pedido>`, única) en el `.sql` y en la base local; se llena al crear el pedido (`PurchaseRepository::create`, `PaymentCode`), con prueba en `DerSchemaTest`
- [x] El cobro de las tres pasarelas usa `payment_code`; cada pasarela vuelve a su dirección de la app con su parámetro (`orderId` en Bold y Sistecrédito, `id_order` en Addi): `GatewayPayment::returnUrl`
- [x] Webhooks como radical: buscan el pedido por `payment_code` (comparado exacto: `PF-12` no es `pf-12`); el despachador solo guarda en el pedido (`status_id`, `payment_state_json`) y aprueba con `PurchaseApproval`. `PaymentResult` quedó idéntico al de radical
- [x] Conciliación de Bold y `scripts/dev-pagar-pedido.php` con `payment_code`
- [x] `GET /api/payments/{pf-N}` lee el pedido por `payment_code` (`UserOrderRepository::findByPaymentCode`): estado, total, fecha y número de transacción; ya no devuelve `payment_method` ni `notified_at`
- [x] Se eliminan `payment_attempts`, `PaymentAttemptRepository`, `PaymentReferenceResolver`, `POST /api/orders/{id}/pay` (`payAgain`), `reference` y `paymentMethod` de `PaymentResult`, y las sobras de radical sin uso (`/response/bold`, `PAYMENT_GATEWAY_SPA_RETURN_URL`)
- [x] Pruebas: ids distintos por pedido, id ajeno o sin prefijo no toca nada, el webhook aprueba por `payment_code`. Pasan 504 (salieron las del diseño anterior: intentos, volver a pagar, medio de pago y `/response/bold`); ninguna de reglas de negocio cambió
- [x] App web: sin «Pagar» en el detalle ni «pagar de nuevo» en el resultado; una dirección de respuesta por pasarela (`/pago/resultado/bold|addi|sistecredito`), cada una con su parámetro. 169 pruebas, lint y build pasan
  - Falta probarla en el navegador y con un pago real de cada pasarela en modo de pruebas (Steven)
- [x] Contrato (`.plan.md`), DER (v0.19) y specs `integraciones/03` y `frontend/01` al día, con la tabla «Comparación con radical» en `integraciones/03`
- [x] Log de errores de las pasarelas restaurado de radical, solo cuando falla: `GatewayPayment` escribe en `logs/payment-gateway/` si la pasarela no abre (Steven, 24 nov 2026). Con pruebas: el fallo deja una línea sin datos sensibles y un pago que abre bien no escribe nada
- [x] Servicio de Sistecrédito: queda como la integración del sitio de Nutramerican, que comparte la cuenta (Steven, 24 nov 2026)
- [x] Credenciales de Addi y Sistecrédito en el `.env` local, tomadas del de `api.megaplexstars` (Nutramerican); las URL de respuesta y de webhook son las de PremiumFitness. Las pruebas apuntan las API de las pasarelas a un puerto cerrado (`tests/bootstrap.php`) para no llamarlas nunca
