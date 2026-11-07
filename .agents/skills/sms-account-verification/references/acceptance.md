# Aceptación

Runner y reglas del destino. Transporte local controlado evita costes; si se prohíben dobles, sandbox autorizado o reportar cobertura pendiente. Persistencia/concurrencia con motor real destino en entorno aislado permitido.

- Solicitud: respuesta sin OTP, MAC persistido, destino/propósito y TTL correctos, una llamada de envío.
- Variantes del mismo teléfono comparten cuota; países no permitidos y entradas inválidas no envían.
- Acierto completa solo propósito previsto. Fallo incrementa contador y confirma; al máximo rechazar incluso código correcto.
- Antes de expires_at permite; en límite rechaza. Reuso, sustitución y contexto/identidad/destino incorrectos rechazan.
- Dos aciertos simultáneos: una finalización. Dos fallos no pierden incrementos. Fallo y acierto respetan serialización.
- Dos solicitudes simultáneas incluso sin desafío previo: cooldown efectivo, máximo un desafío activo.
- Reenvío invalida anterior conservando cuotas; cambiar IP no reinicia intentos.
- Límite exacto, siguiente petición, Retry-After, vencimiento y políticas aisladas. Varias instancias comparten contador.
- Rechazo proveedor invalida; fallo tardío no invalida reemplazo. Timeout cumple política unknown sin envío duplicado.
- Caída almacén, rollback y fallo creando sesión no autentican indebidamente.
- Proxy falso no evade cuota; respuesta pública uniforme ante desconocidos según política.
- Respuestas/logs/errores no contienen OTP, secretos o payload SMS.

Reportar separados checks estáticos, dominio, integración/concurrencia y entrega real. Mocks no demuestran entrega ni concurrencia del almacenamiento.
