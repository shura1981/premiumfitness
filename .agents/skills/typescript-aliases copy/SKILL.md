---
name: typescript-aliases
description: "Skill experta en crear y configurar path aliases (@) en proyectos Node.js con TypeScript. Usar cuando se pida: crear aliases, agregar aliases con @, configurar imports con @, refactorizar imports relativos por aliases, eliminar rutas con ../../../, o que un proyecto nuevo use aliases. Configura tsconfig.json (baseUrl + paths), instala tsconfig-paths y tsc-alias, actualiza scripts de dev/build, y refactoriza imports existentes. Arquitectura-agnóstica: funciona con MVC, hexagonal, feature-based, layered, modular, etc."
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
- Entender cómo funcionan `baseUrl`, `paths`, `tsconfig-paths` y `tsc-alias`

## Paso 0 (OBLIGATORIO): Leer el Proyecto

**Antes de crear o modificar cualquier archivo**, el agente DEBE:

1. Leer `tsconfig.json` para detectar `baseUrl`, `paths`, `rootDir` y `include` existentes
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
npm install -D tsconfig-paths tsc-alias
```

| Paquete | Función |
|---------|---------|
| `tsconfig-paths` | Resuelve aliases en desarrollo con `tsx` o `ts-node` |
| `tsc-alias` | Reemplaza aliases por rutas relativas correctas en `dist/` tras compilar |

## Paso 2: Configurar tsconfig.json

Agregar `baseUrl` y `paths` dentro de `compilerOptions`. Adaptar los alias a las carpetas reales del proyecto:

```jsonc
// tsconfig.json — Adaptar aliases a la estructura real del proyecto
{
    "compilerOptions": {
        "outDir": "./dist",
        "rootDir": "./src",
        "baseUrl": "./src",       // ← Base para resolver los aliases
        "paths": {
            // Un alias por cada carpeta importable dentro de src/
            "@carpetaA/*": ["carpetaA/*"],
            "@carpetaB/*": ["carpetaB/*"],
            "@config/*": ["config/*"]
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

- Cada valor es un **array** de rutas relativas al `baseUrl`
- El `/*` al final es obligatorio en tsconfig para indicar wildcard
- `baseUrl` debe apuntar a `./src` (o donde esté el código fuente)

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
# 1. TypeScript compila sin errores
npm run typecheck

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
| Error `baseUrl` con rutas incorrectas | `baseUrl` no apunta a `src/` | Asegurar `"baseUrl": "./src"` |

## Referencias

- [TypeScript — Path Mapping](https://www.typescriptlang.org/docs/handbook/modules/reference.html#paths)
- [tsconfig-paths](https://github.com/dividab/tsconfig-paths)
- [tsc-alias](https://github.com/justkey007/tsc-alias)
