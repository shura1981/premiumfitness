# Integration / Order

Índice de pruebas de integración del feature `Order`.

## Archivos

### `OrderRepositoryTest.php`

Referencia: [OrderRepositoryTest.php](./OrderRepositoryTest.php)

Qué prueba:

- Creación transaccional de órdenes con items.
- Creación con estado `PENDING`.
- Rechazo cuando `items` está vacío.
- Actualización de tracking y carrier.
- Persistencia de `payment_state_json` y cambio de `status_id`.
- Actualización de código SAP.
- Listado paginado por usuario.
- Eliminación de orden y cascada sobre items.

Qué protege:

- El comportamiento del repositorio principal de órdenes y sus relaciones.

### `StatusOrderRepositoryTest.php`

Referencia: [StatusOrderRepositoryTest.php](./StatusOrderRepositoryTest.php)

Qué prueba:

- Que `findOrCreateIdByValue` sea idempotente.
- Que el listado de estados incluya el estado recién creado.

Qué protege:

- La resolución y persistencia de estados de orden por valor textual.

## Comando útil

```bash
vendor/bin/pest tests/Integration/Order
```
