# Project Test Map (Node.js + Express + TypeScript)

## Suites de Testing (Vitest / Jest)

1. **Unit**
   - Carpeta: `tests/unit`
   - Objetivo: validadores (Zod, schemas), funciones puras, cálculo de puntos, reglas de negocio sin infraestructura ni I/O.

2. **Integration**
   - Carpeta: `tests/integration`
   - Objetivo: repositorios, queries SQL, transacciones ACID, integridad referencial y constraints con MySQL real.

3. **E2E**
   - Carpeta: `tests/e2e`
   - Objetivo: requests HTTP completas contra la aplicación Express real usando `supertest` y base de datos real.

## Colección Manual REST Client

1. Carpeta: `tests/e2e/http`
2. Tipo: manual, para exploración y documentación viva (`.http`).
3. Ejecución: desde VS Code REST Client o plugin de cliente HTTP.
4. Importante: El test runner (`vitest` / `jest`) NO ejecuta estos archivos `.http`.

## Helpers disponibles en `tests/support/`

1. `tests/support/seed.ts`
   - Crea fixtures reales en MySQL (usuarios, entrenadores, clientes, saldos de puntos, etc.).

2. `tests/support/app-factory.ts` (o `createApp` en `src/app.ts`)
   - Construye e inicializa la app Express configurada para pruebas E2E.

3. `tests/support/jwt-factory.ts`
   - Genera tokens JWT firmados y headers `Authorization: Bearer <token>` para endpoints protegidos.

## Comandos de ejecución estándar (npm)

1. `npm test`
   - Ejecuta todas las suites de prueba configuradas.

2. `npm run test:unit`
   - Ejecuta solo pruebas unitarias en `tests/unit`.

3. `npm run test:integration`
   - Ejecuta solo pruebas de integración con MySQL real en `tests/integration`.

4. `npm run test:e2e`
   - Ejecuta solo pruebas E2E con Express y Supertest en `tests/e2e`.

## Ejecutar un archivo específico

1. Comando base:
   - `npx vitest run tests/ruta/archivo.test.ts`
   - (o `npx jest tests/ruta/archivo.test.ts`)

2. Ejemplos:
   - `npx vitest run tests/unit/points/points-validator.test.ts`
   - `npx vitest run tests/integration/trainer/trainer-repository.test.ts`
   - `npx vitest run tests/e2e/orders/orders-api.test.ts`

3. Filtrar por nombre de test dentro del archivo:
   - `npx vitest run tests/e2e/orders/orders-api.test.ts -t "accepts request with valid JWT"`

## Reglas de calidad y buenas prácticas

1. Limpiar estado o aislar datos usando IDs únicos (`crypto.randomUUID()` / prefijos con timestamp).
2. Cerrar pools de conexión a la base de datos en `afterAll()`.
3. No usar mocks en Integration ni en E2E: probar contra MySQL real y Express real.
4. Mantener tipado estricto en TypeScript sin `any` arbitrario.
