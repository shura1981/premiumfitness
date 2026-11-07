---
name: express-ts-vertical-slice
description: "Estándar de backends Express 5 + TypeScript con Vertical Slicing + Screaming Architecture, DI manual, MariaDB (paquete mysql), Zod, Biome, aliases (@features/@shared), Vitest + supertest, variables .env y colección REST Client (.http), basado en batch-record. Úsala para (A) crear un proyecto/API nuevo con esta estructura y (B) corregir o modernizar proyectos existentes: agregar aliases @, instalar o migrar a Biome (desde ESLint/Prettier), configurar Vitest/supertest o arreglar tests que no encuentran módulos, crear archivos .http para REST Client, validar .env con Zod, agregar AppError/errorHandler, migrar Express 4→5, refactorizar de MVC a features por dominio o corregir un tsconfig con `baseUrl`/`moduleResolution: node` (avisos 'está en desuso' de TypeScript 6/7, errores TS5101/TS5107/TS5090). Actívala aunque el usuario no diga 'vertical slicing': basta con que pida 'arma un proyecto como batch-record', 'deja este proyecto con la misma estructura', 'ordena la arquitectura', 'agrega una feature/endpoint siguiendo el estándar' o 'revisa qué le falta a este proyecto'."
---

# Express 5 + TS: Vertical Slicing

Estándar de backend extraído de **batch-record**: Express 5, TypeScript (ES2024, `node16` emitiendo CommonJS), MariaDB (con el paquete `mysql` 2.x), features autocontenidas (`domain/` → `application/` → `http/` + `infrastructure/`), DI manual en `container.ts`, Zod para inputs y `.env`, Biome, aliases `@features/*` y `@shared/*`, Vitest (unit + integration) y una colección REST Client en `tests/http/`.

Esta skill es documentación: describe la estructura y las configuraciones. Los archivos se generan a partir de los fragmentos de las guías, adaptados a cada proyecto. La única excepción es `scripts/fix-tsconfig-deprecations.mjs` (ver más abajo).

## Guías

| Archivo | Contenido | Cuándo leerlo |
| ------- | --------- | ------------- |
| `references/architecture.md` | Árbol de carpetas, reglas de dependencia, código de `shared/`, una feature completa por capas, MariaDB, wiring (`container`, `routes`, `app`, `server`), convenciones, checklist de feature nueva | Siempre que se escriba o mueva código |
| `references/setup.md` | Paquetes, scripts, tsconfig, aliases (4 lugares), `.env` (`env.ts`, `.env.example`, reglas), Biome, VS Code, Vitest (config, helpers, patrones de test), REST Client, README/CLAUDE.md, verificación y errores típicos | Al crear un proyecto o configurar herramientas |
| `references/retrofit.md` | Diagnóstico de un proyecto existente y playbooks: aliases, Biome, Vitest, .env, REST Client, MVC → vertical slicing, convenciones, Express 4→5 | Al corregir o modernizar un proyecto |

## Qué modo aplica

| Situación | Qué hacer |
| --------- | --------- |
| Directorio vacío: "crea / arma un proyecto" | **Proyecto nuevo** (abajo) |
| Ya hay `package.json` o `src/`: "agrega / corrige / migra / revisa" | **Proyecto existente** (abajo) |
| "Agrega la feature X" en un proyecto que ya sigue el estándar | Checklist de `architecture.md` §8 |

### Proyecto nuevo

1. Confirma el nombre, la ruta y si necesita login, PDF o WebSocket. Si no lo dice, crea el núcleo: Express + Zod + JWT + MariaDB (paquete `mysql`).
2. Lee `setup.md` y `architecture.md`, y crea los archivos en este orden:
   1. `package.json`, `tsconfig.json`, `biome.json`, `vitest.config.ts`, `tests/tsconfig.json`, `.vscode/`, `.gitignore`, `.nvmrc`.
   2. `.env.example` y `src/shared/`.
   3. `container.ts`, `routes.ts`, `app.ts` y `server.ts`.
   4. La primera feature con sus tests y su `request.http`.
   5. `README.md` y `CLAUDE.md`.
3. `npm install` y verifica: `npm run check:fix && npm run check && npm run typecheck && npm test && npm run build`. Todo debe pasar.
4. Reporta lo creado, lo verificado y los pasos pendientes (crear `.env`, cargar `database/schema.sql`, instalar las extensiones sugeridas). No hagas `git init` ni commits salvo que te lo pidan.

### Proyecto existente

1. Diagnostica siguiendo `retrofit.md` §0 y presenta un plan corto y priorizado. Corre siempre `node <skill>/scripts/fix-tsconfig-deprecations.mjs` (sin `--write`) como parte del diagnóstico.
2. Aplica un playbook a la vez y verifica después de cada uno.
3. Para refactors de arquitectura, avanza una feature por vez y con una red de seguridad (tests o REST Client). No cambies lógica mientras mueves archivos.
4. Reporta qué cambió, qué se verificó, qué quedó pendiente y cualquier bug que encontraste pero no corregiste.

El estándar define la estructura y las herramientas, no el dominio: respeta el idioma de los nombres, el formato de las respuestas y las tablas existentes.

## Reglas clave

Cada regla tiene una razón. Si un caso real la contradice, explícaselo al usuario en vez de forzarla.

- **Dependencias:** dentro de una feature, `domain` ← `application` ← `http`/`infrastructure`. Entre features, solo se importa `domain/`. `shared/` nunca importa features. `container.ts` es el único wiring. Así una feature se puede mover o borrar sin efectos colaterales.
- **Use case:** clase + constructor DI + `execute()`. Errores de negocio con `AppError(msg, status)`; el `errorHandler` global los traduce y todo lo demás es 500.
- **Controller:** métodos arrow con `try/catch → next(err)`. **Routes:** factory que recibe el controller, con `validate(schema, "body" | "params" | "query")`.
- **Rutas en un solo lugar** (`routes.ts` con `Partial<Controllers>`), compartido por la app y los tests.
- **Express 5:** `req.query` es de solo lectura (el `validate` usa `Object.defineProperty`).
- **MariaDB (paquete `mysql`):** pool singleton que recibe `env`, envuelto una sola vez en promesas (`createQuery` / `createTransaction` en `shared/infrastructure/mysql/connection.ts`). Los repositorios reciben `QueryFn`, no el pool. Placeholders `?` siempre. Si el proyecto ya usa `mysql2`, se respeta.
- **.env:** `process.env` solo se lee en `shared/config/env.ts` (Zod, la app no arranca si falta algo) y el container reparte `env.X`. Cada variable nueva va a la vez en `env.ts`, `.env.example` y el README. Las `TEST_*` de REST Client van en `.env.example` pero no en `env.ts`. `HOST` va sin `/` final.
- **tsconfig sin opciones obsoletas:** nada de `baseUrl`, `moduleResolution: "node"` ni `ignoreDeprecations`; `paths` relativos (`./src/...`) y `node16`. Si aparecen, se migran con el script de abajo.
- **Aliases en 4 lugares sincronizados:** tsconfig, dev (`tsconfig-paths`), build (`tsc-alias`) y tests (`vitest.config.ts` + `tests/tsconfig.json`).
- **Biome:** tabs, comillas dobles, `;`, sin trailing commas y keys ordenadas. `npm run check:fix` después de cada cambio.
- **Código:** `??` en vez de `||`, `Record as const` en vez de `switch`, `unknown` en vez de `any`, objeto tipado para funciones con 2+ parámetros, sin barrel files, `import { z } from "zod"`.
- **Cada endpoint nuevo** lleva unit test del use case, integration test de la ruta, entrada en `tests/http/<feature>/request.http` + `responses/` y fila en el README.
- **Seguridad:** nada de credenciales en archivos versionados (los `.http` usan `{{$dotenv VAR}}`). No ejecutes peticiones que escriben o borran contra entornos reales, ni hagas commits o push, sin que el usuario lo pida.

## tsconfig obsoleto (avisos de TypeScript 6/7)

Si el editor marca `La opción "baseUrl" está en desuso`, `moduleResolution=node10 está en desuso`, `TS5101`, `TS5107`, `TS5102`, `TS5108` o `TS5090 Non-relative paths are not allowed`, el problema está en el tsconfig, no en el código. VS Code trae TypeScript 6 aunque el proyecto use 5.x: TS 6 marca esas opciones como obsoletas y TS 7 las elimina, y `"ignoreDeprecations": "5.0"` no las silencia. **No lo tapes con `"ignoreDeprecations": "6.0"`**: TS 5.x rechaza ese valor y en TS 7 deja de servir. Corrígelo con el script de esta skill:

```bash
node <skill>/scripts/fix-tsconfig-deprecations.mjs            # muestra qué cambiaría
node <skill>/scripts/fix-tsconfig-deprecations.mjs --write --verify
```

Busca `tsconfig*.json` en la raíz y un nivel abajo (`tests/tsconfig.json` incluido) y hace lo siguiente:
- Reescribe `paths` relativos al propio tsconfig (`"shared/*"` con `baseUrl: "./src"` pasa a `"./src/shared/*"`), también en tsconfigs hijos que heredaban el `baseUrl` del padre.
- Quita `baseUrl` e `ignoreDeprecations`.
- En proyectos CommonJS (sin `"type": "module"`) cambia `module` y `moduleResolution` a `"node16"`, que sigue emitiendo `require()`. En ESM no cambia nada: solo explica las opciones (`bundler` o `nodenext`).
- Avisa si `tsconfig-paths` < 4 (exige `baseUrl`), si `tsc-alias` < 1.8, o si quedan otras opciones obsoletas (`target` ES5, `outFile`, `module` amd/umd).
- `--verify` corre `tsc --noEmit` con el TypeScript del proyecto, con TS 6 y con TS 7.

Después corre build, tests y el arranque en dev, confirma que `dist/` no tenga `require("@alias/…")` sin resolver, y pide recargar el servidor de TypeScript del editor (**TypeScript: Restart TS Server**). Si el tsconfig tenía comentarios, el script avisa que se perdieron: revisa el diff.
