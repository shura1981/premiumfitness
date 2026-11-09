# 01 — Casos de uso de procesamiento, envío y seguimiento del pedido

**Estado:** en revisión · **Creado:** 7 de noviembre de 2026

## Petición original

> Primero actualiza los casos de uso y actores porque no tocamos ni los envíos ni el procesamiento del pedido.

## Objetivo

Dejar documentados en `docs/casos-de-uso-y-actores.md` el procesamiento y el envío del pedido, y el seguimiento que ve el comprador. PremiumFitness replica el flujo de `backend-radical-nutrition`: el agente del call center procesa cada pedido en el admin, lo registra en SAP Business One y carga la guía. Sin estos casos de uso, el DER (`specs/diseño/01-DER.md`) y el MVP no tienen un recorrido que los respalde.

## Alcance

- Nuevo actor: agente del call center (admin), autenticado con la intranet.
- Nuevos sistemas de apoyo: SAP Business One, transportadoras, intranet y pasarela de pagos (esta última derivada de radical).
- Nuevas reglas de negocio para la asignación del agente, el registro en SAP, la bodega de despacho, la guía y el seguimiento.
- Nuevos casos de uso: tomar o liberar el pedido, registrarlo en SAP, registrar el envío y consultar el seguimiento.
- Ajustes en los casos de uso de compra y canje (CU-04, CU-12 y CU-13), en los recorridos, en las decisiones pendientes y en la cobertura.

## Fuera de alcance

- Redibujar `docs/img/actores-y-casos-de-uso.png` y su SVG. Queda como tarea del checklist.
- Actualizar `docs/requerimientos.md`, el PRD y el MVP. Lo hace la persona responsable del producto o un spec aparte.
- Los reportes del admin (ventas, productos, clientes y transportadoras), que existen en radical.

## Criterios de aceptación

1. El documento incluye el actor agente del call center y explica que no es un usuario del portal.
2. Cada regla y caso de uso nuevo está clasificado. Lo confirmado por el usuario y ausente de `requerimientos.md` aparece como **Confirmado por producto**, con fecha.
3. Existen casos de uso para tomar o liberar el pedido, registrarlo en SAP, registrar el envío y consultar el seguimiento, con flujo, alternativas y criterio de aceptación.
4. Los recorridos de compra y canje continúan hasta el envío.
5. Lo que falta definir aparece en la tabla de decisiones pendientes: canjes en SAP, costo del envío, historial de estados, intranet compartida y envío automático a SAP.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | Procesamiento del pedido por el agente, registro en SAP, guía y seguimiento: se confirman por indicación del usuario (7 nov 2026) y siguen a radical. | Confirmado |
| S-02 | `requerimientos.md` no menciona estos procesos. Se añaden como decisiones de producto, sin contradecir ese documento. | Confirmado |
| S-03 | El agente solo libera un pedido suyo que todavía no tenga código SAP, como en radical. | Derivado |
| S-04 | La aprobación del pago llega por webhook de la pasarela, como en radical. El proveedor sigue pendiente (D-10). | Derivado |
| S-05 | Si los canjes también pasan por SAP y por el call center. | Pendiente |
| S-06 | Si se cobra el envío y cómo. En el checkout retail de radical, el flete se cobra como un producto interno, «Flete». | Pendiente |

## Checklist

- [x] Revisar en radical el admin del call center, la gestión del pedido, la transportadora y el seguimiento
- [x] Añadir el actor agente y los sistemas de apoyo en `docs/casos-de-uso-y-actores.md`
- [x] Añadir las reglas RN-14 a RN-19
- [x] Añadir y especificar CU-16 a CU-19
- [x] Ajustar CU-04, CU-12, CU-13, los recorridos, las decisiones pendientes y la cobertura
- [ ] Redibujar el diagrama de actores y casos de uso (PNG y SVG) con el agente y CU-16 a CU-19
- [ ] Registrar estos procesos en `docs/requerimientos.md`, el PRD y el MVP
