# PremiumFitness — Documento de requisitos del producto (PRD)

**Versión:** 2.0 · Consolidación de requisitos y planificación
**Fecha:** 2 de noviembre de 2026
**Fuentes:** documentos de negocio y desarrollo indicados en §1.
**Producto:** aplicación para entrenadores, clientes y administración de PremiumFitness.

## 1. Resumen del producto

PremiumFitness conecta entrenadores y clientes mediante compras y referidos. Cada entrenador dispone de dos QR: uno para clientes y otro para invitar entrenadores. El cliente se registra desde el QR, conserva el ID del referente en una cookie y compra con 10% de descuento. Cuando la compra queda aprobada, el entrenador recibe 5% en puntos, calculados con IVA incluido, y 15% de comisión, calculada sin IVA.

El entrenador compra con 25% de descuento o canjea puntos por productos a precio completo, sin pagos mixtos. Cada acumulación vence a los seis meses y se consume por FIFO. Al referir a B, el entrenador A obtiene 50.000 puntos inactivos que se activan con una compra de un cliente de B.

La app de dietas se desarrolla después del MVP comercial, como SaaS para múltiples entrenadores: primer mes gratis y continuidad por meta de ventas o canje de puntos, con validación diaria.

### Fuentes y alcance de esta actualización

- [Requerimientos](requerimientos.md): funciones y beneficios del programa.
- [Reglas de negocio](reglas-de-negocio.md): las 11 reglas de cálculo, vigencia, compra, canje y dietas.
- [Actores y casos de uso](casos-de-uso-y-actores.md): CU-01 a CU-15 y alternativas de los flujos.
- [Fases de desarrollo](fases-de-desarrollo.md): repositorios de referencia, arquitectura y secuencia de implementación.
- [Diagrama](img/diagrama-flujo.jpg): apoyo visual, sin añadir beneficios no respaldados por los textos.

Se incorporan las precisiones de reglas-de-negocio.md que todavía aparecen pendientes en casos-de-uso-y-actores.md: base del 5% (con IVA incluido desde el 9 nov 2026), monto de 50.000, venta de clientes de B, seis meses, FIFO, precio completo y prohibición de pago mixto. La comisión corresponde al entrenador según el flujo explícito de aprobación; la mención aislada al cliente en requerimientos.md es una inconsistencia de redacción.

**Clasificación:** las reglas comerciales se presentan como confirmadas cuando las fuentes las establecen. La persistencia, permisos, idempotencia, pantallas y controles son requisitos técnicos derivados para implementarlas. Las propuestas comerciales y parámetros no definidos se identifican expresamente; no se asumen aprobados.

## 2. Problema y oportunidad

El modelo necesita una forma verificable de relacionar cada cliente y compra con su entrenador, aplicar beneficios de forma consistente y mostrar cómo se generan y consumen las recompensas.

PremiumFitness debe permitir:

- Al entrenador: acceder a herramientas, referir clientes y consultar sus comisiones y puntos.
- Al cliente: vincularse con su entrenador, comprar y comprobar el descuento recibido.
- A la operación: administrar pedidos, canjes y reglas; resolver incidencias; y auditar cada recompensa.

**Hipótesis por validar:** los beneficios aumentarán la participación de los entrenadores, la adquisición de clientes y la recompra. No se dispone todavía de una línea base ni de evidencia de impacto.

## 3. Objetivos y medición

| Objetivo | Indicador | Definición |
| --- | --- | --- |
| Activar entrenadores | Activación a siete días | Entrenadores que completan registro y comparten al menos uno de sus QR dentro de siete días / registros de entrenadores de la cohorte. |
| Convertir referidos | Conversión de referido a primera compra | Clientes vinculados que realizan una primera compra pagada dentro de 30 días / clientes vinculados de la cohorte. |
| Impulsar ventas | Ventas atribuidas | Valor de compras pagadas atribuidas a entrenadores, menos devoluciones; excluir pedidos de prueba. |
| Promover recompra | Recompra a 60 días | Clientes con una segunda compra pagada dentro de 60 días de la primera / clientes de la cohorte con ventana completa. |
| Dar confianza en los beneficios | Exactitud del registro | Operaciones auditadas sin diferencias entre pedido, descuento, comisión y puntos / operaciones auditadas. |
| Favorecer uso de puntos | Uso antes del vencimiento | Puntos canjeados / puntos emitidos de los mismos lotes, medidos al cierre de su vigencia. |

**Propuesta de lanzamiento:** medir una cohorte piloto durante ocho semanas, mantener seguimiento posterior para completar las ventanas de recompra y vencimiento, y fijar metas comerciales después de conocer la línea base. Los controles de aceptación financiera requieren cero recompensas duplicadas y trazabilidad de todos los movimientos.

**Definición de cohorte:** grupo de usuarios que cumple el mismo evento de inclusión durante un período de medición definido y al que se hace seguimiento desde ese evento. Para activación, la cohorte está formada por entrenadores cuyo registro se completa en el período; para conversión, por clientes cuya vinculación con un entrenador se confirma en el período; y para recompra, por clientes cuya primera compra pagada ocurre en el período. Las ventanas de 7, 30 y 60 días se cuentan individualmente desde el evento de inclusión de cada usuario. El piloto fijará y documentará antes de iniciar sus fechas de inclusión, los roles invitados y el tamaño de cada cohorte.

## 4. Usuarios y permisos

| Actor | Funciones | Visibilidad propuesta |
| --- | --- | --- |
| Entrenador | Registro, perfil, ambos QR, referidos, comisiones, puntos, conversión, compra con 25% y canje. Dietas en etapa posterior. | Sus clientes atribuidos con datos mínimos, sus pedidos y sus movimientos. |
| Cliente | Registro por QR, compra con 10% y consulta de pedidos. | Su perfil, referente, direcciones y pedidos. |
| Operación/administración — rol técnico propuesto | Gestionar los datos necesarios para operar compras y canjes; consultar pagos, incidencias, movimientos y reportes. | Acceso restringido por función, con auditoría de ajustes. |

A y B son participantes del flujo de referido, no roles de permisos distintos. El administrador no se incorpora como actor comercial confirmado: el acceso operativo se propone para cubrir reportes y aprovechar la administración del backend de referencia.

El entrenador no podrá consultar pagos privados, credenciales o saldos de otros usuarios. La coexistencia de cliente y entrenador en una misma cuenta se precisa durante el diseño de identidad. Los datos nutricionales se gestionarán con permisos separados en la fase de dietas.

## 5. Alcance del MVP

### Incluido en la primera entrega comercial

1. Registro, acceso, recuperación de cuenta y perfiles de entrenador/cliente; evaluar reutilización del flujo SMS existente.
2. Dos QR por entrenador y vínculo equivalente para compartir, con destinos distintos.
3. Cookie del referente en el recorrido del cliente y atribución persistida en sus pedidos.
4. Catálogo, detalle, carrito, direcciones, compra, pago y consulta/seguimiento de pedidos.
5. Descuentos de 10% para el cliente referido y 25% para compras del entrenador con dinero.
6. Aprobación verificable y registro separado de comisiones y puntos por compras de clientes.
7. Referido A → B y activación de los 50.000 puntos de A.
8. Saldos e historial por lote, vigencia de seis meses, consumo FIFO y remanentes.
9. Conversión del total de comisión a puntos (comisión + 50% de los puntos vigentes, con tope en la comisión) y liquidación mensual en dinero de la comisión no convertida.
10. Canje de productos a precio completo y sin pagos mixtos, con registro de IVA.
11. Acceso operativo mínimo, ajustes trazables y reportes de ventas/IVA, comisiones y puntos por entrenador.

### Etapa posterior: dietas

App basada en el proyecto Flutter existente, evaluando adaptación a Angular; backend SaaS para múltiples entrenadores, primer mes gratis, meta mensual, canje de puntos y validación diaria de acceso. Se conserva como parte del producto, con entrega posterior explícita en fases-de-desarrollo.md.

### Fuera del alcance confirmado

- Bono de bienvenida de 30.000 puntos, seminarios y materiales: aparecen en el PRD anterior, pero no están respaldados por los requisitos actuales.
- Otros beneficios hasta definirlos, referidos multinivel, transferencias de puntos y canje de puntos por efectivo.
- Liquidación bancaria automática de comisiones; registrar una comisión no equivale a ofrecer un retiro.
- Chat, reservas, dispositivos deportivos y funciones nutricionales adicionales no especificadas.

Los avisos externos, exportaciones avanzadas y automatización de soporte son mejoras propuestas, no condiciones nuevas para el MVP. El [documento de MVP](MVP-PremiumFitness.md) organiza entregas y escenarios de aceptación.

## 6. Recorridos principales

### 6.1. Registro y acceso del entrenador — CU-01

1. El entrenador abre la app y completa su registro y verificación de contacto según el mecanismo seleccionado.
2. Accede a su perfil, resumen de comisiones/puntos y los dos QR.
3. Puede compartir la invitación para clientes o para entrenadores.

El registro no otorga un bono de bienvenida. Si proviene del QR de A, se conserva la relación y quedan 50.000 puntos pendientes para A, como se describe en 6.4.

### 6.2. Registro del cliente y atribución — CU-02 y CU-03

1. El cliente escanea el QR específico y la app identifica al entrenador.
2. Completa el registro y se guarda el ID del entrenador en cookie.
3. Se propone mostrar al referente y persistir la relación para atribuir la compra y conservar su historial.
4. El pedido guarda el entrenador atribuido; un cambio posterior no altera pedidos anteriores.

**Alternativas:** QR inválido, cookie bloqueada/eliminada, registro interrumpido, cliente existente y varios QR requieren mensajes y la política de atribución de §14. No se asigna un referente arbitrario. La asociación permanente entre dispositivos es una decisión técnica/comercial pendiente, no una propiedad garantizada de la cookie.

### 6.3. Compra del cliente y recompensas — CU-04 y CU-05

1. El cliente selecciona productos, dirección y método de pago.
2. El servidor valida precios, disponibilidad y referencia; muestra 10% de descuento, IVA y total.
3. Se crea el pedido con su atribución y desglose de cálculo.
4. Se verifica el resultado de pago y la compra pasa a aprobada.
5. Se acredita al entrenador 5% en puntos sobre el valor con IVA y 15% de comisión sobre la base sin IVA.
6. Se conserva el vínculo entre pedido y movimientos y se actualizan los saldos.

Un pedido pendiente o rechazado no genera recompensas. La aprobación repetida no vuelve a acreditarlas. La entrega de productos no añade un período de retención de beneficios: el disparador confirmado es la compra aprobada.

### 6.4. Referido de entrenador — CU-06, CU-07 y CU-08

1. A comparte su QR para entrenadores; B completa el registro desde esa invitación.
2. Se registra A → B con 50.000 puntos pendientes para A (`TRAINER_REFERRAL_POINTS`), separados del saldo utilizable. Los puntos pendientes no caducan.
3. B comparte su QR de clientes; uno de sus clientes compra.
4. Al aprobarse esa compra, se activan los 50.000 puntos de A y se acreditan a B los beneficios habituales de su venta.
5. Se guarda el pedido que activó los puntos para no volver a activarlos. Si ese pedido se cancela o devuelve y A no ha usado los puntos, se retiran y vuelven a quedar pendientes; si los usó, los conserva.

Se usa aprobación por continuidad con el primer flujo de compra. La activación única por relación A → B es el control derivado para no duplicar los 50.000 puntos; nuevas ventas de los clientes de B no crean otro lote. La compra propia de B no los activa y no hay monto mínimo (confirmado el 9 nov 2026). No se añade comisión de la venta a A ni cadena multinivel.

### 6.5. Consulta y conversión — CU-09, CU-10 y CU-11

1. El entrenador consulta su comisión sin liquidar, sus puntos e historial con sus orígenes y vencimientos.
2. Solicita convertir el **total de comisión disponible**, sin entrada de importe parcial.
3. Revisa la comisión a consumir, sus puntos vigentes, el adicional (mín(50% × puntos vigentes, comisión)), los puntos resultantes y el saldo posterior. Un aviso le indica que esa comisión ya no se le pagará en dinero.
4. Confirma; el sistema consume la comisión y acredita un lote en una transacción indivisible.
5. Consulta el comprobante y su vencimiento.

Si el saldo cambia antes de confirmar, se actualiza la vista previa. Una misma comisión nunca se convierte dos veces. Ejemplo: 100.000 puntos vigentes y 100.000 de comisión → recibe 150.000 y queda con 250.000. Lo no convertido se liquida al corte del último día del mes y se paga en dinero (CU-21); no hay canje mixto.

### 6.6. Compra propia y canje — CU-12 y CU-13

- **Compra con dinero:** seleccionar productos del portafolio, aplicar 25%, mostrar IVA y total, pagar y consultar el pedido. No se añaden recompensas de cliente a una compra propia sin regla que las establezca.
- **Canje:** seleccionar productos, mostrar precio completo en puntos, validar saldo vigente, consumir lotes por FIFO, guardar remanentes y emitir el registro de canje con IVA y estado de entrega.

No se aplica el 25% al canje ni se combina dinero con puntos. Con saldo insuficiente no se confirma. Un Bipro Classic de 249.900 requiere 249.900 puntos.

### 6.7. Dietas — CU-14 y CU-15, entrega posterior

1. Aplicar el primer mes gratis según la fecha inicial que se defina.
2. Para períodos siguientes, verificar meta mensual de ventas; si cumple, habilitar dietas.
3. Si no cumple, permitir canje del costo del período en puntos.
4. Validar diariamente la condición mensual y el acceso cubierto por un canje, sin repetir débitos del mismo período.
5. Si no cumple ni canjea, desactivar únicamente dietas; mostrar motivo y condición de recuperación.

Meta, costo, corte mensual y reactivación deben precisarse antes de esa fase. Una falla técnica de evaluación no debe tratarse automáticamente como incumplimiento.

## 7. Reglas de negocio

### 7.1. Reglas consolidadas y trazabilidad

La columna de fuente remite a los numerales de [reglas-de-negocio.md](reglas-de-negocio.md), sin renumerar los RN del documento de casos de uso.

| Regla | Comportamiento | Fuente |
| --- | --- | --- |
| Dos QR y cookie | Invitaciones separadas; ID del entrenador en cookie del cliente. | Requerimientos; CU-02, CU-03, CU-06. |
| Descuento cliente | 10% en la compra del flujo referido. | Requerimientos; CU-04. |
| Recompensas | Compra aprobada: 5% en puntos con IVA incluido y 15% de comisión sin IVA para el entrenador, con porcentajes configurables por producto. | Requerimientos; reglas 1, 7 y 15. |
| Equivalencia | Un peso equivale a un punto. | Requerimientos; regla 1. |
| Vigencia | Seis meses por acumulación. | Regla 2. |
| Compra entrenador | 25% en todo el portafolio comprado con dinero por la app. | Requerimientos; regla 3. |
| Canje | Precio completo, exclusivo en app, sin descuento, sin pago mixto y sin efectivo. | Requerimientos; reglas 3, 4 y 5. |
| Referido A → B | 50.000 inactivos para A; activación con compra de cliente de B. | Regla 6. |
| Consumo | FIFO, estados usado/caducado y remanentes de lotes parcialmente consumidos. | Regla 9. |
| IVA de canjes | Discriminar y persistir IVA de productos redimidos. | Regla 10. |
| Conversión | Total de comisión a puntos: comisión + mín(50% × puntos vigentes, comisión). | Requerimientos; decisión del 9 nov 2026; CU-11. |
| Dietas | Mes inicial gratis; acceso por meta o puntos, evaluación diaria de la condición mensual. | Requerimientos; reglas 8 y 11. |
| Reportes | IVA de ventas, comisiones y puntos acumulados/redimidos por entrenador. | Requerimientos. |

### 7.2. Cálculos y ejemplos

Para cada producto, conservar cantidad, precio, descuento, tasa de IVA, base e IVA. Si un importe incluye IVA, `base_sin_IVA = importe / (1 + tasa_IVA)`; `IVA = importe − base_sin_IVA`. La tasa pertenece al producto, no se fija una tasa única para el pedido.

- Puntos de venta: los puntos del pedido = suma de lo pagado por los productos (con descuento y con IVA, sin el envío) × porcentaje de puntos (5% por defecto), redondeado una sola vez al entero más cercano (0,5 sube); 1 peso = 1 punto. *(Definido el 9 nov 2026.)*
- Comisión: porcentaje de comisión de cada producto (15% por defecto) sobre su base sin IVA.
- Compra propia: descuento de 25% sobre los productos del portafolio.
- Canje: puntos requeridos equivalentes al precio completo del producto, sin descuento.

**Ejemplo aislado del porcentaje:** una compra pagada de 119.000 con IVA, ya con el descuento aplicado (100.000 de base sin IVA al 19%), genera 5.950 puntos y 15.000 de comisión. La base de los puntos es lo que pagó el cliente (9 nov 2026), no el precio antes del descuento del cliente.

**Ejemplo de canje:** con 300.000 puntos, canjear un producto de 249.900 consume 249.900 y deja 50.100, si todo el saldo está disponible y vigente.

**Ejemplo FIFO:** lotes disponibles de 100.000 y 200.000, en ese orden de acumulación: el canje anterior agota el primero, consume 149.900 del segundo y conserva 50.100. No se marca todo el segundo lote como usado.

El ejemplo de requerimientos que asigna 12.500 puntos a un precio de 249.900 usa el precio completo. Desde el 9 nov 2026 los puntos se calculan sobre lo pagado: con 10% de descuento, 224.910 × 5% = 11.245,5 → 11.246 puntos. Tampoco se adopta automáticamente `comisión × 1,5`: el texto del 50% mezcla comisión con puntos acumulados.

### 7.3. Integridad, disponibilidad y vencimiento

- Cada compra, referido activado, conversión, canje y ajuste conserva una clave de origen única. Repetir una solicitud no crea otro beneficio.
- Cada acumulación genera un lote con cantidad inicial, remanente y fecha de caducidad. Los 50.000 puntos por referido no son un lote mientras están pendientes: el lote se crea al activarse.
- El saldo canjeable suma remanentes activados y no caducados; excluye puntos usados, vencidos e inactivos aunque una tarea programada se retrase.
- FIFO usa la fecha de acumulación de los lotes disponibles, con desempate estable por identificador; no se reemplaza por ordenar únicamente por vencimiento.
- Un lote parcialmente consumido mantiene su fecha de caducidad original. Un consumo no renueva su vigencia.
- Las conversiones crean un nuevo lote con seis meses de vigencia. Los 50.000 puntos por referido vencen a los seis meses de activarse; mientras están pendientes no caducan.
- Se propone computar meses calendario y resolver fin de mes según la zona horaria acordada. Es una precisión técnica pendiente, no un cambio al plazo de seis meses.
- Débitos y créditos se ejecutan con transacciones; el servidor verifica saldo, precio, permisos y estado al confirmar.
- Los cambios de reglas aplican a operaciones futuras; el historial conserva la versión del cálculo original.

### 7.4. Cancelaciones, devoluciones y ajustes — propuesta operativa

Las fuentes no fijan la política completa de devoluciones. Se conserva esta propuesta del PRD para cerrarla antes del piloto:

- Compra rechazada o cancelada antes de aprobarse: sin recompensas.
- Devolución total: reversión de beneficios del pedido; parcial: recálculo sobre los importes conservados y ajuste de la diferencia, sin exceder el beneficio original.
- Devolución de la venta que activó un referido: si A no ha usado los 50.000 puntos, se retiran (o su remanente) y vuelven a quedar pendientes; si los usó, los conserva.
- Si puntos/comisiones ya se consumieron, definir compensación o deuda de ajuste antes de aplicarla; no imponer un bloqueo sin política acordada.
- Canje no entregable: restitución trazable con vigencia por definir; no renovar ni perder puntos silenciosamente.
- Los ajustes registran actor, motivo y operación original. No se borra el historial ni se descuenta dos veces un mismo saldo por reintentos.

## 8. Requisitos funcionales y aceptación

**MVP:** obligatorio para la entrega comercial completa. **Posterior:** fase de dietas. **Mejora:** propuesta fuera del mínimo. Los criterios operativos son derivados de las reglas; las políticas señaladas en §14 deben resolverse antes de activar su función.

| ID | Entrega | Requisito | Criterio de aceptación |
| --- | --- | --- | --- |
| RF-01 | MVP | Registro y acceso | Entrenador/cliente se registran, verifican contacto, recuperan acceso y solo consultan recursos autorizados. |
| RF-02 | MVP | Perfil | El usuario consulta/modifica datos permitidos; conserva identidad e historial. |
| RF-03 | MVP | Dos QR | El QR de clientes y el de entrenadores identifican al referente y abren destinos distintos. |
| RF-04 | MVP | Atribución | Se guarda cookie del entrenador en el recorrido cliente y se conserva atribución del pedido; reintentos no duplican relaciones. |
| RF-05 | MVP | Catálogo y carrito | Precio, disponibilidad y desglose se validan en servidor antes de comprar. |
| RF-06 | MVP | Descuento cliente | La compra elegible muestra 10%, IVA y total antes de confirmar y en el pedido. |
| RF-07 | MVP | Pago | Aprobación verificada; resultado persistido; un retorno del navegador no basta y los duplicados no generan beneficios extra. |
| RF-08 | MVP | Pedidos | Cada comprador consulta estado, productos, dirección y seguimiento según el alcance logístico. |
| RF-09 | MVP | Registro de referido | Registrar B desde A crea relación y 50.000 puntos pendientes para A, sin bono de bienvenida para B. |
| RF-10 | MVP | Recompensas | Compra aprobada de cliente genera 5% en puntos (con IVA incluido) y 15% de comisión (sin IVA) una sola vez, con origen trazable. |
| RF-11 | MVP | Saldos e historial | El entrenador ve siempre sus puntos por compras de clientes (acumulado histórico y vigentes), sus puntos por entrenadores referidos (vigentes, pendientes y detalle por B) y su comisión convertible; muestra remanentes y vencimientos. Las consultas se resuelven con índices que las cubren (spec `negocio/03`). |
| RF-12 | MVP | Conversión total | Consume todo el saldo de comisión y acredita comisión + mín(50% × puntos vigentes, comisión) una sola vez; si falla, no deja saldos a medias. |
| RF-13 | MVP | Caducidad | Cada lote vence a seis meses según el corte definido; no queda canjeable ni vuelve a descontarse. |
| RF-14 | MVP | Canje | Precio completo, sin pago mixto, FIFO y remanentes; concurrencia no provoca sobregiro; IVA persistido. |
| RF-15 | Posterior | Dietas | Mes gratis, meta o canje, validación diaria y desactivación limitada a dietas; sin cobro repetido del mismo período. |
| RF-16 | MVP | Operación mínima | Acceso autorizado para gestionar/consultar datos necesarios de catálogo, pedidos, canjes y movimientos; ajustes auditados. |
| RF-17 | MVP | Ajustes | Cancelaciones/devoluciones generan ajustes según la política acordada, ligados al original y sin duplicados. |
| RF-18 | Mejora | Avisos externos | Si se incorporan, los fallos de envío no revierten compras, conversiones ni canjes. |
| RF-19 | MVP | Reportes | Por período y entrenador: ventas/IVA, IVA de canjes, comisiones y puntos emitidos, redimidos, vencidos y disponibles conciliados. Excel mensual generado en el cierre (CU-21), con hoja de cuadre y descarga solo con sesión de administrador (spec `negocio/05`). |
| RF-20 | MVP | Activar puntos por referido | Primera compra aprobada de un cliente de B activa una sola vez los 50.000 puntos de A, sin monto mínimo. La compra propia de B no cuenta. B recibe sus recompensas normales. |
| RF-21 | Mejora | Aviso de vencimiento | Avisos por lote y canal sin duplicados. Las fechas visibles sí forman parte del MVP. |
| RF-22 | MVP | Liquidación mensual | Al corte del último día del mes se liquida la comisión no convertida y el Excel se envía a financiero; ese Excel es lo que se paga, por fuera de la app, y los comprobantes de consignación son la evidencia. La transferencia bancaria automática queda fuera. |
| RF-23 | MVP | Compra entrenador | 25% en compra con dinero sobre todo el portafolio; no se aplica en canjes ni se mezcla dinero/puntos. |

Se conservan los identificadores del PRD anterior para facilitar revisión: RF-09 pasa de bienvenida a registro del referido; RF-15 corresponde a dietas posterior; RF-20 entra al MVP; RF-23 explicita la compra propia.

## 9. Pantallas, plataforma y reutilización

| Área | Pantallas mínimas | Información clave |
| --- | --- | --- |
| Acceso | Registro, verificación, inicio y recuperación | Rol, contacto, referente cuando corresponda y errores. |
| Entrenador | Inicio y perfil | Comisiones, puntos por estado, vencimientos y accesos a QR/compra/canje. |
| Referidos | QR clientes, QR entrenadores, relaciones | Referente, invitados y estado de los 50.000 puntos de cada B (pendientes o activados). |
| Recompensas | Historial, detalle de lote, comisiones y conversión | Origen, base sin IVA, importe/puntos, remanente, fecha y vista previa. |
| Compra/canje | Catálogo, producto, carrito, confirmación y pedidos | Descuento aplicable o precio completo en puntos, IVA, saldo y entrega. |
| Cliente | Registro por QR, perfil, catálogo y pedidos | Entrenador, 10%, dirección, pago y seguimiento. |
| Operación | Catálogo, pedidos, pagos, canjes, movimientos y reportes | Búsqueda por operación, período y entrenador; auditoría. |
| Dietas — posterior | Acceso, estado de suscripción y recuperación | Mes gratis, meta, puntos necesarios, vigencia y motivo de desactivación. |

**Stack definido:** backend Node.js con Express y frontend Angular, dividido para entrenadores y clientes. Motor de base de datos, despliegue y estructura de aplicaciones se seleccionan al revisar reutilización.

**Referencias:** backend-radical-nutrition para datos, SMS, pagos, administración y envíos; join.nutramerican.com para onboarding, comisiones e instalación PWA por dispositivo; gifts.nutramerican.com para catálogo y compra. Las rutas y tareas completas permanecen en [fases de desarrollo](fases-de-desarrollo.md).

**Dietas:** base Flutter en dietas_automaticas_flutter, backend SaaS por construir y evaluación de Angular como candidato de adaptación. No se decide una migración de interfaz antes de revisar los proyectos.

## 10. Datos y estados del dominio

### Entidades mínimas propuestas

| Entidad | Datos y responsabilidad |
| --- | --- |
| Usuario, perfil y dirección | Identidad, rol, contacto, estado, datos de entrega y permisos. |
| Referencia cliente–entrenador | Entrenador, cliente, origen/QR, fechas y política de asociación. |
| Referido A–B | Participantes, registro, 50.000 puntos pendientes o activados y pedido que los activó. |
| Producto y línea de pedido | Cantidad, precio completo, descuento, tasa/base/importe de IVA y total de la operación. |
| Pedido, pago y envío | Comprador, atribución, dirección, estado, referencia externa, respuesta verificada y seguimiento. |
| Movimiento de comisión | Pedido origen, base, porcentaje, importe, saldo convertible, conversión y ajuste relacionado. |
| Lote de puntos | Origen, cantidad inicial, remanente, acumulación, activación, caducidad y estado. |
| Movimiento y consumo | Abono/débito/ajuste, operación, lote afectado y cantidad; permite reconstruir el saldo. |
| Conversión | Comisión consumida, puntos vigentes base, porcentaje, adicional, puntos generados y lote destino. |
| Canje y sus líneas | Productos a precio completo, IVA, puntos, lotes consumidos y estado de entrega/restitución. |
| Auditoría y reglas | Actor, fecha, motivo, parámetros y versión aplicados. |
| Suscripción de dietas — posterior | Entrenador, período, mes gratis, meta, canje que cubre acceso y evaluación diaria. |

### Estados y consistencia

- **Cuenta — propuesta:** pendiente de verificación, activa o suspendida; no confundirla con la disponibilidad de dietas.
- **Pago:** pendiente, aprobado, rechazado o cancelado; devoluciones separadas según la política acordada.
- **Pedido — propuesta:** creado, pendiente de pago, aprobado, en preparación, enviado y entregado; cancelaciones/devoluciones conservan historial.
- **Comisión:** disponible tras compra aprobada; se consume por conversión, por ajuste o por la liquidación mensual, que se paga en dinero.
- **Lote:** disponible, usado o caducado. Un lote parcial conserva remanente; el estado no sustituye las cantidades.
- **Canje — propuesta:** solicitado, confirmado, en entrega, entregado o cancelado con restitución trazable.
- **Dietas — posterior:** prueba gratuita, acceso por meta, acceso por puntos o desactivado.

Pago, pedido, lote y acceso a dietas son estados distintos. Una devolución no borra la aprobación histórica; un ajuste no sobrescribe el movimiento original.

## 11. Requisitos no funcionales

Los siguientes objetivos son propuestas para dimensionar y verificar el piloto:

| Área | Requisito verificable |
| --- | --- |
| Seguridad | Tráfico cifrado, contraseñas protegidas mediante hash adecuado, permisos verificados en servidor, límites de intentos y autenticación reforzada para administradores. |
| Integridad | Cálculos monetarios sin errores de coma flotante; operaciones de saldo atómicas; eventos idempotentes; ninguna compra o canje depende de importes enviados sin validar por el cliente. |
| Privacidad | Recoger únicamente los datos necesarios. Definir conservación, cierre de cuenta y acceso por rol antes de producción; evitar datos personales en QR, telemetría y registros de error. |
| Pagos | Utilizar los componentes del proveedor seleccionado; no almacenar datos completos de tarjetas. Validar autenticidad, importe, moneda y referencia de cada confirmación. |
| Rendimiento | En el entorno acordado del piloto, tiempo de respuesta p95 de hasta 800 ms para consultas internas y 1,5 s para operaciones internas; medir por separado las dependencias externas. |
| Experiencia móvil | En una prueba móvil reproducible, contenido principal visible en hasta tres segundos. Formularios, QR, compra y canje funcionan desde 360 px de ancho sin desplazamiento horizontal. |
| Accesibilidad | Flujos principales utilizables con teclado, etiquetas comprensibles, foco visible, contraste suficiente y errores que no dependan solo del color. Verificar con revisión manual y herramientas automáticas. |
| Disponibilidad | Objetivo mensual propuesto de 99,5 %; monitorizar acceso, compra, confirmación de pago y consulta de saldos. |
| Recuperación | Copias verificadas; objetivo propuesto de pérdida máxima de datos de una hora y restauración en ocho horas. Ensayar la restauración antes del piloto. |
| Observabilidad | Identificadores de correlación por pedido y operación; alertas ante fallos de conciliación, acumulación de pagos pendientes o movimientos duplicados. |
| Capacidad | Volumen de usuarios y concurrencia pendiente. Acordarlo antes de aprobar las pruebas de carga y el costo de infraestructura. |

## 12. Instrumentación y operación

Registrar eventos de registro de entrenador, apertura de QR, vinculación de cliente, registro de B, inicio de compra, aprobación verificada, recompensa acreditada, puntos por referido activados, conversión, canje, vencimiento y ajuste. Dietas incorporará evaluación y cambio de acceso en su etapa.

Cada evento transaccional conserva fecha, actor, operación de origen y versión de regla. La información analítica no modifica los saldos; pedidos y movimientos son la fuente de verdad.

Conciliar pagos, pedidos, comisiones y puntos. Si una compra está aprobada y falla la acreditación, registrar el procesamiento pendiente y reintentarlo sin duplicar beneficios; no revertir el pago por un fallo de visualización.

Los reportes distinguen acumulado histórico y saldo actual, puntos por referido pendientes/activados, puntos usados/caducados, comisión generada/convertida/ajustada e IVA de ventas/canjes. Deben permitir llegar al pedido o canje que explica cada cifra. La operación define responsables de entrega, incidencias y conciliación antes del piloto.

## 13. Casos límite que deben resolverse

Los controles técnicos siguientes se verifican en el MVP. El cambio de entrenador, compras sin referido y compensaciones requieren la política indicada en §14; su tratamiento en esta tabla es una propuesta operativa.

| Caso | Comportamiento esperado |
| --- | --- |
| El cliente abandona el registro tras escanear | Conserva la referencia durante el plazo configurado y vuelve a confirmar si ya no es válida. |
| El cliente ya está vinculado a otro entrenador | Se informa la relación actual y se ofrece soporte; no se reemplaza silenciosamente. |
| QR inválido o entrenador suspendido | No se crea la relación; se muestra un mensaje y una salida hacia soporte. |
| Cliente sin entrenador | Propuesta: puede consultar catálogo, pero debe vincularse antes de comprar bajo este programa. Las compras sin referido requieren una decisión comercial aparte. |
| El usuario cierra el navegador después de pagar | El pago se procesa por confirmación del proveedor y el pedido aparece al volver. |
| Eventos de pago duplicados o fuera de orden | Se verifica el estado autorizado, se conserva auditoría y no se retrocede ni se acredita dos veces. |
| El precio o las existencias cambian durante el checkout | El servidor actualiza el resumen y exige revisar el nuevo total antes de iniciar un pago nuevo. |
| Dos conversiones o canjes simultáneos | Solo se confirman operaciones cubiertas por saldo; nunca se produce sobregiro por concurrencia. |
| Un lote vence durante el canje | La disponibilidad se valida en el momento de confirmar la transacción. |
| Devolución posterior al consumo de recompensas | Se aplica la política aprobada de ajuste y conciliación, conservando todos los movimientos. |
| Cambio de reglas durante una compra | La operación utiliza su versión de reglas; una cotización vencida exige revisión antes del pago. |
| Proveedor de SMS/pagos o integración externa caído | Se ofrece reintento o estado de indisponibilidad sin perder el saldo ni alterar compras confirmadas. |

## 14. Precisiones y dependencias pendientes

Estas decisiones completan las reglas; no dejan en duda los porcentajes, seis meses, FIFO, precio de canje ni quién debe generar la venta del referido.

| ID | Precisión | Responsable propuesto | Momento/impacto |
| --- | --- | --- | --- |
| D-01 | Moneda técnica, zona horaria y corte de períodos. | Negocio y tecnología | Arquitectura y cálculo temporal. |
| D-02 | Catálogo, inventario, envío, entrega y cancelación de canjes. | Operación | Antes de checkout/canje real. |
| D-03 | Resuelta el 9 nov 2026. **Puntos:** 5% de lo pagado por los productos (con descuento e IVA, sin envío), por pedido y redondeado al entero más cercano. **Comisión:** porcentaje vigente del producto sobre lo pagado con descuento, sin IVA y sin envío, por línea con 2 decimales; el total liquidado se redondea al peso. Es la misma base que usa hoy api-comisiones. | Negocio y finanzas | — |
| D-04 | Resuelta el 9 nov 2026: puntos recibidos = comisión + mín(50% × puntos vigentes, comisión). | Negocio y finanzas | — |
| D-05 | Registro, verificación, coexistencia de roles y tratamiento de autorreferidos/cuentas duplicadas. | Producto y tecnología | Acceso y referidos. |
| D-06 | Resuelta el 9 nov 2026: los 50.000 puntos pendientes no caducan; una vez activados, vencen a los seis meses. | Negocio | — |
| D-07 | Corte exacto de seis meses y política de devolución con beneficios consumidos, incluyendo puntos restituidos (los 50.000 puntos de A ya tienen regla). | Negocio y finanzas | Antes del piloto. |
| D-08 | Resuelta el 9 nov 2026: la comisión no convertida se paga en dinero al final de cada mes. Pendiente en términos y condiciones: medio, plazo, mínimo y retenciones. | Negocio y legal | Antes del piloto. |
| D-09 | Meta mensual de dietas, rango/costo de puntos, inicio del mes gratis, renovación y reactivación. El texto cita 50.000 y 49.800 puntos. | Negocio y producto | Fase posterior de dietas. |
| D-10 | Proveedor de pagos/SMS, verificación y conciliación disponibles en repositorios de referencia. | Tecnología y operación | Revisión e integración. |
| D-11 | Duración y sustitución de cookie, varios QR, cliente existente, cambio de referente, ausencia de referencia y continuidad del 10%. | Negocio y producto | Antes de cerrar atribución/checkout. |
| D-12 | Base de datos, distribución PWA, infraestructura y tamaño/concurrencia del piloto. | Tecnología y producto | Después de revisar repositorios; Angular y Express ya están definidos. |
| D-13 | Condiciones del programa, conservación de datos y permisos operativos/nutricionales. | Negocio y operación | Antes de uso real de cada módulo. |

Las decisiones de dietas no bloquean el MVP comercial.

## 15. Riesgos y mitigaciones de producto

| Riesgo | Consecuencia | Mitigación propuesta |
| --- | --- | --- |
| Combinación de descuento, comisión y puntos sin validar margen | Incentivos económicamente insostenibles | Aprobar un modelo de margen por producto antes del piloto. El costo de puntos depende de su canje y no equivale automáticamente al 5 % en efectivo. |
| Aplicación inconsistente de equivalencia o activación de los puntos por referido | Expectativas y saldos inconsistentes | Mostrar 1 peso = 1 punto y el origen/estado de los puntos por referido; verificar su activación única. |
| Registros repetidos y autorreferidos | Abuso de puntos por referido y recompensas | Verificación, límites, trazabilidad y revisión de casos sospechosos con criterios acordados. |
| Duplicación de pagos o recompensas | Diferencias financieras | Idempotencia, transacciones y conciliación con alertas. |
| Devoluciones posteriores al canje | Saldo sin respaldo | Política explícita de ajustes y conciliación, respetando la acreditación al aprobar la compra. |
| App de dietas sin backend SaaS ni parámetros de acceso | Entrega posterior incompleta | Revisar Flutter/Angular, construir backend y precisar meta y costo antes de esa fase. |
| Vencimientos poco visibles | Reclamos y pérdida de confianza | Fechas visibles por lote e historial; avisos previos en una siguiente iteración. |

## 16. Entrega y validación

Las [fases de desarrollo](fases-de-desarrollo.md) contienen tareas, repositorios, entregables y aceptación. Su secuencia es:

| Fase | Resultado | Dependencia |
| --- | --- | --- |
| 1. Reutilización | Inventario y matriz por componente de los proyectos existentes. | Acceso de lectura a repositorios. |
| 2. Arquitectura | Datos, módulos Express, áreas Angular, contratos e integraciones. | Fase 1. |
| 3. Acceso y referidos | Registro, recuperación, ambos QR, cookie y relación A–B. | Fase 2. |
| 4. Compras | Catálogo, descuentos, pagos, IVA y seguimiento de pedidos. | Fase 3. |
| 5. Recompensas | Comisiones, puntos, puntos por referido, vencimientos y conversión. | Fase 4. |
| 6. Canjes y reportes | FIFO, remanentes, entrega, IVA, operación y conciliación. | Fase 5. |
| 7. Piloto | Validación, despliegue controlado y operación preparada. | Fases 3–6. |
| 8. Dietas | App adaptada y backend SaaS con acceso condicionado. | MVP comercial y parámetros de dietas. |

### Criterios de salida del MVP

1. Requisitos marcados MVP verificados con parámetros definidos; trazabilidad de CU-01 a CU-13 y reportes.
2. Flujo entrenador → QR cliente → registro/cookie → compra aprobada → 5% y 15% → consulta → canje completo.
3. Flujo A → QR entrenadores → B → compra aprobada de cliente de B → activación única de 50.000 para A.
4. Compra propia con 25%, canje a precio completo, rechazo de pago mixto y conversión total probados.
5. FIFO, consumo parcial, caducidad, saldo insuficiente y operaciones concurrentes sin doble gasto.
6. Aprobaciones repetidas, pagos rechazados y devoluciones/ajustes según política acordada sin duplicar movimientos.
7. Ventas, IVA, comisiones y puntos conciliados; aislamiento de roles, recuperación y experiencia móvil verificados.
8. Operación de pedidos/canjes, soporte, configuración, copias y restauración preparados para el piloto.

Los escenarios concretos están en el [MVP](MVP-PremiumFitness.md). Se estimarán tiempos tras revisar código e integraciones; el plan de fases no afirma que la implementación ya exista.

## 17. Condición de éxito

El MVP estará listo cuando clientes y entrenadores completen sus compras con el descuento correspondiente, los referidos generen los beneficios definidos y el entrenador pueda consultar, convertir y canjear saldos verificables. Cada importe y punto debe poder seguirse desde su origen hasta uso, vencimiento o ajuste. Dietas tendrá aceptación independiente en su fase posterior.
