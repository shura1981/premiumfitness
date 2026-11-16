# E2E / http / payment-gateway

## Archivo

### `consume.http`

Referencia: [consume.http](./consume.http)

Que prueba manualmente:

- `POST /api/payment-gateway/consume/addi`
- `POST /api/payment-gateway/consume/sistecredito`
- `POST /api/payment-gateway/consume/bold`
- Errores 422 basicos de validacion para cada pasarela.

Que protege:

- Que los endpoints `consume` esten documentados con JWT.
- Que el body minimo requerido por cada validador este claro.
- Que el smoke test manual pueda validar la integracion con cada proveedor.

Notas:

- Estos archivos `.http` no se ejecutan con Pest.
- Los happy paths consumen APIs externas reales segun las variables de entorno configuradas.
- Reemplazar `@jwtToken`, `@orderId`, `@invoice` y los datos del cliente antes de ejecutar.

