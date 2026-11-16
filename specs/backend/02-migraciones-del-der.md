# 02 — Backend: migraciones del DER

**Estado:** en curso · **Creado:** 15 de noviembre de 2026

> **16 nov 2026:** las migraciones se unieron en un solo esquema, `docs/database/schema/crisenri_premiumfitness.sql`, y se borraron (con las cinco heredadas de radical): la app aún no ha salido, y en el hosting el esquema se importa desde phpMyAdmin con un solo archivo. Lo que sigue describe cómo se construyó; los cambios de esquema se hacen ahora en ese archivo.

## Petición original

> Termina los frontends y luego el backend.

Es el paso 1 de «Lo que sigue» del spec [01-fork-base-del-backend.md](01-fork-base-del-backend.md).

## Objetivo

Llevar a la base de datos el modelo de `specs/diseño/01-DER.md` (v0.16), sin romper el código heredado de radical, que se adapta después por partes.

## Alcance

- `docs/database/migrations/premiumfitness_01_der_base.sql`: las 16 tablas nuevas, las columnas añadidas a `users`, `verification_codes`, `orders`, `orders_items` y `orders_coupons`, los índices de saldos del spec `negocio/03` y las claves únicas de idempotencia.
- `scripts/reset-db.sh` aplica la migración después de las de radical.
- Pruebas del esquema: `tests/Integration/Database/DerSchemaTest.php`.

## Fuera de alcance

Los cambios del DER que modifican o quitan algo que el código heredado todavía usa. Cada uno va en la migración de su fase:

| Cambio del DER | Por qué espera | Fase |
| --- | --- | --- |
| `users.num_cell` único | Los datos de prueba y las pruebas de radical repiten el celular `3000000000`. | Autenticación (`seguridad/01`) |
| `verification_codes.code` guarda el hash | La columna ya mide `char(64)`, pero el código de radical aún guarda 4 dígitos. | Autenticación (`seguridad/01`) |
| Quitar la clave foránea `orders_items.product_sku → products` y las tablas `products`, `categories`, `product_groups`, `product_group_map`, `sap_products` y `product_sap_map` | Los pedidos y el catálogo de radical las leen. | Catálogo (`catalogo/01`) |
| `orders_coupons.trainer_id` obligatorio | El checkout de radical inserta el cupón sin entrenador. | Compras (`negocio/01`) |
| Quitar `credit_balances`, `orders.balance_id` y `password_reset_tokens` | Los usa el código de radical que aún no se retira. | Al retirar cada feature |

## Criterios de aceptación

1. `scripts/reset-db.sh` crea la base con todas las tablas del DER y la migración se puede ejecutar dos veces sin error.
2. La base rechaza por sí sola: dos entrenadores para un cliente, un entrenador referido dos veces o por sí mismo, un origen de puntos o de comisión repetido, una referencia de pago repetida, un lote con más puntos de los que tuvo o en negativo, y dos liquidaciones del mismo entrenador en un período.
3. Las consultas de saldos del spec `negocio/03` se resuelven solo con el índice (`EXPLAIN` muestra `Using index`).
4. `composer run lint` y `composer run test` pasan.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| M-01 | La migración solo añade. Lo que cambia o quita va en la fase que adapta el código (tabla de «Fuera de alcance»). | Derivado |
| M-02 | `agent_id` no lleva clave foránea: `tb_empleados` es de otra base (`crisenri_intranet`) y no se toca. | Derivado: así lo hace radical en `orders.agent_id` |
| M-03 | Un usuario nuevo nace con `role = 'client'` y `status = 'pending'`, el valor más restrictivo. La autenticación lo activa al verificar el celular. | Derivado: spec `seguridad/01` |
| M-04 | Se añaden restricciones `CHECK` que el DER no dibuja: puntos del lote entre 0 y el inicial, porcentajes entre 0 y 100, A ≠ B en los referidos y el dueño de cada refresh token según la app. | Derivado |
| M-05 | Las claves foráneas de columnas usadas en un `CHECK` no llevan `ON UPDATE CASCADE`: MariaDB no lo admite. Los ids no cambian. | Confirmado (error 1901 de MariaDB 11.8) |
| M-06 | `is_active` de `product_discount_rules` es `tinyint(1)`, no `bit(1)` como decía el DER (v0.16): PDO entrega y recibe un `bit` como bytes, y un `1` enlazado como texto falla («Data too long»). El `bit(1)` se deja solo en las tablas heredadas y en la intranet, donde ya existe. El DER se corrige en `scripts/der/generar_der.py`. | Confirmado (falló en las pruebas del 15 nov 2026) |

## Resultado (15 de noviembre de 2026)

- La base local queda con 38 tablas (22 de radical y 16 nuevas).
- `composer run test`: 256 pruebas pasadas y 1 omitida (la heredada). `composer run lint`: sin errores.

## Checklist

- [x] Escribir la migración con las tablas nuevas, las columnas añadidas, los índices y las claves únicas
- [x] Aplicarla desde `scripts/reset-db.sh` y comprobar que se puede ejecutar dos veces
- [x] Pruebas de las restricciones de integridad
- [x] Pruebas con `EXPLAIN` de las consultas de saldos
- [x] `composer run lint` y `composer run test`
- [x] `users.num_cell` único y hash en `verification_codes` (`premiumfitness_02_autenticacion.sql`; también `users.document_id` único)
- [x] Quitar la clave foránea de `orders_items` y las tablas de productos de radical (`premiumfitness_03_catalogo_y_compras.sql`)
- [~] `orders_coupons.trainer_id` obligatorio: descartado. La fila solo se crea cuando la compra tiene entrenador, y siempre con él; la columna queda anulable porque no aporta hacerla obligatoria.
- [x] Quitar `credit_balances` y `orders.balance_id` (`premiumfitness_03_catalogo_y_compras.sql`). `password_reset_tokens` se conserva: guarda el token de un solo uso de la recuperación (spec `seguridad/01`, regla 7)
- [x] Unir el esquema de radical y las ocho migraciones en `docs/database/schema/crisenri_premiumfitness.sql` y borrar las migraciones (16 nov 2026): reimportado en una base vacía da la misma estructura (31 tablas) y los mismos datos de referencia
