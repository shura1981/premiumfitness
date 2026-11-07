---
name: backend-testing-node-realdb
description: Expert toolkit for testing this Node.js / Express / TypeScript backend with Vitest (or Jest), Supertest, and real MySQL database. Use when asked to crear tests, testear funcionalidad, probar endpoint, run npm test commands, or verify if a test already exists and execute it. Decides Unit vs Integration vs E2E, places files in tests/unit, tests/integration, or tests/e2e, reuses test helpers, and clarifies that tests/e2e/http REST Client files are manual.
---

# Backend Testing Expert: Node.js + TypeScript + MySQL Real

Skill especializada para este repositorio. Su objetivo es resolver solicitudes tipo:

- Necesito probar X funcionalidad
- Crea el test para este endpoint
- Revisa si ya existe test para Y y ejecútalo
- Ejecuta solo Unit/Integration/E2E
- No quiero mocks, quiero pruebas reales

## When to Use This Skill

Usar esta skill cuando la tarea involucra testing del backend en cualquiera de estos escenarios:

1. Crear tests nuevos para features, repositorios, validadores o endpoints en Node.js/TypeScript.
2. Buscar si ya existe test para una funcionalidad y ejecutarlo.
3. Corregir tests rotos tras cambios de DB, rutas o validadores.
4. Explicar qué comando ejecutar para una suite concreta (`npm test`, `npm run test:unit`, etc.).
5. Aclarar diferencia entre tests automatizados (Vitest/Jest) y archivos `.http` de REST Client.

## Proyecto: Mapa de Testing

Ver mapa detallado en [project-test-map.md](./references/project-test-map.md).

Resumen operativo:

1. `tests/unit`: pruebas unitarias (validadores, schemas Zod, reglas de negocio puras sin I/O).
2. `tests/integration`: repositorios y SQL con MySQL real (queries, transacciones, pools).
3. `tests/e2e`: flujo HTTP completo (Express real con `supertest`).
4. `tests/e2e/http`: colecciones REST Client manuales (NO las ejecuta el test runner).

## Reglas Obligatorias de esta Skill

1. No usar mocks para Integration/E2E; usar DB MySQL real y la app Express real.
2. Usar TypeScript con tipado estricto.
3. Reusar helpers de `tests/support/`:
   - `createApp` / `app-factory.ts`
   - `JwtFactory`
   - `Seed`
4. Datos de prueba con identificadores únicos (`crypto.randomUUID()` o timestamps) para evitar colisiones.
5. Gestionar el ciclo de vida del pool MySQL (`beforeAll` para conectar, `afterAll` para desconectar).

## Decisión: ¿Dónde va el test?

1. **Unit**: valida reglas de un validador, cálculo o función pura sin IO ni DB.
2. **Integration**: valida queries, FK, transacciones y repositorios contra MySQL.
3. **E2E**: valida ruta HTTP, auth middleware, validación de request, códigos de estado y respuesta API en Express.
4. **E2E/http**: prueba manual exploratoria con REST Client (`.http`).

## Workflow Principal: Crear o Ejecutar

### Paso 1: Clasificar funcionalidad

Elegir tipo de prueba (Unit/Integration/E2E) según la capa afectada.

### Paso 2: Buscar si ya existe test

Buscar por feature + verbo funcional en `tests/unit`, `tests/integration` y `tests/e2e`.

Si existe:

1. Ejecutar solo ese archivo:
   - `npx vitest run tests/ruta/archivo.test.ts`
   - (o `npm test -- tests/ruta/archivo.test.ts`)
2. Si hay varios relacionados, ejecutar la carpeta del feature.

Si NO existe:

1. Crear archivo en la carpeta correcta con extensión `.test.ts`.
2. Usar la plantilla correspondiente en `templates/`.
3. Implementar casos mínimos:
   - happy path (caso exitoso)
   - validación/error esperado (400, 401, 404, 422)
   - caso borde relevante
4. Ejecutar la suite mínima del tipo de prueba.

### Paso 3: Verificar y reportar

1. Ejecutar suite objetivo:
   - `npm run test:unit`
   - `npm run test:integration`
   - `npm run test:e2e`
2. Si el cambio fue amplio, ejecutar `npm test`.
3. Reportar cantidad de tests, assertions, y fallos exactos si existen.

## Comandos Estándar de Ejecución

1. `npm test`
   - Ejecuta todas las suites configuradas.

2. `npm run test:unit`
   - Solo Unit.

3. `npm run test:integration`
   - Solo Integration con DB real.

4. `npm run test:e2e`
   - Solo E2E HTTP.

## Ejecutar un Solo Archivo de Test

Usar este comando base:

1. `npx vitest run tests/ruta/archivo.test.ts`

Ejemplos reales:

1. `npx vitest run tests/unit/points/points-validator.test.ts`
2. `npx vitest run tests/integration/trainer/trainer-repository.test.ts`
3. `npx vitest run tests/e2e/orders/orders-api.test.ts`

Ejecutar un test puntual por nombre dentro de un archivo:

1. `npx vitest run tests/e2e/orders/orders-api.test.ts -t "accepts request with valid JWT"`

Regla operativa cuando el usuario diga "ejecuta este test":

1. Si entrega ruta exacta, ejecutar esa ruta con `npx vitest run`.
2. Si no entrega ruta, buscar archivo candidato en `tests/unit`, `tests/integration` y `tests/e2e`.
3. Si hay uno claro, ejecutar solo ese archivo.
4. Si hay varios candidatos, listar opciones y ejecutar el que confirme el usuario.

## Troubleshooting Rápido

1. **DB connection error / ECONNREFUSED**:
   - Confirmar variables de entorno en `.env.test` (`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`).

2. **Test E2E devuelve 401 inesperado**:
   - Verificar `JwtFactory.authHeader(userId)` y que el usuario exista en DB vía `Seed.user()`.

3. **Pool no cerrado / proceso no termina**:
   - Asegurarse de invocar `Database.disconnect()` en `afterAll`.

4. **Expectativa de que `npm run test:e2e` ejecute `.http`**:
   - Incorrecto. Los archivos `.http` se ejecutan manualmente en REST Client.

## Plantillas Incluidas

1. [Unit validator template](./templates/unit-validator-test.template.ts)
2. [Integration repository template](./templates/integration-repository-test.template.ts)
3. [E2E API template](./templates/e2e-api-test.template.ts)

## Resultado Esperado al usar esta Skill

Dado un prompt como "necesito probar X funcionalidad":

1. Identifica tipo de test (Unit / Integration / E2E).
2. Busca test existente.
3. Si existe, lo ejecuta.
4. Si no existe, lo crea en TypeScript en la carpeta correcta.
5. Ejecuta la suite adecuada con `npm` / `vitest`.
6. Entrega resultado verificable (passed/failed + assertions + archivo afectado).
