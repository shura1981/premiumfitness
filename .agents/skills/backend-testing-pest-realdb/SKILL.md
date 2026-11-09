---
name: backend-testing-pest-realdb
description: Expert toolkit for testing this Slim 4 + PHP 8.5 backend with Pest and real MySQL. Use when asked to crear tests, testear funcionalidad, probar endpoint, run composer test commands, or verify if a test already exists and execute it. Decides Unit vs Integration vs E2E, places files in tests/Unit, tests/Integration, or tests/E2E, reuses Tests/Support helpers, avoids dynamic $this properties, and clarifies that tests/E2E/http REST Client files are manual and are not executed by Pest.
---

# Backend Testing Expert: Pest + MySQL Real

Skill especializada para este repositorio. Su objetivo es resolver solicitudes tipo:

- Necesito probar X funcionalidad
- Crea el test para este endpoint
- Revisa si ya existe test para Y y ejecútalo
- Ejecuta solo Unit/Integration/E2E
- No quiero mocks, quiero pruebas reales

## When to Use This Skill

Usar esta skill cuando la tarea involucra testing del backend en cualquiera de estos escenarios:

1. Crear tests nuevos para features, repositorios, validadores o endpoints.
2. Buscar si ya existe test para una funcionalidad y ejecutarlo.
3. Corregir tests rotos tras cambios de DB, rutas o validadores.
4. Explicar qué comando ejecutar para una suite concreta.
5. Aclarar diferencia entre tests Pest y archivos .http de REST Client.

## Proyecto: Mapa de Testing

Ver mapa detallado en [project-test-map.md](./references/project-test-map.md).

Resumen operativo:

1. tests/Unit: pruebas unitarias (validadores/lógica pura).
2. tests/Integration: repositorios y SQL con MySQL real.
3. tests/E2E: flujo HTTP completo (Slim real con AppFactory).
4. tests/E2E/http: colecciones REST Client manuales (NO las ejecuta Pest).

## Reglas Obligatorias de esta Skill

1. No usar mocks para Integration/E2E; usar DB y app real.
2. En Pest, evitar propiedades dinámicas de this para contexto de pruebas.
3. Usar contexto local por closure (por ejemplo ctx o variables locales).
4. Reusar helpers de tests/Support:
- AppFactory
- JwtFactory
- Seed
5. Datos de prueba con valores únicos (uniqid) cuando aplique.
6. Mantener declare(strict_types=1); en cada test PHP.

## Decisión: ¿Dónde va el test?

1. Unit: valida reglas de un validator o función pura sin IO.
2. Integration: valida queries, FK, transacciones y repositorios.
3. E2E: valida ruta HTTP, auth middleware, validación de request, respuesta API.
4. E2E/http: prueba manual exploratoria con REST Client.

## Workflow Principal: Crear o Ejecutar

### Paso 1: Clasificar funcionalidad

Elegir tipo de prueba (Unit/Integration/E2E) según la capa afectada.

### Paso 2: Buscar si ya existe test

Buscar por feature + verbo funcional en tests/Unit, tests/Integration y tests/E2E.

Si existe:

1. Ejecutar solo ese archivo:
- vendor/bin/pest tests/RUTA/ArchivoTest.php
2. Si hay varios relacionados, ejecutar carpeta del feature.

Si NO existe:

1. Crear archivo en carpeta correcta.
2. Usar la plantilla correspondiente en templates.
3. Implementar casos mínimo:
- happy path
- validación/error esperado
- caso borde relevante
4. Ejecutar suite mínima del tipo de prueba.

### Paso 3: Verificar y reportar

1. Ejecutar suite objetivo:
- composer run test:unit
- composer run test:integration
- composer run test:e2e
2. Si el cambio fue amplio, ejecutar composer run test.
3. Reportar cantidad de tests, assertions, y fallos exactos si existen.

## Comandos Estándar de Ejecución

1. composer run test
- Ejecuta todas las suites Pest definidas en phpunit.xml.

2. composer run test:unit
- Solo Unit.

3. composer run test:integration
- Solo Integration.

4. composer run test:e2e
- Solo E2E de Pest (PHP).
- No ejecuta archivos .http de REST Client.

## Ejecutar un Solo Archivo de Test

Usar este comando base:

1. vendor/bin/pest tests/RUTA/ArchivoTest.php

Ejemplos reales de este proyecto:

1. vendor/bin/pest tests/Unit/CreditBalance/CreditBalanceValidatorTest.php
2. vendor/bin/pest tests/Integration/Order/OrderRepositoryTest.php
3. vendor/bin/pest tests/E2E/Order/OrdersApiTest.php

Ejecutar un test puntual por nombre dentro de un archivo:

1. vendor/bin/pest tests/E2E/Order/OrdersApiTest.php --filter "tracking_number"

Regla operativa cuando el usuario diga ejecuta este test:

1. Si entrega ruta exacta, ejecutar esa ruta con vendor/bin/pest.
2. Si no entrega ruta, buscar archivo candidato en tests/Unit, tests/Integration y tests/E2E.
3. Si hay uno claro, ejecutar solo ese archivo.
4. Si hay varios candidatos, listar opciones y ejecutar el que confirme el usuario.

## Troubleshooting Rápido

1. Warning Undefined property en tests Pest:
- Causa: uso de this dinámico en closures.
- Solución: usar contexto local por closure (ctx).

2. DB not configured / conexión fallida:
- Confirmar tests/bootstrap.php y variables de entorno válidas.

3. Test E2E devuelve 401 inesperado:
- Verificar JwtFactory::authHeader y usuario seed existente.

4. Expectativa de que composer run test:e2e ejecute .http:
- Incorrecto. Los .http se ejecutan manualmente en REST Client.

## Plantillas Incluidas

1. [Unit validator template](./templates/unit-validator-test.template.php)
2. [Integration repository template](./templates/integration-repository-test.template.php)
3. [E2E API template](./templates/e2e-api-test.template.php)

## Resultado Esperado al usar esta Skill

Dado un prompt como necesito probar X funcionalidad:

1. Identifica tipo de test.
2. Busca test existente.
3. Si existe, lo ejecuta.
4. Si no existe, lo crea en la carpeta correcta.
5. Ejecuta la suite adecuada.
6. Entrega resultado verificable (passed/failed + assertions + archivo afectado).
