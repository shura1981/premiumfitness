---
name: biome-config
description: Configura Biome como linter y formatter para proyectos JavaScript vanilla, TypeScript y frameworks como React, Vue, Svelte. Usa cuando necesites configurar formateo de código, linting, organización de imports, integración con VS Code, o migrar desde ESLint/Prettier. Soporta JS, JSX, TS, TSX, JSON.
license: Complete terms in LICENSE.txt
---

# Configuración de Biome para Proyectos JS/TS

Esta skill proporciona una configuración completa de Biome optimizada para proyectos JavaScript/TypeScript y frameworks frontend.

## Cuando Usar Esta Skill

- Configurar Biome desde cero en un proyecto nuevo (JS vanilla, React, Vue, etc.)
- Migrar desde ESLint/Prettier a Biome
- Configurar formateo automático en VS Code
- Establecer reglas de linting para JavaScript vanilla o frameworks
- Organizar imports automáticamente
- Configurar scripts de CI/CD para validación de código

## Prerrequisitos

- Node.js ≥18
- npm, pnpm o yarn
- VS Code con extensión [Biome](https://marketplace.visualstudio.com/items?itemName=biomejs.biome)

## Instalación Rápida

### 1. Instalar Biome

```bash
npm install --save-dev @biomejs/biome
```

### 2. Copiar Configuraciones

Copia los archivos de plantilla a tu proyecto:

| Archivo | Destino | Descripción |
|---------|---------|-------------|
| [biome.json](./templates/biome.json) | `./biome.json` | Configuración principal de Biome |
| [vscode-settings.json](./templates/vscode-settings.json) | `.vscode/settings.json` | Integración con VS Code |

### 3. Agregar Scripts al package.json

Añade los scripts de [package-scripts.json](./scripts/package-scripts.json):

```json
{
  "scripts": {
    "check": "npx @biomejs/biome check ./src ./tests",
    "check:fix": "npx @biomejs/biome check --write ./src ./tests",
    "format": "npx @biomejs/biome format --write ./src ./tests"
  }
}
```

> **Nota:** Incluir `./tests` en todos los comandos para que Biome aplique formato y lint también a los archivos de test. Ajusta las carpetas según la estructura de tu proyecto.

## Comandos Disponibles

| Comando | Descripción |
|---------|-------------|
| `npm run check` | Verifica lint + formato + imports (sin modificar). Ideal para CI |
| `npm run check:fix` | **El más completo**: corrige formato, lint auto-fixable, organiza imports y ordena keys. Usar en día a día |
| `npm run format` | Solo formatea archivos (sin lint ni imports). Usar cuando solo necesites formatear |

### ¿Por qué no hay un script `lint` separado?

`biome check` ya ejecuta lint + formato + assist (imports, keys) en un solo paso. Un script `lint` separado es redundante porque:
- `check` (sin `--write`) cubre lint como parte de la verificación completa
- `check:fix` (con `--write`) aplica auto-fixes de lint junto con formato e imports

Si necesitás solo ver errores de lint sin formato, ejecutá directamente: `npx @biomejs/biome lint ./src ./tests`

## Características de la Configuración

### Formatter

- **Indentación**: Tabs (2 espacios de ancho)
- **Ancho de línea**: 80 caracteres
- **Fin de línea**: LF (Unix)
- **Semicolons**: Siempre
- **Comillas**: Dobles (`"`)
- **Trailing commas**: Ninguna

### Linter

Reglas activadas para:
- **Correctness**: Variables no usadas, hooks de React, JSX keys
- **Suspicious**: No console, no debugger, duplicados
- **Complexity**: Escape innecesario, operador coma
- **Security**: dangerouslySetInnerHTML

### Assist (Auto-fix)

- Organización automática de imports
- Ordenamiento de atributos JSX/HTML
- Ordenamiento de keys en objetos

> **Importante:** `biome format` solo aplica reglas de formato. Para que Assist (imports, keys, attributes) también se aplique, usar `biome check --write`, que ejecuta formatter + linter + assist en un solo paso.

## Integración con VS Code

La configuración de VS Code activa:

- Formateo al guardar
- Organización de imports al guardar
- Eliminación de imports no usados
- Ordenamiento de atributos y keys
- Quick fixes automáticos

## Adaptación por Tipo de Proyecto

### JavaScript Vanilla (sin framework)

La configuración funciona directamente. Desactiva las reglas de React si no las necesitas:

```json
{
  "linter": {
    "rules": {
      "correctness": {
        "noChildrenProp": "off",
        "useExhaustiveDependencies": "off",
        "useHookAtTopLevel": "off",
        "useJsxKeyInIterable": "off"
      },
      "suspicious": {
        "noCommentText": "off",
        "noDuplicateJsxProps": "off",
        "noSuspiciousSemicolonInJsx": "off"
      },
      "security": {
        "noDangerouslySetInnerHtmlWithChildren": "off"
      }
    }
  }
}
```

O simplemente ignora las advertencias de React (no afectan proyectos sin JSX).

### React (ya configurado)

La configuración incluye reglas específicas de React:
- `useExhaustiveDependencies`
- `useHookAtTopLevel`
- `useJsxKeyInIterable`
- `noChildrenProp`

### Vue/Svelte

Añade al `biome.json`:

```json
{
  "files": {
    "include": ["**/*.vue", "**/*.svelte"]
  }
}
```

### Angular

Biome es excelente para la lógica en TypeScript, pero tiene limitaciones con los estilos preprocesados:

- **TypeScript (.ts)**: Soporte nativo y perfecto para decoradores (`@Component`, `@Injectable`). Reemplaza totalmente a ESLint.
- **HTML**: Soporte funcional, aunque su manejo de sintaxis compleja de Angular (bindings) puede ser menos robusto que Prettier.
- **Estilos (SCSS/Sass)**: Biome **NO soporta SCSS** actualmente. Deberás mantener Prettier para los archivos `.scss` o usar CSS estándar.

### TypeScript

Biome detecta automáticamente archivos `.ts` y `.tsx`. No requiere configuración adicional.

### Incluir Tests

Por defecto, `biome.json` debe incluir la carpeta de tests para que el formato y lint se apliquen uniformemente a todo el código:

```json
{
  "files": {
    "includes": ["src/**", "tests/**"]
  }
}
```

Ajusta los nombres de carpeta según tu proyecto (`tests/`, `__tests__/`, `test/`, etc.). Si no se incluye, los archivos de test quedan sin formato ni validación de lint.

## Troubleshooting

| Problema | Solución |
|----------|----------|
| Biome no formatea | Verifica extensión VS Code instalada |
| Conflicto con Prettier | Desinstala/desactiva Prettier |
| Reglas muy estrictas | Modifica reglas en `linter.rules` |
| Archivos ignorados | Revisa `files.ignore` en biome.json |

## Referencias

- [Documentación oficial de Biome](https://biomejs.dev/)
- [Reglas de Linting](https://biomejs.dev/linter/rules/)
- [Opciones de Formatter](https://biomejs.dev/formatter/)
