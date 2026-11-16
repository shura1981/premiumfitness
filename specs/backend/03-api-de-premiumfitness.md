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
| P-02 | La pasarela de la primera entrega es Bold. **Addi y Sistecrédito quedan deshabilitadas** (sin rutas): sus avisos de pago en radical se aceptan sin verificar quién los envía (ver «Hallazgos»). Se habilitan cuando se pueda verificar el aviso con cada proveedor. | Derivado: confirmar qué pasarelas se contratan para PremiumFitness |
| P-03 | El envío se cobra con la regla de radical, ahora en variables: `SHIPPING_FEE` (25.000) si los productos pagados no llegan a `SHIPPING_FREE_THRESHOLD` (500.000). Se guarda en `orders.shipping_amount`, una columna que el DER no tenía. No genera puntos ni comisión. | Derivado: confirmar si PremiumFitness cobra envío y cuánto |
| P-13 | El precio con descuento de cada unidad se redondea al peso (0,5 sube): el peso no tiene centavos y la pasarela cobra enteros. La base y el IVA de la línea sí llevan dos decimales. | Derivado |
| P-14 | La venta se atribuye al entrenador que tenía el cliente al crear el pedido (queda en `orders_coupons`), aunque cambie antes de pagarse. | Derivado |
| P-15 | Un pago por menos del valor del pedido no lo aprueba. Un mismo pago de la pasarela no puede aprobar dos pedidos (`orders.payment_reference` única). | Derivado |
| P-04 | El registro pide tipo y número de documento: la app de dietas consulta el acceso por documento (spec `integraciones/02`) y SAP lo necesita para facturar. El documento es único. | Derivado |
| P-05 | Las rutas de radical que PremiumFitness no usa se quitan de la API ahora; sus clases se borran en la fase que adapta cada parte. | Derivado |
| P-06 | El cupón del entrenador se genera al registrarse: letras de su nombre y un número, único. El admin no lo edita en esta entrega. | Derivado |
| P-07 | Un ajuste parcial revierte la comisión y los puntos de las líneas devueltas, en proporción a la cantidad. Un pedido admite un solo ajuste. | Derivado: PRD §7.4 |
| P-16 | El canje no cobra envío. | Derivado: confirmar |
| P-17 | En una devolución, de los puntos solo se retira lo que quede en el lote de ese pedido: lo que el entrenador ya usó no genera deuda. Tampoco se revierten los puntos por referido que esa venta activó. | Pendiente: el PRD §7.4 deja esta política por definir |
| P-18 | Un canje cancelado devuelve los puntos en un lote nuevo, con la vigencia normal (`POINTS_EXPIRATION_MONTHS`). | Derivado: el PRD §7.4 deja la vigencia por definir |
| P-19 | Un pedido pendiente se puede volver a pagar durante un día, con el valor guardado al crearlo. Después hay que hacer la compra de nuevo. Los pedidos que nunca se pagan quedan como pendientes. | Derivado |
| P-21 | Los cargos que gestionan entrenadores, estrategias, cierre y ajustes son ROOT, GERENTE COMERCIAL y ADMINISTRADOR (`ADMIN_MANAGER_ROLES`), los mismos que ya ven el dashboard y los reportes en el admin. El resto de agentes ve pedidos y reportes como en radical. | Derivado: confirmar qué cargo tiene financiero (PD-22) |
| P-22 | En el admin no se puede marcar un pedido como «APROBADA» a mano: lo aprueba el aviso de la pasarela, que es el que acredita comisión y puntos. | Derivado: confirmar si el call center recibe pagos por fuera de la pasarela |
| P-20 | La lista de festivos de `NON_BUSINESS_DAYS` la carga quien despliega y se actualiza cada año; el cierre avisa (`holidays_missing_<año>`) si faltan los del año en que se paga. | Derivado |
| P-08 | Proveedor de SMS: el de radical (Relax Global), con sus variables. | Pendiente (D-10) |
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

En PremiumFitness todo quedó corregido y con pruebas (criterios 2 y 3; P-02 y P-15). **`backend-radical-nutrition` tiene los puntos 1 a 3 en producción.** No se toca desde este repo.

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
- [x] Acceso a dietas: estado, canje y endpoint por documento (spec `integraciones/02`)
- [x] Dispositivos y outbox de push con FCM HTTP v1 (spec `integraciones/01`)
  - La cola, los reintentos y el borrado de tokens inválidos tienen pruebas con un canal simulado. El envío real a Firebase no se ha probado: falta la cuenta de servicio en `storage/private/`.
- [x] Avisos de que el acceso a dietas vence en 3 días o se desactivó (`POST /cron-job/diet-access/notify`, diario)

### Admin

- [x] Entrenadores: lista, detalle y suspensión
- [x] Estrategias por producto: crear, editar y desactivar
- [x] Ajustes por cancelación y devolución (ver P-07 y P-17)
- [x] Auditoría de las operaciones sensibles (`audit_logs`: estado del entrenador, estrategias, pagos, correcciones, ajustes y descarga del Excel)
- [x] Tomar, liberar y procesar pedidos a nombre del agente de la sesión: `take`, `release` y `sap-order-code/by-agent` ignoran el `agent_id` del cuerpo. `agent-id` (reasignar a otro agente) lo sigue recibiendo.
- [x] Permisos del admin por cargo: entrenadores, estrategias, cierre y registrar ajustes exigen un cargo de `ADMIN_MANAGER_ROLES` (ver P-21)

### Cierre

- [x] Conectar la app web y el admin al backend local y recorrer los flujos sin la API simulada (16 nov 2026). Salieron 3 fallas del backend, ya corregidas y con prueba: la lista de pedidos del admin fallaba con un canje, la corrección de un pago no leía el formulario y el agente local no tenía un cargo válido.
- [x] Actualizar AGENTS.md y `codebase-standards.md` con lo que cambió
- [x] Script `scripts/dev-sembrar-demo.php`: entrenador demo con clientes, compras, referido, conversión, canje y un pedido sin pagar, creado con la API local (16 nov 2026)
