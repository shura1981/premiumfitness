# E2E / Order

Índice de pruebas end-to-end automatizadas del feature `Order`.

## Archivo

### `OrdersApiTest.php`

Referencia: [OrdersApiTest.php](./OrdersApiTest.php)

Qué prueba:

- Rechazo de listado sin JWT.
- Listado autenticado de órdenes.
- Creación de una orden vía `POST /api/orders`.
- Error 422 al actualizar tracking sin `carrier_name`.
- Actualización correcta de tracking y carrier.
- Acceso al webhook público sin JWT.

Qué protege:

- El flujo HTTP principal de órdenes.
- El middleware de autenticación.
- La excepción del webhook público, que no requiere token.

## Comando útil

```bash
vendor/bin/pest tests/E2E/Order/OrdersApiTest.php
```
