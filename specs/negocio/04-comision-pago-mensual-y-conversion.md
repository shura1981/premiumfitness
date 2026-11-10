# 04 — Comisión: pago mensual en dinero y conversión a puntos

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> La comisión es un dinero que la empresa debe pagarle al entrenador al final de cada mes (esto hay que definirlo en términos y condiciones). Para canjear la comisión por producto debe convertirla a puntos (esa funcionalidad debe estar bien definida en el frontend de la app del entrenador). No puede haber canje mixto: no puede usar plata y puntos.
>
> Convertir la comisión en puntos: al hacerlo, le suma el 50% de lo que tenga actualmente vigente en puntos. Si tiene 100.000 puntos y 100.000 pesos de comisión, al convertir queda con 200.000 puntos más el 50% de lo que tenía (50.000): 250.000 puntos.
>
> Para evitar que el 50% se multiplique con conversiones repetidas: el 50% adicional no puede superar la comisión convertida.

## Objetivo

Definir qué hace el entrenador con su comisión, cómo se convierte a puntos y cómo se paga en dinero a fin de mes lo que no convirtió.

## Alcance

- Saldo de comisión, conversión a puntos con el 50% adicional y su tope.
- Corte y liquidación mensual de la comisión no convertida.
- La pantalla de conversión en la app del entrenador.
- Cambios en el modelo de datos y en los documentos.

## Fuera de alcance

- La transferencia bancaria. La empresa la hace por fuera de la app y después la registra en el admin con su comprobante (regla 10).
- La redacción de los términos y condiciones.

## Reglas

1. **Saldo de comisión.** Es la suma de los movimientos de comisión del entrenador que todavía no se han liquidado:
   - los abonos por ventas aprobadas;
   - menos lo convertido a puntos;
   - más o menos los ajustes.
2. **Usos de la comisión.** La comisión solo tiene dos destinos:
   - convertirla a puntos en cualquier momento del mes;
   - recibirla en dinero al final del mes.

   No se usa directamente para canjear productos.
3. **Canje.** Se paga solo con puntos, a precio completo. No hay canje mixto de dinero y puntos.
4. **Conversión:**
   - siempre es por el total del saldo de comisión, nunca parcial;
   - puntos recibidos = `C + mín(50% × P, C)`;
   - `C` es el saldo de comisión que se convierte;
   - `P` son los puntos vigentes del entrenador en ese momento, de todos los orígenes. Los 50.000 puntos por referido pendientes no cuentan.
   - el resultado se redondea al entero más cercano (0,5 sube).
5. **Ejemplos de conversión:**

   | Puntos vigentes | Comisión | 50% de los puntos | Adicional (con tope) | Recibe | Queda con |
   | --- | --- | --- | --- | --- | --- |
   | 100.000 | 100.000 | 50.000 | 50.000 | 150.000 | 250.000 |
   | 1.000.000 | 1.000 | 500.000 | 1.000 | 2.000 | 1.002.000 |
   | 0 | 80.000 | 0 | 0 | 80.000 | 80.000 |

6. **Lote de la conversión.** Los puntos recibidos forman un lote nuevo que vence a los 6 meses.
7. **Corte mensual.**
   - **Hora del corte:** el último día del mes a las 23:59:59, hora de Colombia.
   - **Qué entra:** solo los movimientos creados hasta esa hora. Una venta aprobada el día 1 a las 00:30 pertenece al mes nuevo, aunque el cierre corra después.
   - **Cuándo corre:** el día 1 a la 01:00. A esa hora el saldo de comisión vuelve a $0 y la comisión del mes anterior pasa al historial como liquidada, para que financiero la pague.
   - **Conversión bloqueada:** desde las 00:00 del día 1 hasta que termina el cierre, el entrenador no puede convertir. Así no consume comisión del mes que se está liquidando.
   - **Después del cierre:** esa comisión ya no se puede convertir.
8. **Devolución después del corte.** Genera un ajuste negativo que se descuenta del mes siguiente. Si el saldo de un mes queda negativo, no se paga nada y la diferencia pasa al siguiente.
10. **Pago y comprobante.** La comisión de un mes se paga **el día 4 del mes siguiente** (`COMMISSION_PAYMENT_DAY`), **en un solo pago** por liquidación. Si el 4 cae en sábado, domingo o festivo, se paga el **siguiente día hábil** (por ejemplo, el domingo 4 oct 2026 pasa al lunes 5 oct). El día, el sentido del corrimiento y la lista de festivos son variables de entorno (S-11). El cierre calcula esa fecha, la guarda en la liquidación (`due_date`) y es la que ve el entrenador. Financiero paga por fuera de la app lo que dice el Excel del cierre y registra el pago en el admin, liquidación por liquidación:
    - fecha del pago, referencia de la transacción y el comprobante (captura o PDF);
    - la liquidación pasa de `closed` a `paid`; queda registrado quién lo hizo y cuándo (`audit_logs`);
    - el entrenador recibe una notificación push y ve el comprobante en la app;
    - un pago mal registrado se corrige desde el admin (otro comprobante u otra fecha) y queda auditado; no se borra.
    - si termina el día de pago (`due_date`) sin registrar el pago de una liquidación, el admin la marca como **vencida** en Cierre mensual, para que financiero la revise.
11. **Constantes.** El 50% (`COMMISSION_CONVERSION_PERCENTAGE`) es una variable de entorno. Cada conversión guarda el porcentaje, los puntos base, el adicional y el total.

## Pantalla de conversión (app del entrenador)

1. **Antes de confirmar**, muestra:
   - el saldo de comisión que se va a convertir;
   - los puntos vigentes;
   - el 50% adicional ya calculado con su tope;
   - los puntos que recibirá;
   - cómo queda su saldo de puntos;
   - la fecha de vencimiento del nuevo lote.
2. **Aviso explícito:** «La comisión que conviertas a puntos no se te pagará en dinero al final del mes. Esta operación no se puede deshacer.»
3. **Confirmación:** se hace con un botón; no hay campo de importe, porque la conversión es por el total.
4. **Si el saldo cambió antes de confirmar** (por ejemplo, entró una venta o venció un lote), el servidor recalcula y pide confirmar de nuevo.
5. **Durante el corte**, desde las 00:00 del día 1 hasta que termina el cierre, la conversión queda bloqueada con el mensaje «Estamos cerrando la comisión del mes. Vuelve a intentarlo en unos minutos».

## Qué ve el entrenador

| Momento | En Inicio | En Comisión |
| --- | --- | --- |
| Durante el mes | El saldo del mes y el aviso «Si no la conviertes antes del 30 nov, te la pagamos el 4 dic» | Los abonos por pedido y las conversiones del mes |
| Después de convertir | $0 y la conversión hecha | La conversión en el historial |
| Entre las 00:00 y el fin del cierre del día 1 | El saldo del mes nuevo; el botón Convertir, desactivado con el mensaje del corte | Igual |
| Después del cierre | El saldo del mes nuevo (puede ser $0) y la línea «Octubre · $100.000 liquidados · se pagan el 4 nov» | La liquidación de octubre en el historial, con el número de pedidos, la comisión, lo convertido, los ajustes y el total |
| Cuando financiero registra el pago | La línea cambia a «Octubre · $100.000 pagados el 5 nov» y llega una notificación push | La liquidación aparece como pagada, con la fecha, la referencia y el botón «Ver comprobante» |

La línea del último cierre en Inicio se mantiene hasta el cierre siguiente.

## Criterios de aceptación

1. Con 100.000 puntos vigentes y 100.000 de comisión, la conversión entrega 150.000 puntos y el entrenador queda con 250.000.
2. Con 1.000.000 de puntos vigentes y 1.000 de comisión, entrega 2.000 puntos.
3. Una solicitud repetida no convierte dos veces.
4. Un canje no acepta comisión ni pago mixto.
5. Al corte, cada entrenador con saldo positivo tiene una liquidación del mes y su saldo queda en cero. Ninguna comisión se liquida dos veces.
6. Una devolución posterior al corte se descuenta en la liquidación siguiente.
7. Una venta aprobada el día 1 a las 00:30 queda en la liquidación del mes nuevo, no en la del mes que se cierra.
8. Una conversión intentada entre las 00:00 y el fin del cierre del día 1 se rechaza con el mensaje del corte.
9. Al registrar el pago con su comprobante, la liquidación queda `paid`, el entrenador recibe el push y ve el comprobante. Otro entrenador recibe 403 al pedir ese comprobante.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | La comisión es dinero que se paga al final de cada mes; para canjear productos hay que convertirla a puntos; no hay canje mixto. | Confirmado (9 nov 2026) |
| S-02 | Fórmula `C + mín(50% × P, C)` con `P` = puntos vigentes. | Confirmado (9 nov 2026) |
| S-03 | `P` incluye los puntos vigentes de todos los orígenes (clientes, referidos y conversiones anteriores), pero no los 50.000 pendientes. | Derivado de «lo que tenga actualmente vigente en puntos» |
| S-04 | Corte el último día del mes, hora de Colombia. El cron del hosting lo dispara el día 1 a la 01:00 con una sola petición (spec `negocio/05`). | Derivado (D-01) |
| S-05 | Financiero paga por fuera de la app lo que dice el Excel del cierre y registra el pago en el admin con fecha, referencia y comprobante. El entrenador lo ve en la app. **Cambio del 10 nov 2026:** antes decía que el pago no se registraba. | Confirmado (10 nov 2026) |
| S-06 | Plazo de pago: el día 4 del mes siguiente; si no es día hábil, el siguiente día hábil (confirmado el 10 nov 2026). Pendientes para los términos y condiciones: medio de pago, mínimo para pagar y retenciones o impuestos. | Pendiente: términos y condiciones |
| S-07 | Ajuste negativo y saldo negativo trasladado al mes siguiente. | Derivado |
| S-08 | Cada liquidación se paga en un solo pago, con un comprobante. No hay pagos parciales. | Confirmado (10 nov 2026) |
| S-09 | Comprobante: imagen JPG, PNG o WebP, o PDF, de máximo 5 MB, validado por su contenido real (no por la extensión) y guardado en `storage/private/receipts/`. Lo ven el admin y el entrenador dueño de la liquidación. | Derivado |
| S-11 | Día hábil: de lunes a viernes, sin los festivos. Todo es configurable porque puede cambiar: el día (`COMMISSION_PAYMENT_DAY=4`), hacia dónde se mueve si no es hábil (`COMMISSION_PAYMENT_SHIFT=next`, o `previous`) y los festivos (`NON_BUSINESS_DAYS`, lista de fechas `AAAA-MM-DD` separadas por comas, cargada con los festivos de Colombia de 2026 y 2027). Nada de esto está fijo en el código. Al arrancar se valida el formato; si al cerrar falta el año de la fecha de pago en `NON_BUSINESS_DAYS`, el cierre sigue con fines de semana y avisa a financiero en el correo del cierre para que se actualice la variable. | Confirmado que va en variables de entorno (10 nov 2026); el detalle es Derivado |
| S-10 | Quién registra el pago: una persona de financiero con usuario en `tb_empleados` (inicia sesión en el admin). | Derivado: confirmar que financiero tiene usuario en la intranet |

**Diferencias con `docs/requerimientos.md`:** el original no dice que la comisión se pague en dinero cada mes; dice que el entrenador «puede convertir el valor del total de la comisión en puntos». El pago mensual, el tope del 50% y la exclusión del canje mixto con comisión son decisiones de producto del 9 nov 2026. El ejemplo del original (100.000 puntos → 50.000 puntos) coincide con el 50% sobre los puntos vigentes.

## Plan

**Modelo de datos:**

- `commission_conversions` añade `base_points` (`P`) y `bonus_points` (adicional con tope). Ya tenía `commission_amount`, `conversion_percentage` y `points_granted`.
- Nueva tabla `commission_settlements`, una fila por entrenador y mes, con clave única (`period_id`, `trainer_id`); el período vive en `commission_periods` (spec `negocio/05`):
  - `commission_amount`, `conversions_amount`, `adjustments_amount` y `total_amount`;
  - `closed_at`. Sin estado de pago (S-05).
- `commission_movements` añade `settlement_id` (FK, NULL) y el tipo `settlement_debit`.

**Conversión.** Ocurre en una transacción con la fila del entrenador bloqueada:

1. Calcular `C` y `P`.
2. Crear `commission_conversions`.
3. Crear el débito de comisión.
4. Crear el lote de puntos y su movimiento.

Lo protege `idempotency_key`.

**Corte.** Por cada entrenador:

1. Bloquear.
2. Sumar los movimientos sin `settlement_id` con `created_at` hasta el fin del período (último día, 23:59:59). Los posteriores quedan para el mes siguiente.
3. Crear la liquidación.
4. Asignar `settlement_id` a esos movimientos.
5. Si el total es positivo, registrar el `settlement_debit` que deja el saldo en cero.

La clave única de `commission_periods.period` y de (`period_id`, `trainer_id`) evita liquidar dos veces el mismo mes.

**Pago.** `commission_settlements` añade `status` (`closed` o `paid`), `paid_at`, `payment_reference`, `receipt_path`, `receipt_mime` y `paid_by_agent_id` (agente de `tb_empleados`). Endpoints: `POST /api/admin/commission-settlements/{id}/payment` (multipart con el comprobante) y `GET /api/commission-settlements/{id}/receipt`, que solo responde al admin o al entrenador dueño. Al registrar el pago se crea la notificación push `commission_paid` en la misma transacción.

## Checklist

- [x] Añadir `commission_settlements`, `commission_movements.settlement_id` y el tipo `settlement_debit`, y `base_points` y `bonus_points` en `commission_conversions`, en `scripts/der/generar_der.py`
- [x] Regenerar el DER y actualizar `specs/diseño/01-DER.md`
- [x] Añadir las reglas de comisión y conversión en `docs/reglas-de-negocio.md`
- [x] Actualizar RN-11, CU-10, CU-11, la interpretación de importes, PD-07 y PD-14, y añadir el caso de la liquidación mensual en `docs/casos-de-uso-y-actores.md`
- [x] Actualizar PRD (§6.5, RF-12, RF-22, D-04, D-08), MVP y fases
- [x] Corregir el spec `negocio/03` (decía que no había liquidación mensual)
- [x] Registrar el pago con comprobante y avisar al entrenador (regla 10, S-05)
- [ ] Leer y validar `COMMISSION_PAYMENT_SHIFT` y `NON_BUSINESS_DAYS` en `load/bootstrap.php` (S-11)
- [ ] Calcular la fecha de pago con esas variables, con pruebas de 2026 y 2027 (4 oct 2026 → 5 oct; 4 dic 2027 → 6 dic) y del aviso cuando falta el año
- [ ] Guardar `due_date` en `commission_settlements` al cerrar y usarla en la app, el admin y la marca de vencida
- [ ] Redactar los términos y condiciones del pago de la comisión (S-06)
