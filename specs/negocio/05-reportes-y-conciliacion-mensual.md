# 05 — Reportes y conciliación mensual (a partir de api-comisiones.nutramerican.com)

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> Analiza el repo `/home/steven/Proyectos/node/express/api-comisiones.nutramerican.com`. Es el que hoy paga las comisiones en el sistema de la página web; es más complejo, y en este repo vamos a simplificarlo. Revísalo y extrae lo que necesites: reportes y conciliaciones.

## Objetivo

Reutilizar lo que funciona del sistema actual de comisiones (proceso mensual, conciliación y reporte en Excel) y dejar fuera lo que lo hace frágil. Así queda definido cómo PremiumFitness cierra el mes, qué reporte genera y cómo se cuadran las cifras.

## Qué hace hoy api-comisiones

| Pieza | Cómo funciona | Archivos |
| --- | --- | --- |
| Cálculo | Cada mes se **recalcula** la comisión desde las visitas aprobadas, leyendo el JSON del pago y **los nombres de los productos**: «CUPÓN DE DESCUENTO», «FLETE», el sufijo « NN% OFF», promociones con fechas fijas en el código. La base es `(pagado − flete − camiseta) / 1.19 × 15%`, y 10% en los productos con descuento especial. | `report/services/CouponReportService.ts` |
| Proceso mensual | Un único endpoint `POST /api/reports/coupons/process` con `year` y `month` explícitos. Lo dispara el cron de cPanel el día 4 a las 08:00. Recalcula, genera el Excel, guarda los pagos por cupón, cierra la conciliación y envía el correo con un enlace. | `report/services/ProcessFlowCouponReportService.ts`, `docs/endpoint-reporte-mensual.md` |
| Conciliación | `tb_cupones_conciliaciones` (`year`, `month`, `is_closed`, `file_name`), con un único registro por año y mes. `tb_cupones_pagos` (`id_conciliacion`, `id_cupon`, `total` = usos y `amount` = comisión), con un único registro por conciliación y cupón. | `payment-coupon/` |
| Protección | Si el mes ya está cerrado responde 409 `ALREADY_PROCESSED`. Con `force: true` **recalcula y sobrescribe** un mes cerrado. También acepta `skipEmail` y `recipients`. | `docs/endpoint-reporte-mensual.md` |
| Excel | Hojas «Resumen de cupones», «Análisis de ventas», «Historial de cupones» y «Resumen de productos», más «Resumen ciudades» en el código. | `report/services/ExcelExportService.ts`, `public/reports/years/` |
| Vista del dueño del cupón | La comisión del mes en curso agrupada por día, también recalculada desde las visitas. Historial mensual desde `tb_cupones_pagos`. | `user/services/userCouponService.ts`, `conciliationDao.getCouponStats` |

## Qué se extrae

1. **Un solo punto de entrada para el cierre**, con el período explícito (`year`, `month`) e idempotente: si ya está cerrado responde 409, y `skipEmail` y `recipients` sirven para pruebas.
2. **El cron vive fuera de la aplicación.** El cron del hosting hace una sola petición o ejecuta un solo comando. Es la lección de `docs/incidente-cron-duplicado-hosting.md`: en el hosting compartido (LiteSpeed) la app corre como varios procesos, y un programador dentro de la app envió el correo dos veces. PremiumFitness va al mismo hosting.
3. **Cabecera de período más detalle por beneficiario.** Hoy son conciliación más pagos por cupón; aquí será período más liquidación por entrenador, con el número de pedidos y el total.
4. **El Excel mensual** con resumen, análisis de ventas, detalle y productos, más las hojas que exige RF-19: IVA, puntos y canjes.
5. **Correo al administrador** con el resumen y el enlace de descarga.
6. **Historial mensual** para el entrenador: lo que se le liquidó cada mes.
7. **Base de la comisión**, que confirma la del modelo: lo pagado con el descuento, sin IVA y sin envío.

## Qué no se trae (simplificación)

| Hoy | En PremiumFitness | Por qué |
| --- | --- | --- |
| Se recalcula la comisión cada mes leyendo nombres de productos, sufijos y fechas fijas en el código | Cada línea guarda al comprar su porcentaje, su base sin IVA y su comisión (`orders_items`). El cierre **suma**, no recalcula | El cálculo depende de textos del checkout y existe duplicado en la intranet sin pruebas (CLAUDE.md del repo) |
| IVA fijo `/ 1.19` | `tax_rate` por producto copiado en la línea | No todos los productos tienen el mismo IVA |
| Promociones y bonos fijos en el código (15%, 20%, 30%, CreaStack con fechas) | Variables de entorno más `product_discount_rules` con vigencia | Regla 15 y spec `negocio/02` |
| `force: true` recalcula y sobrescribe un mes cerrado | Un mes cerrado **no cambia**. `force` solo regenera el Excel y reenvía el correo. Las correcciones entran como ajustes en el mes siguiente | Lo pagado debe coincidir siempre con lo liquidado |
| Una comisión negativa se suma en silencio y reduce el total del cupón | La comisión de una línea nunca es negativa. Las devoluciones son ajustes explícitos (`adjustments`) | Trampa documentada en el CLAUDE.md del repo |
| Cierra la conciliación y el Excel es lo que se paga; no registra el pago | El Excel del cierre es lo que se paga, y financiero **registra el pago de cada liquidación** con fecha, referencia y comprobante. El entrenador lo ve en la app (spec `negocio/04`, regla 10) | Hay que poder demostrar qué se pagó y al entrenador le da tranquilidad |
| Excel en `public/` con nombre predecible (`report-coupns-7-2026.xlsx`), descargable **sin autenticación** (`reportDownloadController`: «TODO: Implement token validation») | Archivo fuera de la carpeta pública y descarga solo desde el admin autenticado | El Excel trae nombres de clientes, ciudades y comisiones |
| `tb_cupones_ventas` se llena con un script | La atribución se guarda al comprar (`orders_coupons.trainer_id`) | No hay que reconstruirla |
| Secreto estático compartido entre el login y el cron | `CRON_JOB_TOKEN` de radical, exclusivo de los crons (`CronJobTokenMiddleware`) | Si se rota uno, no rompe el otro |

## Alcance

- Tabla de período (`commission_periods`) y ajuste de `commission_settlements`.
- Proceso de cierre (CU-21): disparo, idempotencia, reporte y correo a financiero.
- Contenido del Excel mensual y cuadres de conciliación.

## Fuera de alcance

- Migrar los datos históricos de Megaplex Stars.
- La transferencia bancaria.
- Reportes para cupones que no sean de entrenadores.

## Criterios de aceptación

1. **Cierre en dos pasos.** Disparar dos veces el cierre del mismo mes produce un solo período, una sola liquidación por entrenador y un solo correo. La segunda vez responde 409.
2. **Reproceso.** `force` regenera el Excel y el correo sin cambiar ningún importe.
3. **Cuadre de comisión.** Comisión generada en el mes ± ajustes − convertida = total liquidado.
4. **Cuadre de puntos.** Saldo inicial + emitidos − canjeados − vencidos ± ajustes = saldo final.
5. **Descarga.** El Excel solo se descarga con sesión de administrador.
6. **Base de la comisión.** La comisión de cada línea sale de lo pagado con descuento, sin IVA y sin envío, con el porcentaje vigente del producto.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | Base de la comisión: lo pagado por el producto con el descuento, sin IVA y sin envío. Es la misma que usa hoy api-comisiones (`(pagado − flete) / 1.19`), pero con el IVA de cada producto. Cierra la parte de comisión de D-03. | Confirmado por el sistema en producción |
| S-02 | La comisión se guarda por línea con 2 decimales. El total a pagar de cada liquidación se redondea al peso más cercano. | Derivado (api-comisiones redondea a entero por pedido) |
| S-03 | El cierre corre el día 1 a la 01:00, hora de Colombia, sobre el mes anterior. api-comisiones lo corre el día 4 porque recalcula desde las visitas. Aquí no hace falta esperar: el mes se corta por `approved_at` y las cifras ya están guardadas. | Derivado |
| S-04 | El correo va a las encargadas de financiero (`COMMISSION_REPORT_RECIPIENTS`). Financiero registra el pago de cada liquidación con su comprobante (spec `negocio/04`). **Cambio del 10 nov 2026:** antes decía que el pago no se registraba. | Confirmado (10 nov 2026) |

## Plan

### Modelo de datos

- **Nueva tabla `commission_periods`:**
  - `id`;
  - `period` (`'2026-11'`, único), `period_start` y `period_end`;
  - `closed_at`;
  - `trainers_count`, `orders_count` y `total_amount`;
  - `file_name`, `report_sent_at`, `report_recipients`.
- **`commission_settlements`:**
  - cambia `period` por `period_id` (FK), con clave única (`period_id`, `trainer_id`);
  - añade `orders_count`;
  - estado `closed` → `paid`, con `paid_at`, `payment_reference`, el comprobante y quién lo registró (spec `negocio/04`).

### Proceso de cierre (CU-21)

1. El cron del hosting ejecuta `POST /api/admin/commission-periods/close` con `{ year, month }` y el header `X-Cron-Job-Token` (`CRON_JOB_TOKEN`, validado por `CronJobTokenMiddleware` de radical).
2. Si el período existe, responde 409 (salvo `force`, que solo regenera el Excel y el correo).
3. En una sola transacción:
   - crea el período;
   - crea una liquidación por entrenador con los movimientos sin liquidar creados hasta el fin del período (los posteriores son del mes siguiente);
   - asigna `settlement_id` a los movimientos;
   - registra el débito que deja el saldo en cero.
4. Genera el Excel fuera de la carpeta pública y guarda `file_name`.
5. Envía a `COMMISSION_REPORT_RECIPIENTS` (financiero) el correo con el resumen y un enlace que exige iniciar sesión en el admin, y guarda en el período a quién se envió.

### Excel mensual

| Hoja | Una fila por | Columnas |
| --- | --- | --- |
| Resumen por entrenador | entrenador | Nombre, documento, cupón, pedidos, ventas pagadas (con IVA), base sin IVA, IVA, comisión generada, ajustes, convertido a puntos, total a pagar |
| Análisis de ventas | entrenador y ciudad | Pedidos, base sin IVA, comisión |
| Detalle de pedidos | pedido aprobado del mes | Pedido, fecha de aprobación, cliente, ciudad, forma de pago, precio full, descuento, pagado, envío, base sin IVA, IVA, comisión, puntos, cupón y entrenador |
| Productos | entrenador y producto | Cantidad, base sin IVA, porcentaje de comisión aplicado (estrategia del producto) y comisión |
| Puntos | entrenador | Saldo inicial, emitidos por clientes, por referidos y por conversiones, canjeados, vencidos, ajustes, saldo final y pendientes por referido |
| Canjes | canje del mes | Pedido, entrenador, producto, puntos, base e IVA del canje (regla 10) |
| Cuadre | período | Los dos cuadres de los criterios 3 y 4, con su diferencia, que debe ser cero |

### Índices del reporte mensual

El cierre lee un solo mes. Además de los índices del spec `negocio/03`, usa:

- `orders (status_id, approved_at)`, para los pedidos aprobados del mes;
- `point_movements (created_at)`, para los puntos emitidos, canjeados y vencidos del mes;
- `commission_movements (settlement_id, created_at)`, para los movimientos aún sin liquidar hasta el fin del período.

### Vistas del entrenador

- Comisión del mes en curso agrupada por día: suma de `orders_items.commission_amount` por `approved_at`.
- Historial de liquidaciones por mes desde `commission_settlements`.
- Ninguna de las dos recalcula nada.

## Checklist

- [x] Revisar api-comisiones: cálculo, proceso mensual, conciliación, Excel, correo y vista del dueño del cupón
- [x] Documentar qué se extrae y qué se simplifica
- [x] Añadir `commission_periods` y ajustar `commission_settlements` en `scripts/der/generar_der.py`, y regenerar el DER
- [x] Actualizar CU-21 en `docs/casos-de-uso-y-actores.md`, y RF-19 y D-03 en el PRD
- [x] Añadir las variables del cierre a `.agents/rules/codebase-standards.md` (se usa `CRON_JOB_TOKEN` de radical en lugar de `COMMISSION_CLOSE_TOKEN`)
- [x] Definir los destinatarios del correo mensual y si se registra el pago (S-04)
- [ ] Diseñar el Excel con la skill `xlsx-report-generator` de api-comisiones como referencia (fase de codificación)
