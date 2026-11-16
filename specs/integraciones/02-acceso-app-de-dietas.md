# 02 — Acceso a la app de dietas (Nutrition & Fitness Plus)

**Estado:** en curso · **Creado:** 9 de noviembre de 2026 · **Revisado:** 15 de noviembre de 2026

## Petición original

> Acceso a la app de dietas: un endpoint que retorne el estado de acceso para el entrenador. La app de dietas es aparte, está hecha en Flutter.
>
> Decisiones (9 nov 2026):
> - La meta se evalúa con el mes anterior.
> - La meta se mide en puntos. Va en una variable de entorno y se fija después; para empezar, 249.000 puntos. Esta lógica puede cambiar.
> - Si no cumple la meta, un mes de acceso cuesta 50.000 puntos.
> - El mes gratis cuenta desde el primer ingreso a la app de dietas.

**Revisión del 15 de noviembre de 2026:**

> PremiumFitness tendrá un endpoint que, con el documento del usuario, validará si ha comprado o tiene puntos que validen su entrada a la app. La app de dietas está desacoplada de este repo para poderse usar en otros.

Historial de la integración:

1. **9 nov:** la app de dietas iniciaba sesión en PremiumFitness y le preguntaba el acceso. Partía de un repositorio equivocado (`dietas_automaticas_flutter`, solo local).
2. **15 nov, primera corrección:** la app es Nutrition & Fitness Plus, con su propio backend. Se propuso que PremiumFitness le concediera los módulos por la puerta de integración de ese backend.
3. **15 nov, decisión vigente:** la app de dietas no depende de PremiumFitness. Su backend consulta a un proveedor de acceso por el documento del usuario, y PremiumFitness es uno de esos proveedores.

## Objetivo

Que la app de dietas sepa si un entrenador de PremiumFitness puede usarla, consultando por su documento, sin que la app de dietas conozca las reglas de PremiumFitness y sin atarla a este proyecto.

## Contexto

La app de dietas (Nutrition & Fitness Plus, `/home/steven/Proyectos/flutter/dietas.nutramerican.com`) y su backend son otro proyecto, desacoplado de este. A PremiumFitness solo le llega una pregunta: **¿este documento puede entrar a la app de dietas?** Responde si ese documento cumplió el contrato de compras o de puntos. Lo que la app de dietas haga con la respuesta es asunto de ese proyecto.

## Alcance

- Un endpoint de PremiumFitness, servidor a servidor, que responde si un documento puede entrar a la app de dietas.
- Las reglas de acceso de PremiumFitness (mes gratis, meta y canje).
- El estado y el canje de puntos en el área del entrenador de la app web.
- El modelo de datos que lo soporta.

## Fuera de alcance

- Todo lo del proyecto de dietas: su login, sus cuentas, cuándo pregunta y qué hace con la respuesta.
- Cobro en dinero.

## Reglas

1. **Quién tiene acceso:** solo los usuarios con rol `trainer` y cuenta activa en PremiumFitness. Un cliente o un documento desconocido no tiene acceso.
2. **Acceso por mes calendario**, hora de Colombia. En el mes `M`, el entrenador tiene acceso si se cumple al menos una de estas condiciones:
   - **Mes gratis:** no han pasado `DIET_FREE_DAYS` (30) días desde su primer ingreso a la app de dietas.
   - **Meta:** en `M − 1` ganó por compras de sus clientes al menos `DIET_ACCESS_POINTS_GOAL` puntos (**249.000, valor provisional**).
   - **Canje:** canjeó `DIET_ACCESS_POINTS_COST` (50.000) puntos por el mes `M`.
3. **Qué puntos cuentan para la meta:** la suma de `initial_points` de los lotes `client_sale` con `accrued_at` en `M − 1`. No cuentan los 50.000 por referido ni los puntos de una conversión. **Esta lógica puede cambiar.**
4. **Primer ingreso:** la primera vez que se consulta el documento de un entrenador, PremiumFitness guarda la fecha (`trainers.diet_first_access_at`). Desde ahí corren los 30 días gratis, aunque cubran parte del mes siguiente.
5. **Canje del mes:** se hace en la app web de PremiumFitness.
   - consume 50.000 puntos por FIFO, a precio completo, como cualquier canje;
   - solo se permite si el entrenador no tiene ya acceso en ese mes por la meta, y si no está en su mes gratis o este termina antes de fin de mes;
   - hay un solo canje por entrenador y mes;
   - no se devuelve.
6. **El estado se calcula en cada consulta.** PremiumFitness no guarda un «acceso» precalculado: responde con lo que vale en ese momento y hasta cuándo (`valid_until`).
7. **Sin acceso:** la respuesta dice por qué y cómo recuperarlo: canjear puntos en la app web, si tiene saldo, o cumplir la meta este mes para tener acceso el siguiente. Solo se desactivan las dietas, no la cuenta de PremiumFitness (CU-15).
8. **Constantes en variables de entorno:** `DIET_ACCESS_POINTS_GOAL=249000` (provisional), `DIET_ACCESS_POINTS_COST=50000` y `DIET_FREE_DAYS=30`. Cada acceso guarda los valores que aplicó. La integración usa además `DIET_ACCESS_API_KEY` (la credencial que presenta quien consulta, mínimo 32 caracteres) y `DIET_ACCESS_RENEW_URL` (el área de dietas de la app web).

## Endpoint de acceso

```
POST /api/integrations/diet-access
X-Api-Key: <DIET_ACCESS_API_KEY>
Content-Type: application/json

{ "document": "1010123456" }
```

- **Servidor a servidor**, con una credencial de servicio que se compara en tiempo constante y no vale para ningún otro endpoint.
- **El documento va en el cuerpo**, no en la URL, para que no quede en los registros del servidor. Se normaliza: sin puntos, espacios ni guiones.
- **Busca por `users.document_id`** entre los entrenadores. Si hay más de un usuario con ese número, responde `ambiguous` y no da acceso (ver S-09).
- **Límite de peticiones** con `RateLimitMiddleware`, por IP y por documento.
- **Es de consulta, salvo el primer ingreso:** la primera consulta de un entrenador guarda `diet_first_access_at`. Repetir la consulta no cambia nada más.

**Respuesta** (siempre 200 con una credencial válida; 401 sin ella, 422 si falta el documento, 429 por límite):

```json
{
  "document": "1010123456",
  "has_access": true,
  "status": "active",
  "reason": "sales_goal",
  "valid_until": "2026-11-30T23:59:59-05:00",
  "message": "Tienes acceso por cumplir tu meta de octubre.",
  "renew_url": "https://app.premiumfitness.com/entrenador/dietas",
  "checked_at": "2026-11-15T10:15:00-05:00"
}
```

| Campo | Para qué |
| --- | --- |
| `has_access` | La respuesta a la pregunta: puede entrar o no. |
| `status` | `active`, `inactive`, `not_found` o `ambiguous`. |
| `reason` | Por qué: `trial`, `sales_goal`, `points` o `none`. Informativo. |
| `valid_until` | Hasta cuándo vale la respuesta. Con acceso: fin del mes gratis o del mes. Sin acceso: el fin del día, para volver a preguntar. |
| `message` | Texto listo para mostrar al usuario, en español. |
| `renew_url` | Dónde recuperar el acceso: `DIET_ACCESS_RENEW_URL`, el área de dietas de la app web. Nulo si no aplica. |

La respuesta no incluye nombre, puntos ni saldos. Los detalles los ve el entrenador en la app web.

## Endpoints de la app web (área del entrenador)

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `GET` | `/api/trainer/diet-access` | Estado del acceso, la meta y su avance, y si puede canjear |
| `POST` | `/api/trainer/diet-access/redeem` | Canjea los puntos por el mes en curso (idempotente) y devuelve el estado |

```json
{
  "has_access": true,
  "reason": "sales_goal",
  "period": "2026-11",
  "valid_until": "2026-11-30T23:59:59-05:00",
  "free_until": null,
  "goal": { "points_required": 249000, "points_last_month": 268400, "points_this_month": 41250 },
  "redeem": { "available": false, "points_cost": 50000, "points_available": 312000 },
  "checked_at": "2026-11-15T10:15:00-05:00"
}
```

## Criterios de aceptación

1. **Mes gratis:** la primera consulta de un entrenador el 20 de noviembre responde `has_access = true`, `reason = trial` y `valid_until` el 20 de diciembre.
2. **Meta:** si en octubre ganó 249.000 puntos por clientes, en noviembre responde `reason = sales_goal` con `valid_until` el 30 de noviembre a las 23:59:59.
3. **Canje:** sin meta y sin mes gratis, canjear 50.000 puntos en la app web da acceso hasta fin de mes. Un segundo canje del mismo mes no descuenta otra vez.
4. **Saldo insuficiente:** sin meta, sin mes gratis y sin saldo suficiente, `has_access = false` con `message` y `renew_url`.
5. **Cliente o documento desconocido:** `has_access = false` y `status = not_found`, sin decir si el documento es de un cliente.
6. **Credencial:** sin `X-Api-Key` o con una equivocada, 401. Esa credencial no sirve en ningún otro endpoint, y un token de la app web no sirve en este.
7. **Datos mínimos:** la respuesta no trae nombre, puntos ni saldos.
8. **Cambio de las variables:** cambiar `DIET_ACCESS_POINTS_GOAL` no altera los accesos ya concedidos.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | La meta se evalúa con el mes anterior. | Confirmado (9 nov 2026) |
| S-02 | La meta se mide en puntos, con `DIET_ACCESS_POINTS_GOAL` = 249.000 para empezar. La lógica puede cambiar. | Confirmado (9 nov 2026), valor provisional |
| S-03 | Para la meta solo cuentan los puntos por compras de clientes del mes (`client_sale`), no los de referidos ni conversiones. «Ha comprado» se lee como las compras de sus clientes. | Derivado: «meta de ventas» |
| S-04 | Un mes de acceso cuesta 50.000 puntos. | Confirmado (9 nov 2026) |
| S-05 | El mes gratis son 30 días desde el primer ingreso, que PremiumFitness registra en la primera consulta. | Confirmado (9 nov 2026) |
| S-06 | La consulta es por documento, desde la app de dietas desacoplada de este proyecto. | Confirmado (15 nov 2026) |
| S-07 | La consulta es servidor a servidor, con una credencial de servicio (`DIET_ACCESS_API_KEY`). | Derivado: una credencial no puede vivir en una app instalada |
| S-08 | El canje se hace en la app web de PremiumFitness, no en la app de dietas. | Derivado |
| S-09 | Se busca solo por número de documento, sin el tipo. Si dos usuarios comparten el número, no se da acceso. | Derivado |

**Diferencia con `docs/requerimientos.md`:** el original habla de «tope de ventas» y del valor de la app en «50,000 mil puntos (primer mes gratis)», que serían 4 Bipro por 49.800. Producto fijó el costo en 50.000 puntos y la meta en puntos.

## Plan

- **Modelo de datos:**
  - `trainers.diet_first_access_at`.
  - `diet_access_periods`: `id`, `trainer_id`, `period`, `source` (`free`, `sales_goal` o `points`), `points_goal`, `points_earned`, `points_spent`, `valid_from`, `valid_to` y `created_at`. Clave única (`trainer_id`, `period`, `source`).
  - `point_movements.diet_access_period_id` para el canje.
- **Cómo se calcula el estado:** evalúa en este orden: mes gratis, meta del mes anterior (crea el período `sales_goal` la primera vez que se consulta en el mes) y canje del mes. La consulta de la meta usa el índice `point_lots (trainer_id, source, accrued_at, initial_points)`.
- **Código:** feature `DietAccess` con un servicio que calcula el estado, usado por los dos endpoints (integración y app web). La credencial la valida un middleware propio (`IntegrationApiKeyMiddleware`), como `CronJobTokenMiddleware` de radical.
- **Avisos:** el proceso nocturno avisa por push en la app web cuando el acceso vence en 3 días o se desactivó (spec `integraciones/01`).

## Checklist

- [x] Revisar la app de dietas en Flutter (autenticación y almacenamiento)
- [x] Definir la regla de acceso, la autenticación y los endpoints
- [x] Añadir `diet_access_periods`, `trainers.diet_first_access_at`, `point_movements.diet_access_period_id` y la app `diets` en el DER
- [x] Actualizar las reglas 8 y 11, CU-14, CU-15, D-09 y las variables de entorno
- [x] Corregir el repositorio de la app de dietas: `dietas.nutramerican.com` y su backend `dietas.elitenutapp.com`
- [~] Conceder el acceso por la puerta de integración del backend de dietas: reemplazado el 15 nov 2026 por la consulta por documento
- [x] Rediseñar la integración: endpoint por documento, servidor a servidor
- [x] Quitar la app `diets` de la autenticación y de las push
- [x] Actualizar el DER, la autenticación, la arquitectura, los estándares, las reglas, los casos de uso, el PRD y las fases
- [ ] Fijar el valor definitivo de la meta (S-02)
- [~] Acordar con el equipo de Flutter la integración del login (S-07 anterior): descartado el 15 nov 2026, la app de dietas conserva su propio login
