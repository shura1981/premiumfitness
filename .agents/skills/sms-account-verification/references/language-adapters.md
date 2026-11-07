# TypeScript, PHP y otros lenguajes

Decisiones de adaptación, no archivos completos para copiar:

| Responsabilidad | TypeScript / Node | PHP | Otros |
| --- | --- | --- | --- |
| Seis dígitos | crypto.randomInt(0, 1_000_000).toString().padStart(6, '0') | str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) | CSPRNG uniforme y padding |
| MAC | crypto.createHmac('sha256', key) sobre UTF-8 canónico | hash_hmac('sha256', payload, key) | HMAC-SHA256 estándar |
| Comparar | crypto.timingSafeEqual con buffers del mismo tamaño | hash_equals | Comparación constante de biblioteca |
| Datos | ORM/driver actual, transacción en conexión única | Repositorio actual y SQL parametrizado | Transacción o CAS equivalente |
| HTTP | Cliente existente con timeout | Cliente HTTP del destino | Timeout y errores tipados |

OTP siempre string. Validar longitud configurable contra rango entero de la primitiva.

TypeScript: dominio sin imports Express/Fastify/Nest; adaptadores HTTP traducen DTO/errores. Capturar unknown y estrechar tipos; validar entradas y respuestas externas en runtime. No añadir paquetes si ya existe solución.

PHP: cada archivo empieza con apertura PHP y declare(strict_types=1); antes del namespace. Inyectar repositorio, reloj, generador y proveedor, sin construir infraestructura en servicio. Respetar autoload destino sin imports App del origen. En Radical Nutrition solo fromEnv lee entorno y se usan AbstractRepository/CurlHttpClient: convenciones locales, no dependencias portables.

Middleware: comprobar orden real para limitar antes de efectos costosos. Slim ejecuta add en LIFO; Express sigue registro. Probar que 429 no llama al proveedor.

Otros lenguajes trasladan contratos e invariantes, no jerarquía de clases. Reutilizar sesiones existentes y transporte sustituible para pruebas.
