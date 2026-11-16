# Unit / Order

Índice de pruebas unitarias del feature `Order`.

## Archivos

### `OrderValidatorTest.php`

Referencia: [OrderValidatorTest.php](./OrderValidatorTest.php)

Qué prueba:

- Payload válido mínimo para crear una orden.
- Falla cuando faltan `user_id` o `amount`.
- Falla cuando `user_id` no es entero.

Qué protege:

- Validación base de creación de órdenes.

### `OrderTrackingValidatorTest.php`

Referencia: [OrderTrackingValidatorTest.php](./OrderTrackingValidatorTest.php)

Qué prueba:

- Acepta combinaciones válidas de `tracking_number` y `carrier_name`.
- Rechaza ausencia de cualquiera de los dos campos.
- Rechaza transportadoras fuera del enum permitido.
- Rechaza tracking mayor a 50 caracteres.

Qué protege:

- El endpoint de actualización de tracking y carrier.

### `OrderStatusUpdateValidatorTest.php`

Referencia: [OrderStatusUpdateValidatorTest.php](./OrderStatusUpdateValidatorTest.php)

Qué prueba:

- Acepta `status_id` entero.
- Rechaza ausencia de `status_id`.
- Rechaza tipos inválidos.

Qué protege:

- El endpoint que cambia el estado de una orden por `status_id`.

### `OrderSapCodeValidatorTest.php`

Referencia: [OrderSapCodeValidatorTest.php](./OrderSapCodeValidatorTest.php)

Qué prueba:

- Acepta `sap_order_code` entero.
- Rechaza ausencia del campo.
- Rechaza tipos inválidos.

Qué protege:

- El endpoint que asigna el código SAP a una orden.

### `OrderWebhookValidatorTest.php`

Referencia: [OrderWebhookValidatorTest.php](./OrderWebhookValidatorTest.php)

Qué prueba:

- Acepta `order_id` y `status_value` válidos.
- Rechaza payload vacío.
- Rechaza `status_value` de más de 50 caracteres.

Qué protege:

- El webhook público de actualización de estado de pagos/órdenes.

## Comando útil

```bash
vendor/bin/pest tests/Unit/Order
```
