---
name: clean-code-js-ts
description: Toolkit for generating clean, maintainable JavaScript and TypeScript. Use when asked to write or refactor JS/TS, split large functions, apply early returns, optional chaining, nullish coalescing, logical assignment, improve naming, or add types with JSDoc/TypeScript utilities. Covers general code quality patterns for services, controllers, utilities, and domain logic.
---

# Clean Code for JavaScript and TypeScript

Guia para producir codigo claro, consistente y facil de mantener en JS y TS.

## When to Use This Skill
- Crear o refactorizar codigo en JavaScript o TypeScript.
- Reducir complejidad en metodos grandes o con anidacion profunda.
- Mejorar legibilidad, nombres y estructura de funciones.
- Tipar datos en JS con JSDoc o en TS con tipos avanzados.

## Prerequisites
- Respeta el estilo del repositorio y su configuracion de lint/format.
- En TypeScript, mantente dentro del target configurado por el proyecto.

## Principios Base
- **Claridad primero**: prioriza que el codigo se entienda rapido.
- **Responsabilidad unica**: una funcion, una tarea.
- **Pequenas unidades**: divide metodos grandes en helpers enfocados.
- **Consistencia**: misma terminologia y estilo en todo el modulo.

## Patrones Esenciales

### Early Return
- Usa guard clauses para reducir anidacion.
```ts
function handle(user?: User) {
  if (!user) return null;
  if (!user.isActive) return null;
  return buildResponse(user);
}
```

### Extraccion de Metodos Auxiliares
- Un metodo orquestador llama helpers especializados.
```ts
function createInvoice(input: InvoiceInput) {
  validateInput(input);
  const items = normalizeItems(input.items);
  const totals = calculateTotals(items);
  return buildInvoiceResponse(items, totals);
}
```

### Optional Chaining
- Usa `?.` cuando una propiedad es realmente opcional.
- Evita ocultar errores en rutas que deberian existir.
```ts
const city = profile?.address?.city;
```

### Nullish Coalescing
- Usa `??` para defaults solo con `null` o `undefined`.
```ts
const retries = config.retries ?? 3;
```

### Logical Assignment
- Usa `||=`, `??=`, `&&=` para defaults y asignaciones condicionales.
```ts
options.timeout ??= 5000;
flags.debug ||= false;
```

### Alternativas a `switch`
- Evita `switch`; usa objetos diccionario o funciones por tipo.
```ts
const STATUS_LABELS: Record<string, string> = {
  pending: "Pendiente",
  paid: "Pagado",
  failed: "Fallido"
};

const label = STATUS_LABELS[status] ?? "Desconocido";
```
```ts
const handlers: Record<string, (input: Input) => Output> = {
  text: handleText,
  image: handleImage
};

const handler = handlers[type] ?? handleDefault;
return handler(input);
```

### Alternativas a `for`
- Usa `forEach`, `map`, `filter`, `reduce`, `some`, `every` segun el objetivo.
- Prefiere `for...of` cuando necesites `break`/`continue`.
```ts
items.forEach(processItem);
const ids = items.map((item) => item.id);
const total = items.reduce((sum, item) => sum + item.amount, 0);
```

### Evitar `if/else` Anidados
- Usa guard clauses, early return y helpers para reducir nesting.
```ts
function canCheckout(user?: User) {
  if (!user) return false;
  if (!user.isActive) return false;
  return user.balance > 0;
}
```

### Evitar Valores Magicos
- No uses numeros/strings "sueltos"; define constantes o enums segun el caso.
- Constantes para valores puntuales; `enum` o union de literales para dominios cerrados.
```ts
const MAX_RETRIES = 3;
const DEFAULT_TIMEOUT_MS = 5000;

type Status = "pending" | "paid" | "failed";
const DEFAULT_STATUS: Status = "pending";
```

## Tipado en TypeScript

### Reglas Practicas
- Tipos explicitos en limites del sistema (entradas/salidas publicas).
- Evita `any`; usa `unknown` y type guards si el origen es inseguro.
- Usa `as const` para literales y objetos inmutables.

### Utilidades Recomendadas
- `Omit`, `Pick`, `Partial`, `Required`, `Readonly`, `Record`.
- `ReturnType`, `Parameters` para reflejar firmas existentes.
```ts
interface User {
  id: number;
  name: string;
  email: string;
}

type PublicUser = Omit<User, "email">;

type UserPatch = Partial<Pick<User, "name" | "email">>;
```

### Uniones Discriminadas
- Para variantes con comportamiento distinto.
```ts
type Result =
  | { ok: true; data: Data }
  | { ok: false; error: string };
```

## Tipado en JavaScript con JSDoc
- Define tipos reutilizables con `@typedef` y usa `@param`/`@returns`.
- Usa `@template` para genericos simples.
```js
/**
 * @typedef {Object} User
 * @property {number} id
 * @property {string} name
 */

/**
 * @template T
 * @param {T[]} items
 * @returns {T | null}
 */
function first(items) {
  return items.length ? items[0] : null;
}
```

## Nombres y Semantica
- Variables: nombres descriptivos, evita abreviaturas opacas.
- Funciones: verbos que describan accion (`fetchUser`, `buildPayload`).
- Booleanos: prefijos `is`, `has`, `can`, `should`.

## Manejo de Errores
- En limites del sistema, captura errores y agrega contexto.
- No uses `try/catch` para control de flujo normal.

## Estructura de Modulos
- Agrupa funciones por responsabilidad.
- Exporta solo lo necesario; oculta detalles internos.

## Workflows

### 1) Refactor de Metodo Grande
1. Identifica responsabilidades distintas.
2. Extrae helpers con nombres por intencion.
3. Aplica early return para validaciones.
4. Reemplaza defaults con `??` y `??=`.
5. Revisa nombres y reduce parametros opcionales.

### 2) Tipado Progresivo en JavaScript
1. Agrega `@typedef` para entidades clave.
2. Tipar funciones publicas con `@param` y `@returns`.
3. Agrega `@template` si hay genericos repetidos.
4. Simplifica con helpers reutilizables.

### 3) Modelado en TypeScript
1. Define interfaces en limites (API, DB, IO).
2. Usa `Omit`/`Pick` para DTOs especificos.
3. Declara uniones discriminadas para variantes.
4. Reemplaza `any` con `unknown` y type guards.

## Troubleshooting

| Problema             | Causa Comun                 | Solucion                                       |
| -------------------- | --------------------------- | ---------------------------------------------- |
| Funciones largas     | Multiples responsabilidades | Extrae helpers por tarea y aplica early return |
| Defaults incorrectos | Uso de `                    |                                                | ` con valores falsy validos | Cambia a `??` |
| Tipos imprecisos     | Uso de `any`                | Usa `unknown` + validacion                     |
| Nombres genericos    | Falta de contexto           | Usa nombres de dominio claros                  |

## Checklist Rapido
- Funciones pequenas y con una sola responsabilidad.
- Guard clauses para validaciones.
- Defaults con `??` y `??=`.
- Tipado fuerte en limites del sistema.
- Nombres expresivos y consistentes.
