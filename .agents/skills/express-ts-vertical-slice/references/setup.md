# Setup: paquetes, configuración, .env, testing y REST Client

Todo lo que hay que crear (o revisar) para que un proyecto cumpla el estándar. Los fragmentos son el contenido real que se usa; adapta nombres y aliases al proyecto.

## Contenido

1. Paquetes
2. package.json (scripts)
3. tsconfig.json
4. Aliases: los 4 lugares
5. Variables de entorno (.env)
6. Biome
7. VS Code
8. Vitest: configuración, helpers y patrones de test
9. REST Client: colección manual
10. README y CLAUDE.md del proyecto
11. Verificación y errores típicos

## 1. Paquetes

```bash
npm i express cors dotenv zod jsonwebtoken mysql
npm i -D typescript tsx tsconfig-paths tsc-alias @biomejs/biome vitest supertest \
  @types/node @types/express @types/cors @types/jsonwebtoken @types/supertest @types/mysql
# Según necesidad: bcryptjs @types/bcryptjs · socket.io · pdfkit @types/pdfkit
```

Versiones de referencia: express 5.2, typescript 5.9, zod 4.3, mysql 2.18 (+ @types/mysql 2.15) contra MariaDB, vitest 3.2, @biomejs/biome 2.4, tsx 4.21, supertest 7.2. Node indicado en `.nvmrc`.

## 2. package.json (scripts)

```json
"scripts": {
	"dev": "tsx watch -r tsconfig-paths/register src/server.ts",
	"build": "tsc && tsc-alias",
	"live-server": "node dist/server.js",
	"start": "npm run build && npm run live-server",
	"typecheck": "tsc --noEmit && tsc --noEmit -p tests",
	"check": "npx @biomejs/biome check ./src ./tests",
	"check:fix": "npx @biomejs/biome check --write ./src ./tests",
	"format": "npx @biomejs/biome format --write ./src ./tests",
	"test": "vitest run",
	"test:unit": "vitest run /unit/",
	"test:integration": "vitest run /integration/",
	"test:watch": "vitest",
	"token": "tsx -r tsconfig-paths/register scripts/dev-token.ts"
}
```

- `typecheck` revisa también `tests/`, porque Vitest no hace typecheck.
- `test:unit` / `test:integration` filtran por ruta. **No** los apuntes a `tests/unit`: con la estructura `tests/features/<f>/{unit,integration}` esa carpeta no existe y el script no corre nada (pasó en batch-record).
- `token` es opcional: genera un JWT de desarrollo para REST Client (§9).

## 3. tsconfig.json

```json
{
	"compilerOptions": {
		"target": "ES2024",
		"module": "commonjs",
		"lib": ["ES2024"],
		"outDir": "./dist",
		"rootDir": "./src",
		"strict": true,
		"esModuleInterop": true,
		"skipLibCheck": true,
		"forceConsistentCasingInFileNames": true,
		"resolveJsonModule": true,
		"declaration": true,
		"declarationMap": true,
		"sourceMap": true,
		"moduleResolution": "node",
		"ignoreDeprecations": "5.0",
		"baseUrl": "./src",
		"paths": {
			"@shared/*": ["shared/*"],
			"@features/*": ["features/*"]
		}
	},
	"include": ["src/**/*"],
	"exclude": ["node_modules", "dist"]
}
```

- `commonjs` + `moduleResolution: node`: es la combinación con la que `tsconfig-paths` y `tsc-alias` funcionan sin fricción. Pasar a ESM obliga a cambiar la estrategia de aliases.
- `ignoreDeprecations: "5.0"` silencia el aviso de `baseUrl`.
- `rootDir: ./src` + `include: src/**`: los tests no se compilan a `dist/`.

## 4. Aliases: los 4 lugares

| # | Dónde | Para qué | Si falta |
| - | ----- | -------- | -------- |
| 1 | `tsconfig.json` → `paths` | Compilador y editor en `src/` | `Cannot find module '@shared/...'` en tipos |
| 2 | Script `dev`: `-r tsconfig-paths/register` | Ejecución en desarrollo | Compila, pero falla al correr |
| 3 | Script `build`: `tsc && tsc-alias` | Reescribe `@...` a rutas relativas en `dist/` | `node dist/server.js` → `Cannot find module` |
| 4a | `vitest.config.ts` → `resolve.alias` | Tests en ejecución | Los tests no importan |
| 4b | `tests/tsconfig.json` | Editor dentro de `tests/` | Subrayado rojo aunque los tests pasen |

Un alias por carpeta de primer nivel de `src/`. Al agregar uno nuevo, actualiza 1, 4a y 4b (2 y 3 lo toman solos). Dentro de la misma carpeta de una feature, los imports relativos (`./queries/x`) están bien.

## 5. Variables de entorno (.env)

### Archivos

| Archivo | ¿Versionado? | Papel |
| ------- | ------------ | ----- |
| `.env` | **No** (`.gitignore`) | Valores reales de esa máquina |
| `.env.example` | Sí | Todas las variables con valores de ejemplo falsos y comentarios. `cp .env.example .env` debe bastar para empezar |
| `src/shared/config/env.ts` | Sí | Schema Zod: qué existe, qué es requerido, defaults |
| README → tabla de variables | Sí | Variable, requerida, default y descripción |

Las cuatro fuentes deben coincidir siempre.

### Flujo

```
server.ts → import "dotenv/config"   ① .env → process.env (no pisa variables ya definidas en el sistema)
         → createApp() → createContainer()
              → loadEnv()             ② valida con Zod → Env tipado y cacheado; si falla, la app no arranca
              → new X(env.Y)          ③ reparte valores por constructor
```

**Regla: `process.env` solo se lee en `env.ts`.** El resto del código recibe `env.X` por constructor, así los use cases se testean con valores literales y un nombre mal escrito es un error de compilación.

### env.ts

```ts
import { z } from "zod";

const envSchema = z.object({
	HOST: z.string().default("http://localhost:3000"), // SIN "/" final → `${host}/api/...`
	JWT_EXPIRES_IN: z.string().default("24h"),
	JWT_SECRET: z.string().min(8),
	MYSQL_CONNECTION_LIMIT: z.string().default("10"),
	MYSQL_DATABASE: z.string().min(1),
	MYSQL_HOST: z.string().default("localhost"),
	MYSQL_PASSWORD: z.string().min(1),
	MYSQL_PORT: z.string().default("3306"),
	MYSQL_USER: z.string().min(1),
	PORT: z.string().default("3000")
});

export type Env = z.infer<typeof envSchema>;

let cachedEnv: Env | null = null;

export function loadEnv(): Env {
	if (cachedEnv) return cachedEnv;
	const result = envSchema.safeParse(process.env);
	if (!result.success) {
		const formatted = result.error.issues
			.map((i) => `  ${i.path.join(".")}: ${i.message}`)
			.join("\n");
		throw new Error(`[Config] Variables de entorno inválidas:\n${formatted}`);
	}
	cachedEnv = result.data;
	return cachedEnv;
}
```

- **Requeridas** (credenciales, secretos): `.min(1)`, y `.min(8)` para secretos. **Opcionales**: `.default(...)`.
- Todo llega como string. Se puede convertir en el schema con `z.coerce.number().int().positive().default(3306)`. Para booleanos usa `z.enum(["true", "false"]).default("false").transform((v) => v === "true")`, porque `z.coerce.boolean()` convierte `"false"` en `true`.
- Nunca imprimas `env` completo en logs: contiene secretos.

### .env.example

```bash
# App
PORT=3000
# URL base pública, SIN "/" final
HOST=http://localhost:3000

# JWT
JWT_SECRET=tu_clave_secreta_minimo_8_chars
JWT_EXPIRES_IN=24h

# MariaDB (paquete mysql)
MYSQL_HOST=localhost
MYSQL_USER=usuario
MYSQL_PASSWORD=contraseña
MYSQL_DATABASE=nombre_db
MYSQL_PORT=3306
MYSQL_CONNECTION_LIMIT=10

# Pruebas manuales (REST Client) — no las valida la app
TEST_TOKEN=pegar_aqui_el_token
```

### Agregar una variable nueva

1. `env.ts` (requerida o con default).
2. `.env.example`, en su bloque, con un valor de ejemplo falso.
3. Fila en la tabla del README.
4. `container.ts`: pasarla por constructor a quien la use.
5. Tu `.env` local, y el entorno de despliegue si es requerida.

### Variables solo para REST Client

`TEST_TOKEN`, `TEST_EMAIL` y `TEST_PASSWORD` van en `.env.example` (bloque aparte) y en el README, pero **no** en `env.ts`: la app no las usa, y exigirlas rompería el arranque en producción.

## 6. Biome

```json
{
	"$schema": "./node_modules/@biomejs/biome/configuration_schema.json",
	"assist": {
		"actions": {
			"source": { "organizeImports": "on", "useSortedAttributes": "on", "useSortedKeys": "on" }
		},
		"enabled": true
	},
	"files": { "ignoreUnknown": false, "includes": ["src/**", "tests/**"] },
	"formatter": {
		"enabled": true,
		"expand": "always",
		"indentStyle": "tab",
		"indentWidth": 2,
		"lineEnding": "lf",
		"lineWidth": 80
	},
	"javascript": {
		"formatter": {
			"arrowParentheses": "always",
			"bracketSpacing": true,
			"quoteStyle": "double",
			"semicolons": "always",
			"trailingCommas": "none"
		}
	},
	"json": { "formatter": { "trailingCommas": "none" } },
	"linter": {
		"enabled": true,
		"rules": {
			"correctness": { "noUndeclaredVariables": "error", "noUnusedVariables": "warn" },
			"recommended": true,
			"suspicious": { "noConsole": "warn", "noEmptyBlockStatements": "error" }
		}
	},
	"overrides": [
		{
			"includes": ["tests/**"],
			"javascript": {
				"globals": ["afterAll", "afterEach", "beforeAll", "beforeEach", "describe", "expect", "it", "test", "vi"]
			}
		}
	]
}
```

- `useSortedKeys` ordena alfabéticamente las keys de **todos** los objetos. Revisa el diff donde el orden importe.
- El `overrides` declara los globals de Vitest. Sin él, `noUndeclaredVariables` falla en los tests que no importan desde `"vitest"`.
- Corre `npm run check:fix` después de cada cambio de código.

## 7. VS Code

`.vscode/settings.json`:

```json
{
	"[jsonc]": { "editor.defaultFormatter": "vscode.json-language-features" },
	"editor.codeActionsOnSave": {
		"quickfix.biome": "explicit",
		"source.action.useSortedKeys.biome": "explicit",
		"source.fixAll.biome": "explicit",
		"source.organizeImports.biome": "explicit",
		"source.removeUnusedImports.biome": "explicit"
	},
	"editor.defaultFormatter": "biomejs.biome",
	"editor.formatOnSave": true,
	"js/ts.tsdk.path": "node_modules/typescript/lib"
}
```

`.vscode/extensions.json` (VS Code ofrece instalarlas al abrir el proyecto):

```json
{ "recommendations": ["biomejs.biome", "humao.rest-client"] }
```

## 8. Vitest

### Configuración

```ts
// vitest.config.ts — los aliases deben coincidir con tsconfig paths
import path from "node:path";
import { defineConfig } from "vitest/config";

export default defineConfig({
	resolve: {
		alias: {
			"@features": path.resolve(__dirname, "src/features"),
			"@shared": path.resolve(__dirname, "src/shared")
		}
	},
	test: { globals: true, include: ["tests/**/*.test.ts"], root: "." }
});
```

```json
// tests/tsconfig.json — aliases y tipos de Vitest para el editor
{
	"compilerOptions": {
		"baseUrl": "../src",
		"ignoreDeprecations": "5.0",
		"noEmit": true,
		"rootDir": "..",
		"types": ["vitest/globals", "node"]
	},
	"extends": "../tsconfig.json",
	"include": ["../src/**/*", "./**/*"]
}
```

### Helpers

```ts
// tests/helpers/createTestApp.ts
// Mismo routing que producción (registerRoutes), sin container, sin base de datos y con un secreto fijo.
export const TEST_JWT_SECRET = "test-secret-key-for-vitest";

export function createTestApp(controllers: Partial<Controllers>) {
	const app = express();
	app.use(express.json({ limit: "5mb" }));
	registerRoutes({ app, controllers, jwtSecret: TEST_JWT_SECRET });
	app.use(errorHandler);
	return app;
}

// tests/helpers/auth.ts
export function generateTestToken(): string {
	return signToken({ expiresIn: "1h", payload: { email: "test@example.com", id: 1 }, secret: TEST_JWT_SECRET });
}
```

`createTestApp` **no** usa `createApp()`: este llama a `createContainer()`, que necesita `.env` y MariaDB. Por eso los tests automatizados corren sin `.env` y sin base de datos.

### Unit test: un use case con el port mockeado

```ts
function createMockItemRepo(): ItemRepository {
	return { create: vi.fn(), findAll: vi.fn(), findByName: vi.fn() }; // tipado como el port
}

describe("CreateItem", () => {
	let itemRepo: ItemRepository;
	let createItem: CreateItem;

	beforeEach(() => {
		itemRepo = createMockItemRepo();
		createItem = new CreateItem(itemRepo);
	});

	it("lanza 409 si el nombre ya existe", async () => {
		vi.mocked(itemRepo.findByName).mockResolvedValue(fakeItem);
		await expect(createItem.execute({ name: "Tornillo", quantity: 5 }))
			.rejects.toMatchObject({ code: 409 });
		expect(itemRepo.create).not.toHaveBeenCalled();
	});
});
```

### Integration test: la capa HTTP con los use cases mockeados

```ts
vi.mock("@features/items/application/ListItems");
vi.mock("@features/items/application/CreateItem");

function buildApp() {
	const listItemsUC = new ListItems(null as never);   // automock: execute ya es vi.fn()
	const createItemUC = new CreateItem(null as never);
	const items = new ItemController(listItemsUC, createItemUC);
	return { app: createTestApp({ items }), createItemUC, listItemsUC };
}

const token = generateTestToken();

it("200 — retorna la lista", async () => {
	const { app, listItemsUC } = buildApp();
	vi.mocked(listItemsUC.execute).mockResolvedValue([fakeItem]);
	const res = await request(app).get("/api/items").set("Authorization", `Bearer ${token}`);
	expect(res.status).toBe(200);
});

it("401 — sin token", async () => {
	const { app } = buildApp();
	expect((await request(app).get("/api/items")).status).toBe(401);
});
```

Casos mínimos por endpoint: éxito, `401` sin token (si es protegido), `400` con input inválido (si valida) y el error de negocio principal (`404`/`409`). Nombres de test: unit en presente ("lanza 409 si…"); integration con el formato `"<status> — <qué pasa>"`.

| Nivel | Prueba | No prueba |
| ----- | ------ | --------- |
| Unit | Reglas de negocio, `AppError` con su `code` | HTTP, SQL |
| Integration | Rutas, auth, validación, status codes, forma de la respuesta | Lógica de negocio (mockeada), DB |
| REST Client (manual) | El flujo real contra MariaDB | — |

## 9. REST Client: colección manual

La extensión [REST Client](https://marketplace.visualstudio.com/items?itemName=humao.rest-client) (`humao.rest-client`) ejecuta las peticiones de archivos `.http` desde VS Code: aparece un enlace **Send Request** encima de cada una. Sirve para probar la API real sin Postman, y la colección queda versionada junto al código.

### Estructura

```
tests/http/<feature>/
├── request.http
└── responses/
    ├── list-200.json
    ├── create-201.json
    ├── create-400.json
    └── 401.json
```

### request.http

```http
### ===========================
### Items Endpoints (protegidos)
### ===========================
@token = {{$dotenv TEST_TOKEN}}

### Listar items
GET {{$dotenv HOST}}/api/items
Authorization: Bearer {{token}}

###

### Crear item
POST {{$dotenv HOST}}/api/items
Content-Type: application/json
Authorization: Bearer {{token}}

{
  "name": "Tornillo",
  "quantity": 10
}
```

- Un `request.http` por feature. Las peticiones se separan con `###`; el texto que sigue a `###` es el título.
- **Nunca** pongas credenciales ni tokens en el archivo: usa `{{$dotenv VAR}}`, que lee el `.env` de la raíz.
- `HOST` va sin `/` final.
- `responses/<accion>-<status>.json` guarda una respuesta real o representativa (con los datos sensibles anonimizados). Es la documentación viva del contrato.

### Token

- **Con login:** ejecuta la petición de `tests/http/auth/request.http` y copia el `token` en `TEST_TOKEN`. Dentro de un mismo archivo también se puede encadenar:
  ```http
  # @name login
  POST {{$dotenv HOST}}/api/auth/login
  Content-Type: application/json

  { "email": "{{$dotenv TEST_EMAIL}}", "password": "{{$dotenv TEST_PASSWORD}}" }

  ###
  @token = {{login.response.body.token}}
  ```
- **Sin login:** el script `npm run token` (`scripts/dev-token.ts`) firma un JWT con el `JWT_SECRET` del `.env`:
  ```ts
  import "dotenv/config";
  import { loadEnv } from "@shared/config/env";
  import { signToken } from "@shared/infrastructure/auth/jwt";

  const env = loadEnv();
  process.stdout.write(`${signToken({ expiresIn: env.JWT_EXPIRES_IN, payload: { email: "dev@localhost", id: 1 }, secret: env.JWT_SECRET })}\n`);
  ```

Al crear o cambiar un endpoint, actualiza en el mismo commit su `request.http`, la respuesta de ejemplo y la fila del README.

## 10. README y CLAUDE.md del proyecto

**README.md**, secciones mínimas:

1. Descripción y stack (tabla tecnología/propósito).
2. Arquitectura (árbol de `src/` y reglas de dependencia).
3. Instalación (`nvm use`, `npm install`, `cp .env.example .env`, cargar `database/schema.sql`).
4. Scripts.
5. Tabla de variables de entorno (incluidas las de REST Client, marcadas como tales).
6. Endpoints (método, ruta, parámetros, descripción), separados en públicos y protegidos.
7. Testing: Vitest (estructura y conteo) y "Pruebas manuales con REST Client" (configuración y flujo del token).

**CLAUDE.md**, una guía corta para agentes: comandos (`dev`, `typecheck`, `test`, cómo correr un test, `check:fix`) y las reglas de `SKILL.md` en forma de lista. Deja el detalle en el README.

## 11. Verificación y errores típicos

Después de crear o cambiar la configuración:

```bash
npm run check:fix && npm run check && npm run typecheck && npm test && npm run build
npm run dev   # debe arrancar; GET a una ruta protegida sin token → 401
```

| Síntoma | Causa | Arreglo |
| ------- | ----- | ------- |
| `Cannot find module '@shared/...'` en `npm run dev` | El script dev no tiene `-r tsconfig-paths/register` | Agregar el flag |
| Lo mismo, pero en `node dist/server.js` | El build no corre `tsc-alias` | `"build": "tsc && tsc-alias"` |
| Los tests no encuentran `@features/...` | Falta `resolve.alias` en `vitest.config.ts` | §8 |
| VS Code subraya los imports en `tests/` | Falta `tests/tsconfig.json` | §8 |
| `The vi variable is undeclared` (Biome) | Faltan los globals de Vitest en Biome | `overrides` en §6 |
| `Cannot set property query` | Express 5: `req.query` es solo getter | `validate` con `Object.defineProperty` |
| `[Config] Variables de entorno inválidas` | Falta una variable o no cumple el schema | Completar `.env` según `.env.example` |
| Una variable llega `undefined` | `dotenv/config` importado tarde, o `process.env` leído fuera de `env.ts` | Primera línea de `server.ts`; usar `env` inyectado |
| URLs del tipo `http://host:3000api/...` | `HOST` con o sin `/` final usado de forma inconsistente | `HOST` sin `/`; `${host}/api/...` |
| REST Client envía `{{$dotenv HOST}}` literal | `.env` fuera de la raíz del workspace, o falta la variable | Abrir la carpeta del proyecto como raíz; definir la variable |
