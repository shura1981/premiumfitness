# Origen y compatibilidad

Evidencia local revisada: AuthController, VerificationCodeService, VerificationCodeRepository, SmsNotificationChannel, RateLimitMiddleware y ApiRoute de Radical Nutrition. Esta descripción no requiere acceso a esos archivos.

| Aspecto | Comportamiento observado |
| --- | --- |
| Solicitar | POST /api/auth/request-code con num_cell; busca usuario existente; 404 si desconocido; SMS; 200 con message y expires_in: 300 |
| Verificar | POST /api/auth/verify-code con num_cell y code; 404 si usuario desconocido; 401 si OTP inválido/expirado; al aceptar emite token existente |
| OTP | Cuatro dígitos entre 1000 y 9999, TTL cinco minutos, invalida anteriores del usuario |
| Canal | Relax Global, prefijo 57, marca Radical Nutrition; fallback email si destino SMS vacío |
| Límites | Ventana fija por REMOTE_ADDR: solicitar 3/min, verificar 5/min; 429 con Retry-After y retryAfter |
| Datos | verification_codes con user_id, código en claro, expires_at, used_at, created_at; cuotas en archivos |

Limitaciones observadas: lectura y consumo separados sin condición de consumo ni comprobación de filas afectadas; invalidación/creación sin serialización; sin contador efectivo de errores. Archivos locales no garantizan cuota global entre servidores. 404 revela registro del teléfono.

Son características del origen, no requisitos portables. Conservar rutas/campos cuando se exija compatibilidad y documentar diferencias. No añadir phone_verified_at como dependencia supuesta del origen.
