# Flujo y concurrencia

## Puertos independientes del framework

PhoneNormalizer devuelve destino canónico validado con región explícita. ChallengeStore ofrece transacción o compare-and-swap (CAS) y serialización por ámbito. RateLimiter.consume(key, policy, now) es atómico y devuelve allowed y retryAfterSeconds. SmsSender.send(destination, message, idempotencyKey) devuelve accepted/rejected/unknown y referencia opcional. AuthCompletion aplica solo el efecto del propósito tras consumo confirmado. Reloj y generador criptográfico son sustituibles para pruebas.

Ámbito: tenant si existe, destino, propósito y contexto cuando corresponda. Cliente no puede cambiar identidad ni propósito del desafío. Usar codificación no ambigua para claves.

## Solicitar y reenviar

1. Cuota de entrada por IP, tamaño y validación de forma. Normalizar antes de cuotas por destino. Resolver identidad/contexto en servidor.
2. Consumir cuotas de destino y presupuesto global; reservar cooldown atómicamente. Rechazo no llama al proveedor. Mantener respuesta uniforme para desconocidos según política.
3. Generar OTP con CSPRNG e ID opaco. Persistir HMAC vinculado a ID y ámbito con secreto externo al almacén.
4. Serializar por ámbito: bloquear solo desafíos existentes no protege primer envío. Usar fila estable, restricción única con reintento o equivalente. Invalidar anterior y crear nuevo en la misma operación. Commit antes de HTTP externo.
5. Enviar fuera de transacción. Estados prepared, accepted, failed, unknown. Permitir verificar prepared evita perder códigos entregados antes de guardar accepted. Rechazo definitivo invalida ese ID/version sin tocar un reemplazo posterior.
6. Timeout significa unknown: no reintentar ciegamente ni generar otro OTP automáticamente. Usar idempotencia si proveedor la soporta o reconciliación. Sin ellas conservar cooldown y expiración; puede elegirse invalidar unknown y exigir solicitud nueva tras cooldown.
7. Responder solo metadatos. Accepted no significa delivered. Outbox/cola necesita OTP cifrado con retención corta; HMAC no permite reconstruirlo.

Política inicial: reemplazar con cada reenvío permitido. SMS antiguo puede llegar después del nuevo; solo desafío actual vale. Reenviar el mismo código requiere almacenamiento recuperable explícito.

## Verificar

1. Validar string de dígitos con longitud exacta y consumir cuotas IP y destino/identidad. Malformados también consumen cuota de entrada.
2. En transacción/CAS serializado cargar por ID/ámbito, NO por código candidato. Rechazar terminal, reemplazado, expirado (now >= expiresAt) o sin intentos.
3. Calcular HMAC candidato y comparar en tiempo constante. Fallo incrementa intentos y bloquea al máximo. Confirmar cambio antes de devolver error: una excepción con rollback no debe borrar el contador.
4. Acierto consume una vez y aplica efecto de dominio transaccional sobre ese destino exacto. CAS debe comprobar versión, estado, TTL e intentos y revisar filas afectadas.
5. Solo ganador completa autenticación existente. Si sesión falla después del commit, OTP sigue consumido: pedir otro o recuperación idempotente ligada al cliente; nunca rehabilitar código.

Tiempo coherente entre nodos. Nunca transacción abierta durante HTTP. Fallos y aciertos concurrentes tienen orden serializado.
