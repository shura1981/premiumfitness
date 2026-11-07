# Arquitectura: Vertical Slicing + Screaming Architecture

## Contenido

1. Idea central
2. Estructura de carpetas
3. Reglas de dependencia
4. shared/: código base
5. Una feature por capas (ejemplo `items`)
6. Wiring: container.ts, routes.ts, app.ts, server.ts
7. Convenciones de código
8. Checklist para una feature nueva

## 1. Idea central

- **Screaming Architecture**: al abrir `src/features/` se ve *qué hace* el sistema (`orders`, `invoices`, `users`), no *con qué está hecho* (`controllers`, `models`).
- **Vertical Slicing**: cada feature es una rebanada completa (dominio → caso de uso → HTTP → persistencia). Para cambiar una funcionalidad se toca una sola carpeta.
- **DI manual**: sin framework de inyección. Las clases reciben sus dependencias por constructor y `container.ts` las instancia. Gracias a esto, los tests reemplazan cualquier dependencia con un `vi.fn()`.

## 2. Estructura de carpetas

```
<proyecto>/
├── src/
│   ├── app.ts                # createApp(): middleware → registerRoutes → errorHandler
│   ├── routes.ts             # registerRoutes(): único lugar donde se montan rutas
│   ├── server.ts             # import "dotenv/config" primero; createServer(app).listen
│   ├── container.ts          # DI manual: infra → use cases → controllers
│   ├── features/
│   │   └── <feature>/
│   │       ├── domain/          # Entidades (interfaces/types), ports, reglas puras
│   │       ├── application/     # Un archivo por use case
│   │       ├── http/            # <Feature>Controller.ts, <feature>.routes.ts, schemas.ts
│   │       └── infrastructure/  # Mysql<Entidad>Repository.ts, adapters externos
│   └── shared/                  # Código transversal, sin lógica de negocio
│       ├── config/env.ts        # Validación Zod de .env
│       ├── domain/AppError.ts
│       ├── http/middleware/     # authMiddleware, validate, errorHandler
│       ├── http/schemas/        # common.ts: idParamSchema, dateRangeSchema…
│       └── infrastructure/      # auth/jwt.ts, mysql/connection.ts
├── tests/
│   ├── tsconfig.json
│   ├── helpers/                 # createTestApp.ts, auth.ts
│   ├── features/<feature>/{unit,integration}/
│   └── http/<feature>/          # request.http + responses/*.json (REST Client)
├── database/schema.sql          # DDL de las tablas (MariaDB)
├── .vscode/                     # settings.json, extensions.json
├── .env.example · .nvmrc · biome.json · tsconfig.json · vitest.config.ts · package.json
├── README.md
└── CLAUDE.md
```

No todas las features necesitan las cuatro capas. Una feature que solo reutiliza el repositorio de otra (p. ej. `users` usando el `UserRepository` de `auth/domain`) puede tener solo `application/` y `http/`.

## 3. Reglas de dependencia

| Desde → hacia | ¿Permitido? |
| ------------- | ----------- |
| `application` → `domain` (misma feature) | Sí |
| `http` / `infrastructure` → `application`, `domain` | Sí |
| `domain` → cualquier otra capa | No: el dominio no conoce Express, MariaDB ni Zod |
| feature A → `domain/` de feature B | Sí |
| feature A → `application/`, `http/`, `infrastructure/` de B | No |
| `shared/` → `features/` | No |
| cualquiera → `shared/` | Sí |
| `container.ts` → todo | Sí: es el único punto de acoplamiento total |

Si una feature necesita lógica de otra, pide en su constructor el **port** (la interfaz del `domain/` de B) y deja que `container.ts` le pase la implementación. Ejemplo: `new CreateInvoice(invoiceRepo, orderRepo)`, donde `orderRepo` cumple `OrderRepository` de `orders/domain`.

## 4. shared/: código base

### AppError + errorHandler

```ts
// shared/domain/AppError.ts
export class AppError extends Error {
	constructor(message: string, public readonly code: number) {
		super(message);
		this.name = "AppError";
	}
}

// shared/http/middleware/errorHandler.ts — se registra al final de app.ts
export function errorHandler(err: Error, _req: Request, res: Response, _next: NextFunction): void {
	if (err instanceof AppError) {
		res.status(err.code).json({ error: err.message });
		return;
	}
	res.status(500).json({ error: "Error interno del servidor" }); // no filtra detalles internos
}
```

### validate (Zod)

```ts
// shared/http/middleware/validate.ts
type Source = "body" | "query" | "params";

export function validate(schema: ZodSchema, source: Source = "body") {
	return (req: Request, res: Response, next: NextFunction): void => {
		const result = schema.safeParse(req[source]);
		if (!result.success) {
			res.status(400).json({ details: result.error.flatten().fieldErrors, error: "Validación fallida" });
			return;
		}
		// Express 5: req.query es read-only (solo getter). Se redefine la propiedad.
		if (source === "query") {
			Object.defineProperty(req, "query", { configurable: true, value: result.data, writable: true });
		} else {
			req[source] = result.data;
		}
		next();
	};
}
```

### Schemas comunes

```ts
// shared/http/schemas/common.ts
export const idParamSchema = z.object({ id: z.coerce.number().int().positive() });
export const dateRangeSchema = z.object({
	fechaF: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Formato: YYYY-MM-DD"),
	fechaI: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Formato: YYYY-MM-DD")
});
```

### JWT + authMiddleware

```ts
// shared/infrastructure/auth/jwt.ts
export interface TokenPayload { id: number; email: string; }
interface SignTokenParams { payload: TokenPayload; secret: string; expiresIn: string | number; }

export function signToken({ payload, secret, expiresIn }: SignTokenParams): string {
	return jwt.sign(payload, secret, { expiresIn: expiresIn as jwt.SignOptions["expiresIn"] });
}
export function verifyToken(token: string, secret: string): TokenPayload {
	return jwt.verify(token, secret) as TokenPayload;
}

// shared/http/middleware/authMiddleware.ts
export type AuthenticatedRequest = Request & { user: TokenPayload };

export function authMiddleware(jwtSecret: string) {
	return (req: Request, _res: Response, next: NextFunction): void => {
		const header = req.headers.authorization;
		if (!header?.startsWith("Bearer ")) {
			next(new AppError("Token no proporcionado", 401));
			return;
		}
		try {
			(req as AuthenticatedRequest).user = verifyToken(header.slice(7), jwtSecret);
			next();
		} catch {
			next(new AppError("Token inválido o expirado", 401));
		}
	};
}
```

### Base de datos: MariaDB con el paquete `mysql`

El motor es **MariaDB**. Se conecta con el paquete `mysql` (2.x), que es compatible con MariaDB. La carpeta, las funciones y las variables conservan el nombre `mysql`/`MYSQL_*` porque así se llama el paquete y el protocolo.

El paquete `mysql` (2.x) trabaja con callbacks. Este archivo lo envuelve una sola vez en promesas, así los repositorios usan `async/await` y no tocan el paquete directamente.

```ts
// shared/infrastructure/mysql/connection.ts
import type { Env } from "@shared/config/env";
import mysql from "mysql";

// Resultado de INSERT / UPDATE / DELETE
export interface WriteResult {
	insertId: number;
	affectedRows: number;
}

interface QueryParams {
	sql: string;
	params?: unknown[];
}

export type QueryFn = <T>(query: QueryParams) => Promise<T>;
export type TransactionFn = <T>(work: (tx: QueryFn) => Promise<T>) => Promise<T>;

let pool: mysql.Pool | null = null;

export function getMysqlPool(env: Env): mysql.Pool {
	if (pool) return pool;
	pool = mysql.createPool({
		connectionLimit: Number.parseInt(env.MYSQL_CONNECTION_LIMIT, 10),
		database: env.MYSQL_DATABASE,
		dateStrings: true, // fechas como string, no Date con zona horaria
		host: env.MYSQL_HOST,
		password: env.MYSQL_PASSWORD,
		port: Number.parseInt(env.MYSQL_PORT, 10),
		user: env.MYSQL_USER,
		waitForConnections: true
	});
	return pool;
}

// Convierte el callback de mysql en promesa. Sirve para Pool y PoolConnection.
function run<T>(
	target: mysql.Pool | mysql.PoolConnection,
	{ sql, params = [] }: QueryParams
): Promise<T> {
	return new Promise((resolve, reject) => {
		target.query(sql, params, (err, results) => {
			if (err) {
				reject(err);
				return;
			}
			resolve(results as T);
		});
	});
}

export function createQuery(dbPool: mysql.Pool): QueryFn {
	return (query) => run(dbPool, query);
}

// Ejecuta `work` en una transacción: commit si termina bien, rollback si lanza.
export function createTransaction(dbPool: mysql.Pool): TransactionFn {
	return (work) =>
		new Promise((resolve, reject) => {
			dbPool.getConnection((connErr, conn) => {
				if (connErr) {
					reject(connErr);
					return;
				}
				conn.beginTransaction(async (txErr) => {
					if (txErr) {
						conn.release();
						reject(txErr);
						return;
					}
					try {
						const result = await work((query) => run(conn, query));
						conn.commit((commitErr) => {
							if (commitErr) {
								conn.rollback(() => {
									conn.release();
									reject(commitErr);
								});
								return;
							}
							conn.release();
							resolve(result);
						});
					} catch (err) {
						conn.rollback(() => {
							conn.release();
							reject(err);
						});
					}
				});
			});
		});
}

export function closeMysqlPool(): Promise<void> {
	return new Promise((resolve, reject) => {
		if (!pool) {
			resolve();
			return;
		}
		pool.end((err) => {
			pool = null;
			if (err) {
				reject(err);
				return;
			}
			resolve();
		});
	});
}
```

- La conexión recibe `env` por parámetro; no lee `process.env`. Las reglas de `env.ts` están en `setup.md` §5.
- Los repositorios reciben `QueryFn` (y `TransactionFn` si la necesitan), no el pool. Así en tests se reemplazan con un `vi.fn()` y el repositorio no depende del paquete.
- Si un proyecto existente ya usa `mysql2`, no lo cambies: `mysql2/promise` trae `pool.execute<RowDataPacket[]>(sql, params)` y no necesita este wrapper. Mantén el mismo contrato `QueryFn` para que los repositorios se vean igual.

## 5. Una feature por capas (ejemplo `items`)

### domain/: entidad y port

```ts
// domain/Item.ts
export interface Item { id: number; name: string; quantity: number; createdAt: string; }
export type ItemCreate = Pick<Item, "name" | "quantity">;

// domain/ItemRepository.ts — el dominio define el contrato; infrastructure lo implementa
export interface ItemRepository {
	findAll(): Promise<Item[]>;
	findByName(name: string): Promise<Item | null>;
	create(data: ItemCreate): Promise<Item>;
}
```

Catálogos y mapeos como diccionarios `as const`, nunca con `switch`:

```ts
export const ORDER_STATUS = { 0: "Pendiente", 1: "Aprobada", 2: "Anulada" } as const;
export function getOrderStatusLabel(code: number): string {
	return ORDER_STATUS[code as keyof typeof ORDER_STATUS] ?? "Desconocido";
}
```

### application/: use case

Una clase por archivo, con constructor DI y un único `execute()`. Los errores de negocio se lanzan con `AppError(mensaje, httpStatus)`.

```ts
export class CreateItem {
	constructor(private readonly itemRepo: ItemRepository) {}

	async execute(data: ItemCreate): Promise<Item> {
		const existing = await this.itemRepo.findByName(data.name);
		if (existing) throw new AppError("Ya existe un item con ese nombre", 409);
		return this.itemRepo.create(data);
	}
}
```

La configuración (secreto JWT, `HOST`, etc.) también entra por constructor; el use case no lee `process.env`.

### http/: schemas, controller, routes

```ts
// schemas.ts — keys en orden alfabético (Biome useSortedKeys)
export const createItemSchema = z.object({
	name: z.string().min(1, "Nombre requerido").max(120),
	quantity: z.number().int().nonnegative()
});

// ItemController.ts — métodos arrow para no perder `this` al pasarlos al router
export class ItemController {
	constructor(
		private readonly listItemsUC: ListItems,
		private readonly createItemUC: CreateItem
	) {}

	list = async (_req: Request, res: Response, next: NextFunction): Promise<void> => {
		try {
			res.json(await this.listItemsUC.execute());
		} catch (err) {
			next(err);
		}
	};

	create = async (req: Request, res: Response, next: NextFunction): Promise<void> => {
		try {
			res.status(201).json(await this.createItemUC.execute(req.body));
		} catch (err) {
			next(err);
		}
	};
}

// item.routes.ts — factory que recibe el controller
export function itemRoutes(controller: ItemController): Router {
	const router = Router();
	router.get("/", controller.list);
	router.post("/", validate(createItemSchema), controller.create);
	// router.get("/:id", validate(idParamSchema, "params"), controller.detail);
	return router;
}
```

### infrastructure/: repositorio MariaDB

```ts
import type { Item, ItemCreate } from "@features/items/domain/Item";
import type { ItemRepository } from "@features/items/domain/ItemRepository";
import type { QueryFn, WriteResult } from "@shared/infrastructure/mysql/connection";

const SELECT_ITEM = "SELECT id, name, quantity, created_at AS createdAt FROM items";

export class MysqlItemRepository implements ItemRepository {
	constructor(private readonly query: QueryFn) {}

	async findAll(): Promise<Item[]> {
		return this.query<Item[]>({ sql: `${SELECT_ITEM} ORDER BY id DESC` });
	}

	async findByName(name: string): Promise<Item | null> {
		const rows = await this.query<Item[]>({
			params: [name],
			sql: `${SELECT_ITEM} WHERE name = ?`
		});
		return rows[0] ?? null;
	}

	async create(data: ItemCreate): Promise<Item> {
		const result = await this.query<WriteResult>({
			params: [data.name, data.quantity],
			sql: "INSERT INTO items (name, quantity) VALUES (?, ?)"
		});
		const rows = await this.query<Item[]>({
			params: [result.insertId],
			sql: `${SELECT_ITEM} WHERE id = ?`
		});
		return rows[0];
	}
}
```

Reglas de base de datos:

- Siempre placeholders `?` en `params`. Nunca interpolar valores del usuario en el SQL. Los `?` del paquete `mysql` escapan el valor en el cliente; para nombres de columna o tabla dinámicos usa `??` (o una lista blanca), nunca un string armado con input del usuario.
- Lecturas tipadas con `query<Entidad[]>`; escrituras con `query<WriteResult>` (`insertId`, `affectedRows`).
- Alias `snake_case AS camelCase` en el SELECT para que la fila coincida con la entidad.
- Columnas JSON: en MariaDB `JSON` es un alias de `LONGTEXT` (con `CHECK (JSON_VALID(col))`), así que el valor siempre llega como string. Usa `JSON.stringify` al escribir y `JSON.parse` al leer, dentro del repositorio.
- Transacciones (varias escrituras que deben ir juntas): el repositorio recibe `TransactionFn` y todo lo de adentro usa `tx`:
  ```ts
  constructor(private readonly query: QueryFn, private readonly transaction: TransactionFn) {}

  async createOrder(order: OrderCreate): Promise<number> {
  	return this.transaction(async (tx) => {
  		const result = await tx<WriteResult>({ params: [order.customerId], sql: "INSERT INTO orders (customer_id) VALUES (?)" });
  		for (const line of order.lines) {
  			await tx<WriteResult>({ params: [result.insertId, line.itemId, line.qty], sql: "INSERT INTO order_lines (order_id, item_id, qty) VALUES (?, ?, ?)" });
  		}
  		return result.insertId; // si algo lanza antes, se hace rollback
  	});
  }
  ```
- El DDL de las tablas se guarda en `database/schema.sql`.
- El DDL se escribe para MariaDB (`ENGINE=InnoDB`, `DEFAULT CHARSET=utf8mb4`). Si algún día el servidor fuera MySQL 8, el paquete `mysql` 2.x no soporta su autenticación por defecto (`caching_sha2_password`): habría que crear el usuario con `mysql_native_password` o pasar a `mysql2`.

### Otras piezas que pueden aparecer en una feature

- **PDF (pdfkit):** el port vive en `domain/` (`generate(entity, output: Writable)`) y la implementación en `infrastructure/pdf/`. El controller fija los headers (`Content-Type: application/pdf`) antes del use case y le pasa `res` como `output` (stream, sin buffer en memoria). Fuentes e imágenes van en `<raíz>/assets/`, no en `src/`.
- **WebSocket (socket.io):** los handlers van en `infrastructure/<feature>Handlers.ts` y se registran en `src/socketSetup.ts`. Los use cases emiten a través de un port `SocketEmitter` (`shared/domain/`). Los eventos usan el formato `feature:accion`.

## 6. Wiring

### container.ts

Orden: infraestructura → use cases → controllers. Exporta el tipo `Controllers`.

```ts
export function createContainer() {
	const env = loadEnv();
	const mysqlPool = getMysqlPool(env);
	const query = createQuery(mysqlPool);
	// const transaction = createTransaction(mysqlPool); // solo si algún repo la usa

	// --- Infrastructure ---
	const itemRepo = new MysqlItemRepository(query);

	// --- Use cases ---
	const listItems = new ListItems(itemRepo);
	const createItem = new CreateItem(itemRepo);

	// --- Controllers ---
	return {
		controllers: { items: new ItemController(listItems, createItem) },
		env
	};
}

export type Controllers = ReturnType<typeof createContainer>["controllers"];
```

### routes.ts

Recibe `Partial<Controllers>` y monta solo los que existen. Así `createApp()` y `createTestApp()` comparten exactamente el mismo routing, y un integration test solo construye el controller que prueba.

```ts
interface RegisterRoutesParams {
	app: Express;
	controllers: Partial<Controllers>;
	jwtSecret: string;
}

export function registerRoutes({ app, controllers, jwtSecret }: RegisterRoutesParams): void {
	const protect = authMiddleware(jwtSecret);

	// Públicas
	if (controllers.auth) app.use("/api/auth", authRoutes(controllers.auth));

	// Protegidas
	if (controllers.items) app.use("/api/items", protect, itemRoutes(controllers.items));
}
```

> En batch-record las rutas se montan en `app.ts` y `tests/helpers/createTestApp.ts` las duplica, por lo que cada integration test debe instanciar todos los controllers con `null as never`. `routes.ts` corrige eso: úsalo en proyectos nuevos y propónlo al hacer retrofit.

### app.ts

```ts
export function createApp() {
	const { env, controllers } = createContainer();
	const app = express();
	app.use(cors());
	app.use(express.json({ limit: "5mb" }));
	registerRoutes({ app, controllers, jwtSecret: env.JWT_SECRET });
	app.use(errorHandler); // siempre al final
	return { app, env };
}
```

### server.ts

```ts
import "dotenv/config"; // primera línea: antes de cualquier módulo que lea process.env
import { createServer } from "node:http";
import { createApp } from "./app";

const { app, env } = createApp();
const server = createServer(app);
server.listen(Number.parseInt(env.PORT, 10), () => {
	process.stdout.write(`Servidor escuchando en ${env.HOST}\n`);
});
```

## 7. Convenciones de código

| Usa | En vez de |
| --- | --------- |
| `??` para defaults | `\|\|` (convierte `0` y `""` en el default) |
| Diccionario `Record<...> as const` | `switch` |
| `unknown` + type guard | `any` |
| Objeto tipado (`interface XParams`) desestructurado, en funciones con 2+ parámetros | Parámetros posicionales |
| Early return | `if/else` anidados |
| Import directo al archivo | Barrel files (`index.ts`) |
| Aliases `@features/...`, `@shared/...` entre capas | `../../../` |
| `import { z } from "zod"` | `"zod/v4"` |
| `import` | `require()` |

- Nombres: `PascalCase` para clases e interfaces, `camelCase` para funciones y variables, `UPPER_SNAKE_CASE` para constantes.
- Archivos: `PascalCase.ts` para clases (`CreateItem.ts`, `ItemController.ts`) y `camelCase` con sufijo para rutas (`item.routes.ts`).
- Excepción aceptada: los constructores de use cases y controllers usan parámetros posicionales (DI).
- Respeta el idioma del dominio del proyecto (nombres en español si el negocio habla así: `fechaI`, `id_orden`).

## 8. Checklist para una feature nueva

1. `domain/`: entidad y port.
2. `application/`: un use case por archivo.
3. `infrastructure/`: repositorio (MariaDB) que implementa el port (y el DDL en `database/schema.sql`).
4. `http/`: `schemas.ts`, controller y `<feature>.routes.ts`.
5. `container.ts` (instanciar) y `routes.ts` (montar).
6. Tests: `tests/features/<feature>/unit/<UseCase>.test.ts` e `integration/<feature>.test.ts`.
7. REST Client: `tests/http/<feature>/request.http` y `responses/<accion>-<status>.json`.
8. Fila del endpoint en el README (y, si hay variables nuevas, en `.env.example` y en `env.ts`).
9. `npm run check:fix && npm run typecheck && npm test`.
