---
name: typescript-aliases
description: "Skill experta en crear y configurar path aliases (@) en proyectos Node.js con TypeScript. Usar cuando se pida: crear aliases, agregar aliases con @, configurar imports con @, refactorizar imports relativos por aliases, eliminar rutas con ../../../, o que un proyecto nuevo use aliases. También corrige tsconfigs con `baseUrl` o `moduleResolution: node` cuando el editor los marca 'en desuso' (TypeScript 6/7, errores TS5101/TS5107/TS5102/TS5108/TS5090). Configura tsconfig.json (`paths` relativos, sin `baseUrl`), instala tsconfig-paths y tsc-alias, actualiza scripts de dev/build, y refactoriza imports existentes. Arquitectura-agnóstica: funciona con MVC, hexagonal, feature-based, layered, modular, etc."
license: Complete terms in LICENSE.txt
---

# TypeScript Path Aliases — Configuración Completa

Skill para crear y configurar path aliases (`@carpeta/*`) en proyectos Node.js con TypeScript, reemplazando imports relativos profundos (`../../../`) por rutas limpias y legibles.

**Arquitectura-agnóstica** — funciona con cualquier estructura de carpetas dentro de `src/`.

## Cuando Usar Esta Skill

- Crear aliases `@` en un proyecto TypeScript nuevo o existente
- Refactorizar imports relativos (`../../../`) por aliases (`@carpeta/...`)
- Resolver errores "Cannot find module '@alias/...'" en desarrollo o compilación
- Configurar que `dist/` genere rutas correctas tras compilar con aliases
- Entender cómo funcionan `paths`, `tsconfig-paths` y `tsc-alias`
- Quitar los avisos "`baseUrl` está en desuso" / "`moduleResolution=node10` está en desuso" del editor (ver **Migrar un tsconfig con `baseUrl`**)

## Paso 0 (OBLIGATORIO): Leer el Proyecto

**Antes de crear o modificar cualquier archivo**, el agente DEBE:

1. Leer `tsconfig.json` para detectar `paths`, `rootDir` e `include` existentes. Si trae `baseUrl`, `moduleResolution: "node"`/`"node10"` o `ignoreDeprecations`, migrarlo primero (sección **Migrar un tsconfig con `baseUrl`**)
2. Listar las carpetas de primer nivel dentro de `src/` para identificar los aliases necesarios
3. Leer `package.json` → `scripts` para no pisar scripts existentes
4. Si es refactorización: buscar imports relativos profundos (`../../../`) que sean candidatos

A partir de las carpetas encontradas, generar la lista de aliases. Ejemplos:

| Carpetas en `src/` | Aliases resultantes |
|---------------------|---------------------|
| `controllers/`, `models/`, `routes/`, `config/` | `@controllers/*`, `@models/*`, `@routes/*`, `@config/*` |
| `features/`, `shared/`, `config/` | `@features/*`, `@shared/*`, `@config/*` |
| `domain/`, `application/`, `infrastructure/` | `@domain/*`, `@application/*`, `@infrastructure/*` |
| `modules/`, `common/`, `lib/` | `@modules/*`, `@common/*`, `@lib/*` |

> **Regla**: Crear un alias `@carpeta/*` por cada subcarpeta directa de `src/` que contenga código importable. Omitir carpetas que no se importan (como `__tests__`, `assets`, `public`).

## Paso 1: Instalar Dependencias

```bash
npm install -D tsconfig-paths@^4 tsc-alias@^1.8
```

`tsconfig-paths` 3.x exige `baseUrl`; desde la 4 funciona con `paths` relativos.

| Paquete | Función |
|---------|---------|
| `tsconfig-paths` | Resuelve aliases en desarrollo con `tsx` o `ts-node` |
| `tsc-alias` | Reemplaza aliases por rutas relativas correctas en `dist/` tras compilar |

## Paso 2: Configurar tsconfig.json

Agregar `paths` dentro de `compilerOptions`, **sin `baseUrl`**. Adaptar los alias a las carpetas reales del proyecto:

```jsonc
// tsconfig.json — Adaptar aliases a la estructura real del proyecto
{
    "compilerOptions": {
        "outDir": "./dist",
        "rootDir": "./src",
        "module": "node16",            // CommonJS: sin "type": "module" en package.json
        "moduleResolution": "node16",  // nunca "node"/"node10": obsoleto en TS 6, eliminado en TS 7
        "paths": {
            // Un alias por cada carpeta importable dentro de src/
            // Rutas relativas al propio tsconfig, empezando con ./
            "@carpetaA/*": ["./src/carpetaA/*"],
            "@carpetaB/*": ["./src/carpetaB/*"],
            "@config/*": ["./src/config/*"]
        }
    },
    "include": ["src/**/*"],
    "exclude": ["node_modules", "dist"]
}
```

### Convención de nombres

| Patrón | Ejemplo | Cuándo usar |
|--------|---------|-------------|
| `@nombre-carpeta/*` | `@models/*`, `@config/*` | Por defecto — 1 alias por carpeta |
| `@/*` | `@/models/*`, `@/config/*` | Alias único raíz (estilo Vue/Next) — solo si se solicita explícitamente |

### Reglas para `paths`

- Cada valor es un **array** de rutas relativas al tsconfig que las declara, empezando con `./` (sin `./`, TS 7 da `TS5090 Non-relative paths are not allowed`)
- El `/*` al final es obligatorio en tsconfig para indicar wildcard
- **No usar `baseUrl`** ni `ignoreDeprecations`: TypeScript 6 (el que trae VS Code aunque el proyecto use 5.x) marca `baseUrl` como obsoleto, `"ignoreDeprecations": "5.0"` no lo silencia, `"6.0"` lo rechaza TS 5.x, y TypeScript 7 lo elimina
- Un `tsconfig` hijo con `extends` (como `tests/tsconfig.json`) hereda los `paths` resueltos desde el padre: no necesita repetirlos ni declarar `baseUrl`
- ESM (`"type": "module"`): `moduleResolution: "bundler"` con `module: "esnext"`/`"preserve"` si un bundler o `tsx` ejecuta el código; `"nodenext"` si corre Node directo (imports relativos con `.js`)

## Paso 3: Actualizar Scripts en package.json

Agregar o **fusionar** con los scripts existentes (no reemplazar scripts que ya estén):

```json
{
    "scripts": {
        "dev": "tsx watch -r tsconfig-paths/register src/<entrypoint>.ts",
        "build": "tsc && tsc-alias",
        "typecheck": "tsc --noEmit"
    }
}
```

**Notas para el agente:**

- `<entrypoint>` → detectarlo del `package.json` actual (`main`, `scripts.dev` existente, o convención: `server.ts`, `main.ts`, `index.ts`, `app.ts`)
- `dev`: **Obligatorio** usar `-r tsconfig-paths/register` para que `tsx`/`ts-node` resuelva los aliases
- `build`: **Obligatorio** ejecutar `tsc-alias` después de `tsc` para traducir aliases a rutas relativas en `dist/`
- Si usa `nodemon` en vez de `tsx`: `"dev": "nodemon --exec ts-node -r tsconfig-paths/register src/<entrypoint>.ts"`
- NO pisar scripts existentes como `start`, `lint`, `format`, etc.

## Paso 4: Refactorizar Imports (si aplica)

Si el proyecto ya tiene código con imports relativos, reemplazar:

```typescript
// Antes
import { pool } from "../../../config/database";
import { UserService } from "../../services/UserService";

// Después
import { pool } from "@config/database";
import { UserService } from "@services/UserService";
```

**Proceso para refactorizar:**

1. Buscar todos los imports con `../` en archivos `.ts` dentro de `src/`
2. Para cada import, determinar a qué carpeta alias corresponde la ruta resuelta
3. Reemplazar la ruta relativa por el alias equivalente
4. Ejecutar `npm run typecheck` para confirmar que no hay errores
5. Ejecutar `npm run build` para confirmar que `dist/` genera rutas correctas

## Verificación

Ejecutar en este orden para confirmar que todo funciona:

```bash
# 1. TypeScript compila sin errores, también con TS 6 y 7 (lo que usa el editor)
npm run typecheck
node <skill>/scripts/fix-tsconfig-deprecations.mjs --verify

# 2. Build genera dist/ con rutas relativas correctas (no alias)
npm run build

# 3. Inspeccionar un archivo en dist/ para confirmar
# Los imports deben ser rutas relativas, NO @alias/...
cat dist/<cualquier-archivo>.js | grep require

# 4. Dev arranca sin errores
npm run dev
```

## Troubleshooting

| Problema | Causa | Solución |
|----------|-------|----------|
| `Cannot find module '@alias/...'` en `npm run dev` | Falta `-r tsconfig-paths/register` | Agregar el flag al script `dev` |
| `Cannot find module '@alias/...'` en `dist/` | Falta `tsc-alias` post-compilación | Agregar `tsc-alias` al script `build` |
| `Cannot find module '@alias/...'` en el editor | VS Code no reconoce los paths | Reiniciar TS Server (Ctrl+Shift+P → "TypeScript: Restart TS Server") |
| `dist/` contiene `@alias/...` en los requires | `tsc-alias` no se ejecutó | Verificar que build sea `tsc && tsc-alias` (con `&&`) |
| Los alias no resuelven desde `tests/` | tests fuera del `include` de tsconfig | Ver skill `vitest-typescript` para configuración de tests |
| "La opción `baseUrl` está en desuso" (TS5101) o "`moduleResolution=node10` está en desuso" (TS5107) en el editor | El editor usa TS 6+ y el tsconfig trae opciones viejas | `node <skill>/scripts/fix-tsconfig-deprecations.mjs --write --verify` |
| `TS5102`/`TS5108` "has been removed" o `TS5090 Non-relative paths` | Lo mismo, con TypeScript 7 | Igual: el script migra `paths` a `./src/...` |
| `Cannot find module '@alias/...'` en dev tras quitar `baseUrl` | `tsconfig-paths` 3.x | `npm i -D tsconfig-paths@^4` |

## Migrar un tsconfig con `baseUrl`

Si el proyecto ya tiene aliases con `baseUrl` (el patrón viejo), o el editor muestra los avisos de TS 6/7, no lo silencies: migra con el script de esta skill (sin dependencias, Node ≥ 18).

```bash
node <skill>/scripts/fix-tsconfig-deprecations.mjs                    # simulación: muestra qué cambiaría
node <skill>/scripts/fix-tsconfig-deprecations.mjs --write --verify   # aplica y verifica con TS del proyecto, 6 y 7
```

Busca `tsconfig*.json` en la raíz y un nivel abajo (incluye `tests/tsconfig.json`) y hace esto:
- Reescribe `paths` relativos al tsconfig (`"shared/*"` + `baseUrl: "./src"` → `"./src/shared/*"`), también en tsconfigs hijos que heredaban el `baseUrl`.
- Quita `baseUrl` e `ignoreDeprecations`.
- En CommonJS pasa `module`/`moduleResolution` a `"node16"`, que sigue emitiendo `require()`.
- En ESM solo explica las opciones.
- Avisa si `tsconfig-paths` < 4 o `tsc-alias` < 1.8.

Después corre la **Verificación** completa (build, `dist/` sin `@alias`, dev) y recarga el TS Server del editor. Si el tsconfig tenía comentarios, se pierden al reescribir: revisa el diff.

## Referencias

- [TypeScript — Path Mapping](https://www.typescriptlang.org/docs/handbook/modules/reference.html#paths)
- [tsconfig-paths](https://github.com/dividab/tsconfig-paths)
- [tsc-alias](https://github.com/justkey007/tsc-alias)
