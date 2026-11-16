# E2E

Pruebas end-to-end automatizadas con Pest sobre la app Slim real.

## Qué cubre esta suite

- Request HTTP real contra la aplicación.
- Middleware de autenticación con JWT real.
- Validación de request, controlador, repositorio y respuesta final.

## Features documentadas

- [CreditBalance/README.md](./CreditBalance/README.md)
- [Order/README.md](./Order/README.md)
- [http/README.md](./http/README.md): pruebas manuales con REST Client.

## Helpers clave

- `tests/Support/AppFactory.php`
- `tests/Support/JwtFactory.php`
- `tests/Support/Seed.php`

## Comando útil

```bash
composer run test:e2e
```
