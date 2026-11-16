# E2E / http / orders

## Archivo

### `orders.http`

Referencia: [orders.http](./orders.http)

Qué prueba manualmente:

- Listado de estados de orden.
- Listado paginado de órdenes.
- Creación válida de orden.
- Error 422 cuando `items` está vacío.
- Consulta de orden por id.
- Error 404 al consultar inexistentes.
- Listado de órdenes por usuario.
- Cambio de estado por `status_id`.
- Actualización de código SAP.
- Actualización de tracking y carrier.
- Webhook público de pagos sin JWT.
- Eliminación de orden.
- Error 404 al eliminar inexistentes.

Qué protege:

- El flujo HTTP operativo del módulo `orders`, incluyendo su webhook público.

## Requisitos manuales

- Casi todos los endpoints requieren JWT.
- El webhook es público.
- Deben existir `userId`, `statusId` y `productSku` válidos.
