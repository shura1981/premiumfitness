---
name: vitest-typescript
description: "Skill experta en configurar Vitest en proyectos TypeScript. Usar cuando se pida: configurar Vitest, agregar tests a un proyecto TypeScript, resolver errores de módulos en tests, configurar supertest para integration testing, hacer que los alias funcionen en vitest, configurar tests/tsconfig.json para VS Code, solucionar errores 'módulo no encontrado' en archivos de test, o migrar de Jest a Vitest. Cubre vitest.config.ts, resolución de aliases en tests (2 sistemas: Vitest runtime + VS Code editor), estructura de tests, y supertest. Arquitectura-agnóstica."
license: Complete terms in LICENSE.txt
---

# Vitest + TypeScript — Configuración Completa

Skill para configurar Vitest como test runner en proyectos TypeScript. Si el proyecto usa path aliases (`@alias/*`), configura los **2 sistemas adicionales de resolución** que necesitan los tests.

**Arquitectura-agnóstica** — funciona con cualquier estructura de carpetas.

## Cuando Usar Esta Skill

- Configurar Vitest desde cero en un proyecto TypeScript
- Resolver errores "Cannot find module" en archivos de test
- Resolver errores del editor VS Code que no encuentra módulos en `tests/`
- Configurar supertest para integration testing con Express/Fastify/Koa
- Migrar de Jest a Vitest
- Entender por qué los alias funcionan al compilar pero no en tests

## Concepto Clave: Resolución de Aliases en Tests

Si el proyecto usa path aliases, estos deben resolverse en **2 sistemas adicionales** además del `tsconfig.json` principal:

| # | Sistema | Archivo de configuración | Cuándo actúa |
|---|---------|--------------------------|---------------|
| 1 | **Vitest Runtime** | `vitest.config.ts` → `resolve.alias` | `npm run test` |
| 2 | **VS Code Editor** (TS Language Server) | `tests/tsconfig.json` | Análisis en tiempo real del editor |

> **Error común**: Asumir que Vitest lee los `paths` del `tsconfig.json`. No lo hace — necesita su propia configuración en `resolve.alias`.

Si el proyecto **NO usa aliases**, omitir `resolve.alias` y `tests/tsconfig.json`.

## Prerrequisitos

- Node.js ≥ 18
- TypeScript ≥ 5.0
- Proyecto con código fuente en `src/`
- Si usa aliases: `tsconfig.json` con `baseUrl` y `paths` ya configurados (ver skill `typescript-aliases`)

## Paso 0 (OBLIGATORIO): Leer el Proyecto

**Antes de crear o modificar cualquier archivo**, el agente DEBE:

1. Leer `tsconfig.json` → detectar si tiene `paths` (aliases)
2. Leer `package.json` → `scripts` para no pisar scripts existentes, detectar framework HTTP (express, fastify, etc.)
3. Verificar si ya existe `vitest.config.ts` o `tests/`

## Paso 1: Instalar Dependencias

```bash
npm install -D vitest
```

Para integration tests HTTP (opcional):

```bash
npm install -D supertest @types/supertest
```

## Paso 2: Configurar vitest.config.ts

Crear `vitest.config.ts` en la raíz del proyecto. Usar la plantilla [vitest.config.ts](./templates/vitest.config.ts) como base.

### Sin aliases

```typescript
import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        globals: true,
        root: ".",
        include: ["tests/**/*.test.ts"],
    },
});
```

### Con aliases

Si el proyecto tiene `paths` en `tsconfig.json`, agregar `resolve.alias` mapeando cada alias:

```typescript
import path from "node:path";
import { defineConfig } from "vitest/config";

export default defineConfig({
    resolve: {
        alias: {
            // Generar una entrada por cada alias en tsconfig.json → paths
            // "@alias": path.resolve(__dirname, "src/<carpeta>"),
        },
    },
    test: {
        globals: true,
        root: ".",
        include: ["tests/**/*.test.ts"],
    },
});
```

### Regla de conversión tsconfig → vitest

Para cada entrada en `tsconfig.json` → `paths`:

| tsconfig.json `paths` | vitest.config.ts `resolve.alias` |
|------------------------|----------------------------------|
| `"@nombre/*": ["nombre/*"]` | `"@nombre": path.resolve(__dirname, "src/nombre")` |

**Diferencias clave:**
- tsconfig usa `"@nombre/*"` (con `/*`) → vitest usa `"@nombre"` (sin `/*`)
- tsconfig usa rutas relativas al `baseUrl` → vitest usa `path.resolve(__dirname, ...)` desde la raíz

## Paso 3: Configurar tests/tsconfig.json (Solo si usa aliases)

> **Si el proyecto NO usa path aliases, omitir este paso.**

Copiar la plantilla [tests-tsconfig.json](./templates/tests-tsconfig.json) al directorio `tests/tsconfig.json`.

Este archivo resuelve los errores "Cannot find module" que muestra VS Code en los archivos de test:

```jsonc
// tests/tsconfig.json — Portátil, no requiere adaptación manual
{
    "extends": "../tsconfig.json",
    "compilerOptions": {
        "rootDir": "..",
        "noEmit": true,
        "baseUrl": "../src"
    },
    "include": [
        "../src/**/*",
        "./**/*"
    ]
}
```

### Por qué es necesario

- VS Code usa el `tsconfig.json` más cercano al archivo para resolución de tipos
- El tsconfig principal tiene `"include": ["src/**/*"]`, excluyendo `tests/`
- `extends` hereda los `paths` del principal → aliases se resuelven automáticamente
- `noEmit: true` → no genera archivos compilados
- **Portátil**: no necesita modificación al cambiar aliases — los hereda del padre

## Paso 4: Agregar Scripts en package.json

Agregar o **fusionar** con scripts existentes:

```json
{
    "scripts": {
        "test": "vitest run",
        "test:unit": "vitest run tests/unit",
        "test:integration": "vitest run tests/integration",
        "test:watch": "vitest"
    }
}
```

**Nota:** NO pisar scripts existentes como `dev`, `build`, `start`, `lint`, etc.

## Paso 5: Estructura de Tests

El directorio `tests/` va en la **raíz del proyecto** (no dentro de `src/`). La estructura interna se adapta a la arquitectura:

```
proyecto/
├── src/                         # Código fuente
│   └── ...
├── tests/                       # Tests (siempre fuera de src/)
│   ├── tsconfig.json            # Solo si el proyecto usa aliases
│   ├── unit/                    # Tests unitarios
│   │   └── ...
│   └── integration/             # Tests de integración
│       ├── helpers/             # Helpers compartidos
│       └── ...
├── tsconfig.json
├── vitest.config.ts
└── package.json
```

> **Buena práctica**: `tests/` fuera de `src/` separa código de producción del de pruebas y evita que se incluyan en `dist/`.

## Troubleshooting

| Problema | Causa | Solución |
|----------|-------|----------|
| `Cannot find module '@alias/...'` al correr tests | Falta alias en `vitest.config.ts` | Agregar en `resolve.alias` |
| `Cannot find module '@alias/...'` en VS Code | Falta `tests/tsconfig.json` | Crear según Paso 3 |
| Los alias tienen `/*` en vitest.config.ts | Formato incorrecto | Quitar `/*` — Vitest usa `"@nombre"`, NO `"@nombre/*"` |
| `rootDir` error al agregar tests a `include` | Tests fuera de `rootDir` | Usar `tests/tsconfig.json` separado con `"rootDir": ".."` |
| Tests pasan pero editor muestra errores | Vitest y VS Code son sistemas distintos | Ambos necesitan configuración independiente |
| Express 5: `TypeError: Cannot set property query` | `req.query` es read-only en Express 5 | Usar `Object.defineProperty` (ver sección abajo) |

## Nota: Express 5 y req.query Read-Only

Si el proyecto usa Express ≥ 5 con middleware de validación que reescribe `req.query`, la asignación directa `req.query = data` lanza `TypeError` porque Express 5 define `query` como solo getter.

**Solución en el middleware de validación:**

```typescript
if (source === "query") {
    Object.defineProperty(req, "query", {
        configurable: true,
        value: result.data,
        writable: true,
    });
} else {
    req[source] = result.data;
}
```

## Verificación Final

```bash
# Vitest ejecuta tests sin errores
npm run test

# Si usa aliases: verificar resolución de tipos en tests
npx tsc --project tests/tsconfig.json --noEmit

# VS Code: abrir cualquier test → los imports con @ no deben tener errores
```

## Referencias

- [Vitest — resolve.alias](https://vitest.dev/config/#resolve-alias)
- [Vitest — Configuration](https://vitest.dev/config/)
- [supertest](https://github.com/ladjs/supertest)
