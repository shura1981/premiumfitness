# Integration

Pruebas de integración con Pest y MySQL real.

## Qué cubre esta suite

- Repositorios contra SQL real.
- Servicios que dependen de repositorios reales.
- Reglas de persistencia, idempotencia, FKs, paginación y estados.

## Features documentadas

- [CreditBalance/README.md](./CreditBalance/README.md)
- [Order/README.md](./Order/README.md)

## Dependencias importantes

- `tests/Support/Seed.php` crea registros reales.
- Los tests no usan mocks.
- La base de datos debe estar configurada como en la app real.

## Comando útil

```bash
composer run test:integration
```
