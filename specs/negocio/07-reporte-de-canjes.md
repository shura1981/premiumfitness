# Reporte de canjes con puntos

Pedido de Steven (24 nov 2026): «haz el reporte de canjes». Los canjes no entran en los reportes de ventas porque se pagan con puntos, no con dinero (spec `backend/03`, 23 nov 2026): «si es reporte de ventas no tiene por qué estar los canjes, ese sería otro reporte».

## Objetivo

Que la gerencia vea en el admin los canjes de productos con puntos (CU-12), aparte de las ventas: cuántos hubo, cuántos puntos se gastaron, qué productos salieron, qué entrenadores canjean y cuáles se cancelaron.

## Alcance

- Endpoint `GET /api/admin/reports/redemptions?date_from=&date_to=`, solo para gerentes, con:
  - totales del rango: canjes efectivos, puntos canjeados, unidades, entrenadores que canjearon, canjes cancelados y sus puntos devueltos;
  - canjes y puntos por día;
  - productos más canjeados (unidades y puntos);
  - entrenadores que más canjean (canjes y puntos);
  - la lista de canjes del rango: pedido, fecha, entrenador, ciudad, unidades, puntos, estado, pedido de SAP y guía.
- Endpoint `GET /api/admin/reports/redemptions/export?date_from=&date_to=` con una fila por producto canjeado, para Excel: pedido, fecha, entrenador, documento, ciudad, producto, código, cantidad, puntos de la línea, forma de pago («Puntos»), precio completo de referencia, base sin IVA, IVA, estado, pedido de SAP y guía.
- Pantalla «Canjes» en los reportes del admin, visible solo para gerentes, con descarga a Excel.

## Fuera de alcance

- Dinero: un canje no es una venta y no aparece en los reportes de ventas (spec `backend/03`).
- Cambiar o cancelar canjes desde el reporte: se hace en el detalle del pedido (ajustes, RF-17).
- Los canjes del acceso a la app de dietas: no crean pedido (spec `integraciones/02`).

## Criterios de aceptación

1. Solo aparecen pedidos de canje (`order_type = 'redemption'`); ninguna compra.
2. Un canje efectivo es uno en estado «APROBADA»; uno cancelado por un gerente (estado «REVERSADA», spec `backend/03` P-26) no suma a los puntos canjeados y aparece aparte, con sus puntos devueltos.
3. Los puntos salen de lo guardado en cada pedido y línea (`redemption_points`); el reporte no recalcula precios.
4. Un agente del call center recibe 403; un gerente ve el reporte.
5. Un rango de fechas inválido o con «desde» mayor que «hasta» responde 422.

## Supuestos y preguntas abiertas

| # | Punto | Estado |
| --- | --- | --- |
| C-01 | La fecha de un canje es la de su aprobación (`approved_at`), que en un canje es la misma de su creación: no espera pago. | Derivado |
| C-02 | Un canje cancelado devuelve todos sus puntos al entrenador en un lote nuevo (ajustes, RF-17): «puntos devueltos» es el costo del canje cancelado. | Confirmado (código de `AdjustmentService`) |
| C-03 | Solo lo ven los gerentes (`ADMIN_MANAGER_ROLES`), como entrenadores, estrategias y comisión, porque muestra lo que gasta cada entrenador. | Derivado |
| C-04 | El Excel incluye el precio completo y el IVA de cada línea, que el canje guarda como referencia («entre más información se tenga mejor», Steven, 24 nov 2026). Son valores de referencia: no entró dinero. | Confirmado (24 nov 2026, Steven) |

## Plan

- Backend: `RedemptionReportRepository` y `RedemptionReportController` en `src/Features/Reports/`, con las rutas en `AdminRoute.php` detrás de `AdminMiddleware` y del filtro de gerentes. Las consultas filtran por `order_type = 'redemption'` y por `DATE(approved_at)`.
- Admin: página `reports/redemptions` con el mismo estilo de los demás reportes (rango de fechas, fichas de totales, tablas y Excel), en el menú de reportes solo para gerentes. Antes de construirla se muestra a Steven cómo se vería.

## Checklist

- [x] Endpoint del reporte con totales, días, productos, entrenadores y lista, solo para gerentes (`RedemptionReportController::summary`)
- [x] Endpoint de exportación por línea para Excel (`RedemptionReportController::export`)
- [x] Pruebas: solo canjes, cancelados aparte, puntos exactos, 403 para un agente y 422 con fechas inválidas (`tests/E2E/Reports/RedemptionReportTest.php`)
- [x] Mostrar a Steven el diseño de la pantalla: eligió la versión completa, como Operación (24 nov 2026)
- [x] Pantalla «Canjes» en el admin (Reportes → Canjes, `/reports/redemptions`), para los cargos que ven los reportes: fichas de totales, puntos por día, productos y entrenadores que más canjean, lista de canjes con su estado y Excel por línea. Con prueba de la página
  - Falta revisarla en el navegador contra el backend real
- [x] Contrato en `specs/backend/03-api-de-premiumfitness.plan.md`
