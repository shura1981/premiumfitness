# 06 — Ventas del entrenador en el módulo de comisión

**Estado:** en curso · **Creado:** 16 de noviembre de 2026

## Petición original

> En el módulo de comisiones se debe mostrar todo lo que la app de comisiones muestra => https://join.nutramerican.com/user/home

## Objetivo

Que el entrenador vea en `/coach/comision` lo mismo que hoy ve un distribuidor en la app de comisiones de Nutramerican (`join.nutramerican.com`): cuánto vendió, dónde, qué productos y cómo le ha ido mes a mes. Hoy el módulo solo muestra el saldo, los movimientos y las liquidaciones.

## Lo que muestra la app de comisiones (revisada el 16 nov 2026)

| Sección | Contenido |
| --- | --- |
| Inicio | Comisión del mes y número de ventas; fichas de ciudades, productos y ventas de los últimos 7 días; acceso a estadísticas; últimas ventas agrupadas por día (día, número de ventas y valor). |
| Semana | Gráfico de barras de los últimos días con ventas y el detalle por día. |
| Ciudades | Totales (ciudades, compras, comisión) y ranking de ciudades con compras, comisión y valor vendido; medalla para las tres primeras. |
| Productos | Totales (productos, unidades) y ranking de productos por unidades, con su porcentaje y una barra. |
| Estadísticas | Total acumulado y transacciones, gráfico que compara los meses de cada año, y detalle por mes filtrable por año. |
| Configuración | Tema y color de acento: ya están en Cuenta. |

## Alcance

- **Backend:** dos endpoints del entrenador (solo lectura) que suman lo guardado en los pedidos y en `commission_movements`:
  - `GET /trainer/sales?period=AAAA-MM`: resumen del mes, ventas por día, ranking de ciudades y de productos.
  - `GET /trainer/sales/history`: totales históricos y cada mes con ventas.
- **App web:**
  - En `/coach/comision`, debajo del saldo: fichas de ventas del mes, ciudades, productos y ventas de los últimos 7 días, y las últimas ventas por día.
  - Pantalla `/coach/comision/ventas` con el selector de mes y cuatro vistas: Días (gráfico y detalle), Ciudades, Productos e Histórico (comparativa por año y detalle por mes).

## Fuera de alcance

- Datos de los clientes (nombres, documentos o direcciones) en estas vistas: solo ciudades y productos.
- Exportar a Excel o PDF.
- Cambiar cómo se calcula o se paga la comisión (spec `negocio/04`).

## Criterios de aceptación

1. Una venta es un pedido `client_purchase` aprobado, atribuido al entrenador en `orders_coupons`, cuya comisión neta (abono menos ajustes) es mayor que cero. Un pedido anulado por completo deja de contar.
2. La comisión de cada venta es la suma de sus movimientos `sale_credit` y `adjustment`; el valor vendido es lo que pagó el cliente sin el envío. Todo se suma de lo guardado: nada se recalcula.
3. El mes es el del calendario de la comisión (`CommissionCalendar`, hora de Bogotá), según la fecha de aprobación del pago.
4. Un entrenador solo ve sus ventas; un cliente recibe 403 y sin sesión 401.
5. Con el entrenador demo, las cifras de la pantalla coinciden con la base: ventas, comisión, ciudades y unidades.
6. Los gráficos tienen tooltip al pasar el dedo o el cursor, su detalle en lista (la vista de tabla) y funcionan en tema claro y oscuro.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| V-01 | Lo que el entrenador ve como «valor vendido» es `orders.amount − shipping_amount` (con IVA y con el descuento del cupón). | Derivado |
| V-02 | Las ventas propias del entrenador (`trainer_purchase`) y los canjes no cuentan como ventas: no generan comisión. | Derivado |
| V-03 | «Ventas de los últimos 7 días» cuenta los 7 días calendario hasta hoy, como la app de comisiones. | Derivado |
| V-04 | La comparativa por año muestra hasta los tres años más recientes con ventas, con los colores de gráfico del sistema de diseño (validados para daltonismo en los dos temas). | Derivado |

## Plan

- **Backend:** feature `Trainer`, nuevo `TrainerSalesRepository` con consultas agregadas sobre `orders`, `orders_coupons`, `commission_movements`, `orders_items`, `addresses` y `cities`, y dos acciones en `TrainerController` con `JwtMiddleware` y el filtro de rol del entrenador. Una subconsulta calcula la comisión neta por pedido y filtra las ventas con comisión positiva; todo lo demás se agrupa sobre ese conjunto.
- **App web:** modelos en `core/api/models.ts`, datos simulados en la API de desarrollo, dos componentes de gráfico en `shared/ui` (barras de una serie y barras agrupadas) hechos en SVG, sin librerías, y la pantalla de ventas en `features/trainer`.
- **Riesgo:** las consultas recorren los pedidos del entrenador; con el índice de `orders_coupons.trainer_id` y el filtro por mes alcanzan para el volumen esperado.

## Checklist

- [x] Endpoints `GET /trainer/sales` y `GET /trainer/sales/history` con su validación del mes
- [x] Pruebas del backend: definición de venta, ajustes, ciudades, productos, histórico y permisos (`tests/E2E/Trainer/TrainerSalesTest.php`)
- [x] Contrato en `specs/backend/03-api-de-premiumfitness.plan.md`
- [x] Modelos y API simulada en la app web
- [x] Componente de gráfico `pf-bar-chart` (una o varias series) con tooltip, etiqueta para lector de pantalla y pruebas
- [x] La leyenda filtra por año: tocar un año deja solo esas barras (la escala se ajusta) y filtra también la lista; tocarlo otra vez muestra todos. Las marcas del eje van dentro del gráfico para que use todo el ancho
- [x] Fichas y últimas ventas en `/coach/comision`
- [x] Pantalla `/coach/comision/ventas`: Días, Ciudades, Productos e Histórico
- [x] Revisión en el navegador con el entrenador demo, en tema claro y oscuro, con `php scripts/dev-sembrar-demo.php --historial` para tener 12 meses y 8 ciudades
- [x] Corrección encontrada en el camino: el reporte de productos del admin fallaba si un producto vendido ya no estaba en el catálogo o no tenía categoría (ahora sale sin categoría; prueba en `ReportsRepositoryTest`)
