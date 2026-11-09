# 02 — Acceso a la app de dietas (Flutter)

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> Acceso a la app de dietas: un endpoint que retorne el estado de acceso para el entrenador. La app de dietas es aparte, está hecha en Flutter.
>
> Decisiones (9 nov 2026):
> - La meta se evalúa con el mes anterior.
> - La meta se mide en puntos. Va en una variable de entorno y se fija después; para empezar, 249.000 puntos. Esta lógica puede cambiar.
> - Si no cumple la meta, un mes de acceso cuesta 50.000 puntos.
> - El mes gratis cuenta desde el primer ingreso a la app de dietas.

## Objetivo

Que la app de dietas (`/home/steven/Proyectos/flutter/dietas_automaticas_flutter`) sepa en todo momento si el entrenador tiene acceso, por qué lo tiene y cómo recuperarlo, consultando el backend de PremiumFitness.

## Contexto encontrado

La app de dietas funciona **solo en local**: guarda todo en SQLite, no tiene cliente HTTP, y su login compara usuario y clave en texto plano en `tb_cargos`. Para consultar el acceso debe añadir un cliente HTTP y autenticar al entrenador contra PremiumFitness.

## Alcance

- La regla de acceso por mes.
- La autenticación de la app de dietas contra el backend.
- Los endpoints de estado, de primer ingreso y de canje de puntos por un mes de acceso.
- El modelo de datos que lo soporta.

## Fuera de alcance

- Las funciones de la app de dietas.
- La convivencia del login de PremiumFitness con los usuarios locales de `tb_cargos`. Lo decide el equipo de Flutter.
- Cobro en dinero.

## Reglas

1. **Acceso por mes calendario**, hora de Colombia. En un momento del mes `M`, el entrenador tiene acceso si se cumple al menos una de estas condiciones:
   - **Mes gratis:** no han pasado `DIET_FREE_DAYS` (30) días desde su primer ingreso a la app de dietas.
   - **Meta:** en `M − 1` ganó por compras de sus clientes al menos `DIET_ACCESS_POINTS_GOAL` puntos (**249.000, valor provisional**).
   - **Canje:** canjeó `DIET_ACCESS_POINTS_COST` (50.000) puntos por el mes `M`.
2. **Qué puntos cuentan para la meta:** la suma de `initial_points` de los lotes `client_sale` con `accrued_at` en `M − 1`. No cuentan los 50.000 por referido ni los puntos de una conversión. **Esta lógica puede cambiar.**
3. **Primer ingreso:** se registra la primera vez que el entrenador abre la app de dietas con sesión iniciada. Desde ahí corren los 30 días gratis, aunque cubran parte del mes siguiente.
4. **Canje del mes:**
   - consume 50.000 puntos por FIFO, a precio completo, como cualquier canje;
   - solo se permite si el entrenador no tiene ya acceso en ese mes por la meta, y si no está en su mes gratis o este termina antes de fin de mes;
   - hay un solo canje por entrenador y mes;
   - no se devuelve.
5. **Validación diaria:** la app consulta el estado al abrir y al menos una vez al día. El servidor siempre calcula el estado en el momento de la consulta.
6. **Sin acceso:** la app bloquea sus funciones y muestra el motivo y cómo recuperar el acceso: canjear puntos si tiene saldo, o cumplir la meta este mes para tener acceso el siguiente. Solo se desactivan las dietas, no la cuenta (CU-15).
7. **Sin conexión:** la app usa la última respuesta guardada hasta su `valid_until`, que nunca pasa del fin del mes en curso.
8. **Constantes en variables de entorno:** `DIET_ACCESS_POINTS_GOAL=249000` (provisional), `DIET_ACCESS_POINTS_COST=50000` y `DIET_FREE_DAYS=30`. Cada acceso guarda los valores que aplicó.

## Autenticación de la app de dietas

- **Login:** con el celular y la contraseña del entrenador (spec `seguridad/01`). Solo cuentas `trainer`.
- **Tokens:** es la tercera app, `diets` (las otras son `web` y `admin`; spec `arquitectura/02`). El access token dura 15 minutos y el refresh token 30 días, con rotación.
- **Dónde se guarda el refresh token:** al ser una app nativa, no usa cookie. Lo guarda en el almacenamiento seguro del dispositivo (`flutter_secure_storage`) y lo envía en el cuerpo de `/api/diets/auth/refresh`.

## Endpoints

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `POST` | `/api/diets/auth/login`, `/refresh`, `/logout` | Sesión de la app de dietas |
| `POST` | `/api/diets/access/first-use` | Registra el primer ingreso (idempotente) y devuelve el estado |
| `GET` | `/api/diets/access` | Estado de acceso en este momento |
| `POST` | `/api/diets/access/redeem` | Canjea los puntos por el mes en curso (idempotente) y devuelve el estado |

**Respuesta de `GET /api/diets/access`:**

```json
{
  "trainer_id": 123,
  "full_name": "Jorge Herrera",
  "has_access": true,
  "reason": "sales_goal",
  "period": "2026-11",
  "valid_until": "2026-11-30T23:59:59-05:00",
  "free_until": null,
  "goal": { "points_required": 249000, "points_last_month": 268400, "points_this_month": 41250 },
  "redeem": { "available": false, "points_cost": 50000, "points_available": 312000 },
  "checked_at": "2026-11-09T10:15:00-05:00"
}
```

Valores de `reason`:

| Valor | Significado |
| --- | --- |
| `free` | Está en su mes gratis |
| `sales_goal` | Cumplió la meta en el mes anterior |
| `points` | Canjeó puntos por este mes |
| `none` | No tiene acceso |
| `not_started` | Nunca ha ingresado a dietas |

## Criterios de aceptación

1. **Mes gratis:** un entrenador que entra por primera vez el 20 de noviembre tiene acceso hasta el 20 de diciembre (`reason = free`).
2. **Meta:** si en octubre ganó 249.000 puntos por clientes, tiene acceso todo noviembre (`reason = sales_goal`).
3. **Canje:** sin meta y sin mes gratis, canjear 50.000 puntos le da acceso hasta fin de mes. Un segundo canje del mismo mes no descuenta otra vez.
4. **Saldo insuficiente:** sin meta, sin mes gratis y sin saldo suficiente, `has_access = false` y `redeem.available = false`.
5. **Cambio de las variables:** cambiar `DIET_ACCESS_POINTS_GOAL` no altera los accesos ya concedidos.
6. **App web:** un token `web` no sirve en `/api/diets/*`, y al revés.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | La meta se evalúa con el mes anterior. | Confirmado (9 nov 2026) |
| S-02 | La meta se mide en puntos, con `DIET_ACCESS_POINTS_GOAL` = 249.000 para empezar. La lógica puede cambiar. | Confirmado (9 nov 2026), valor provisional |
| S-03 | Para la meta solo cuentan los puntos por compras de clientes del mes (`client_sale`), no los de referidos ni conversiones. | Derivado: «meta de ventas» |
| S-04 | Un mes de acceso cuesta 50.000 puntos. | Confirmado (9 nov 2026) |
| S-05 | El mes gratis son 30 días desde el primer ingreso a la app de dietas. | Confirmado (9 nov 2026) |
| S-06 | Sin conexión, la app usa el último estado hasta `valid_until`. | Derivado |
| S-07 | Cómo convive el login de PremiumFitness con los usuarios locales de `tb_cargos` en Flutter. | Pendiente: equipo de Flutter |

**Diferencia con `docs/requerimientos.md`:** el original habla de «tope de ventas» y del valor de la app en «50,000 mil puntos (primer mes gratis)», que serían 4 Bipro por 49.800. Producto fijó el costo en 50.000 puntos y la meta en puntos.

## Plan

- **Modelo de datos:**
  - `trainers.diet_first_access_at`.
  - Nueva tabla `diet_access_periods`: `id`, `trainer_id`, `period`, `source` (`free`, `sales_goal` o `points`), `points_goal`, `points_earned`, `points_spent`, `valid_from`, `valid_to` y `created_at`. Clave única (`trainer_id`, `period`, `source`).
  - `point_movements.diet_access_period_id` para el canje.
  - `refresh_tokens.app` añade `diets`.
- **Cómo se calcula el estado:** `GET /api/diets/access` evalúa en este orden: mes gratis, meta del mes anterior (crea el período `sales_goal` la primera vez que se consulta en el mes) y canje del mes. La consulta de la meta usa el índice `point_lots (trainer_id, source, initial_points)` filtrado por `accrued_at`; se añade `accrued_at` al índice.
- **Feature nueva:** `DietAccess`.
- **Avisos:** el proceso nocturno avisa por push cuando el acceso vence en 3 días o se desactivó (spec `integraciones/01`).

## Checklist

- [x] Revisar la app de dietas en Flutter (autenticación y almacenamiento)
- [x] Definir la regla de acceso, la autenticación y los endpoints
- [x] Añadir `diet_access_periods`, `trainers.diet_first_access_at`, `point_movements.diet_access_period_id` y la app `diets` en el DER
- [x] Actualizar las reglas 8 y 11, CU-14, CU-15, D-09 y las variables de entorno
- [ ] Fijar el valor definitivo de la meta (S-02)
- [ ] Acordar con el equipo de Flutter la integración del login (S-07)
