---
description: 'Estándares de arquitectura, código y mejores prácticas para el repositorio de PremiumFitness, fork de backend-radical-nutrition. Aplica para cualquier modificación o creación de código.'
applyTo: '**/*.php, **/*.js, **/*.css, **/*.html'
---

# PremiumFitness — Estándares de código

PremiumFitness es un **fork de `backend-radical-nutrition`** (spec `specs/arquitectura/01-stack-php-fork-de-radical.md`). Estas reglas parten de las de radical y añaden las propias del proyecto. Todavía no hay código de aplicación: la primera tarea de codificación es crear el fork.

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: Always detect and respect the exact versions of languages, frameworks, and libraries used in this project (PHP 8.5, Slim 4, Firebase JWT, PHPMailer, Symfony RateLimiter/Cache, Pest, PHPStan).
2. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase (or radical, before the fork exists) for established patterns.
3. **Architectural Consistency**: Maintain our Vertical Slicing / Screaming Architecture style (`src/Features`, `src/Infrastructure`, `src/Shared`) and established boundaries.
4. **Code Quality**: Prioritize maintainability, strict typing (PHPStan), and security in all generated code.
5. **Specs first**: Follow `.agents/rules/specs-y-progreso.md`. The business rules live in `docs/reglas-de-negocio.md` and `specs/`; the data model in `specs/diseño/01-DER.md`.

## Technology Version Detection

1. **Language Versions**:
   - PHP 8.5 strict features: `declare(strict_types=1)`, typed properties, readonly classes/readonly properties, named arguments, match expressions, nullsafe operator, union types.
   - JavaScript ES6+ for `assets/js/`.
   - CSS/HTML5 for `views/` and `assets/css/`.

2. **Framework Versions**:
   - Slim Framework `^4.15` (PSR-7, fast-route).
   - Routes split across `app/Routes/ApiRoute.php`, `WebRoute.php`, `ResourceRoute.php`, `AdminRoute.php`.

3. **Library Versions**:
   - `firebase/php-jwt ^6.10` — autenticación JWT (HS256).
   - `phpmailer/phpmailer ^6.9` — envío SMTP.
   - `vlucas/phpdotenv ^5.6` — variables de entorno.
   - `symfony/rate-limiter ^8.0` + `symfony/cache ^8.0` — rate limiting con FilesystemAdapter (compartido entre los procesos de LiteSpeed).
   - `pestphp/pest ^4.6` (dev) — pruebas con base de datos real.
   - `phpstan/phpstan ^2.0` (dev) — análisis estático estricto.
   - `ext-curl` — HTTP externo vía `CurlHttpClient`.

4. **Base de datos y frontend**:
   - MariaDB 11.8, `utf8mb4_unicode_ci`.
   - Tres apps (spec `arquitectura/02`): la **web** (PWA Angular para clientes y entrenadores, con un área por rol cargada aparte), el **admin** (Angular: call center, SAP, envíos y reportes) y **dietas** (Flutter, externa).

## Feature Slices

Cada feature vive en `src/Features/<FeatureName>/`: Controller, Model, Repository, Validator (varios validadores en `Validators/`) y, cuando hay lógica de negocio, Service.

**Heredadas de radical:** `Address`, `Admin`, `Auth`, `Carrier`, `Category`, `City`, `CityWarehouse`, `CronJob`, `DocumentType`, `Locality`, `Order`, `PaymentGateway`, `Product`, `ProductGroup`, `Reports`, `SapProduct` y `User`.

**De radical a revisar al hacer el fork:** `CreditBalance` (queda fuera del DER), y `PublicStore`, `RetailCheckout`, `RetailAccount` y `Checkout`, porque PremiumFitness no tiene tienda pública.

**Nuevas de PremiumFitness:**

| Feature | Responsabilidad | Spec |
| --- | --- | --- |
| `Trainer` | Perfil del entrenador, cupón y enlaces | `negocio/01` |
| `Referral` | Vínculo cliente → entrenador (enlace o cupón) y referido A → B con sus 50.000 puntos | `negocio/01`, `negocio/02` |
| `DiscountRule` | Estrategias por producto (`product_discount_rules`) | `negocio/01` |
| `Commission` | Movimientos, conversión a puntos, cierre mensual y Excel | `negocio/04`, `negocio/05` |
| `Point` | Lotes, movimientos, consumo FIFO y vencimiento | `negocio/03` |
| `Redemption` | Canje de productos con puntos a precio completo | CU-12 |
| `Notification` | Dispositivos y outbox de push con FCM HTTP v1 | `integraciones/01` |
| `DietAccess` | Estado y canje de acceso a la app de dietas (Flutter) | `integraciones/02` |

## Codebase Scanning Instructions

1. Analyze patterns for:
   - **Naming**: PascalCase para clases, camelCase para métodos/variables, SCREAMING_SNAKE para constantes. Tablas y columnas en inglés y `snake_case`.
   - **Organización**: `src/Features/<Feature>/` para Modelos, Repositorios, Controllers, Services y Validators; `src/Infrastructure/` para servicios transversales (Middlewares, Auth, Database, Http, Mail, Notification, Validation, Controllers, Handlers); `src/Shared/Exceptions/` para excepciones compartidas.
   - **Error handling**: Lanzar excepciones tipadas desde `src/Shared/Exceptions/`. Los middlewares las capturan y responden HTTP. Usar `HttpRequestException` para errores de APIs externas.
   - **Routing**: Rutas API en `ApiRoute.php`, admin en `AdminRoute.php`, vistas HTML y SPA en `WebRoute.php`, recursos estáticos en `ResourceRoute.php`.
   - **Value Objects / readonly classes**: `readonly class` con constructor público o privado + factory estático `fromApiResponse()`/`fromEnv()`.
   - **Factories estáticas**: `fromEnv()` como único punto de acceso a `$_ENV`. Nunca leer `$_ENV` directamente fuera de estos métodos.

2. Follow the most consistent patterns found in the codebase.
3. Never introduce patterns not found in the existing codebase.

## Code Quality Standards

### Maintainability
- Siempre incluir `declare(strict_types=1);` en todos los archivos PHP.
- Usar tipos de retorno explícitos, propiedades tipadas y `readonly` donde aplique.
- Mantener features encapsuladas en `src/Features/<FeatureName>/`.
- Métodos orquestadores cortos que deleguen en métodos privados especializados (early return).

### Security
- Validar input con `AbstractValidator.php` — reglas: `required`, `string`, `int`, `float`, `email`, `min:N`, `max:N`, `in:a,b,c`, `regex:/pattern/`.
- `min`/`max` usan `mb_strlen()` para correcta validación UTF-8.
- PDO parametrizado siempre en `AbstractRepository.php` — nunca concatenar strings en queries.
- JWT validado en `JwtMiddleware.php` — claims accesibles vía `$request->getAttribute('jwt_claims')`.
- Contraseñas hasheadas con `PasswordHasher` (ARGON2ID).
- Rate limiting con `RateLimitMiddleware` en endpoints sensibles (login, registro, códigos SMS) — responde `429` con header `Retry-After`. Limitar por IP **y** por celular.
- CORS con lista blanca de dominios desde `CORS_ALLOWED_ORIGINS` en `.env`.
- `JWT_SECRET` y `JWT_REFRESH_SECRET` validados con longitud mínima de 32 caracteres en `load/bootstrap.php`.
- **Ningún endpoint emite tokens sin contraseña o sin un código verificado.** Al hacer el fork se desactivan `POST /api/auth/login-with-credential` y el autologin por QR de radical.

### Money, points and transactions
- Ninguna constante de negocio se escribe en el código: se lee de las variables de entorno. Las estrategias por producto (`product_discount_rules`) son datos del catálogo y tienen prioridad sobre los valores por defecto.
- Cada pedido, línea, referido y conversión guarda el valor que aplicó (porcentaje, IVA, puntos), para que un cambio en las variables no altere el histórico. Los reportes y el cierre **suman** lo guardado; nunca recalculan.
- Las operaciones que mueven saldo (aprobación del pago, conversión, canje, vencimiento, cierre) van en una sola transacción, con la fila del entrenador bloqueada (`SELECT … FOR UPDATE`) y una clave única de idempotencia.
- Los importes son `decimal(12,2)` y los puntos enteros, redondeados al entero más cercano (0,5 sube).

### Performance
- PDO compartido vía `DB::getInstance()` — una sola conexión por request.
- `FETCH_CLASS` en `AbstractRepository` para mapeo directo a objetos tipados.
- Los saldos del dashboard se calculan con índices que cubren la consulta (spec `negocio/03`), sin tabla de saldos.
- Assets estáticos servidos directamente por Apache (nunca pasar por PHP).
- `TypedArgsStrategy` como estrategia de invocación por defecto: convierte automáticamente params numéricos de ruta a `int`, permitiendo type-hints `int $id` en controllers.

## Authentication

Spec: `specs/seguridad/01-autenticacion-y-sesiones.md`.

- Login con celular (`users.num_cell`, único) y contraseña. Un rol por cuenta: `client` o `trainer`.
- Access token JWT de 15 min, solo en memoria del frontend, con `sub`, `role` y `app` (`web`, `admin` o `diets`). Una API rechaza los tokens de otra app, y los endpoints del entrenador exigen `role = trainer`.
- Refresh token de 30 días en cookie `httpOnly`, `Secure`, `SameSite=Strict`, rotado en cada uso y guardado como hash en `refresh_tokens`. Reutilizar uno ya rotado revoca toda la familia.
- Una cookie por app web (`pf_rt_web` en `/api/auth`, `pf_rt_admin` en `/api/admin/auth`). La app de dietas (Flutter, app `diets`) no usa cookie: guarda el refresh token en `flutter_secure_storage` y lo envía en el cuerpo.
- Admin: login con `tb_empleados` (externa, **no se toca**: solo lectura), sesión de 12 horas.

## Verification Code Flow

Basado en `VerificationCodeService` de radical, ajustado al spec de autenticación:

1. `generate()` invalida los códigos anteriores del mismo usuario y propósito, genera **6 dígitos**, guarda el **hash** con TTL de 5 minutos y lo envía por el canal.
2. `verify()` compara en tiempo constante, cuenta los intentos (máximo 5) y marca el código como usado.
3. Propósitos: `phone_verification` y `password_reset`. El código nunca se devuelve por la API ni se registra en logs.

## Trainer Link Flow

En PremiumFitness el «QR» es un enlace con el id del entrenador en base64 (regla 12). No se usa el autologin por QR de radical.

1. Los dos enlaces abren la app web: `/r/c/<id>` registra o asocia a un cliente y `/r/t/<id>` registra a un entrenador B referido por A.
2. La app guarda el id hasta el registro o el login, y el backend crea el vínculo (`client_referrals` o `trainer_referrals`).
3. Un id inválido o de un entrenador inactivo no asocia a nadie.

## Scheduled Jobs

- **Nunca** se programan tareas dentro de la aplicación. LiteSpeed levanta varios procesos y cada uno ejecutaría la tarea (incidente de api-comisiones, spec `negocio/05`).
- El cron de cPanel llama al endpoint con el header `X-Cron-Job-Token`, que valida `CronJobTokenMiddleware` contra `CRON_JOB_TOKEN`.
- Cada job es idempotente: el cierre mensual responde 409 si el período ya existe.
- Jobs: envío de push pendientes (cada minuto), cierre mensual de comisión (día 1, 01:00), vencimiento de lotes de puntos y avisos de vencimiento de puntos y de dietas (diario), limpieza de refresh tokens y códigos vencidos (diaria), procesamiento de eventos de pago (radical).

## Email Templates

- Las plantillas de correo son archivos **HTML standalone** en `views/emails/<name>.html`.
- Los `{{placeholders}}` se reemplazan con `str_replace` directo (sin escapar) — permite pasar HTML pre-construido.
- `{{subject}}` y `{{year}}` se inyectan automáticamente por `Mailer::send()`.
- Usar `MailMessage` (fluent builder) + `Mailer::fromEnv()->send()`.

## Notification Channel Pattern

Notificaciones desacopladas del canal mediante la interfaz `NotificationChannelInterface`:

```php
interface NotificationChannelInterface
{
    public function send(string $recipient, string $subject, string $message): void;
}
```

- `EmailNotificationChannel` — envío por SMTP vía PHPMailer.
- `SmsNotificationChannel` — envío por SMS vía Relax Global API (`SMS_API_URL`, `SMS_API_TOKEN`). Proveedor por confirmar (D-10).
- `FcmPushChannel` — push con **FCM HTTP v1** (cuenta de servicio, OAuth2 con JWT RS256). Nunca el API legacy `fcm/send` con server key, que Google retiró. Se envía desde la outbox `push_notifications`, nunca dentro de la petición del usuario.
- Los canales se instancian con `SmsNotificationChannel::fromEnv()` o `EmailNotificationChannel::fromEnv()`.

## External HTTP Client Pattern

Usar `CurlHttpClient` para todo consumo de APIs externas:

```php
$http = new CurlHttpClient();
$response = $http->request($url, [
    'method'  => 'POST',
    'headers' => ['Authorization' => 'Bearer ' . $token],
    'payload' => $data,
    'timeout' => 15,
]);
$decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
```

- Lanza `HttpRequestException` en errores HTTP — contiene `statusCode()`, `errorPayload()`, `toError()`.
- **Nunca** usar `file_get_contents`, `curl_*` directo u otros clientes HTTP.

## Middleware Registration Pattern

Los middlewares se añaden en orden LIFO (el último en añadirse es el primero en ejecutarse):

```php
// Proteger ruta con JWT + rate limit + validación
$app->post('/api/auth/login', [$authController, 'login'])
    ->add(new ValidationMiddleware(new LoginValidator(), $app->getResponseFactory()))
    ->add(new RateLimitMiddleware($app->getResponseFactory(), id: 'web-login', limit: 5, interval: '1 minute'));
```

## Private Files

- Los reportes (Excel del cierre) se guardan en `REPORTS_STORAGE_PATH`, fuera de la carpeta pública, y solo se descargan desde el admin autenticado.

## Environment Variables

Todas las variables se validan en `load/bootstrap.php` al arranque. Nunca acceder a `$_ENV` fuera de métodos `fromEnv()`.

```
APP_ENV=development|production
DB_HOST, DB_NAME, DB_USER, DB_PASS
CORS_ALLOWED_ORIGINS=https://app.dominio.com,https://admin.dominio.com
MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
MAIL_FROM, MAIL_FROM_NAME, MAIL_ENCRYPTION=tls|ssl
SAP_API_URL, SAP_API_KEY
SMS_API_URL, SMS_API_TOKEN
CRON_JOB_TOKEN=<mínimo 32 caracteres>

# Autenticación (spec seguridad/01)
JWT_SECRET=<mínimo 32 caracteres>
JWT_REFRESH_SECRET=<mínimo 32 caracteres>
JWT_ACCESS_TTL_MINUTES=15
JWT_REFRESH_TTL_DAYS=30
ADMIN_SESSION_TTL_HOURS=12
OTP_LENGTH=6
OTP_TTL_MINUTES=5
OTP_MAX_ATTEMPTS=5
OTP_MAX_SENDS_PER_DAY=10

# Constantes de negocio (reglas 1, 2, 6, 15 y 17 de docs/reglas-de-negocio.md)
CLIENT_DISCOUNT_PERCENTAGE=10
TRAINER_DISCOUNT_PERCENTAGE=25
CLIENT_PURCHASE_POINTS_PERCENTAGE=5
TRAINER_COMMISSION_PERCENTAGE=15
TRAINER_REFERRAL_POINTS=50000
POINTS_EXPIRATION_MONTHS=6
COMMISSION_CONVERSION_PERCENTAGE=50

# Notificaciones push (spec integraciones/01)
FIREBASE_PROJECT_ID=premiumfitness-12ca6
FIREBASE_CREDENTIALS_PATH=/ruta/privada/firebase-service-account.json
PUSH_MAX_ATTEMPTS=5
PUSH_EXPIRY_NOTICE_DAYS=7

# Acceso a dietas (spec integraciones/02; la meta es provisional y su lógica puede cambiar)
DIET_ACCESS_POINTS_GOAL=249000
DIET_ACCESS_POINTS_COST=50000
DIET_FREE_DAYS=30

# Cierre mensual de comisión (spec negocio/05)
COMMISSION_REPORT_RECIPIENTS=financiero1@dominio.com,financiero2@dominio.com
REPORTS_STORAGE_PATH=/ruta/privada/fuera/de/public
```

## Testing

- Pest con base de datos MariaDB real (skill `backend-testing-pest-realdb`): `composer run test`, `test:unit`, `test:integration`, `test:e2e`.
- Las reglas de dinero y puntos tienen pruebas con los ejemplos de los specs (por ejemplo, 224.910 → 11.246 puntos; 100.000 + 100.000 → 250.000 puntos tras convertir).
- `composer run lint` (PHPStan) debe pasar antes de cerrar una tarea.

## Documentation Requirements

- Seguir el formato exacto de documentación encontrado en el codebase.
- Documentar parámetros, retornos y excepciones para que PHPStan los analice estrictamente.
- Documentar comportamiento no obvio con comentarios inline breves.

## Technology-Specific Guidelines

### PHP Guidelines
- Strict adherence to modern PHP 8.5 standards.
- Use strict typing `declare(strict_types=1);` in all PHP files.
- Use only PHP language features compatible with the detected version.
- Match PDO usage patterns from existing code via `AbstractRepository.php`.
- Apply the dependency injection patterns evident in existing code.
- Ensure the code passes the strict Linter (PHPStan).
- Use `readonly class` for value objects de solo lectura.

### JavaScript/HTML/CSS Guidelines
- Frontend code of this repo goes into `assets/js`, `assets/css` and `views/`.
- Las apps Angular (web y admin) se sirven cada una en su subdominio. Si se compilan dentro de este repo, como hace radical con `SpaController` y `dist/`, o si viven en repos propios, se decide al crear el fork.

## General Best Practices

- Follow naming conventions exactly as they appear in existing code.
- Respect existing architectural boundaries without exception (Infrastructure should not leak into Features inappropriately).
- When in doubt, prioritize consistency with existing code over external best practices.
- Use Composer tasks properly (`composer run start`, `composer run lint`, `composer run test`).
