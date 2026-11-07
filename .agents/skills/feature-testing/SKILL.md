---
name: feature-testing
description: "Sistema de pruebas por feature para APIs backend: tests automáticos (Vitest unit + integration con supertest) que replican la estructura de src/, más una colección manual de REST Client en tests/http/<feature>/request.http con los cuerpos de respuesta guardados en responses/<accion>-<status>.json. Host, credenciales y tokens van siempre en {{$dotenv VAR}}, así que nada sensible queda versionado. Úsala para: agregar tests a un endpoint o feature, montar la estructura de tests en un repo nuevo o existente, crear o revisar archivos .http, guardar respuestas de ejemplo, auditar que los .http y las respuestas no filtren secretos, o reorganizar tests sueltos por feature. Actívala también cuando pidan 'tests como en batch-record', 'tests por feature', 'un request.http', 'probar el endpoint a mano' o 'dónde guardo la respuesta'. Es portable: se adapta a features/, modules/ o la unidad de slicing que tenga el repo, y a Express, Fastify, Hono o NestJS."
---

# Feature Testing: automáticos + manuales, organizados por feature

Cada feature del código tiene su gemelo en `tests/`: tests automáticos que corren sin red, sin base de datos y sin `.env`, y una colección manual de REST Client para golpear la API real y guardar los cuerpos de respuesta. Las dos capas se organizan igual que `src/`, así que para encontrar las pruebas de algo basta con saber en qué feature vive.

## Las tres capas

| Capa | Dónde | Prueba | No prueba | Necesita |
| ---- | ----- | ------ | --------- | -------- |
| Unit | `tests/<base>/<feature>/unit/` | Reglas de negocio de cada use case/servicio, errores con su código | HTTP, SQL, red | Nada: los ports se mockean con `vi.fn()` |
| Integration | `tests/<base>/<feature>/integration/` | Rutas, auth, validación, status codes, forma del body | Lógica de negocio (mockeada), DB | Nada: app de test con secreto fijo |
| Manual (REST Client) | `tests/http/<feature>/request.http` | El flujo real contra la DB o los servicios de verdad | — | `.env` local con `HOST` y `TEST_*` |

`<base>` es la carpeta que agrupa las unidades de slicing en `src/`: `features`, `modules`, `domains`, etc. Lo compartido (`src/shared/`, `src/common/`) se prueba en `tests/shared/` con la misma subestructura.

## Estructura

```
tests/
├── tsconfig.json                  # aliases y tipos de Vitest para el editor
├── helpers/
│   ├── createTestApp.ts           # mismo routing que producción, sin container ni DB
│   └── auth.ts                    # generateTestToken() con el secreto de test
├── <base>/<feature>/
│   ├── unit/<UseCase>.test.ts
│   └── integration/<feature>.test.ts
├── shared/…                       # replica src/shared
└── http/<feature>/
    ├── request.http               # una petición por endpoint/caso
    └── responses/
        ├── <accion>-<status>.json # list-200.json, create-201.json, create-400.json
        └── <status>.json          # errores comunes a toda la feature: 401.json
vitest.config.ts
.env.example                       # documenta HOST y TEST_* con valores falsos
```

## Seguridad: `.env` y `{{$dotenv}}`

Esto no es opcional. La colección `.http` se versiona y se comparte; el `.env` no.

1. **En un `.http` no va ningún valor sensible ni dependiente del entorno.** Host, usuario, contraseña, token, API key y datos personales de prueba se escriben como `{{$dotenv VAR}}`. Ni una URL `http://…` ni un `Bearer eyJ…` literal.
2. **`.env` está en `.gitignore` y no está trackeado.** Si ya se coló, sácalo con `git rm --cached .env` y rota las credenciales, porque siguen en el historial.
3. **Cada `$dotenv` usado tiene su línea en `.env.example`** con un valor de mentira (`TEST_PASSWORD=tu_password_de_prueba`). Así otra persona sabe qué llenar y la petición no falla en silencio.
4. **Las `TEST_*` son solo para REST Client.** No entran al schema de validación del `.env` de la app (si lo hay): la app no las necesita para arrancar.
5. **Los tests automáticos nunca leen `.env`.** Usan un secreto fijo de test (`TEST_JWT_SECRET = "test-secret-key-for-vitest"`) y mocks. Si un test necesita `.env` o una DB, no es unit ni integration, y no puede correr en CI sin filtrar secretos.
6. **Las respuestas guardadas se sanean antes de commitearlas.** Un token se trunca (`"eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."`), y correos, documentos, teléfonos y hashes se reemplazan por datos ficticios. Una respuesta de login real tal cual es un token válido publicado.
7. **Nada de secretos en `rest-client.environmentVariables` de un `.vscode/settings.json` versionado.** Si se usan entornos de REST Client, ahí solo van valores no sensibles.
8. **Peticiones que escriben o borran** llevan `# @note` (REST Client pide confirmación antes de enviarlas). Como agente, no las ejecutes contra un entorno real sin que el usuario lo pida.

Para auditar todo esto de una vez: `bash <skill>/scripts/audit-http.sh [tests/http]`. Revisa que `.env` no esté versionado, que cada `$dotenv` exista en `.env.example`, y busca hosts, tokens y contraseñas literales en los `.http`, además de JWT completos, hashes y contraseñas en `responses/`.

## Flujos de trabajo

### A. Montar el sistema en un repo

1. **Detecta el layout:** la unidad de slicing (`src/features/*`, `src/modules/*`…), los aliases (`tsconfig.json` → `paths`), el framework HTTP y cómo se arma la app (¿hay una factory tipo `createApp()`? ¿un container de DI que lee `.env`?).
2. **Vitest:** `vitest.config.ts` con los mismos aliases que `tsconfig`, y `tests/tsconfig.json` para que el editor los resuelva también (son dos sistemas distintos). Plantillas en `templates/`; detalle en `references/vitest.md`.
3. **Helpers:** `createTestApp` (monta las rutas reales con controllers inyectados y un secreto fijo, **sin** pasar por el container) y `generateTestToken`. Si `app.ts` mezcla wiring y rutas, extrae primero un `registerRoutes({ app, controllers, jwtSecret })` para que producción y tests compartan exactamente el mismo routing.
4. **Scripts** en `package.json`: `test`, `test:watch`, `test:unit` (`vitest run /unit/`), `test:integration` (`vitest run /integration/`) y un `typecheck` que incluya `-p tests`. No apuntes `test:unit` a `tests/unit`: con esta estructura esa carpeta no existe y el script no corre nada.
5. **REST Client:** `.env.example` con `HOST` y las `TEST_*`, `.vscode/extensions.json` recomendando `humao.rest-client`, y un `tests/http/<feature>/` por feature. Detalle en `references/rest-client.md`.
6. **Scaffold por feature:** `bash <skill>/scripts/scaffold-feature-tests.sh <feature> [--base features] [--public]` crea `unit/`, `integration/`, `request.http` y `responses/` sin pisar nada que ya exista.

### B. Agregar o cambiar un endpoint

En el mismo cambio:

1. **Unit test** del use case: éxito, y cada error de negocio con su código (`rejects.toMatchObject({ code: 409 })`), verificando también lo que **no** debe pasar (`expect(repo.create).not.toHaveBeenCalled()`).
2. **Integration test** de la ruta. Casos mínimos: éxito, `401` sin token (si es protegida), `400` con input inválido (si valida) y el error de negocio principal (`404`/`409`) propagado por el error handler.
3. **Entrada en `request.http`**: una petición por caso que valga la pena repetir a mano, con un título `### ` que diga qué hace.
4. **Respuesta en `responses/`**: envía la petición, guarda el cuerpo con el botón *Save Response Body* de REST Client como `<accion>-<status>.json`, sanéalo y pásale el formatter del repo.
5. Corre los tests, el linter y la auditoría (`audit-http.sh`).

### C. Revisar un repo existente

Corre `audit-http.sh` y compara contra la estructura de arriba. Lo típico: tests sueltos en `tests/unit` y `tests/integration` en vez de por feature, tests de integración que levantan la app real (y por eso necesitan `.env` o DB), `.http` con hosts o tokens literales, respuestas con tokens completos o datos reales, y scripts `test:*` que apuntan a carpetas que ya no existen. Mueve una feature a la vez y corre los tests después de cada movimiento.

## Convenciones

- **Nombres de test:** unit en presente, describiendo el comportamiento (`"lanza 409 si el nombre ya existe"`); integration como `"<status> — <qué pasa>"` (`"401 — sin token"`), agrupados en `describe("GET /api/items")`.
- **Archivos:** unit `<UseCase>.test.ts` (uno por use case); integration `<feature>.test.ts` (uno por feature, un `describe` por endpoint).
- **Mocks tipados por el port:** `function createMockItemRepo(): ItemRepository { return { create: vi.fn(), … } }`. Si el port cambia, el mock deja de compilar, que es justo lo que quieres.
- **Integration con use cases automockeados:** `vi.mock("@features/items/application/ListItems")` y `new ListItems(null as never)`; el `execute` ya es un `vi.fn()`.
- **`request.http`:** encabezado `### === <Feature> Endpoints (públicos|protegidos) ===`, `@token = {{$dotenv TEST_TOKEN}}` arriba si es protegida, peticiones separadas por `###`.
- **`responses/`:** `<accion>-<status>.json` en kebab-case; los errores que comparte toda la feature (como `401.json`) van sin acción.
- Sigue el formatter y el linter del repo en tests y JSON. Si es Biome, corre `check:fix` sobre `./tests`.

## Adaptar a otro stack

El patrón (tres capas, espejo por feature, `$dotenv`) no depende del framework; cambia cómo se monta la app de test:

| Stack | Integration |
| ----- | ----------- |
| Express / Koa | `supertest(app)` sobre una `createTestApp` |
| Fastify | `app.inject({ method, url, headers })`, sin supertest |
| Hono | `await app.request("/api/items", { headers })` |
| NestJS | `Test.createTestingModule({ … }).overrideProvider(UseCase).useValue(mock)` + supertest sobre `app.getHttpServer()` |
| Jest en vez de Vitest | Igual, cambiando `vi` por `jest` y `vitest.config.ts` por `moduleNameMapper` |

La capa REST Client funciona igual con cualquier backend, incluso fuera de Node (PHP, Python, Go…).

## Referencias

| Archivo | Contenido | Cuándo leerlo |
| ------- | --------- | ------------- |
| `references/vitest.md` | Config, aliases en dos sistemas, helpers, patrones unit/integration, qué cubrir, errores típicos | Al configurar Vitest o escribir tests automáticos |
| `references/rest-client.md` | Sintaxis `.http`, cómo resuelve `$dotenv`, estrategias de token, `@prompt` y `@note`, guardar y sanear respuestas, varios entornos | Al crear o revisar la colección manual |
| `templates/` | `vitest.config.ts`, `tests-tsconfig.json`, `createTestApp.ts`, `auth.ts`, `unit.test.ts`, `integration.test.ts`, `request.http`, `env.example` | Como punto de partida; adapta aliases y nombres |
| `scripts/scaffold-feature-tests.sh` | Crea la estructura de tests de una feature | Al empezar una feature |
| `scripts/audit-http.sh` | Auditoría de seguridad de `.env`, `.http` y `responses/` | Antes de commitear cambios en `tests/http/` |
