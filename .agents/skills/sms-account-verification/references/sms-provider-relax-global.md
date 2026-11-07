# Relax Global: adaptador opcional

Contrato observado en código origen, no documentación oficial verificada. Confirmar contrato/semántica actuales antes de integración nueva; no inferir entrega por 2xx.

POST a URL configurable (origen https://backend.relax.global/api/sms/); Authorization Bearer, Content-Type/Accept application/json.

Payload observado: name (asunto), type: fast, message (OTP/TTL), api: true, transactional: true, category: informative, phones (lista con indicative y cell_phone como strings).

Origen usa indicative 57 y teléfono colombiano nacional. Separar destino canónico según contrato proveedor sin asumir otros países.

Cliente HTTP destino con timeout, errores sanitizados y validación de respuesta. No asumir idempotencia ni reintentar automáticamente tras timeout. transactional no garantiza cumplimiento legal ni entrega. Segmentos/coste dependen de codificación y longitud; no prometer un segmento por contar caracteres.

No SMS reales en pruebas sin alcance explícito. Transporte controlado captura llamadas sin logs sensibles y simula aceptación, rechazo, timeout y aceptación seguida de desconexión.
