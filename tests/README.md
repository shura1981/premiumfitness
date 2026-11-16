# Tests

Este directorio quedó indexado por suite y por feature para que se pueda ubicar rápido qué prueba existe, qué valida y cómo correrla.

## Mapa rápido

- [Unit/README.md](./Unit/README.md): validadores y reglas puras, sin HTTP ni base de datos.
- [Integration/README.md](./Integration/README.md): repositorios y servicios con MySQL real.
- [E2E/README.md](./E2E/README.md): flujo HTTP completo con Slim real y JWT real.
- [E2E/http/README.md](./E2E/http/README.md): colecciones manuales `.http` para REST Client.

## Infraestructura compartida

- `Pest.php`: registra Pest para `Unit`, `Integration` y `E2E`.
- `bootstrap.php`: reutiliza el bootstrap real de la app y hace `DB::boot()`.
- `Support/AppFactory.php`: crea la app Slim real y permite despachar requests end-to-end.
- `Support/JwtFactory.php`: emite JWT reales para tests autenticados.
- `Support/Seed.php`: helpers para sembrar usuarios, órdenes y saldos a favor en la base real.

## Comandos base

```bash
composer run test
composer run test:unit
composer run test:integration
composer run test:e2e
```

Para ejecutar un archivo puntual:

```bash
vendor/bin/pest tests/RUTA/ArchivoTest.php
```

## Nota importante sobre `tests/E2E/http`

Los archivos `.http` no los ejecuta Pest. Son pruebas manuales para VS Code REST Client y funcionan como documentación viva de endpoints.
