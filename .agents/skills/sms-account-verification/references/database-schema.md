# Modelo portable

No ejecutar DDL fijo sin inspeccionar motor, migraciones y tipos de identidad.

| Entidad | Campos lógicos |
| --- | --- |
| Ámbito estable | scope_key única, current_challenge_id, version; serializa incluso primer envío |
| Desafío | id opaco, scope_key, identidad nullable según flujo, destino canónico, propósito/contexto, code_mac, key_version, created_at, expires_at, failed_attempts, max_attempts, consumed_at, invalidated_at, delivery_status, version |
| Cuota | policy_id, key, contador/estado ventana o bucket, vencimiento |
| Envío opcional | challenge_id, idempotency_key, referencia proveedor, estado y timestamps |

MAC binario o hex fijo; nunca OTP en claro. Hash simple de espacio pequeño permite enumeración offline: usar HMAC y secreto externo. Vincular ID, ámbito y código con codificación canónica (array JSON de strings UTF-8 en orden fijo, por ejemplo). Versionar clave para rotación.

Índices por ámbito y vencimiento. Identidades pueden ser UUID/string/bigint: no convertir bigint a number JS fuera del rango seguro. SQL parametrizado, transacción en conexión única, bloqueo estable o CAS con filas afectadas. KV necesita scripts/transacciones atómicas, no get seguido de set.

TTL de limpieza no sustituye comprobar expires_at. Definir retención corta de terminales y datos personales. No alterar automáticamente tablas de usuarios.
