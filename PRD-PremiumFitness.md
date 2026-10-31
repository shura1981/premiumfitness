# PremiumFitness — Documento de requisitos del producto (PRD)

**Versión:** 1.0 · Borrador para validación de negocio  
**Fecha:** 31 de octubre de 2026  
**Fuente:** [Diagrama del sistema de entrenadores y clientes](./diagrama-flujo.jpg)  
**Producto:** aplicación para entrenadores, clientes y administración de PremiumFitness.

## 1. Resumen del producto

PremiumFitness conecta a entrenadores con sus clientes mediante un programa de compras y beneficios. Cada entrenador comparte un QR personal; el cliente lo utiliza para vincularse, registrarse y comprar desde la aplicación con un 10 % de descuento. Las compras elegibles generan para el entrenador una comisión del 15 % y una recompensa del 5 % en puntos.

El entrenador recibe 30.000 puntos de bienvenida y accede a herramientas relacionadas con dietas, seminarios de nutrición y materiales de apoyo. Puede convertir comisiones disponibles en puntos. Los puntos tienen una vigencia de seis meses. El diagrama también contempla un incentivo de 50.000 puntos por metas o registro, cuya condición debe definirse antes de activarlo.

Este documento convierte el diagrama en un alcance implementable, distingue los requisitos explícitos de las propuestas y enumera las decisiones necesarias para cerrar las reglas comerciales.

## 2. Problema y oportunidad

El modelo necesita una forma verificable de relacionar cada cliente y compra con su entrenador, aplicar beneficios de forma consistente y mostrar cómo se generan y consumen las recompensas.

PremiumFitness debe permitir:

- Al entrenador: acceder a herramientas, referir clientes y consultar sus comisiones y puntos.
- Al cliente: vincularse con su entrenador, comprar y comprobar el descuento recibido.
- A la operación: administrar contenido, pedidos y reglas; resolver incidencias; y auditar cada recompensa.

**Hipótesis por validar:** los beneficios aumentarán la participación de los entrenadores, la adquisición de clientes y la recompra. No se dispone todavía de una línea base ni de evidencia de impacto.

## 3. Objetivos y medición

| Objetivo | Indicador | Definición |
| --- | --- | --- |
| Activar entrenadores | Activación a siete días | Entrenadores que completan registro, abren una herramienta y comparten su QR dentro de siete días / registros de entrenadores de la cohorte. |
| Convertir referidos | Conversión de referido a primera compra | Clientes vinculados que realizan una primera compra pagada dentro de 30 días / clientes vinculados de la cohorte. |
| Impulsar ventas | Ventas atribuidas | Valor de compras pagadas atribuidas a entrenadores, menos devoluciones; excluir pedidos de prueba. |
| Promover recompra | Recompra a 60 días | Clientes con una segunda compra pagada dentro de 60 días de la primera / clientes de la cohorte con ventana completa. |
| Dar confianza en los beneficios | Exactitud del registro | Operaciones auditadas sin diferencias entre pedido, descuento, comisión y puntos / operaciones auditadas. |
| Favorecer uso de puntos | Uso antes del vencimiento | Puntos canjeados / puntos emitidos de los mismos lotes, medidos al cierre de su vigencia. |

**Propuesta de lanzamiento:** medir una cohorte piloto durante ocho semanas, mantener seguimiento posterior para completar las ventanas de recompra y vencimiento, y fijar metas comerciales después de conocer la línea base. Los controles de aceptación financiera requieren cero recompensas duplicadas y trazabilidad de todos los movimientos.

## 4. Usuarios y permisos

| Rol | Necesidad principal | Acciones permitidas |
| --- | --- | --- |
| Entrenador | Obtener herramientas y beneficios por sus referidos | Gestionar su perfil, compartir QR, consultar clientes vinculados con datos mínimos, consultar comisiones y puntos, convertir comisión en puntos y canjear beneficios habilitados. |
| Cliente | Comprar con los beneficios de su entrenador | Registrarse, confirmar vinculación, consultar catálogo, comprar, consultar pedidos y solicitar soporte. |
| Administrador | Operar y controlar el programa | Gestionar cuentas, contenido, catálogo y pedidos; configurar reglas; tramitar incidencias; consultar auditoría y reportes. |

**Propuesta de permisos:** el entrenador verá el estado de vinculación y la actividad comercial necesaria para explicar sus recompensas. No podrá consultar datos de pago, contraseñas ni información de otros entrenadores. La visibilidad de datos nutricionales requerirá definir previamente su finalidad y permisos.

## 5. Alcance del MVP

### Incluido

1. Registro, acceso, recuperación de cuenta y perfiles por rol.
2. QR y enlace personal del entrenador, con vinculación confirmada por el cliente.
3. Catálogo, detalle de producto, carrito, checkout y consulta de pedidos.
4. Integración con un proveedor de pagos, por seleccionar, y confirmación verificable del resultado.
5. Descuento del cliente y atribución de compras al entrenador.
6. Registro separado de comisiones y puntos, con movimientos, estados y vencimientos.
7. Bono de bienvenida de 30.000 puntos por entrenador elegible.
8. Conversión de comisión disponible a puntos, una vez aprobada la tasa de conversión.
9. Catálogo básico de beneficios canjeables con puntos, definido por negocio antes del piloto.
10. Acceso a dietas, seminarios de nutrición y materiales de apoyo.
11. Panel administrativo, ajustes auditados, devoluciones y reportes básicos.
12. Avisos transaccionales y soporte básico.

### Fuera del MVP

- Generación de dietas con IA, diagnóstico, seguimiento clínico o prescripciones automáticas.
- Rutinas de entrenamiento, reservas de clases, chat y videollamadas.
- Referidos multinivel, reparto entre entrenadores o marketplaces de entrenadores.
- Suscripciones y cobros recurrentes: el nombre PremiumFitness no implica una membresía pagada.
- Retiro o pago automático de comisiones a cuentas bancarias.
- Conversión de puntos a dinero o transferencias de puntos entre usuarios.
- Incentivo automático de 50.000 puntos hasta definir elegibilidad y relación con el bono de bienvenida.
- Integraciones con dispositivos deportivos y operación comercial en varias monedas.

La comisión monetaria se registrará y mostrará en el MVP. Su liquidación externa, si existe, requiere una política específica; el registro de una comisión no debe presentarse como un retiro bancario disponible.

## 6. Recorridos principales

### 6.1 Alta del entrenador

1. El entrenador crea su cuenta y verifica el medio de contacto elegido.
2. Completa los datos obligatorios y acepta las condiciones vigentes del programa.
3. Si cumple las condiciones de elegibilidad, recibe una única asignación de 30.000 puntos.
4. Accede a su inicio, herramientas, saldo y QR personal.
5. Comparte el QR o su enlace equivalente.

**Resultado esperado:** el entrenador puede comenzar a referir y reconocer el origen y vencimiento de sus puntos. La verificación o aprobación profesional del entrenador está pendiente de definición.

### 6.2 Vinculación del cliente

1. El cliente escanea el QR o abre el enlace compartido.
2. La aplicación muestra la identidad pública del entrenador y permite confirmar la vinculación.
3. Si no tiene cuenta, se registra; si ya tiene una, inicia sesión.
4. La intención de vinculación se conserva durante ese proceso.
5. La aplicación valida el entrenador y crea la relación sin duplicarla.
6. El cliente ve quién es su entrenador y el beneficio aplicable.

**Propuesta:** un cliente tendrá un entrenador activo a la vez. Un nuevo QR no reemplazará automáticamente una relación existente. Los cambios se gestionarán mediante soporte, quedarán auditados y solo afectarán compras futuras.

### 6.3 Compra y generación de beneficios

1. El cliente selecciona productos disponibles y revisa su carrito.
2. El servidor valida vinculación, elegibilidad, precio y disponibilidad; presenta el descuento y total.
3. Se crea un pedido con el entrenador atribuido y la versión de reglas aplicable.
4. El cliente paga mediante el proveedor seleccionado.
5. El sistema verifica el pago y actualiza el pedido de forma idempotente.
6. Registra la comisión del 15 % y los puntos equivalentes al 5 % según las reglas aprobadas.
7. Muestra los beneficios como pendientes o disponibles según el estado del pedido.
8. Una cancelación o devolución revierte los beneficios correspondientes sin borrar el historial.

### 6.4 Conversión de comisión a puntos

1. El entrenador consulta su comisión disponible y la tasa vigente.
2. Introduce el importe que desea convertir.
3. Revisa cuántos puntos recibirá, su vencimiento y el nuevo saldo monetario.
4. Confirma la operación.
5. El sistema debita la comisión y acredita los puntos en una transacción indivisible.
6. Entrega un comprobante consultable en el historial.

### 6.5 Canje y consumo de contenido

1. El entrenador consulta herramientas y beneficios disponibles.
2. Los recursos incluidos se abren según su permiso de acceso.
3. Los beneficios canjeables muestran costo en puntos, disponibilidad y condiciones.
4. El entrenador confirma el canje; el sistema valida el saldo y registra el consumo.
5. Puede consultar el estado del beneficio y su entrega o habilitación.

**Propuesta:** acceso incluido y acceso mediante canje son configuraciones distintas. No se cobrará en puntos por dietas, seminarios o materiales salvo que negocio lo defina expresamente para un recurso.

## 7. Reglas de negocio

### 7.1 Requisitos explícitos del diagrama

| ID | Regla | Precisión pendiente |
| --- | --- | --- |
| RN-01 | El entrenador obtiene 30.000 puntos de bienvenida. | Condición de elegibilidad y momento exacto de acreditación. |
| RN-02 | El QR asocia al cliente con el entrenador. | Cambio de entrenador, vigencia del enlace y tratamiento de relaciones existentes. |
| RN-03 | El cliente obtiene 10 % de descuento en la compra. | Productos elegibles, exclusiones y acumulación con promociones. |
| RN-04 | El entrenador gana 15 % de comisión por ventas. | Base de cálculo, disponibilidad y liquidación monetaria. |
| RN-05 | El entrenador gana 5 % en puntos por ventas. | Base de cálculo, equivalencia monetaria y redondeo. |
| RN-06 | El entrenador puede canjear comisión por puntos. | Tasa, mínimos, límites e irreversibilidad. No se establece conversión inversa. |
| RN-07 | Los puntos duran seis meses. | Inicio de la vigencia y momento exacto de vencimiento. |
| RN-08 | Existe un incentivo de 50.000 puntos por metas o registro. | Disparador, frecuencia y compatibilidad con RN-01. |
| RN-09 | El entrenador dispone de dietas, seminarios y materiales. | Formatos, origen del contenido y modalidad de acceso. |

### 7.2 Propuesta de cálculo para validar

Las siguientes fórmulas son una propuesta, no una regla confirmada por el diagrama:

- `S`: subtotal de productos elegibles antes del descuento, excluyendo impuestos y envío.
- `D = redondear_moneda(S × 0,10)`: descuento del cliente.
- `B = S − D`: base propuesta para recompensas, después del descuento.
- `C = redondear_moneda(B × 0,15)`: comisión del entrenador.
- `V = B × 0,05`: valor de referencia de la recompensa en puntos.
- `P = redondear_puntos(V × T_venta)`: puntos de la compra; `T_venta` expresa puntos por unidad monetaria.
- `P_conversion = redondear_puntos(importe_convertido × T_conversion)`: puntos por conversión de comisión.

`T_venta` y `T_conversion` son parámetros distintos y no tienen valor definido todavía. El costo de los beneficios en puntos se administra por separado. La política de redondeo monetario dependerá de la moneda elegida; se propone redondear los puntos hacia abajo al entero y guardar el cálculo original para auditoría.

**Ejemplo ilustrativo, en unidades monetarias:** para un subtotal elegible de 100.000, el descuento es 10.000 y la base propuesta es 90.000. La comisión sería 13.500; el valor de referencia de puntos, 4.500. Los puntos acreditados serían `redondear_puntos(4.500 × T_venta)`. Este ejemplo no fija moneda ni equivalencia del punto.

Si la base elegida fuera el subtotal anterior al descuento, los resultados cambiarían. Negocio debe aprobar la base antes de habilitar ventas reales.

### 7.3 Integridad, disponibilidad y vencimientos propuestos

- Cada bono, recompensa, conversión, canje y reversión tendrá un identificador único de origen. Los reintentos no generarán movimientos adicionales.
- El bono de bienvenida se otorgará una vez por entrenador elegible; suspender y reactivar una cuenta no lo repetirá.
- Se propone reservar la atribución al crear el pedido y conservarla tras el pago. Un cambio posterior de entrenador no moverá compras anteriores.
- Las recompensas de una compra pagada comenzarán pendientes y pasarán a disponibles tras el hito de cumplimiento y plazo de devolución que apruebe negocio.
- Los puntos pendientes no podrán canjearse. La vigencia propuesta comenzará al quedar disponibles.
- Cada acreditación generará un lote con origen, cantidad inicial, saldo y fecha de vencimiento.
- Se propone vencer cada lote a los seis meses calendario desde su disponibilidad, conservando la hora local de la zona del programa; si no existe el mismo día, usar el último día del mes. El saldo debe excluir lotes vencidos aunque el proceso programado se retrase.
- Los canjes consumirán primero los lotes que vencen antes; en empate, los más antiguos.
- Los puntos originados en una conversión tendrán su propio lote y seis meses de vigencia desde la conversión.
- La conversión utilizará únicamente comisión disponible y mostrará la tasa antes de confirmar. Se propone que no pueda revertirse desde la cuenta del entrenador.
- Los cambios de porcentajes o tasas aplicarán a operaciones futuras; cada operación conservará la versión usada.
- Las cuentas suspendidas no podrán generar nuevas referencias ni convertir o canjear; el historial permanecerá accesible para administración.

### 7.4 Cancelaciones, devoluciones y ajustes

- Un pago rechazado o cancelado no genera recompensas disponibles.
- Una devolución total revierte la comisión y los puntos de la compra original.
- Una devolución parcial recalcula las recompensas sobre los importes elegibles conservados, usando las reglas originales, y revierte la diferencia. Las devoluciones acumuladas nunca excederán el beneficio original.
- Revertir puntos vencidos o ya revertidos no debe descontar otra vez saldo utilizable.
- Si las recompensas ya se consumieron o convirtieron, se propone registrar una deuda de ajuste y bloquear nuevos canjes o conversiones hasta su conciliación. La política de compensación requiere aprobación de negocio antes del piloto.
- Si el sistema no puede entregar un canje, restituirá los puntos con trazabilidad. La vigencia de los puntos restituidos debe definirse antes de ofrecer ese beneficio.
- Un administrador podrá registrar ajustes con motivo obligatorio y actor identificado. Nunca editará ni eliminará silenciosamente movimientos históricos.

## 8. Requisitos funcionales y aceptación

**Prioridades:** P0 = indispensable para operar el piloto; P1 = siguiente iteración; P2 = expansión. Las funciones P0 sujetas a reglas abiertas deben permanecer deshabilitadas hasta su aprobación.

| ID | Pri. | Requisito | Criterio de aceptación |
| --- | --- | --- | --- |
| RF-01 | P0 | Registro y acceso por rol | El usuario verifica su contacto, recupera acceso y solo consulta recursos de su rol. No puede asignarse privilegios administrativos. |
| RF-02 | P0 | Perfil del entrenador | Puede consultar y modificar sus datos permitidos y ver su estado de elegibilidad. |
| RF-03 | P0 | QR y enlace de referido | Ambos identifican al mismo entrenador; no exponen información privada y funcionan al retomar el registro. |
| RF-04 | P0 | Vinculación | El cliente confirma la identidad del entrenador. Repetir la solicitud conserva una sola relación; un QR inválido o inactivo explica cómo continuar. |
| RF-05 | P0 | Catálogo y carrito | El cliente ve precio, descripción y disponibilidad. El servidor recalcula importes y valida existencias al comprar. |
| RF-06 | P0 | Descuento | Un cliente elegible ve el 10 % aplicado a los conceptos aprobados antes de confirmar el pago y en el detalle del pedido. |
| RF-07 | P0 | Checkout y pagos | Un resultado verificado actualiza el pedido; un retorno del navegador por sí solo no confirma el pago. Eventos repetidos no duplican cobros locales ni beneficios. |
| RF-08 | P0 | Pedidos | El cliente ve estado, conceptos, descuento y total; administración identifica pago, atribución y cumplimiento. |
| RF-09 | P0 | Bono de bienvenida | El entrenador elegible recibe exactamente 30.000 puntos una vez, incluso ante solicitudes concurrentes. |
| RF-10 | P0 | Comisión y puntos de compra | Una compra elegible genera movimientos vinculados al pedido, con base, tasa, versión y estados consultables. |
| RF-11 | P0 | Saldos e historial | El entrenador distingue comisión pendiente y disponible, puntos pendientes y disponibles, próximos vencimientos y movimientos de ajuste. |
| RF-12 | P0 | Conversión de comisión | La confirmación descuenta comisión y acredita puntos una sola vez; con saldo insuficiente no modifica ninguno de los saldos. |
| RF-13 | P0 | Vencimiento | Un lote deja de estar disponible en la fecha definida; conserva su historial y no vence dos veces. |
| RF-14 | P0 | Canje de beneficios | Muestra costo y condiciones; confirma saldo y disponibilidad de forma atómica. Dos solicitudes simultáneas no gastan los mismos puntos. |
| RF-15 | P0 | Herramientas | El entrenador abre dietas, seminarios y materiales publicados según permisos. El contenido no disponible muestra un estado comprensible. |
| RF-16 | P0 | Administración | Puede gestionar usuarios, catálogo, contenido, beneficios y versiones de reglas, con validación y auditoría de cambios. |
| RF-17 | P0 | Reversiones | Cancelaciones y devoluciones parciales o totales producen ajustes trazables y no duplicados con las reglas originales. |
| RF-18 | P0 | Notificaciones básicas | El usuario puede consultar avisos de vinculación, pago, recompensa, conversión y canje. Un fallo de envío no revierte la operación. |
| RF-19 | P0 | Soporte y reportes | Soporte localiza una operación por identificador. Administración consulta ventas atribuidas, comisiones, puntos emitidos, canjeados y vencidos, y exporta los datos autorizados. |
| RF-20 | P1 | Incentivo de 50.000 puntos | Una regla aprobada determina evento, elegibilidad, límite y compatibilidad; el sistema explica por qué se otorgó o rechazó. |
| RF-21 | P1 | Aviso de vencimiento | Envía avisos configurables antes del vencimiento sin repetirlos por lote y canal. |
| RF-22 | P2 | Liquidación monetaria | Requiere un alcance nuevo con validación, conciliación y estados de pago antes de implementarse. |

## 9. Pantallas y contenido mínimo

| Área | Pantallas | Información clave |
| --- | --- | --- |
| Acceso | Inicio de sesión, registro, verificación, recuperación | Rol, contacto, errores y condiciones aplicables. |
| Entrenador | Inicio | Resumen de clientes, comisión, puntos y próximos vencimientos; acceso al QR y herramientas. |
| Referidos | Mi QR, compartir enlace, clientes vinculados | Identidad pública, estado de relación y actividad comercial autorizada. |
| Beneficios | Historial, conversión, catálogo de canjes, detalle del canje | Origen del saldo, tasas, costo, vencimiento, comprobante y estado. |
| Herramientas | Dietas, seminarios, materiales | Autor o responsable, descripción, fecha, formato y permiso de acceso. |
| Cliente | Confirmar entrenador, catálogo, producto, carrito, checkout, pedidos | Entrenador asociado, beneficio aplicable y desglose de la compra. |
| Administración | Usuarios, pedidos, catálogo, contenido, reglas, movimientos, reportes | Búsqueda, filtros, auditoría y resolución de incidencias. |

**Propuesta de plataforma:** aplicación web adaptable a móviles para clientes y entrenadores, y panel web de administración. La necesidad de aplicaciones nativas y publicación en tiendas está pendiente; no se presupone en el MVP.

## 10. Datos y estados del dominio

### Entidades mínimas propuestas

| Entidad | Responsabilidad y datos esenciales |
| --- | --- |
| Usuario y perfil | Identidad, rol, contacto verificado, estado y aceptación de condiciones versionadas. |
| Referido y vinculación | Token público, entrenador, cliente, origen, fechas y motivo de cambio. |
| Producto y pedido | Precio, elegibilidad, existencias si aplican, líneas, descuento, total, atribución y versión de reglas. |
| Pago y devolución | Referencia externa, importe, moneda, estado, eventos procesados y conciliación. |
| Movimiento de comisión | Origen, importe, estado, regla, referencia de ajuste y fecha de disponibilidad. |
| Movimiento y lote de puntos | Origen, cantidad, lote afectado, saldo, disponibilidad y vencimiento. |
| Conversión | Importe monetario, tasa, puntos resultantes y referencias a ambos movimientos. |
| Beneficio y canje | Costo, disponibilidad, condiciones, destinatario y cumplimiento. |
| Contenido | Tipo, título, responsable, archivo o enlace, versión, publicación y permisos. |
| Regla y auditoría | Vigencia, parámetros, actor, momento, motivo y operación afectada. |

### Estados principales

- **Cuenta:** pendiente de verificación → activa → suspendida o cerrada.
- **Pago:** pendiente → aprobado, rechazado o cancelado; un aprobado puede tener devoluciones parciales o totales.
- **Pedido:** creado → pendiente de pago → pagado → en preparación → entregado; admite cancelación y devolución según política. La etapa de cumplimiento se adaptará a productos físicos o digitales.
- **Comisión:** pendiente → disponible → consumida por conversión; cualquier ajuste conserva referencia al movimiento original.
- **Lote de puntos:** pendiente → disponible → consumido, vencido o revertido. Un lote puede tener consumos parciales; las cantidades prevalecen sobre una etiqueta única.
- **Canje:** solicitado → confirmado → entregado; una cancelación genera una restitución trazable según política.

Los estados de pago, pedido y recompensa son independientes y deben reconciliarse; no se debe usar una sola etiqueta para representar los tres procesos.

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

Registrar al menos los eventos `trainer_registered`, `trainer_activated`, `referral_opened`, `client_linked`, `checkout_started`, `payment_verified`, `order_refunded`, `welcome_bonus_granted`, `reward_available`, `commission_converted`, `points_redeemed`, `points_expired` y `tool_opened`.

Cada evento incluirá fecha, identificador interno del actor, origen y versión de reglas cuando corresponda. Los eventos de dinero y puntos usarán el identificador de la operación para deduplicación. La fuente de verdad de ventas y saldos será el registro transaccional; las métricas analíticas no modificarán esos registros.

La operación dispondrá de una conciliación periódica entre pagos, pedidos y recompensas. Un pedido pagado cuyo procesamiento de beneficios falle quedará identificado para reintento seguro; el usuario verá su compra confirmada y sus beneficios en procesamiento.

## 13. Casos límite que deben resolverse

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
| Contenido externo o proveedor de avisos caído | Se ofrece reintento o estado de indisponibilidad sin perder el saldo ni alterar compras confirmadas. |

## 14. Dependencias y decisiones abiertas

| ID | Decisión necesaria | Responsable propuesto | Impacto |
| --- | --- | --- | --- |
| D-01 | País, moneda, zona horaria del programa y forma de expresar precios e impuestos. | Negocio y operación | Bloquea configuración comercial. |
| D-02 | Productos físicos, digitales o ambos; precios, existencias, entrega y devoluciones. | Negocio y operación | Bloquea cierre de catálogo y checkout. |
| D-03 | Base de cálculo del 15 % y 5 %; productos elegibles; promociones acumulables y redondeos. | Negocio y finanzas | Bloquea cálculo de beneficios. |
| D-04 | `T_venta`, `T_conversion`, beneficios canjeables, sus costos y condiciones de restitución. | Negocio y finanzas | Bloquea emisión utilizable, conversión y canje de puntos. |
| D-05 | Elegibilidad del entrenador, entrega del bono de bienvenida y prevención de registros duplicados. | Negocio | Bloquea activación del bono en producción. |
| D-06 | Condición del incentivo de 50.000 puntos y si se suma a los 30.000. | Negocio | Mantiene deshabilitado ese incentivo; no bloquea el resto del MVP. |
| D-07 | Hito para liberar recompensas, vencimiento exacto y tratamiento de devoluciones con beneficios consumidos. | Negocio y finanzas | Bloquea activación comercial de saldos. |
| D-08 | Condiciones de liquidación de comisión monetaria y comunicación al entrenador. | Negocio y finanzas | Necesario antes del piloto, aunque el pago bancario quede fuera del MVP. |
| D-09 | Acceso a una app de dietas existente o módulo propio; formatos, responsables y contenido inicial. | Producto y contenido | Bloquea implementación final de herramientas. |
| D-10 | Proveedor de pagos, confirmaciones, conciliación y canales de avisos. | Tecnología y operación | Bloquea compra de extremo a extremo. |
| D-11 | Cambio de entrenador, compras sin referido y compras propias del entrenador. | Negocio | Bloquea reglas de atribución definitivas. |
| D-12 | Plataforma final, volumen del piloto y usuarios concurrentes esperados. | Producto y tecnología | Determina distribución, pruebas y presupuesto. |
| D-13 | Condiciones del programa, tratamiento y conservación de datos, permisos sobre contenido nutricional y atención de solicitudes. | Negocio y responsable de privacidad | Bloquea publicación de condiciones y operación con usuarios reales. |

Hasta resolver D-06, la interfaz no prometerá el bono de 50.000 puntos. Las demás reglas pendientes se podrán probar con configuración de ensayo, claramente separada de las operaciones reales.

## 15. Riesgos y mitigaciones de producto

| Riesgo | Consecuencia | Mitigación propuesta |
| --- | --- | --- |
| Combinación de descuento, comisión y puntos sin validar margen | Incentivos económicamente insostenibles | Aprobar un modelo de margen por producto antes del piloto. El costo de puntos depende de su canje y no equivale automáticamente al 5 % en efectivo. |
| Interpretaciones distintas de un punto o del bono de 50.000 | Expectativas y saldos inconsistentes | Publicar reglas aprobadas y bloquear incentivos indefinidos. |
| Registros repetidos y autorreferidos | Abuso de bonos y recompensas | Verificación, límites, trazabilidad y revisión de casos sospechosos con criterios acordados. |
| Duplicación de pagos o recompensas | Diferencias financieras | Idempotencia, transacciones y conciliación con alertas. |
| Devoluciones posteriores al canje | Saldo sin respaldo | Disponibilidad diferida y política explícita de ajustes. |
| Herramientas nutricionales sin contenido o responsables | Experiencia incompleta | Definir responsables, permisos y contenido inicial antes de la aceptación. |
| Vencimientos poco visibles | Reclamos y pérdida de confianza | Fechas visibles por lote e historial; avisos previos en una siguiente iteración. |

## 16. Entrega y validación

### Etapa 1 — Cierre de producto

- Resolver las decisiones que bloquean el piloto y registrar las reglas aprobadas.
- Validar prototipos de registro por QR, compra y consulta/canje de beneficios con representantes de ambos roles.
- Preparar catálogo, contenido inicial, beneficios y condiciones de acceso.

### Etapa 2 — Construcción del MVP

- Implementar identidad, permisos, QR y vinculación.
- Implementar catálogo, pedidos y confirmación de pagos.
- Implementar movimientos de comisión y puntos, bonos, vencimientos, conversiones y canjes.
- Completar herramientas, administración, soporte e instrumentación.

### Etapa 3 — Piloto controlado

- Invitar a una cohorte acordada de entrenadores y clientes.
- Verificar el recorrido completo y conciliar diariamente pedidos, pagos y beneficios.
- Medir activación, compras, incidencias y uso de herramientas; revisar resultados a las ocho semanas.

No se fija una duración de desarrollo sin conocer equipo, plataforma, integraciones y decisiones comerciales. La ampliación del piloto dependerá de resultados y estabilidad operativa.

### Criterios de salida del MVP

1. Todos los requisitos P0 pasan sus criterios de aceptación con reglas comerciales aprobadas.
2. El recorrido registro de entrenador → bono → QR → registro de cliente → compra → recompensas → conversión/canje funciona de extremo a extremo.
3. Se prueban pagos rechazados, abandonos, eventos duplicados, devoluciones parciales y totales, vencimientos y solicitudes concurrentes de saldo.
4. Ninguna prueba de reintentos o concurrencia duplica bonos, recompensas o consumos.
5. Los saldos se reconstruyen a partir de movimientos y coinciden con los pedidos y pagos conciliados del piloto.
6. Se verifican aislamiento de roles, navegación móvil, recuperación de cuenta y restauración de una copia.
7. Hay contenido inicial accesible, beneficios entregables y responsables de soporte y conciliación.
8. Las decisiones que afecten dinero, puntos y elegibilidad están documentadas y comunicadas; cualquier función pendiente permanece deshabilitada.

## 17. Condición de éxito

PremiumFitness estará preparada para el piloto cuando un cliente pueda vincularse y comprar con su beneficio, el entrenador pueda entender y utilizar las recompensas que correspondan, y la operación pueda explicar cada importe y punto desde su origen hasta su consumo, vencimiento o reversión.
