---
description: 'Estándares de arquitectura, código y mejores prácticas para el repositorio de Radical Nutrition. Aplica para cualquier modificación o creación de código.'
applyTo: '**/*.php, **/*.js, **/*.css, **/*.html'
---

# Radical Nutrition (Antigravity Agent Instructions)

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: Always detect and respect the exact versions of languages, frameworks, and libraries used in this project (PHP 8.5, Slim 4, Firebase JWT, PHPMailer, Symfony RateLimiter/Cache).
2. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase for established patterns.
3. **Architectural Consistency**: Maintain our Vertical Slicing / Screaming Architecture style (`src/Features`, `src/Infrastructure`, `src/Shared`) and established boundaries.
4. **Code Quality**: Prioritize maintainability, strict typing (PHPStan), and security in all generated code.

## Technology Version Detection

Before generating code, scan the codebase to identify:

1. **Language Versions**:
   - PHP 8.5 strict features: `declare(strict_types=1)`, typed properties, readonly classes/readonly properties, named arguments, match expressions, nullsafe operator, union types.
   - JavaScript ES6+ for `assets/js/`.
   - CSS/HTML5 for `views/` and `assets/css/`.

2. **Framework Versions**:
   - Slim Framework `^4.15` (PSR-7, fast-route).
   - Routes split across `app/Routes/ApiRoute.php`, `WebRoute.php`, `ResourceRoute.php`.

3. **Library Versions**:
   - `firebase/php-jwt ^6.10` — autenticación JWT (HS256).
   - `phpmailer/phpmailer ^6.9` — envío SMTP.
   - `vlucas/phpdotenv ^5.6` — variables de entorno.
   - `symfony/rate-limiter ^8.0` + `symfony/cache ^8.0` — rate limiting por IP con FilesystemAdapter.
   - `phpstan/phpstan ^2.0` (dev) — análisis estático estricto.
   - `ext-curl` — HTTP externo vía `CurlHttpClient`.

## Observed Feature Slices

Cada feature vive en `src/Features/<FeatureName>/` y sigue el patrón:

| Feature | Archivos estándar |
|---|---|
| `Address` | Controller, Model, Repository, Validator |
| `Auth` | Controller, QrController, SapClientService, SapClientResponse, VerificationCodeService, `Validators/`, `VerificationCode/` |
| `Category` | Controller, Model, Repository, Validator |
| `City` | Controller, Model, Repository, Validator, CityUpdateValidator |
| `CityWarehouse` | Controller, Model, Repository, Validator |
| `DocumentType` | Controller, Model, Repository, Validator |
| `Product` | Controller, Model, Repository, Validator |
| `ProductGroup` | Controller, Model, Repository, Validator |
| `SapProduct` | Controller, Model, Repository, Validator |
| `User` | Controller, Model, Repository, Validator, UserWithAddressValidator |

Regla: cuando una feature requiere más de un validador, agruparlos en una subcarpeta `Validators/` dentro del feature (ver `Auth/Validators/`).

## Codebase Scanning Instructions

When context files don't provide specific guidance:

1. Analyze patterns for:
   - **Naming**: PascalCase para clases, camelCase para métodos/variables, SCREAMING_SNAKE para constantes.
   - **Organización**: `src/Features/<Feature>/` para Modelos, Repositorios, Controllers y Validators; `src/Infrastructure/` para servicios transversales (Middlewares, Auth, Database, Http, Mail, Notification, Validation, Controllers, Handlers); `src/Shared/Exceptions/` para excepciones compartidas.
   - **Error handling**: Lanzar excepciones tipadas desde `src/Shared/Exceptions/`. Los middlewares las capturan y responden HTTP. Usar `HttpRequestException` para errores de APIs externas.
   - **Routing**: Rutas API en `ApiRoute.php`, vistas HTML y SPA en `WebRoute.php`, recursos estáticos en `ResourceRoute.php`.
   - **Value Objects / readonly classes**: `SapClientResponse`, `DatabaseConfig` usan `readonly class` con constructor público o privado + factory estático `fromApiResponse()`/`fromEnv()`.
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
- Rate limiting con `RateLimitMiddleware` en endpoints sensibles (login, registro) — responde `429` con header `Retry-After`.
- CORS con lista blanca de dominios desde `CORS_ALLOWED_ORIGINS` en `.env`.
- `JWT_SECRET` validado con longitud mínima de 32 caracteres en `load/bootstrap.php`.

### Performance
- PDO compartido vía `DB::getInstance()` — una sola conexión por request.
- `FETCH_CLASS` en `AbstractRepository` para mapeo directo a objetos tipados.
- Assets estáticos servidos directamente por Apache (nunca pasar por PHP).
- `TypedArgsStrategy` como estrategia de invocación por defecto: convierte automáticamente params numéricos de ruta a `int`, permitiendo type-hints `int $id` en controllers.

## Environment Variables

Todas las variables se validan en `load/bootstrap.php` al arranque. Nunca acceder a `$_ENV` fuera de métodos `fromEnv()`.

```
APP_ENV=development|production
DB_HOST, DB_NAME, DB_USER, DB_PASS
CORS_ALLOWED_ORIGINS=https://domain.com,https://other.com
JWT_SECRET=<mínimo 32 caracteres>
JWT_TTL_SECONDS=3600
MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
MAIL_FROM, MAIL_FROM_NAME, MAIL_ENCRYPTION=tls|ssl
SAP_API_URL, SAP_API_KEY
SMS_API_URL, SMS_API_TOKEN
```

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
- `SmsNotificationChannel` — envío por SMS vía Relax Global API (`SMS_API_URL`, `SMS_API_TOKEN`).
- Los canales se instancian con `SmsNotificationChannel::fromEnv()` o `EmailNotificationChannel::fromEnv()`.
- Si el canal SMS no tiene destinatario, `VerificationCodeService` cae automáticamente a email (fallback).

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

## QR Autologin Flow

Patrón implementado en `QrController` + `SapClientService`:

1. `GET /qr?id=<base64>` — decodifica el ID de documento
2. `SapClientService::fromEnv()->fetchClient($documentId)` — consulta la API SAP (Basic Auth)
3. `SapClientResponse` — readonly value object devuelto por el servicio SAP
4. Si no existe en SAP → redirect a `/qr-error?error=not_found`
5. Si existe → busca/crea usuario local → redirect a Angular con `userId`

`SapClientResponse::documentNumber()` extrae el número limpio quitando el prefijo de tipo (ej. `"C6394880"` → `"6394880"`).

## Verification Code Flow

Patrón implementado en `VerificationCodeService`:

1. `generate($userId, $channel, $recipientAddress, $fallbackEmail)` → genera 4 dígitos, persiste con TTL 5 min, envía por canal.
2. `verify($userId, $code)` → valida código no expirado, retorna `bool`.
3. Los códigos se invalidan al generar uno nuevo para el mismo usuario.

## Middleware Registration Pattern

Los middlewares se añaden en orden LIFO (el último en añadirse es el primero en ejecutarse):

```php
// Proteger ruta con JWT + rate limit + validación
$app->post('/api/login', LoginController::class)
    ->add(new ValidationMiddleware(new LoginValidator(), $app->getResponseFactory()))
    ->add(new RateLimitMiddleware($app->getResponseFactory(), id: 'login', limit: 5, interval: '1 minute'))
    ->add(new JwtMiddleware(JwtService::fromEnv(), $app->getResponseFactory()));
```

## SPA Serving Pattern

`SpaController` sirve la SPA Angular desde `dist/index.html` en la catch-all route:

```php
// WebRoute.php
$app->get('/user[/{path:.*}]', new SpaController(__DIR__ . '/../../dist'));
```

- `SpaController` lanza `RuntimeException` si el `index.html` no existe (build pendiente).
- Assets estáticos del SPA (`.js`, `.css`) los sirve Apache directamente sin pasar por PHP.

## Web Routes Reference

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/` | Landing page HTML estático |
| GET | `/qr` | QR autologin — decodifica base64, consulta SAP, redirect |
| GET | `/qr-error` | Página de error para flujos QR fallidos |
| GET | `/user[/{path:.*}]` | SPA Angular servida desde `dist/` |

## Documentation Requirements

- Seguir el formato exacto de documentación encontrado en el codebase.
- Documentar parámetros, retornos y excepciones para que PHPStan los analice estrictamente.
- Documentar comportamiento no obvio con comentarios inline breves.
- Document parameters, returns, and exceptions properly to help PHPStan do a strict analysis.
- Follow existing patterns for documenting non-obvious behavior.

## Technology-Specific Guidelines

### PHP Guidelines
- Strict adherence to modern PHP 8.5 standards.
- Use strict typing `declare(strict_types=1);` in all PHP files.
- Use only PHP language features compatible with the detected version.
- Match PDO usage patterns from existing code via `AbstractRepository.php`.
- Apply the dependency injection patterns evident in existing code.
- Ensure the code passes the strict Linter (PHPStan).
- Use `readonly class` for value objects de solo lectura (ej. `SapClientResponse`).

### JavaScript/HTML/CSS Guidelines
- Frontend code should go into `assets/js`, `assets/css`.
- Ensure paths are correctly aligned with existing views (`views/landing.html`, `views/errors/404.html`, `views/errors/qr-error.html`).

## General Best Practices

- Follow naming conventions exactly as they appear in existing code.
- Respect existing architectural boundaries without exception (Infrastructure should not leak into Features inappropriately).
- When in doubt, prioritize consistency with existing code over external best practices.
- Use Composer tasks properly (`composer run start`, `composer run lint`, etc.).
