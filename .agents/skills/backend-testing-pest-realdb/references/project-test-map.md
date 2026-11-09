# Project Test Map (backend-radical-nutrition)

## Suites Pest

1. Unit
- Carpeta: tests/Unit
- Objetivo: validadores y lógica sin infraestructura.

2. Integration
- Carpeta: tests/Integration
- Objetivo: repositorios, SQL, transacciones, FK con MySQL real.

3. E2E
- Carpeta: tests/E2E
- Objetivo: request HTTP completa contra Slim real.

## Colección Manual REST Client

1. Carpeta: tests/E2E/http
2. Tipo: manual, para exploración y documentación viva.
3. Ejecución: desde VS Code REST Client.
4. Importante: Pest no ejecuta estos archivos.

## Helpers disponibles

1. tests/Support/Seed.php
- Crea fixtures reales en DB (usuarios, órdenes, saldos, SKU, etc.).

2. tests/Support/AppFactory.php
- Construye app Slim real para E2E.

3. tests/Support/JwtFactory.php
- Genera JWT para endpoints protegidos.

## Comandos de ejecución

1. composer run test
- Todas las suites Pest.

2. composer run test:unit
- Solo Unit.

3. composer run test:integration
- Solo Integration.

4. composer run test:e2e
- Solo E2E de Pest (PHP).

## Ejecutar un archivo específico

1. Comando base:
- vendor/bin/pest tests/RUTA/ArchivoTest.php

2. Ejemplos:
- vendor/bin/pest tests/Unit/CreditBalance/CreditBalanceValidatorTest.php
- vendor/bin/pest tests/Integration/Order/OrderRepositoryTest.php
- vendor/bin/pest tests/E2E/Order/OrdersApiTest.php

3. Filtrar por nombre de test dentro del archivo:
- vendor/bin/pest tests/E2E/Order/OrdersApiTest.php --filter "tracking_number"

## Reglas de estilo para evitar warnings de editor

1. No usar propiedades dinámicas this->userId, this->repo, etc.
2. Usar variables locales o contexto local por closure.
3. Mantener declare(strict_types=1).
