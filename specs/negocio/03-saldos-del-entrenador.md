# 03 — Saldos del entrenador: puntos por clientes, puntos por referidos y comisión

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> No es al cierre de mes: la comisión se usa cuando el entrenador la convierte a puntos o canjea productos. El entrenador siempre debe ver el total de puntos acumulados por las compras de sus clientes y el total de puntos vigentes de los entrenadores a los que ha dado el link. El sistema debe ser óptimo para esas consultas.

## Objetivo

Que el entrenador vea en todo momento, y con una respuesta inmediata:

- los puntos que ha ganado por las compras de sus clientes;
- los puntos de los entrenadores que refirió con su enlace;
- la comisión que puede convertir.

## Alcance

- Qué cifras ve el entrenador y de dónde sale cada una.
- Las consultas y los índices que las resuelven sin recorrer tablas completas.
- Ajustes a CU-09, CU-10, PD-14, D-08 y RF-11.

## Fuera de alcance

- La liquidación mensual de la comisión (spec `negocio/04`).
- El diseño visual del dashboard.
- La fórmula de la conversión (D-04).

## Criterios de aceptación

1. El entrenador ve sus puntos de clientes en dos cifras: el total acumulado histórico y los vigentes (disponibles y no vencidos).
2. El entrenador ve sus puntos de referidos en tres cifras: los vigentes, los pendientes y el detalle por cada entrenador B (pendiente, activo con su remanente, usado o vencido).
3. El entrenador ve su saldo de comisión sin liquidar, que puede convertir o que se le pagará al final del mes.
4. Cada cifra se obtiene con una consulta que usa un índice que la cubre, sin recorrer las tablas completas.
5. Los puntos vigentes son correctos aunque el proceso nocturno de vencimiento no haya corrido todavía.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | La comisión se convierte a puntos (CU-11) o se paga en dinero al final del mes (CU-21); los puntos se canjean por productos (CU-12) y no hay canje mixto. **Corregido el 9 nov 2026:** antes decía que no había pago en dinero ni cierre de mes. | Confirmado (9 nov 2026); ver spec `negocio/04` |
| S-02 | «Vigente» significa `status = 'available'` y `expires_at > NOW()`. Se calcula al consultar, así que no depende de que ya haya corrido el proceso de vencimiento. | Derivado |
| S-03 | No se crea una tabla de saldos precalculados. Las cifras se obtienen sumando los lotes con índices que cubren la consulta. | Derivado: ver Plan |
| S-04 | Los puntos por referido pendientes suman `trainer_referrals.referral_points` donde `activated_at IS NULL`. No son lotes y no suman al saldo canjeable. | Confirmado (spec `negocio/02`) |
| S-05 | El total histórico de puntos de clientes suma `initial_points` de los lotes con `source = 'client_sale'`, incluidos los ya usados y los vencidos. No descuenta los lotes revertidos por devolución, que se ven en sus movimientos de ajuste. | Derivado |
| S-06 | El saldo de comisión es la suma de `commission_movements.amount` sin liquidar: abonos por venta menos conversiones, más o menos ajustes. El débito de la liquidación mensual lo deja en cero. | Confirmado (S-17 del DER; spec `negocio/04`) |

## Plan

### Por qué no se usa una tabla de saldos

Una tabla de saldos (`trainer_balances`) ahorraría una suma, pero tiene dos costos:

- hay que actualizarla en cada abono, canje, conversión, ajuste y vencimiento;
- los puntos vencidos solo se reflejan cuando corre el proceso nocturno de vencimiento.

El volumen es pequeño: cada entrenador acumula como mucho unos cientos de lotes al año, y la mayoría vencen a los seis meses. Con un índice que cubre la consulta, MariaDB responde leyendo solo las entradas del índice de ese entrenador, sin tocar las filas. Si el volumen creciera, la tabla de saldos se añade después sin cambiar el modelo.

### Consultas del dashboard

```sql
-- Puntos vigentes por origen (clientes y referidos)
SELECT source, SUM(remaining_points) AS points
FROM point_lots
WHERE trainer_id = ? AND status = 'available' AND expires_at > NOW()
GROUP BY source;

-- Total histórico acumulado por origen
SELECT source, SUM(initial_points) AS points
FROM point_lots
WHERE trainer_id = ?
GROUP BY source;

-- Puntos por referido pendientes
SELECT COUNT(*) AS trainers, COALESCE(SUM(referral_points), 0) AS points
FROM trainer_referrals
WHERE referrer_id = ? AND activated_at IS NULL;

-- Detalle por entrenador B
SELECT tr.referred_id, u.full_name, tr.registered_at, tr.activated_at,
       pl.remaining_points, pl.status, pl.expires_at
FROM trainer_referrals tr
JOIN users u ON u.id = tr.referred_id
LEFT JOIN point_lots pl ON pl.trainer_referral_id = tr.id
WHERE tr.referrer_id = ?
ORDER BY tr.registered_at DESC
LIMIT ? OFFSET ?;

-- Saldo de comisión convertible
SELECT COALESCE(SUM(amount), 0) AS commission_balance
FROM commission_movements
WHERE trainer_id = ?;
```

### Índices

Las columnas de cada índice siguen el orden de los filtros: primero las igualdades y después el rango. Las columnas que se suman van al final, para que la consulta se resuelva solo con el índice.

| Tabla | Índice | Resuelve |
| --- | --- | --- |
| `point_lots` | `ix_point_lots_balance (trainer_id, status, expires_at, source, remaining_points)` | Puntos vigentes por origen. |
| `point_lots` | `ix_point_lots_history (trainer_id, source, accrued_at, initial_points)` | Total histórico por origen y puntos ganados en un mes (meta de dietas, spec `integraciones/02`). |
| `point_lots` | `ix_point_lots_fifo (trainer_id, status, accrued_at, id)` | Consumo FIFO al canjear. |
| `point_lots` | `ix_point_lots_expiration (status, expires_at)` | Proceso nocturno que marca los lotes vencidos: lee solo los disponibles cuya fecha ya pasó. |
| `trainer_referrals` | `ix_trainer_referrals_pending (referrer_id, activated_at, referral_points)` | Pendientes y detalle por B. |
| `commission_movements` | `ix_commission_movements_balance (trainer_id, amount)` | Saldo de comisión. |
| `orders_coupons` | `ix_orders_coupons_trainer (trainer_id, order_id)` | Ventas atribuidas al entrenador. |

Los índices únicos que ya existen (`trainer_referral_id`, `source_key` y otros) siguen igual.

## Checklist

- [x] Documentar las cifras del dashboard, las consultas y los índices en este spec
- [x] Registrar los índices y el supuesto de no usar tabla de saldos en `specs/diseño/01-DER.md`
- [x] Actualizar CU-09 y CU-10, y resolver PD-14 en `docs/casos-de-uso-y-actores.md`
- [x] Actualizar RF-11 y resolver D-08 en el PRD
- [ ] Crear los índices en la migración inicial (fase de codificación)
- [ ] Probar las consultas con `EXPLAIN` sobre datos de prueba y confirmar que usan solo el índice
