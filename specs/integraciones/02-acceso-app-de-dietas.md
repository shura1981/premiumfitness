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

**Corrección del 15 de noviembre de 2026:** la versión anterior de este spec apuntaba al repositorio `dietas_automaticas_flutter` y decía que la app funcionaba solo en local, sin cliente HTTP. Por eso proponía que la app de dietas iniciara sesión en PremiumFitness y le preguntara el acceso. La app que se va a usar es otra: **Nutrition & Fitness Plus**, que ya tiene su propio backend, cuentas, prueba gratis, módulos con vencimiento y una puerta de integración para otros sistemas. La integración se invierte: PremiumFitness decide el acceso y se lo comunica al backend de dietas. La app de dietas no se modifica.

## Objetivo

Que el entrenador de PremiumFitness use la app de dietas mientras tenga acceso (mes gratis, meta del mes anterior o canje de puntos), sin una segunda lógica de acceso dentro de la app de dietas y sin cambiarla.

## Contexto encontrado

| Pieza | Dónde | Qué hace |
| --- | --- | --- |
| App | `/home/steven/Proyectos/flutter/dietas.nutramerican.com` (Flutter: escritorio, móvil y PWA) | Inicia sesión contra su backend con correo y contraseña. Al abrirse pregunta `GET /acceso` y deshabilita los módulos que la organización no tiene. Escucha `acceso:cambio` por Socket.IO y vuelve a preguntar. Funciona sin conexión y sincroniza. |
| Backend | `/home/steven/Proyectos/node/express/dietas.elitenutapp.com` (Node, MariaDB; se despliega en `dietas.elitenutapp.com`) | Organizaciones, usuarios, prueba gratis (`prueba_hasta`), 12 módulos con `vence_en`, y una credencial de servicio (`X-Api-Key`, variable `INTEGRACION_API_KEY`) para que otro sistema inscriba personas y cambie sus módulos. |

Cada entrenador de PremiumFitness será **una organización** en el backend de dietas, con él como administrador.

Las dos rutas del backend de dietas que usa la integración, según su `README.md`:

| Ruta | Qué hace |
| --- | --- |
| `POST /integracion/organizaciones` | Inscribe a una persona y devuelve un enlace. Al abrirlo, la persona pone su contraseña y se crean la organización y su administrador, con todos los módulos y prueba gratis (sin `prueba_hasta`, un mes de calendario desde la activación). |
| `PUT /plataforma/organizaciones/:id/funcionalidades` | Habilita o quita módulos, cada uno con `vence_en`. Un módulo vencido deja de estar concedido sin que nadie lo quite. La app recibe `acceso:cambio` al instante. |

## Alcance

- La regla de acceso por mes (sin cambios de fondo).
- La activación de la app de dietas desde la app web de PremiumFitness.
- La concesión mensual del acceso en el backend de dietas: por meta o por canje.
- El estado del acceso y el canje de puntos en el área del entrenador de la app web.
- El modelo de datos que lo soporta.

## Fuera de alcance

- Las funciones de la app de dietas y su login: el entrenador entra con la cuenta que crea al activar.
- Iniciar sesión en la app de dietas con la cuenta de PremiumFitness (era la propuesta anterior; se descarta).
- Cobro en dinero.

## Reglas

1. **Acceso por mes calendario**, hora de Colombia. En el mes `M`, el entrenador tiene acceso si se cumple al menos una de estas condiciones:
   - **Mes gratis:** está dentro de la prueba que el backend de dietas abre al activar la cuenta.
   - **Meta:** en `M − 1` ganó por compras de sus clientes al menos `DIET_ACCESS_POINTS_GOAL` puntos (**249.000, valor provisional**).
   - **Canje:** canjeó `DIET_ACCESS_POINTS_COST` (50.000) puntos por el mes `M`.
2. **Qué puntos cuentan para la meta:** la suma de `initial_points` de los lotes `client_sale` con `accrued_at` en `M − 1`. No cuentan los 50.000 por referido ni los puntos de una conversión. **Esta lógica puede cambiar.**
3. **Activación:** el entrenador activa la app desde su área en la app web. PremiumFitness lo inscribe en el backend de dietas y le entrega el enlace; al abrirlo pone su contraseña de la app de dietas. Ese es el «primer ingreso»: desde ahí corre el mes gratis.
4. **Concesión por meta:** el día 1 de cada mes, un proceso revisa la meta de `M − 1` de cada entrenador con la app activada. A quien la cumplió le concede en el backend de dietas todos los módulos con `vence_en` al último instante de `M` (23:59:59 de Colombia, en UTC). Se concede aunque siga en su mes gratis, para que no quede un hueco cuando la prueba termine.
5. **Canje del mes:**
   - consume 50.000 puntos por FIFO, a precio completo, como cualquier canje;
   - solo se permite si ese mes no tiene acceso por la meta, y si no está en su mes gratis o este termina antes de fin de mes;
   - hay un solo canje por entrenador y mes, y no se devuelve;
   - concede los módulos hasta el último instante del mes.
6. **Sin acceso:** no hay que quitar nada. Al vencer la prueba o el `vence_en` del mes, el backend de dietas deja de conceder los módulos y la app los muestra deshabilitados. Solo se desactivan las dietas, no la cuenta de PremiumFitness (CU-15).
7. **Dónde se ve el estado y cómo recuperarlo:** en el área del entrenador de la app web: si tiene acceso y hasta cuándo, la meta y el avance del mes, y el botón de canje. Los avisos de vencimiento llegan por push a la app web (spec `integraciones/01`).
8. **Si el backend de dietas no responde:** la concesión se guarda igual en PremiumFitness y se reintenta hasta que se aplique. Un fallo técnico nunca le quita el acceso a quien lo ganó: los puntos del canje ya están descontados y el período registrado.
9. **Constantes en variables de entorno:** `DIET_ACCESS_POINTS_GOAL=249000` (provisional) y `DIET_ACCESS_POINTS_COST=50000`. Cada período guarda los valores que aplicó.

## Integración

**Variables de entorno de PremiumFitness:**

```
DIETS_API_URL=https://dietas.elitenutapp.com/api/v1
DIETS_API_KEY=<la INTEGRACION_API_KEY del backend de dietas>
DIET_ACCESS_POINTS_GOAL=249000
DIET_ACCESS_POINTS_COST=50000
```

La credencial se usa solo desde el backend de PremiumFitness, con `CurlHttpClient`, y nunca llega a un navegador.

**Activación** (`POST /api/trainer/diet-access/activate`, app web, solo `role = trainer`):

1. PremiumFitness llama `POST /integracion/organizaciones` con el nombre, documento, correo y celular del entrenador, `origen: "premiumfitness"` y sin `prueba_hasta`.
2. Guarda el `inscripcion_id` y entrega el enlace al entrenador: lo muestra en pantalla y lo envía por correo.
3. Si el backend de dietas responde `registrada` (ese correo ya tiene cuenta), PremiumFitness lo informa y enlaza la organización existente (ver D-02).
4. Repetir la activación no duplica: el backend de dietas actualiza la inscripción pendiente y cambia el enlace.

**Concesión** (proceso del día 1 y canje):

```
PUT /plataforma/organizaciones/{organizacion_id}/funcionalidades
X-Api-Key: <DIETS_API_KEY>

{ "funcionalidades": [
    { "funcionalidad": "clientes",  "habilitada": true, "vence_en": "2026-12-01T04:59:59Z" },
    { "funcionalidad": "valoracion", "habilitada": true, "vence_en": "2026-12-01T04:59:59Z" },
    … los 12 módulos …
] }
```

El `PUT` reemplaza las filas de los módulos enviados, así que repetirlo es idempotente. Si un mes ya tiene concesión vigente, la nueva solo puede alargar el `vence_en`.

**Endpoints de PremiumFitness** (app web, área del entrenador):

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `GET` | `/api/trainer/diet-access` | Estado del acceso en este momento |
| `POST` | `/api/trainer/diet-access/activate` | Inscribe al entrenador en la app de dietas y devuelve el enlace |
| `POST` | `/api/trainer/diet-access/redeem` | Canjea los puntos por el mes en curso (idempotente) y concede el acceso |

**Respuesta de `GET /api/trainer/diet-access`:**

```json
{
  "status": "active",
  "reason": "sales_goal",
  "period": "2026-11",
  "valid_until": "2026-11-30T23:59:59-05:00",
  "trial_until": null,
  "goal": { "points_required": 249000, "points_last_month": 268400, "points_this_month": 41250 },
  "redeem": { "available": false, "points_cost": 50000, "points_available": 312000 },
  "sync": "applied",
  "checked_at": "2026-11-15T10:15:00-05:00"
}
```

| `status` | Significado |
| --- | --- |
| `not_activated` | No ha activado la app de dietas |
| `pending` | Recibió el enlace y aún no lo abre |
| `active` | Tiene acceso (`reason`: `trial`, `sales_goal` o `points`) |
| `inactive` | No tiene acceso este mes |

`sync` es `applied` o `pending` (la concesión aún no llega al backend de dietas y se está reintentando).

## Criterios de aceptación

1. **Activación:** un entrenador sin cuenta en la app de dietas activa desde la app web, recibe el enlace, pone su contraseña y entra a la app de dietas con todos los módulos en prueba.
2. **Meta:** si en octubre ganó 249.000 puntos por clientes, el 1 de noviembre el backend de dietas recibe sus 12 módulos con `vence_en` al 30 de noviembre a las 23:59:59 de Colombia.
3. **Canje:** sin meta y sin prueba, canjear 50.000 puntos concede los módulos hasta fin de mes. Un segundo canje del mismo mes no descuenta otra vez.
4. **Saldo insuficiente:** sin meta, sin prueba y sin saldo suficiente, `status = inactive` y `redeem.available = false`.
5. **Fin de mes sin acceso:** el 1 del mes siguiente, sin meta ni canje, la app de dietas muestra los módulos deshabilitados sin que PremiumFitness haga ninguna llamada.
6. **Falla del backend de dietas:** si el `PUT` falla, el período queda con `sync = pending`, se reintenta y se aplica cuando el backend responde. El entrenador no pierde los puntos ni el acceso.
7. **Credencial:** `DIETS_API_KEY` no aparece en respuestas, registros ni en el frontend.
8. **Cambio de las variables:** cambiar `DIET_ACCESS_POINTS_GOAL` no altera los accesos ya concedidos.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | La meta se evalúa con el mes anterior. | Confirmado (9 nov 2026) |
| S-02 | La meta se mide en puntos, con `DIET_ACCESS_POINTS_GOAL` = 249.000 para empezar. La lógica puede cambiar. | Confirmado (9 nov 2026), valor provisional |
| S-03 | Para la meta solo cuentan los puntos por compras de clientes del mes (`client_sale`), no los de referidos ni conversiones. | Derivado: «meta de ventas» |
| S-04 | Un mes de acceso cuesta 50.000 puntos. | Confirmado (9 nov 2026) |
| S-05 | El mes gratis empieza en el primer ingreso. **Cambio:** era «30 días desde el primer ingreso» (Confirmado el 9 nov 2026); el backend de dietas da **un mes de calendario desde la activación** (del 20 de noviembre al 20 de diciembre), y con la credencial de integración PremiumFitness no puede fijar otra fecha después de activar. La diferencia es de uno a tres días según el mes. | Pendiente: confirmar el cambio |
| S-06 | La app de dietas ya funciona sin conexión y vuelve a preguntar su acceso al abrir y al recibir `acceso:cambio`. PremiumFitness no le envía un `valid_until`. | Derivado |
| S-07 | Cada entrenador es una organización en el backend de dietas, con él como administrador. Puede añadir nutricionistas a su organización desde la app de dietas. | Derivado |
| S-08 | El acceso concede los 12 módulos de la app de dietas. | Derivado |
| S-09 | El entrenador tiene dos contraseñas: la de PremiumFitness (celular) y la de la app de dietas (correo), que pone al activar. Unificar el login quedaría para otra entrega. | Derivado |
| D-01 | El backend de dietas no le devuelve a PremiumFitness el id de la organización: `POST /integracion/organizaciones` responde con `inscripcion_id`, y la organización se crea después, al activar. Para conceder módulos hace falta ese id y la fecha de fin de la prueba. Propuesta para el backend de dietas: `GET /integracion/inscripciones/:inscripcion_id` con `X-Api-Key`, que responda `estado`, `organizacion_id` y `prueba_hasta`. | Pendiente: backend de dietas |
| D-02 | Si el correo del entrenador ya tiene cuenta en la app de dietas (se registró solo o por el seminario), el backend responde `registrada` sin id. Hace falta buscar la organización por correo con la misma credencial, o pedirle al entrenador que use otro correo. | Pendiente: backend de dietas |
| D-03 | Con los módulos deshabilitados, la app de dietas no dice cómo recuperar el acceso. Conviene un texto que lleve a la app web de PremiumFitness, sobre todo para las organizaciones con origen `premiumfitness`. | Pendiente: app de dietas |

**Diferencia con `docs/requerimientos.md`:** el original habla de «tope de ventas» y del valor de la app en «50,000 mil puntos (primer mes gratis)», que serían 4 Bipro por 49.800. Producto fijó el costo en 50.000 puntos y la meta en puntos.

## Plan

- **Modelo de datos:**
  - `trainers` reemplaza `diet_first_access_at` por `diet_enrollment_id` (inscripción en el backend de dietas), `diet_organization_id` (único) y `diet_trial_until`, que llegan por la consulta de D-01.
  - `diet_access_periods`: `source` pasa a `sales_goal` o `points` (la prueba vive en el backend de dietas y en `trainers.diet_trial_until`). Añade `sync_status` (`pending` o `applied`), `sync_attempts`, `last_sync_error` y `synced_at`. Sigue siendo única por (`trainer_id`, `period`, `source`).
  - `point_movements.diet_access_period_id` para el canje, sin cambios.
  - Sin la app `diets`: `refresh_tokens.app` queda en `web` y `admin`, y las push solo van a la app web.
- **Envío:** cada período se crea en la misma transacción que lo origina (el canje con su consumo FIFO, o el cierre del día 1) con `sync_status = pending`. Un job del cron (`X-Cron-Job-Token`) envía los pendientes con `CurlHttpClient` y los marca `applied`, igual que la outbox de push. El canje intenta el envío enseguida y deja el reintento al job.
- **Feature:** `DietAccess` (estado, activación, canje y concesión) y un cliente `DietsApiClient` con `fromEnv()`.
- **Avisos:** el proceso nocturno avisa por push en la app web cuando el acceso vence en 3 días o se desactivó (spec `integraciones/01`).

## Checklist

- [x] Revisar la app de dietas en Flutter (autenticación y almacenamiento)
- [x] Definir la regla de acceso, la autenticación y los endpoints
- [x] Añadir `diet_access_periods`, `trainers.diet_first_access_at`, `point_movements.diet_access_period_id` y la app `diets` en el DER
- [x] Actualizar las reglas 8 y 11, CU-14, CU-15, D-09 y las variables de entorno
- [x] Corregir el repositorio de la app de dietas: `dietas.nutramerican.com` y su backend `dietas.elitenutapp.com`
- [x] Rediseñar la integración: PremiumFitness concede el acceso por la puerta de integración del backend de dietas; se quita la app `diets` de la autenticación y de las push
- [x] Actualizar el DER, la autenticación, las push, la arquitectura, los estándares, las reglas, los casos de uso, el PRD y las fases
- [ ] Confirmar el cambio del mes gratis a un mes de calendario (S-05)
- [ ] Acordar con el backend de dietas la consulta de la organización por inscripción y por correo (D-01, D-02)
- [ ] Acordar el texto de la app de dietas para recuperar el acceso (D-03)
- [ ] Fijar el valor definitivo de la meta (S-02)
- [~] Acordar con el equipo de Flutter la integración del login (S-07 anterior): descartado el 15 nov 2026, la app de dietas conserva su propio login
