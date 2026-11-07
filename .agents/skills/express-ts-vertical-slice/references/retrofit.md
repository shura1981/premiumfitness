# Retrofit: llevar un proyecto existente al estándar

Primero el diagnóstico y después playbooks independientes. Aplica solo los que el proyecto necesite.

## Contenido

0. Diagnóstico
1. Principios
2. Agregar aliases
3. Agregar Biome (o migrar desde ESLint/Prettier)
4. Agregar Vitest + supertest
5. Validación de .env + AppError + errorHandler
6. Agregar la colección REST Client
7. Refactor a Vertical Slicing (desde MVC o por capas)
8. Corregir violaciones de convenciones
9. Express 4 → 5

## 0. Diagnóstico

Lee `package.json`, `tsconfig.json`, el árbol de `src/` y `tests/`, y revisa:

| Área | Qué buscar |
| ---- | ---------- |
| Proyecto | ¿TypeScript? ¿Express 4 o 5? ¿`.nvmrc`? |
| Aliases | `paths` + `baseUrl` en tsconfig; `tsconfig-paths` y `tsc-alias` instalados y en los scripts; imports `../../` en `src/` |
| Biome | `biome.json`, `@biomejs/biome`, scripts `check`/`check:fix`; ¿quedan ESLint o Prettier? |
| Testing | Vitest o Jest; `supertest`; `resolve.alias` en `vitest.config.ts`; `tests/tsconfig.json`; scripts de test que apunten a carpetas inexistentes |
| REST Client | Archivos `.http`; `.vscode/extensions.json` |
| Entorno y errores | `env.ts` con Zod; `.env.example`; `.env` en `.gitignore`; `process.env` disperso; `AppError` + `errorHandler` |
| Arquitectura | ¿`src/features/` o carpetas técnicas (`controllers/`, `models/`, `routes/`, `services/`)?; `container.ts`; ¿`shared/` importa features?; imports entre features fuera de `domain/` |
| Convenciones | `switch`, `any`, `\|\|` como default, barrel `index.ts`, `console.log`, `require()` |

Comandos útiles:

```bash
grep -rn "from ['\"]\.\./\.\./" src                        # imports profundos
grep -rhoE "process\.env\.[A-Z_]+" src | sort -u            # variables usadas
grep -rn "from ['\"]@features/" src/shared                  # shared importando features
grep -rnE "switch *\(|: any\b|as any|console\.log|require\(" src
find src tests -name index.ts                               # barrel files
```

Presenta al usuario un resumen corto (qué está bien, qué falta, qué propones y en qué orden) antes de hacer cambios grandes. Si pidió algo concreto ("solo agrega aliases"), haz solo eso y menciona el resto en una línea.

## 1. Principios

- **Orden recomendado:** aliases → Biome → Vitest → .env/errores → REST Client → arquitectura. Cada paso deja una red de seguridad (tipos, lint, tests) para el siguiente, que es más riesgoso.
- **Un playbook = un commit** (o un PR). El formateo masivo va en su propio commit.
- **Verifica después de cada paso:** `npm run typecheck`, `npm test` y, si cambió el arranque, que `npm run dev` levante.
- **No cambies comportamiento mientras reestructuras.** Mover archivos y cambiar lógica a la vez hace imposible saber qué rompió qué.
- **Respeta el proyecto:** idioma de los nombres, formato de las respuestas y nombres de tablas se mantienen aunque difieran de los ejemplos.
- Si el proyecto no es TypeScript o no usa Express, aplica solo lo que tenga sentido (Biome sirve en JS; REST Client sirve con cualquier API) y avisa qué no aplica.

## 2. Agregar aliases

1. Un alias por carpeta de primer nivel de `src/` que se importe (`@features`, `@shared`; en MVC: `@controllers`, `@models`…).
2. `tsconfig.json`: `baseUrl`, `paths` e `ignoreDeprecations` (`setup.md` §3).
3. `npm i -D tsconfig-paths tsc-alias`.
4. Scripts: `-r tsconfig-paths/register` en dev (tsx, ts-node o nodemon) y `tsc && tsc-alias` en build.
5. Si hay tests: `resolve.alias` en `vitest.config.ts` y `tests/tsconfig.json` (`setup.md` §8).
6. Reemplaza los `../../` que cruzan carpetas de primer nivel. Los relativos dentro de la misma carpeta se quedan.
7. Verifica: `typecheck`, `npm run dev` y `npm run build && node dist/server.js`.

## 3. Agregar Biome

1. `npm i -D @biomejs/biome`.
2. Si había ESLint o Prettier: `npx @biomejs/biome migrate eslint --write` / `migrate prettier --write` para conservar reglas propias, y fusiona el resultado con el `biome.json` de `setup.md` §6.
3. Ajusta `files.includes` a las carpetas reales.
4. Scripts `check`, `check:fix` y `format`; `.vscode/settings.json` y `extensions.json` (`setup.md` §7).
5. `npm run check:fix` en un commit propio. Corrige a mano lo que quede (suelen ser `noUnusedVariables`, `noConsole` y `noUndeclaredVariables` en tests).
6. Quita ESLint y Prettier: dependencias, configs, `.eslintignore`/`.prettierignore` y el script `lint`.

## 4. Agregar Vitest + supertest

1. `npm i -D vitest supertest @types/supertest`. Si había Jest: quítalo junto con `ts-jest`, `@types/jest` y `jest.config.*`, y reemplaza `jest.fn`/`jest.mock`/`jest.spyOn` por `vi.fn`/`vi.mock`/`vi.spyOn`.
2. `vitest.config.ts`, `tests/tsconfig.json` y el override de globals en Biome (`setup.md` §6 y §8).
3. Para que la app sea testeable sin puerto ni DB:
   - Si `app.ts` hace `app.listen`, sepáralo en `createApp()` (sin listen) y `server.ts` (con listen).
   - Extrae el montaje de rutas a `routes.ts` → `registerRoutes({ app, controllers, jwtSecret })`.
   - Crea `tests/helpers/createTestApp.ts` y `tests/helpers/auth.ts`.
4. Escribe primero un integration test del endpoint más usado: será la red de seguridad para los refactors siguientes.
5. Scripts `test`, `test:unit`, `test:integration`, `test:watch` y un `typecheck` que incluya `-p tests` (`setup.md` §2).

## 5. Validación de .env + AppError + errorHandler

1. Inventaria las variables: `grep -rhoE "process\.env\.[A-Z_]+" src | sort -u`.
2. Crea `src/shared/config/env.ts` (`setup.md` §5): requeridas con `.min(1)` y opcionales con `.default()`.
3. `import "dotenv/config"` en la primera línea de `server.ts`.
4. Reemplaza los `process.env.X` dispersos por `env.X` inyectado desde el container. Empieza por los use cases y controllers; las conexiones se pueden migrar después.
5. Sincroniza `.env.example` y la tabla del README. Verifica que `.env` esté en `.gitignore`; si alguna vez se versionó, avisa al usuario para que rote los secretos.
6. `AppError(message, code)` + `errorHandler` al final de `app.ts`. Busca `res.status(4xx).json(...)` dentro de servicios o lógica y conviértelos en `throw new AppError(...)`.
7. Controllers con `try { ... } catch (err) { next(err) }`.

## 6. Agregar la colección REST Client

1. Inventaria los endpoints: `grep -rn "router\.\(get\|post\|put\|patch\|delete\)" src`. Revisa los schemas de validación para conocer body, params y query.
2. Crea `tests/http/<feature>/request.http` por feature o grupo de rutas (`setup.md` §9), con datos de ejemplo sacados de los schemas.
3. Agrega `HOST` y `TEST_TOKEN` (y `TEST_EMAIL`/`TEST_PASSWORD` si hay login) a `.env.example`, en el bloque "Pruebas manuales (REST Client)". No van en `env.ts`.
4. Si no hay un login usable en desarrollo, agrega `scripts/dev-token.ts` y el script `token`.
5. Recomienda `humao.rest-client` en `.vscode/extensions.json`.
6. Si el servidor está corriendo, ejecuta las peticiones de **lectura** y guarda las respuestas en `responses/`. **No ejecutes POST/PATCH/DELETE** contra un entorno que no sea de desarrollo sin confirmarlo con el usuario.
7. Agrega la sección "Pruebas manuales con REST Client" al README.

## 7. Refactor a Vertical Slicing

### Mapeo típico desde MVC

| MVC / por capas | Vertical slicing |
| --------------- | ---------------- |
| `models/Order.ts` (tipos) | `features/orders/domain/Order.ts` |
| `models/Order.ts` (queries SQL) | `features/orders/infrastructure/MysqlOrderRepository.ts` (recibe `QueryFn`) + port `domain/OrderRepository.ts` |
| `services/orderService.ts` | `features/orders/application/<UseCase>.ts`, una clase por función pública |
| `controllers/orderController.ts` | `features/orders/http/OrderController.ts` |
| `routes/orders.ts` | `features/orders/http/order.routes.ts` |
| `validators/` o `schemas/` | `features/<f>/http/schemas.ts` (los comunes → `shared/http/schemas/common.ts`) |
| `middlewares/` | `shared/http/middleware/` |
| `config/`, `db/` (conexión a la base) | `shared/config/env.ts`, `shared/infrastructure/mysql/connection.ts` |
| `utils/` genéricos | `shared/` |
| `utils/` con lógica de una sola feature | Dentro de esa feature |

### Pasos (una feature por vez)

1. **Red de seguridad:** integration tests de los endpoints de la feature (§4), o al menos la colección REST Client (§6) con una pasada manual antes y después.
2. **Esqueleto compartido**, si no existe: `shared/` (AppError, errorHandler, validate, env, conexión MariaDB con `createQuery`), `container.ts`, `routes.ts` y aliases.
   - Conexión existente a la base: si el proyecto hace `pool.query(sql, params, callback)` disperso, centralízalo en `connection.ts` (`architecture.md` §4) y migra los callbacks a `await query(...)` a medida que mueves cada feature. Mantén el paquete que ya usa (`mysql` o `mysql2`).
3. **Empieza por la feature más simple**, para validar el patrón con poco riesgo.
4. **Mueve sin cambiar lógica:** tipos → `domain/`; acceso a datos → repositorio en `infrastructure/` que implementa un port nuevo en `domain/`; cada función del servicio → use case en `application/`; controller → clase con métodos arrow; rutas → factory `<feature>Routes(controller)`.
5. **Conecta** en `container.ts` y `routes.ts`, y **borra** los archivos viejos de esa feature (sin duplicados "por si acaso").
6. **Verifica:** `typecheck`, `test` y REST Client de esa feature.
7. **Commit** (`refactor(orders): migrar a vertical slice`) y sigue con la siguiente feature.
8. Al final: `shared/` no importa features y no hay imports entre features fuera de `domain/` (comandos de §0).

### Decisiones frecuentes

- **Lógica compartida entre dos features:** si es de negocio, va en el `domain/` de la feature dueña y la otra importa ese `domain/`. Si es técnica (fechas, paginación), va en `shared/`.
- **Un servicio llama al servicio de otra feature:** el use case recibe el **port** de la otra feature por constructor, y el container conecta la implementación.
- **Feature muy pequeña** (un endpoint de lectura): basta con `application/` + `http/`.
- **Proyecto JavaScript:** pregunta al usuario si migrar primero a TypeScript (`allowJs` + renombrado progresivo) o aplicar solo la estructura de carpetas.

## 8. Corregir violaciones de convenciones

| Hallazgo | Corrección |
| -------- | ---------- |
| `switch` | Diccionario `const X = {...} as const` + lookup con `?? default` |
| `any` | `unknown` + type guard, o el tipo real |
| `\|\|` para defaults | `??`. Ojo: si el código depende de que `""` o `0` caigan al default, `??` cambia el comportamiento. Revisa caso por caso |
| Barrel `index.ts` | Imports directos al archivo; borra el `index.ts` |
| `console.log` en `src` | Quitarlo; en el arranque del servidor, `process.stdout.write` |
| `require()` | `import` |
| Import entre features fuera de `domain/` | Extraer un port a `domain/` e inyectarlo desde el container |

## 9. Express 4 → 5

- `npm i express@5 && npm i -D @types/express@5`.
- `req.query` es de solo lectura: usa el `validate` de `architecture.md` §4.
- Los handlers `async` que lanzan errores se propagan solos, pero se mantiene `try/catch → next(err)` por consistencia.
- Rutas con comodines: `*` pasa a `/*splat`, y `:param?` pasa a `{/:param}`.
- `res.send(number)` para status ya no existe: usa `res.sendStatus(n)` o `res.status(n).json(...)`.
