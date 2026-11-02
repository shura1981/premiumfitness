# MVP comercial — PremiumFitness

**Versión:** 1.0 · **Fecha:** 2 de noviembre de 2026.

El MVP permite operar el programa de referidos, compras, comisiones y puntos de principio a fin. Este documento define qué debe construirse y cómo aceptar la entrega. Las reglas se detallan en el [PRD](PRD-PremiumFitness.md); el trabajo técnico se organiza en [fases de desarrollo](fases-de-desarrollo.md).

## 1. Resultado esperado por usuario

- **Entrenador:** registrarse, compartir dos QR, consultar referidos y saldos, comprar con 25%, convertir el total de comisión y canjear productos con puntos.
- **Cliente:** registrarse desde el QR, conservar la referencia, comprar con 10% y consultar el estado del pedido.
- **Operación — acceso propuesto:** localizar compras y canjes, atender incidencias y conciliar IVA, comisiones y puntos por entrenador.

La app de dietas conserva su alcance en el producto y se implementa después del MVP comercial.

## 2. Alcance funcional y trazabilidad

| Bloque | Funciones incluidas | PRD | Casos de uso |
| --- | --- | --- | --- |
| Identidad | Registro, verificación de contacto, acceso, recuperación y perfil; áreas de cliente/entrenador. | RF-01, RF-02 | CU-01, CU-03, CU-07 |
| Referidos | QR para clientes, QR para entrenadores, cookie de referente, atribución y relación A → B. | RF-03, RF-04, RF-09 | CU-02, CU-03, CU-06, CU-07 |
| Comercio | Catálogo, detalle, carrito, dirección, descuentos, pago, pedido y seguimiento. | RF-05 a RF-08, RF-23 | CU-04, CU-13 |
| Beneficios por venta | Aprobación de compra de cliente, 5% en puntos y 15% de comisión sin IVA. | RF-10 | CU-05 |
| Incentivo de referido | 50.000 inactivos para A al registrar B; activación por compra aprobada de cliente de B. | RF-09, RF-20 | CU-07, CU-08 |
| Cuenta de recompensas | Comisión convertible, puntos por estado, lotes, remanentes, historial y vencimiento. | RF-11, RF-13 | CU-09, CU-10 |
| Conversión | Convertir el total de comisión, vista previa, confirmación y movimientos vinculados. | RF-12 | CU-11 |
| Canje | Productos a precio completo, consumo FIFO, sin pago mixto, IVA y entrega. | RF-14 | CU-12 |
| Operación | Consulta/gestión mínima, ajustes conforme a política y reportes por período/entrenador. | RF-16, RF-17, RF-19 | Requerimientos y regla 10 |

Los CU describen recorridos; las reglas específicas de negocio completan los puntos que el documento de casos aún presenta como pendientes. La correspondencia se explica en el PRD.

## 3. Flujos que debe completar la primera entrega

### 3.1. Cliente referido

Entrenador registrado → QR de clientes → registro de cliente y cookie → compra con 10% → aprobación verificada → 5% en puntos y 15% de comisión sin IVA para el entrenador → consulta de movimientos → canje.

**Datos conservados:** entrenador atribuido, pedido, productos, descuento, base sin IVA, IVA, pago y movimientos de recompensa.

### 3.2. Entrenador referido

A comparte QR de entrenadores → B se registra → A ve 50.000 inactivos → cliente de B compra → aprobación → activación única de 50.000 para A y recompensas habituales para B.

**Condiciones:** registrar B no activa el bono; los puntos inactivos no permiten canjear; repetir la aprobación o recibir otra venta no vuelve a activar el mismo incentivo.

### 3.3. Compra propia, conversión y canje

- **Compra propia:** entrenador selecciona productos → 25% de descuento → paga con dinero → consulta pedido.
- **Conversión:** consulta total de comisión disponible → revisa fórmula y puntos resultantes → confirma → débito de comisión y crédito de puntos juntos.
- **Canje:** selecciona productos a precio completo → revisa puntos requeridos → confirma con saldo vigente → consumo FIFO → registro de IVA y entrega.

La conversión parcial y el pago mixto no forman parte de estos flujos. No se concede una recompensa de venta de cliente por una compra propia sin regla que lo establezca.

## 4. Pantallas e información mínima

| Área | Pantallas | Contenido necesario |
| --- | --- | --- |
| Acceso | Registro, verificación, ingreso, recuperación | Rol, datos requeridos, referente si aplica y errores recuperables. |
| Entrenador | Inicio y perfil | Acumulado de comisiones, saldo convertible, puntos disponibles/inactivos, próximos vencimientos. |
| Referidos | Dos QR y consulta de referidos | Destino de cada QR, B registrados y estado de cada bono. |
| Recompensas | Historial, detalle de lote y conversión | Origen, fecha, importe, base, remanente, caducidad, puntos a recibir y comprobante. |
| Compras | Catálogo, detalle, carrito, dirección, pago, resultado y pedidos | Precio, descuento, IVA, total, estado y seguimiento. |
| Canjes | Selección, confirmación y detalle | Precio completo en puntos, saldo previo/posterior y entrega. |
| Operación | Pedidos/pagos, canjes, movimientos y reportes | Filtros por fecha y entrenador, referencias de origen y ajustes auditados. |

Adaptar la UX de comisiones y onboarding de `join.nutramerican.com`, la compra de `gifts.nutramerican.com` y las capacidades operativas de `backend-radical-nutrition`, después de revisarlas.

## 5. Escenarios de aceptación

| ID | Escenario | Resultado esperado |
| --- | --- | --- |
| A-01 | Registrar entrenador y cliente desde sus recorridos | Cada rol accede a su área; el QR identifica el destino y referente correctos. |
| A-02 | Completar registro de cliente por QR | Cookie con ID del entrenador; compra atribuible según la política definida. |
| A-03 | Comprar como cliente referido | 10% visible antes de pagar; descuento, IVA y total persistidos. |
| A-04 | Compra pendiente o rechazada | No acredita puntos ni comisión. |
| A-05 | Aprobar compra con base elegible definida de 100.000 sin IVA | 5.000 puntos y 15.000 de comisión; movimientos relacionados con el pedido. |
| A-06 | Repetir la aprobación de A-05 | Saldos sin nuevas acreditaciones. |
| A-07 | B se registra desde A sin ventas | A ve 50.000 inactivos, excluidos del saldo canjeable. |
| A-08 | Primera compra aprobada de un cliente de B | Activa una vez los 50.000 de A y acredita recompensas de B; otra aprobación no duplica el bono. |
| A-09 | Comprar como entrenador con dinero | Aplica 25% al portafolio y registra IVA/pedido. |
| A-10 | Canjear Bipro Classic de 249.900 | Exige 249.900 puntos; rechaza descuento del 25% y pago mixto. |
| A-11 | Canjear 249.900 con lotes vigentes de 100.000 y 200.000, en ese orden | Consume 100.000 del primero y 149.900 del segundo; conserva 50.100. |
| A-12 | Lote usado, inactivo o caducado | No suma saldo disponible; el remanente de otro lote vigente sigue utilizable. |
| A-13 | Confirmar dos canjes que juntos exceden el saldo | Solo se confirman operaciones cubiertas; no hay sobregiro ni doble consumo. |
| A-14 | Convertir total de comisión y repetir la solicitud | Fórmula definida y vista previa correcta; una sola conversión; ambos saldos consistentes ante fallo. |
| A-15 | Vencer lote al corte de seis meses definido | Su remanente deja de estar disponible; se conserva historial y no vence dos veces. |
| A-16 | Cancelación/devolución o canje no entregable | Se aplica la política acordada con ajustes/restituciones trazables y sin duplicados. |
| A-17 | Consultar reportes | IVA de ventas y canjes, comisiones y puntos coinciden con operaciones y movimientos de cada entrenador. |
| A-18 | Intentar consultar pedido o saldo de otro usuario | Acceso rechazado; operación autorizada sigue disponible para su dueño. |
| A-19 | Recuperar cuenta y usar los flujos en móvil | Recuperación correcta y navegación/formularios utilizables; verificar instalación según dispositivo si se adopta PWA. |

A-05 usa una base ya determinada para comprobar porcentajes; no decide el orden entre descuento y recompensa ni la tasa de IVA de un producto real.

## 6. Condiciones previas al piloto

- Definir orden de cálculo, cargos elegibles y redondeo; cargar catálogo con IVA y precios correctos.
- Resolver fórmula del incentivo del 50% para convertir comisiones.
- Definir política de cookie, referencia existente y continuidad del descuento del cliente.
- Precisar corte de caducidad y vigencia del bono inicialmente inactivo.
- Seleccionar/verificar pagos y SMS; definir entrega, devoluciones, restituciones y responsables de operación.
- Preparar entornos, permisos, copias y restauración; conciliar un conjunto de compras y canjes de prueba.

Estas dependencias corresponden a §14 del PRD. La revisión de repositorios y la arquitectura pueden avanzar mientras se precisan los parámetros comerciales.

## 7. Qué se entrega después

- **Dietas:** revisión de Flutter y alternativa Angular, backend SaaS, aislamiento por entrenador, primer mes gratis, meta mensual, canje y evaluación diaria. Cobertura CU-14/CU-15 y RF-15.
- **Mejoras propuestas:** avisos externos y alertas de vencimiento, exportaciones o automatizaciones adicionales según necesidad del piloto.
- **Fuera del alcance confirmado:** bienvenida de 30.000 puntos, seminarios, materiales, otros beneficios sin especificar, puntos en efectivo, transferencias y pago bancario automático de comisiones.

## 8. Cierre de la entrega

El MVP completo requiere aprobar A-01 a A-19, resolver los parámetros que afectan esas pruebas y operar compras/canjes con reportes conciliados. Si se lanza sin conversión por falta de fórmula, se registra como piloto parcial con esa función pendiente.

Las fases 1–7 construyen y validan esta entrega. La fase 8 implementa dietas con sus propios criterios de aceptación. Medir activación de entrenadores, conversión de referidos a compra, ventas atribuidas, uso/vencimiento de puntos e incidencias; las ventanas y métricas están en el PRD.
