# 02 — 50.000 puntos por entrenador referido y constantes de negocio en variables de entorno

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> 1. Es una sola vez: no se le dan 50.000 puntos por cada compra de los clientes del entrenador B.
> 2. Tiene que ser un pago aprobado. No tiene monto mínimo.
> 3. Para activar los 50.000 puntos del entrenador A, debe comprar un cliente del entrenador B. La compra propia de B no los activa.
> 4. Si se cancela o devuelve la compra que los activó, se aplica la recomendación: si A no los ha usado, se revierten y el referido vuelve a quedar pendiente; si ya los usó, se mantienen.
> 5. Los 50.000 puntos pendientes no caducan.
> 6. Son 50.000 puntos (no es un bono ni dinero).
>
> Todas las constantes deben ir en las variables de entorno: los 50.000 puntos, el porcentaje de puntos en compras de clientes y el porcentaje del entrenador.

## Objetivo

Dejar definido, sin ambigüedades, cómo gana el entrenador A los 50.000 puntos por referir al entrenador B. También se fija dónde viven las constantes del negocio para que el equipo pueda empezar a codificar.

## Alcance

- La regla de los 50.000 puntos por referido: cuándo quedan pendientes, qué compra los activa, que se den una sola vez, qué pasa ante una devolución y cuándo vencen.
- Las constantes del negocio como variables de entorno validadas al iniciar la aplicación.
- La actualización de las reglas de negocio, los casos de uso, el PRD, el MVP, las fases, los estándares de código y el DER.

## Fuera de alcance

- La fórmula de la conversión de la comisión (D-04). Solo se fija que su porcentaje es una variable de entorno.
- La administración de las estrategias por producto (`product_discount_rules`). Son datos del catálogo, no constantes.
- La regla de dietas.

## Criterios de aceptación

1. Los documentos llaman a esta recompensa «50.000 puntos por referido», no «bono» ni «incentivo».
2. Está documentado que los puntos se activan una sola vez, con la primera compra aprobada de cualquier cliente de B, sin monto mínimo. La compra propia de B no los activa.
3. Está documentado qué pasa si se cancela o devuelve la compra que los activó.
4. Los 50.000 puntos pendientes no caducan. Una vez activados, vencen a los seis meses como cualquier otra acumulación.
5. Las constantes están listadas como variables de entorno, y cada pedido, referido y conversión guarda el valor que aplicó.
6. El DER no tiene una tabla de configuración de reglas (`rule_versions`).

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | Al registrarse B desde el enlace de A, se crea `trainer_referrals` con `referral_points` igual al valor vigente de `TRAINER_REFERRAL_POINTS` (50.000). Todavía no se crea ningún lote de puntos. | Confirmado (9 nov 2026) |
| S-02 | Los 50.000 puntos se activan con la primera compra **aprobada** de cualquier cliente asociado a B, sin monto mínimo. Se activan una sola vez por referido. | Confirmado (9 nov 2026) |
| S-03 | La compra propia de B (`trainer_purchase`) no activa los puntos de A. | Confirmado (9 nov 2026) |
| S-04 | Al activarse, se guarda `activation_order_id` y `activated_at`. En la misma transacción se crea el lote de A en `point_lots` (`source='trainer_referral'`, disponible) y su movimiento de abono. | Confirmado (9 nov 2026) |
| S-05 | Los puntos pendientes no caducan. Una vez activados son un lote como cualquier otro: vencen a los seis meses de la activación y se consumen por FIFO. | Pendientes: Confirmado. Vencimiento tras activarse: Derivado de la regla 2 («6 meses por cada acumulación»). |
| S-06 | Si se cancela o devuelve la compra que activó los puntos y A no los ha usado, el lote se revierte y el referido vuelve a quedar pendiente, de modo que la siguiente compra aprobada de un cliente de B los activa. Si A ya los usó, se mantienen. | Confirmado (9 nov 2026) |
| S-07 | Si A usó solo una parte, se revierte el remanente y se mantiene lo usado. | Derivado: aplica la misma regla por partes. |
| S-08 | Las constantes del negocio son variables de entorno validadas al iniciar la aplicación. Cambiarlas exige reiniciar el servicio. | Confirmado (9 nov 2026) |
| S-09 | Las estrategias por producto (como Nutra Balance) siguen en `product_discount_rules`, porque son datos del catálogo. | Confirmado (regla 15) |
| S-10 | Cada registro guarda el valor que aplicó, para que un cambio de las variables no altere el histórico ni los reportes. Lo guardan `orders_items.discount_percentage`, `orders_items.commission_percentage`, `orders.reward_percentage`, `trainer_referrals.referral_points` y `commission_conversions.conversion_percentage`. | Derivado |

**Diferencias con `docs/requerimientos.md`:** el original dice «50.000 mil puntos» y que «se activan cuando el entrenador gestione una venta con sus clientes». Producto confirmó que son 50.000 puntos y que el entrenador que debe vender es B. Así quedan cerradas PD-05 y PD-06. Que los porcentajes se configuren no contradice el original, que no dice dónde viven.

## Plan

**Constantes.** Se reemplaza `rule_versions` por variables de entorno. Sus valores por defecto son:

| Variable | Valor |
| --- | --- |
| `CLIENT_DISCOUNT_PERCENTAGE` | 10 |
| `TRAINER_DISCOUNT_PERCENTAGE` | 25 |
| `CLIENT_PURCHASE_POINTS_PERCENTAGE` | 5 |
| `TRAINER_COMMISSION_PERCENTAGE` | 15 |
| `TRAINER_REFERRAL_POINTS` | 50000 |
| `POINTS_EXPIRATION_MONTHS` | 6 |
| `COMMISSION_CONVERSION_PERCENTAGE` | 50 |

Se quitan las FK `rule_version_id`. Los valores aplicados se guardan en las columnas de S-10.

**Referido.** Los puntos pendientes viven en `trainer_referrals` con `activated_at` en NULL. Se elimina el estado `inactive` de `point_lots` y el tipo `activation` de `point_movements`.

**Activación.** El servicio que procesa la aprobación del pedido hace lo siguiente en una sola transacción:

1. Crea los puntos del entrenador del cupón.
2. Si el pedido es `client_purchase`, bloquea con `SELECT … FOR UPDATE` el referido pendiente de ese entrenador.
3. Si existe, lo activa y crea el lote de A.

Lo protegen de duplicados los índices únicos de `trainer_referrals.activation_order_id`, `point_lots.trainer_referral_id` y `point_lots.source_key`.

**Reversión.** Se registra en `adjustments` (CU-13). Si hay remanente, se descuenta del lote con un movimiento `adjustment`. Luego se limpian `activation_order_id` y `activated_at`.

## Checklist

- [x] Quitar `rule_versions` y las FK `rule_version_id`, y añadir `orders.reward_percentage`, `trainer_referrals.referral_points` y `commission_conversions.conversion_percentage` en `scripts/der/generar_der.py`
- [x] Quitar el estado `inactive` de `point_lots` y el tipo `activation` de `point_movements`
- [x] Regenerar el DER y actualizar `specs/diseño/01-DER.md`
- [x] Actualizar las reglas 6 y 15 de `docs/reglas-de-negocio.md`
- [x] Actualizar RN-07, CU-07, CU-08, §7.2, PD-05 y PD-06 en `docs/casos-de-uso-y-actores.md`
- [x] Cambiar «bono» e «incentivo» del referido por «50.000 puntos» en el PRD, el MVP y las fases, y resolver D-06
- [x] Añadir las variables de entorno a `.agents/rules/codebase-standards.md`
- [x] Marcar en `specs/negocio/01-enlace-cupon-y-descuentos.md` el cambio de S-05 (de configuración versionada a variables de entorno)
