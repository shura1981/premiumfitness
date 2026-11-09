# Fases de desarrollo — PremiumFitness

Este plan desarrolla la información original de reutilización y arquitectura. El alcance funcional está en el [PRD](PRD-PremiumFitness.md) y la primera entrega en el [MVP](MVP-PremiumFitness.md).

**Orden:** revisión de repositorios → arquitectura → acceso y referidos → compras → recompensas → canjes y reportes → piloto. La app de dietas se desarrolla después como SaaS.

Las descripciones de los repositorios provienen del documento original; su compatibilidad se verificará en la fase 1. Esta planificación no implica que ya hayan sido auditados. Las fechas se estimarán después de conocer los componentes reutilizables y el equipo disponible.

## Fase 1. Revisar repositorios y seleccionar funcionalidades reutilizables

**Objetivo:** aprovechar lógica de negocio, infraestructura y experiencia de usuario existentes antes de construir los módulos del proyecto.

### 1.1. Backend y comercio: backend-radical-nutrition

**Ruta:** `/home/steven/Proyectos/php/backend-radical-nutrition`.

Funcionalidades identificadas en el documento original:

- Base de datos de clientes, vendedores, pedidos y direcciones.
- Frontend para realizar compras.
- Frontend administrativo.
- Autenticación y recuperación de cuenta mediante mensajes SMS.
- Pasarelas de pago y persistencia de sus respuestas.
- Tracking de pedidos y envíos.

Trabajo de revisión:

1. Identificar tablas, relaciones y estados aplicables a entrenadores, clientes, direcciones y pedidos.
2. Revisar registro, verificación SMS, inicio de sesión y recuperación de cuenta.
3. Revisar creación de pagos, recepción/verificación de resultados, persistencia y conciliación.
4. Identificar cómo maneja catálogo, precios, IVA, inventario, compra, administración y seguimiento de envíos.
5. Clasificar cada componente como reutilizable, adaptable, integrable o solo referencia. La lógica PHP que se traslade al nuevo backend deberá adaptarse a Node.js; no se asume compatibilidad directa.

### 1.2. Onboarding y comisiones: join.nutramerican.com

**Ruta:** `/home/steven/Proyectos/node/angular/join.nutramerican.com`.

Funcionalidades identificadas:

- UI y UX para mostrar el acumulado de comisiones.
- Instrucciones de instalación de la app PWA según el dispositivo del usuario.
- Registro mediante onboarding con verificación por SMS.

Revisar componentes, navegación móvil, formularios, validaciones, estados de carga/error e instalación por dispositivo. Identificar qué puede adaptarse al inicio del entrenador, registro y consulta de comisiones de PremiumFitness.

### 1.3. Catálogo y compra: gifts.nutramerican.com

**Ruta:** `/home/steven/Proyectos/node/angular/gifts.nutramerican.com`.

Funcionalidades identificadas:

- Diseño y experiencia de catálogo de productos.
- Flujo de compra con diferentes métodos de pago.

Revisar catálogo, detalle, carrito, resumen de compra, selección de pago y resultado de la operación. Separar componentes visuales reutilizables de reglas comerciales propias del proyecto de origen.

### 1.4. Entregables y aceptación

- Inventario por repositorio: módulo, archivos relevantes, dependencias y limitaciones.
- Matriz de reutilización: componente de origen, destino en PremiumFitness, acción y adaptación necesaria.
- Lista de integraciones disponibles y decisiones pendientes: SMS, pagos, catálogo, inventario y envíos.
- Recomendación de estructura técnica y estimación inicial basada en la revisión.

**Cierre:** cada módulo del MVP tiene una fuente de referencia o una justificación para construirlo desde cero. Las credenciales y datos reales no se copian a pruebas.

## Fase 2. Definir arquitectura, base de datos y estructura del proyecto

**Depende de:** fase 1.

### 2.1. Base de datos

Tomar como referencia el modelo de `backend-radical-nutrition` y definir las entidades nuevas:

- Usuarios, entrenadores, clientes, perfiles y direcciones.
- Referencia cliente–entrenador y relación entrenador A–entrenador B.
- Productos, precios e IVA por producto; pedidos, líneas, pagos y seguimiento.
- Comisiones con origen, importe, estado y saldo convertible.
- Lotes de puntos con origen, cantidad, remanente, activación y caducidad.
- Movimientos de puntos, conversiones, canjes y consumos de cada lote.
- Registro de activación de los 50.000 puntos por referido y auditoría de operaciones.

Elegir motor de base de datos, migraciones, índices y restricciones. Diseñar transacciones para impedir doble consumo, duplicación de recompensas y pérdida de trazabilidad.

### 2.2. Backend

**Tecnología definida: Node.js con Express.**

- Organizar módulos de identidad, referidos, catálogo, pedidos/pagos, comisiones, puntos, canjes y reportes.
- Adaptar la autenticación, recuperación por SMS, pasarelas y persistencia de respuestas tomando el backend existente como referencia.
- Definir contratos de API, permisos por rol, validaciones, errores y procesamiento de eventos repetidos.
- Planificar tareas de vencimiento y conciliación. La evaluación diaria de dietas pertenece a la fase posterior.
- Definir configuración por entorno, almacenamiento de secretos, registros operativos y despliegue.

### 2.3. Frontend

**Tecnología definida: Angular. Áreas separadas para entrenadores y clientes.**

- Usar `join.nutramerican.com` como referencia de onboarding, comisiones e instalación PWA.
- Usar `gifts.nutramerican.com` como referencia de catálogo y compra.
- Compartir componentes de acceso, productos, formularios y pedidos cuando sea conveniente.
- Definir navegación, sesión, manejo de errores y permisos de cada área.
- Evaluar qué parte de la administración existente se puede adaptar para pedidos, movimientos y reportes.
- Conservar Angular como candidato para adaptar componentes de dietas del proyecto en desarrollo; la decisión final se toma en la fase de dietas junto con la revisión de Flutter.

### 2.4. Entregables y aceptación

**Entregables:** arquitectura, modelo de datos, estructura del proyecto, contratos iniciales y decisiones de integración. Prototipos de registro por QR, compra, consulta de saldos y canje.

**Cierre:** el diseño cubre los dos tipos de referido, los descuentos, el IVA, las comisiones y los puntos por lotes. Están identificadas las precisiones comerciales necesarias para implementar cada módulo; no se eligen tasas o fórmulas por suposición.

## Fase 3. Implementar acceso, perfiles y referidos

**Depende de:** fase 2. **Cobertura:** CU-01, CU-02, CU-03, CU-06 y CU-07.

### Trabajo

- Registro, verificación de contacto y recuperación de cuenta, adaptando el flujo SMS si la revisión confirma su uso.
- Inicio de sesión, perfil y acceso separado para cliente y entrenador.
- QR para clientes y QR para entrenadores, con destinos diferenciados.
- Guardar en cookie el ID del entrenador en el recorrido del cliente.
- Persistir la atribución necesaria para pedidos y la relación A → B para los 50.000 puntos.
- Registrar los 50.000 puntos de A como pendientes en el referido cuando B completa el registro.
- Mostrar mensajes para QR inválido, registro incompleto y referencia no disponible; aplicar la política definida para usuarios existentes y varios QR.

**Entregables:** recorridos de registro operativos, ambos QR, perfiles y relaciones de referencia.

**Aceptación:** se identifica quién refirió a cada participante; el registro de B no libera los 50.000 puntos; un usuario no consulta datos privados de otro. La política de cookie y sustitución de referente está documentada.

## Fase 4. Implementar catálogo, compras, pagos y pedidos

**Depende de:** fase 3. **Cobertura:** CU-04 y CU-13.

### Trabajo

- Adaptar catálogo, detalle de producto, carrito y compra desde `gifts.nutramerican.com`.
- Integrar datos de productos, precios, IVA, disponibilidad y direcciones según la arquitectura.
- Aplicar 10% de descuento al cliente referido y 25% al entrenador que compra con dinero.
- Recalcular importes en servidor y guardar precios, descuentos, IVA y entrenador atribuido en el pedido.
- Integrar los métodos de pago seleccionados tras revisar los proyectos existentes; conservar sus respuestas y verificar la aprobación.
- Mostrar estados de compra pendiente, aprobada y rechazada, e historial de pedidos.
- Adaptar seguimiento de pedidos y envíos del backend de referencia al alcance logístico acordado.

**Entregables:** compra por ambos roles, resultado de pago persistido y consulta/seguimiento de pedidos.

**Aceptación:** se comprueban descuentos y desglose; el retorno del navegador no basta para aprobar; un pago rechazado no genera beneficios. Repetir una notificación no duplica la operación.

## Fase 5. Implementar comisiones, puntos y puntos por referido

**Depende de:** fase 4. **Cobertura:** CU-05, CU-08, CU-09, CU-10 y CU-11.

### Trabajo

- Ante la compra aprobada del cliente, calcular 5% en puntos sobre el valor con IVA incluido y la comisión (15% por defecto, o la del producto) sobre la base sin IVA.
- Guardar base, porcentaje, redondeo y referencia del pedido para explicar cada recompensa.
- Activar una sola vez los 50.000 puntos de A con la primera compra aprobada de un cliente de B; B recibe sus recompensas por esa compra.
- Crear lotes por acumulación con seis meses de vigencia y procesos de vencimiento.
- Mostrar acumulado de comisiones, puntos inactivos, disponibles, usados y caducados; adaptar la UI de `join.nutramerican.com`.
- Implementar conversión del total de comisión (comisión + mín(50% × puntos vigentes, comisión)) con vista previa, aviso y débito/crédito indivisibles.
- Implementar el corte mensual de la comisión (cron) y el registro del pago en el admin.
- Registrar ajustes sin borrar movimientos originales, conforme a la política de devoluciones acordada.

**Entregables:** cuenta de comisiones y puntos, activación de los puntos por referido, historial, vencimientos y conversión.

**Aceptación:** no hay recompensas sin aprobación ni duplicados por reintentos; cada saldo se reconstruye desde movimientos. La conversión consume el total una sola vez. Cada mes se liquida una sola vez por entrenador.

## Fase 6. Implementar canjes, operación y reportes

**Depende de:** fase 5. **Cobertura:** CU-12, reportes de requerimientos y regla 10.

### Trabajo

- Permitir seleccionar productos para canje desde la app a precio completo.
- Mostrar costo en puntos, saldo y remanente; no aplicar 25% ni permitir pago mixto.
- Consumir lotes disponibles por FIFO, conservar saldos parciales e impedir usar puntos caducados o inactivos.
- Registrar los lotes consumidos, productos, IVA y estado de entrega del canje.
- Adaptar administración para consultar pedidos, pagos, canjes y movimientos, con permisos y auditoría.
- Implementar reportes por período y entrenador: ventas e IVA, comisiones, puntos acumulados/redimidos/disponibles/caducados e IVA de canjes.
- Aplicar restitución y ajustes de operaciones canceladas según las decisiones registradas.

**Entregables:** canje completo, consulta operativa y reportes conciliables.

**Aceptación:** Bipro de 249.900 exige 249.900 puntos; un canje parcial conserva el remanente del lote; dos canjes simultáneos no gastan el mismo saldo. Los reportes explican tanto ventas como canjes y permiten localizar su operación de origen.

## Fase 7. Validar y desplegar el MVP comercial

**Depende de:** fases 3–6 y cierre de decisiones que afecten operaciones reales.

### Trabajo

- Ejecutar los escenarios de aceptación del [MVP](MVP-PremiumFitness.md).
- Probar flujo cliente referido, flujo A → B, compra propia, conversión, canje, FIFO y vencimiento.
- Verificar pagos rechazados, aprobaciones duplicadas, concurrencia, devoluciones y restituciones según política acordada.
- Revisar permisos, recuperación de acceso, navegación móvil e instalación por dispositivo si se adopta PWA.
- Preparar catálogo, integraciones, configuración, copias de seguridad, restauración y monitoreo.
- Definir usuarios del piloto, responsables de soporte, entrega y conciliación; desplegar y medir incidencias.

**Entregables:** evidencia de validación, guía operativa, entorno de piloto y seguimiento de métricas.

**Cierre:** todos los requisitos del MVP están verificados y los saldos/reportes concilian. Un piloto con conversión pendiente se identifica como parcial; la app de dietas no condiciona la entrega comercial.

## Fase 8. Desarrollar la app de dietas como SaaS — posterior al MVP

**Base:** `/home/steven/Proyectos/flutter/dietas_automaticas_flutter`.

El proyecto de referencia aporta la base de la app; falta construir el backend para que pueda servir a múltiples entrenadores. También se evaluará la alternativa de adaptación a Angular mencionada en el documento original.

### Trabajo

1. Revisar funciones y modelos de la app Flutter y los componentes Angular aprovechables; decidir qué reutilizar, adaptar o desarrollar.
2. Definir las funciones de dietas que tendrá la primera entrega, sin asumir capacidades no documentadas.
3. Diseñar backend SaaS con separación de datos por entrenador e integración de identidad.
4. Definir meta mensual de ventas, rango de puntos, costo exacto del acceso y período de renovación. Resolver la referencia a 50.000 frente a 49.800 puntos.
5. Implementar primer mes gratis y acceso por cumplimiento de meta o canje de puntos.
6. Ejecutar validación diaria de la condición mensual; la revisión diaria no debe repetir el débito de un mismo período.
7. Desactivar dietas si no cumple la meta ni canjea puntos; reactivar conforme a la condición definida. Mantener separados los permisos de dietas y los de la cuenta comercial.
8. Mostrar estado de acceso, vigencia, motivo de desactivación y alternativa para recuperar el servicio.

**Entregables:** app adaptada, backend SaaS, integración de puntos y gestión del acceso.

**Aceptación:** aislamiento entre entrenadores; mes gratis; acceso por meta; acceso por puntos; falta de saldo; evaluación diaria sin doble cobro; desactivación y reactivación verificadas. **Cobertura:** CU-14 y CU-15, reglas 8 y 11.
