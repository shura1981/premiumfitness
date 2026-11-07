# Tests automáticos con Vitest

## Contenido

1. Paquetes y scripts
2. Configuración: aliases en dos sistemas
3. Helpers: `createTestApp` y `generateTestToken`
4. Unit: un use case con sus ports mockeados
5. Integration: la capa HTTP con los use cases mockeados
6. Qué cubrir
7. Errores típicos

## 1. Paquetes y scripts

```bash
npm i -D vitest supertest @types/supertest
```

```json
"scripts": {
	"test": "vitest run",
	"test:watch": "vitest",
	"test:unit": "vitest run /unit/",
	"test:integration": "vitest run /integration/",
	"typecheck": "tsc --noEmit && tsc --noEmit -p tests"
}
```

- Los argumentos de `vitest run` filtran por substring de la ruta, así que `/unit/` encuentra `tests/features/*/unit/*` en todas las features.
- Vitest transpila sin chequear tipos; por eso `typecheck` también revisa `tests/`.
- Un solo archivo: `npx vitest run tests/features/items/unit/CreateItem.test.ts`. Por nombre: `npx vitest run -t "401 — sin token"`.

## 2. Configuración: aliases en dos sistemas

Si el proyecto usa aliases (`@features/*`, `@shared/*`, `@/*`…), hay que declararlos en dos sitios más aparte de `tsconfig.json`. Vitest no lee `paths` por su cuenta, y el editor no sabe que `tests/` pertenece al proyecto.

**Runtime (Vitest):** `vitest.config.ts` (`templates/vitest.config.ts`).

```ts
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

**Editor (VS Code / tsc):** `tests/tsconfig.json` (`templates/tests-tsconfig.json`), que extiende el de la raíz, apunta `baseUrl` a `../src` e incluye `vitest/globals` en `types`.

Si el `tsconfig.json` raíz tiene `rootDir: ./src`, el de `tests/` lo sobreescribe con `rootDir: ".."`; si no, `tsc -p tests` se queja de archivos fuera de `rootDir`.

Alternativa: el plugin `vite-tsconfig-paths` lee `paths` del tsconfig y evita duplicarlos. Úsalo si el repo ya lo tiene; si no, la lista explícita es más fácil de depurar.

## 3. Helpers

### `createTestApp` (`templates/createTestApp.ts`)

Monta **las mismas rutas que producción** con controllers inyectados, un secreto JWT fijo y el error handler real. Lo que **no** hace es llamar a la factory de producción (`createApp()`), porque esa arma el container, que lee `.env` y abre conexiones a la DB.

- Si producción ya tiene una función que registra las rutas (`registerRoutes`), úsala: así una ruta nueva queda cubierta sin tocar el helper.
- Si las rutas están montadas a mano dentro de `app.ts`, extrae ese bloque a `registerRoutes({ app, controllers, jwtSecret })` y llámalo desde los dos lados. Copiar el montaje en el helper funciona, pero se desincroniza.
- Acepta `Partial<Controllers>`: cada test de integración construye solo el controller de su feature. Si el helper exige todos, cada test termina construyendo diez controllers con `null as never`.

### `generateTestToken` (`templates/auth.ts`)

Firma un JWT con `TEST_JWT_SECRET` usando la misma función de firma que producción. El middleware de la app de test lo valida con ese mismo secreto, así que no hace falta login, DB ni `.env`.

## 4. Unit: un use case con sus ports mockeados

Plantilla: `templates/unit.test.ts`.

```ts
function createMockItemRepo(): ItemRepository {
	return { create: vi.fn(), findAll: vi.fn(), findByName: vi.fn() };
}

describe("CreateItem", () => {
	let itemRepo: ItemRepository;
	let createItem: CreateItem;

	beforeEach(() => {
		itemRepo = createMockItemRepo();
		createItem = new CreateItem(itemRepo);
	});

	it("crea el item si el nombre está libre", async () => {
		vi.mocked(itemRepo.findByName).mockResolvedValue(null);
		vi.mocked(itemRepo.create).mockResolvedValue(fakeItem);

		await expect(createItem.execute(input)).resolves.toEqual(fakeItem);
		expect(itemRepo.create).toHaveBeenCalledWith(input);
	});

	it("lanza 409 si el nombre ya existe", async () => {
		vi.mocked(itemRepo.findByName).mockResolvedValue(fakeItem);

		await expect(createItem.execute(input)).rejects.toMatchObject({ code: 409 });
		expect(itemRepo.create).not.toHaveBeenCalled();
	});
});
```

- El mock se tipa con el port (la interfaz de `domain/`), no con la implementación. Si el port gana un método, el mock deja de compilar.
- Instancia nueva en `beforeEach`: ningún test hereda llamadas del anterior.
- Verifica el código del error (`toMatchObject({ code })`) y, si importa, el mensaje. `toThrow(AppError)` solo confirma que es un error de negocio.
- Verifica también lo que no debe ocurrir: que no se escriba, que no se firme un token, que no salga el `password` en la respuesta.
- Fakes de dominio como constantes arriba del archivo y con datos claramente ficticios (`test@example.com`, `"123456"`).

## 5. Integration: la capa HTTP con los use cases mockeados

Plantilla: `templates/integration.test.ts`.

```ts
vi.mock("@features/items/application/ListItems");
vi.mock("@features/items/application/CreateItem");

function buildApp() {
	const listItemsUC = new ListItems(null as never);
	const createItemUC = new CreateItem(null as never);
	const items = new ItemController(listItemsUC, createItemUC);
	return { app: createTestApp({ items }), createItemUC, listItemsUC };
}

const token = generateTestToken();

describe("POST /api/items", () => {
	it("201 — crea el item", async () => {
		const { app, createItemUC } = buildApp();
		vi.mocked(createItemUC.execute).mockResolvedValue(fakeItem);

		const res = await request(app)
			.post("/api/items")
			.set("Authorization", `Bearer ${token}`)
			.send({ name: "Tornillo", quantity: 10 });

		expect(res.status).toBe(201);
		expect(res.body.id).toBe(1);
	});

	it("409 — el error de negocio llega con su status", async () => {
		const { app, createItemUC } = buildApp();
		vi.mocked(createItemUC.execute).mockRejectedValue(new AppError("Ya existe", 409));

		const res = await request(app)
			.post("/api/items")
			.set("Authorization", `Bearer ${token}`)
			.send({ name: "Tornillo", quantity: 10 });

		expect(res.status).toBe(409);
		expect(res.body.error).toBe("Ya existe");
	});

	it("400 — validación fallida", async () => { /* body inválido, sin mock */ });
	it("401 — sin token", async () => { /* sin header Authorization */ });
});
```

- `vi.mock(ruta)` automockea el módulo: la clase se puede instanciar y sus métodos ya son `vi.fn()`. Por eso el constructor recibe `null as never`, porque nunca se usa.
- `buildApp()` por test: mocks limpios sin `vi.clearAllMocks()`.
- En `400` y `401` no se configura el mock: si el use case llegara a ejecutarse, el test fallaría por otra razón, y eso también es información.
- Para respuestas no JSON (PDF, archivos): `expect(res.headers["content-type"]).toMatch(/pdf/)`, más `.buffer(true)` si hace falta el cuerpo.

## 6. Qué cubrir

| Endpoint | Unit (use case) | Integration (ruta) |
| -------- | --------------- | ------------------ |
| Lectura | Filtros, mapeo, `404` si no existe | `200` con forma del body, `400` por query inválida, `401` |
| Escritura | Cada regla de negocio y su código, efectos que no deben ocurrir | `201`/`200`, `400` por body inválido, error de negocio, `401` |
| Público (login, registro) | Credenciales, autorización, lo que se excluye de la respuesta | `200`/`201`, `400`, `401`/`403` |
| `shared/` | Value objects, helpers, `AppError` | Middlewares (auth, validate, errorHandler) con una mini app |

Repositorios y adaptadores (SQL, clientes HTTP externos) no se prueban en esta capa: sus contratos se ejercitan a mano con REST Client contra la DB real. Si el proyecto necesita tests de repositorio, van aparte (`tests/<base>/<feature>/db/`, con una DB efímera tipo Testcontainers) y fuera de `npm test`.

## 7. Errores típicos

| Síntoma | Causa | Arreglo |
| ------- | ----- | ------- |
| `Cannot find module '@shared/…'` al correr | Falta el alias en `vitest.config.ts` | Añádelo con la misma ruta que en `tsconfig` |
| Rojo en el editor, pero los tests pasan | Falta `tests/tsconfig.json` o su `baseUrl` | Usa la plantilla; reinicia el TS server |
| `describe is not defined` en el editor | Falta `vitest/globals` en `types` | Agrégalo en `tests/tsconfig.json`, o importa de `"vitest"` |
| El test de integración pide `.env` o abre la DB | Usa `createApp()` de producción | Usa `createTestApp` |
| `401` en todos los tests protegidos | Secreto distinto entre `generateTestToken` y el middleware de test | Que los dos usen `TEST_JWT_SECRET` |
| `test:unit` no corre nada | El script apunta a `tests/unit` | Usa `vitest run /unit/` |
| Un mock "recuerda" llamadas de otro test | Instancia compartida entre tests | Créala en `beforeEach` o en `buildApp()` |
