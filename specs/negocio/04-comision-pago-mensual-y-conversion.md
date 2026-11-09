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

- La transferencia bancaria. La empresa paga por fuera de la app; la app calcula la liquidación y registra el pago.
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
7. **Corte mensual.** El corte es el último día del mes a las 23:59:59, hora de Colombia. Lo que quede en el saldo de comisión se liquida para pagarse en dinero, y el saldo queda en cero. Desde ese momento, esa comisión ya no se puede convertir.
8. **Devolución después del corte.** Genera un ajuste negativo que se descuenta del mes siguiente. Si el saldo de un mes queda negativo, no se paga nada y la diferencia pasa al siguiente.
9. **Constantes.** El 50% (`COMMISSION_CONVERSION_PERCENTAGE`) es una variable de entorno. Cada conversión guarda el porcentaje, los puntos base, el adicional y el total.

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
5. **Durante el corte mensual**, la conversión queda bloqueada unos minutos con un mensaje.

## Criterios de aceptación

1. Con 100.000 puntos vigentes y 100.000 de comisión, la conversión entrega 150.000 puntos y el entrenador queda con 250.000.
2. Con 1.000.000 de puntos vigentes y 1.000 de comisión, entrega 2.000 puntos.
3. Una solicitud repetida no convierte dos veces.
4. Un canje no acepta comisión ni pago mixto.
5. Al corte, cada entrenador con saldo positivo tiene una liquidación del mes y su saldo queda en cero. Ninguna comisión se liquida dos veces.
6. Una devolución posterior al corte se descuenta en la liquidación siguiente.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | La comisión es dinero que se paga al final de cada mes; para canjear productos hay que convertirla a puntos; no hay canje mixto. | Confirmado (9 nov 2026) |
| S-02 | Fórmula `C + mín(50% × P, C)` con `P` = puntos vigentes. | Confirmado (9 nov 2026) |
| S-03 | `P` incluye los puntos vigentes de todos los orígenes (clientes, referidos y conversiones anteriores), pero no los 50.000 pendientes. | Derivado de «lo que tenga actualmente vigente en puntos» |
| S-04 | Corte el último día del mes, hora de Colombia. El cron del hosting lo dispara el día 1 a la 01:00 con una sola petición (spec `negocio/05`). | Derivado (D-01) |
| S-05 | La empresa paga por fuera de la app. Un usuario del admin registra la fecha y la referencia del pago. | Derivado |
| S-06 | Medio y plazo de pago, mínimo para pagar y retenciones o impuestos sobre la comisión. | Pendiente: términos y condiciones |
| S-07 | Ajuste negativo y saldo negativo trasladado al mes siguiente. | Derivado |

**Diferencias con `docs/requerimientos.md`:** el original no dice que la comisión se pague en dinero cada mes; dice que el entrenador «puede convertir el valor del total de la comisión en puntos». El pago mensual, el tope del 50% y la exclusión del canje mixto con comisión son decisiones de producto del 9 nov 2026. El ejemplo del original (100.000 puntos → 50.000 puntos) coincide con el 50% sobre los puntos vigentes.

## Plan

**Modelo de datos:**

- `commission_conversions` añade `base_points` (`P`) y `bonus_points` (adicional con tope). Ya tenía `commission_amount`, `conversion_percentage` y `points_granted`.
- Nueva tabla `commission_settlements`, una fila por entrenador y mes, con clave única (`period_id`, `trainer_id`); el período vive en `commission_periods` (spec `negocio/05`):
  - `commission_amount`, `conversions_amount`, `adjustments_amount` y `total_amount`;
  - `status` (`closed` o `paid`);
  - `closed_at`, `paid_at`, `payment_reference` y `agent_id`.
- `commission_movements` añade `settlement_id` (FK, NULL) y el tipo `settlement_debit`.

**Conversión.** Ocurre en una transacción con la fila del entrenador bloqueada:

1. Calcular `C` y `P`.
2. Crear `commission_conversions`.
3. Crear el débito de comisión.
4. Crear el lote de puntos y su movimiento.

Lo protege `idempotency_key`.

**Corte.** Por cada entrenador:

1. Bloquear.
2. Sumar los movimientos sin `settlement_id`.
3. Crear la liquidación.
4. Asignar `settlement_id` a esos movimientos.
5. Si el total es positivo, registrar el `settlement_debit` que deja el saldo en cero.

La clave única de `commission_periods.period` y de (`period_id`, `trainer_id`) evita liquidar dos veces el mismo mes.

## Checklist

- [x] Añadir `commission_settlements`, `commission_movements.settlement_id` y el tipo `settlement_debit`, y `base_points` y `bonus_points` en `commission_conversions`, en `scripts/der/generar_der.py`
- [x] Regenerar el DER y actualizar `specs/diseño/01-DER.md`
- [x] Añadir las reglas de comisión y conversión en `docs/reglas-de-negocio.md`
- [x] Actualizar RN-11, CU-10, CU-11, la interpretación de importes, PD-07 y PD-14, y añadir el caso de la liquidación mensual en `docs/casos-de-uso-y-actores.md`
- [x] Actualizar PRD (§6.5, RF-12, RF-22, D-04, D-08), MVP y fases
- [x] Corregir el spec `negocio/03` (decía que no había liquidación mensual)
- [ ] Redactar los términos y condiciones del pago de la comisión (S-06)
