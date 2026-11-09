---
name: slim-php85-scaffold
description: Toolkit framework and workflow to generate new robust projects using Slim ^4.x along with PHP 8.5. Includes automatic boilerplate scaffolding for Vertical Slicing and Screaming Architecture. Uses PDO properly configured for type safety (FETCH_CLASS) instead of mysqli. Route distribution splits Api, HTML and Resources endpoints cleanly. Trigger this skill when asked to create a new Slim project from scratch or initialize a backend architecture in a new PHP repository.
license: Apache-2.0
---

# Slim PHP 8.5 Screaming Architecture Scaffold

Esta skill empodera a los agentes de IA y desarrolladores a estructurar y mantener proyectos web de PHP 8.5 sobre el framework Slim de una manera escalable, moderna y robusta sin usar ORMs pesados pero manteniendo un inquebrantable _type-safety_ (fuertemente tipado) en acceso a bases de datos PDO.

## When to Use This Skill

- Cuando el usuario solicite crear un **nuevo proyecto de Slim** o un boilerplate backend.
- Cuando se pida aplicar **Screaming Architecture** o **Vertical Slicing**.
- Para inicializar un entorno backend ligero que vaya a residir en servidores CPanel o arquitecturas clásicas nativas libres de Docker, pero aprovechando estándares modernos de PHP 8.5.
- Para cambiar de mysqli a un wrapper de PDO tipado.

## Características de la Arquitectura Propuesta

1. **Screaming Architecture y Vertical Slicing:**
   El código ya no estará agrupado en enormes carpetas técnicas de `Controllers/`, `Models/` o `Services/`.
   En cambio, la lógica se concentrará en `src/Features/`. Por ejemplo, todo el alcance de Pagos residirá en `src/Features/Payments`, que albergará allí sus modelos, controladores, y lógica de acceso PDO.
   El código neutral habitará en `src/Infrastructure/` (conexiones de bases de datos, librerías, correos) y `src/Shared/` (Value Objects, utilidades generales).

2. **Acceso DB Tipado (PDO):**
   Garantizamos el uso de `PDO` en lugar de `mysqli`. La base devuelta por los queries puede usar `PDO::FETCH_CLASS` para inyectar las celdas recabadas directamente dentro de una lista de objetos (Modelos) fuertemente tipados definidos en PHP.

3. **Separación de Rutas:**
   Conformando una carpeta dedicadas `app/Routes/ApirRoute.php`, `WebRoute.php`, etc, conectadas transparentemente al Kernel del framework Slim.

## Prerequisites

- Requiere PHP 8.5 (o >= 8.x) instalado en el entorno objetivo (o cPanel configurado con PHP 8.5 y extensiones nativas: pdo_mysql, json, curl).
- Composer debe estar instalado.

## Step-by-Step Workflows

### 1. Iniciar Scaffold de Proyecto Nuevo

Si el usuario pide inicializar el proyecto, corre el siguiente script de la skill. **Ejecutar el script desde la raíz del proyecto.** Este generará todas las carpetas, clases base y un `composer.json` ajustado a Vertical Slicing.

```bash
bash .github/skills/slim-php85-scaffold/scripts/scaffold.sh
```

### 2. Sincronizar Dependencias

Tras ejecutar el scaffold, notifica al agente/usuario o tú mismo ejecuta:

```bash
composer install
```
El autoload PSR-4 se encargará de enlazar el namespace `App\` a la carpeta `src/`.

### 3. Crear un Feature con repositorio tipado

Todo el código de un dominio vive junto en `src/Features/<NombreFeature>/`:

```
src/Features/Payments/
  ├── Payment.php              ← modelo (propiedades públicas tipadas)
  ├── PaymentRepository.php    ← extiende AbstractRepository
  └── PaymentController.php    ← recibe Request, llama al repositorio
```

```php
// src/Features/Payments/PaymentRepository.php
namespace App\Features\Payments;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class PaymentRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<Payment> */
    public function findPending(): array
    {
        return $this->fetchAll(
            'SELECT id, amount, status FROM payments WHERE status = ?',
            Payment::class,
            ['pending']
        );
    }
}
```

### 4. Validar el body de un request

Crear un validador por Feature que extienda `AbstractValidator`:

```php
// src/Features/Auth/LoginValidator.php
namespace App\Features\Auth;

use App\Infrastructure\Validation\AbstractValidator;

class LoginValidator extends AbstractValidator
{
    protected function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'min:8', 'max:128'],
        ];
    }
}
```

Adjuntar como middleware en la ruta:

```php
// app/Routes/ApiRoute.php
$group->post('/login', LoginController::class)
    ->add(new ValidationMiddleware(new LoginValidator(), $app->getResponseFactory()));
```

Si falla responde `422` con el mapa de errores:
```json
{ "error": "Validation failed", "errors": { "email": ["The email field is required."] } }
```

### 5. Hashear y verificar contraseñas

```php
$hasher = new PasswordHasher();           // usa ARGON2ID
$hash   = $hasher->hash($plainPassword);  // guardar en BD

// En login:
if (!$hasher->verify($input, $storedHash)) {
    // credenciales incorrectas
}
// Rehash si el costo cambió (llamar en login exitoso):
if ($hasher->needsRehash($storedHash)) {
    $user->password = $hasher->hash($plainPassword);
    $userRepository->save($user);
}
```

### 6. Emitir y validar JWT

Asegurarse de tener `JWT_SECRET` y `JWT_TTL_SECONDS` en `.env`.

```php
// Emitir token (ej. en LoginController después de verificar credenciales)
$jwtService = JwtService::fromEnv();
$token = $jwtService->issue(['sub' => $userId, 'role' => 'admin']);
// devolver $token al cliente

// Proteger un grupo de rutas con JwtMiddleware:
$jwt = new JwtMiddleware(JwtService::fromEnv(), $app->getResponseFactory());
$app->group('/api/private', function (RouteCollectorProxy $group) use ($app) {
    $group->get('/profile', ProfileController::class);
})->add($jwt);

// Leer claims en el controller:
$claims = $request->getAttribute('jwt_claims'); // stdClass
$userId = $claims->sub;
```

Si el token falta o es inválido el middleware responde `401 Unauthorized` automáticamente.

### 7. Enviar correos con Mailer

El `Mailer` usa PHPMailer con SMTP, configurado desde `.env`. Las plantillas son archivos **HTML standalone** en `views/emails/` con `{{placeholders}}` que se reemplazan en tiempo de envío.

`subject` y `year` se inyectan automáticamente en toda plantilla sin necesidad de pasarlos.

```php
$mailer = Mailer::fromEnv();

// Con plantilla HTML (views/emails/welcome.html)
$mailer->send(
    MailMessage::to('user@example.com', 'John')
        ->subject('Welcome to Our App!')
        ->htmlTemplate('welcome', [
            'name'     => 'John',
            'appName'  => 'My App',
            'loginUrl' => 'https://myapp.com/login',
        ])
);

// Con HTML directo
$mailer->send(
    MailMessage::to('admin@example.com')
        ->subject('New order #1234')
        ->html('<p>A new order has been placed.</p>')
        ->text('A new order has been placed.')
);

// Con adjunto y múltiples destinatarios
$mailer->send(
    MailMessage::to('billing@example.com')
        ->addCc('manager@example.com')
        ->subject('Invoice attached')
        ->htmlTemplate('invoice', ['orderId' => 1234])
        ->attach('/absolute/path/to/invoice.pdf')
);
```

**Crear una plantilla nueva** en `views/emails/<nombre>.html`:

Cada plantilla es un archivo HTML completo y standalone (layout + contenido fusionados). Se puede abrir directamente en el browser para previsualizar el diseño sin ejecutar PHP.

Los `{{placeholders}}` se sustituyen con `str_replace` directo — sin escapar, por lo que puedes pasar HTML pre-construido como valor de un placeholder sin problema.

```html
<!-- views/emails/reset-password.html -->
<!-- Placeholders disponibles: {{subject}}, {{year}}, {{appName}}, {{name}}, {{resetUrl}} -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{subject}}</title>
    <!-- estilos inline aquí -->
</head>
<body>
    <h1>{{appName}}</h1>
    <h2>Reset your password</h2>
    <p>Hi {{name}}, click below to reset your password. Link expires in 1 hour.</p>
    <a href="{{resetUrl}}">Reset password</a>
    <footer>&copy; {{year}} {{appName}}</footer>
</body>
</html>
```

**Template base disponible:** `templates/email-welcome.html.template` — contiene el layout completo con estilos compatibles con clientes de correo (inline CSS, reset MSO, media queries). Úsalo como punto de partida para nuevas plantillas.

### 8. Guía para añadir rutas con framework fronted como angular, react o view en un servidor con php.

1. Generar el build con la ruta en la que va ir en el servidor
ejemplo: si la app angular va en la subruta /user debe compilarse así:
`ng build --output-path=../../radical-nutrition/dist --base-href=/user/`

2. En el Servidor crear un endpoint que apunte al dist o nombre donde va a estar.

ejemplo: `$app->get('/user[/{path:.*}]', new SpaController(__DIR__ . '/../../dist'));`

Dentro del dist/ debe existir un archivo .htaccess

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.html [QSA,L]
```

3. En el archivo .htaccess del proyecto backend poner:

```apache
##  SPA Angular — servir assets estáticos desde dist/
##  /profile/main-abc.js → dist/main-abc.js (Apache lo sirve directo, sin pasar por PHP)
RewriteCond %{DOCUMENT_ROOT}/dist/$1 -f
RewriteRule ^user/(.+)$ dist/$1 [L]
```

#### 5. Herramientas de Desarrollo y Linter Local

Este scaffold viene equipado con potentes alias para Composer y VS Code que promueven un flujo de trabajo impecable:

**Comandos útiles de terminal:**
- `composer run start` → Levanta el servidor local aislando scripts dev en `server.php`.
- `composer run install:dev` → Instala todas las dependencias (incluyendo el linter `phpstan`).
- `composer run lint` → Ejecuta un análisis estricto en el código (búsqueda de errores lógicos, PHPStan no auto-corrige, solo avisa).
- `composer run build` → Muta el entorno a Producción borrando dependencias inseguras (~28MB) y optimizando el autoloader (`--optimize-autoloader`).
- `composer run dump` → Comando que emite `composer dump-autoload -o`. **Úsalo exclusivamente cuando agregues una nueva carpeta raíz o modifiques la directiva PSR-4** de `composer.json` para que Composer remapee las clases, de lo contrario, bastará con el lazy-loading automático que ya tiene tu carpeta `src/`.

**Tareas (Tasks) en VS Code:**
Si abres tu proyecto en VS Code encontrarás estas mismas herramientas estandarizadas presionando `Ctrl+Shift+P` -> `Tasks: Run Task`. Por ejemplo, la tarea del Linter está programada condicionalmente (`dependsOn`) para garantizar que las dependencias existan en memoria antes de ejecutarse.
También existe un _Launcher_ automágico (`F5`) que levanta el entorno CLI y dispara Chrome de inmediato a `http://localhost:8000`.

#### 6. Emulación SPA en Servidor Local CLI (`server.php`)

Para evitar manchar tu archivo `index.php` limpio de producción con reglas de Apache (que `php -S` de forma nativa ignora), el entorno utiliza un archivo dedicado **`server.php`**.
En tu archivo `.env` de desarrollo usa:
```env
APP_ENV=development
DEV_SPA_ROUTES='{"/profile": "/dist", "/admin": "/spa/admin"}'
```
Esto le permite a PHP interceptar puramente local y de forma limpia la ruteabilidad de la SPA y servir tus archivos `.js/.css` interceptándolos con el header correcto como si fuera `.htaccess`, dejándote así operar el SPA totalmente Offline & Clean.
  src/Infrastructure/Controllers/
    SpaController.php    ← generado por el scaffold
```

> **Nota para producción:** Añadir `spa/` al `.gitignore` si los builds se generan en CI/CD y no se versiona el output del build.

---

## Patrones de Producción Verificados

Secciones extraídas de un proyecto real en producción (Slim 4 + PHP 8.5). **Todas las clases respetan la arquitectura Screaming de la skill:** infraestructura transversal en `src/Infrastructure/`, excepciones en `src/Shared/Exceptions/`, lógica de dominio en `src/Features/<Feature>/`, rutas en `app/Routes/`.

Cada sección incluye el **template físico** en `templates/` listo para copiar, el **destino** donde va en el proyecto, y **ejemplos de integración** dentro de un Feature.

---

### 9. AppConfig — Centralización de Variables de Entorno

**Template:** `templates/AppConfig.php.template`
**Destino:** `src/Infrastructure/AppConfig.php`

**Regla de oro: `$_ENV` se lee en UN solo lugar.** `AppConfig::fromEnv()` es el único método del proyecto que accede a `$_ENV`. El resto del código recibe valores tipados vía constructor o método `init()`.

```php
// src/Infrastructure/AppConfig.php — estructura de sub-configs
readonly class AppConfig
{
    public function __construct(
        public string        $appEnv,
        public DbConfig      $db,
        public JwtConfig     $jwt,
        public MailConfig    $mail,
        public CorsConfig    $cors,
        public CacheConfig   $cache,
        public SessionConfig $session,
        public string        $mediaApiKey,
    ) {}

    public static function fromEnv(): self { /* única lectura de $_ENV */ }

    public function isProduction(): bool { return $this->appEnv === 'production'; }
}
```

**Flujo de inicialización en `App::create()`** — todo sigue a una sola llamada:

```php
$config = AppConfig::fromEnv();  // ← único acceso a $_ENV en todo el proyecto

// Servicios estáticos reciben valores tipados via init()
LoggerService::init($config->isProduction());
CookieService::init($config->isProduction());
SessionService::init($config->session->ttl);

// Servicios con constructor reciben su sub-config
DB::boot($config->db);
$slim->add(new CorsMiddleware($config->cors->origins));
$cache = new CacheService($config->cache->dir, $config->cache->ttl);
$mailer = new Mailer($config->mail);
```

**Sub-configs disponibles:**

| Sub-config | Tipo | Propiedades |
|---|---|---|
| `$config->db` | `DbConfig` | `host`, `name`, `user`, `pass`, `charset` |
| `$config->jwt` | `JwtConfig` | `secret`, `ttlSeconds` |
| `$config->mail` | `MailConfig` | `host`, `port`, `username`, `password`, `from`, `fromName`, `encryption` |
| `$config->cors` | `CorsConfig` | `origins: list<string>` |
| `$config->cache` | `CacheConfig` | `dir`, `ttl` |
| `$config->session` | `SessionConfig` | `ttl` |
| `$config->mediaApiKey` | `string` | API key para multimedia |

**Ventajas del patrón:**
- Un solo punto de fallo si falta una variable de entorno
- Los servicios son testeables sin variables de entorno (solo instanciar la sub-config)
- PHPStan detecta inmediatamente si se accede a una propiedad inexistente

---

### 10. Twig Templates

Instalar dependencias:
```bash
composer require slim/twig-view
```

Crear el directorio de caché (writable por el servidor web):
```bash
mkdir -p cache/twig && chmod 775 cache/twig
```

**Template:** `templates/TwigNotFoundHandler.php.template`
**Destino:** `src/Infrastructure/Handlers/TwigNotFoundHandler.php`

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Handlers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Throwable;

/**
 * 404 con soporte Twig. Rutas /api/* responden JSON; rutas web renderizan
 * views/errors/404.html con la variable {{ path }}.
 */
class TwigNotFoundHandler
{
    public function __construct(private readonly Twig $twig) {}

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $path = $request->getUri()->getPath();
        error_log("404 Not Found: [{$request->getMethod()}] {$path}");

        return $this->isApiRequest($request)
            ? $this->jsonResponse($path)
            : $this->twigResponse($path);
    }

    private function isApiRequest(ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getUri()->getPath(), '/api')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function jsonResponse(string $path): ResponseInterface
    {
        $response = new SlimResponse();
        $response->getBody()->write(
            json_encode(['error' => 'Not Found', 'path' => $path], JSON_THROW_ON_ERROR)
        );
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    private function twigResponse(string $path): ResponseInterface
    {
        return $this->twig->render(new SlimResponse(), 'errors/404.html', [
            'path' => htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ])->withStatus(404);
    }
}
```

**Registrar Twig en `src/Infrastructure/App.php`** — añadir tras `AppFactory::create()`:

```php
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Slim\Exception\HttpNotFoundException;
use App\Infrastructure\Handlers\TwigNotFoundHandler;

// Caché solo en producción para no trabajar con plantillas obsoletas en dev
$twig = Twig::create(__DIR__ . '/../../../views', [
    'cache' => $_ENV['APP_ENV'] === 'production'
        ? __DIR__ . '/../../../cache/twig'
        : false,
    'auto_reload' => $_ENV['APP_ENV'] !== 'production',
]);
$slim->add(TwigMiddleware::create($slim, $twig));

// Registrar en el errorMiddleware DESPUÉS de addErrorMiddleware()
$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    new TwigNotFoundHandler($twig),
);
```

**Renderizar desde un Feature controller (`src/Features/Blog/BlogController.php`):**

```php
<?php declare(strict_types=1);
namespace App\Features\Blog;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Infrastructure\Http\CookieService;
use App\Infrastructure\Logger\LoggerService;

class BlogController
{
    // Listado del blog
    public static function index(Request $request, Response $response): Response
    {
        return Twig::fromRequest($request)->render($response, 'blog.html', [
            'url'     => $_ENV['URL_SERVER'],
            'version' => $_ENV['VERSION'],
            'page'    => 'blog',
            'theme'   => CookieService::getTheme(),
        ]);
    }

    // Artículo por slug dinámico
    public static function article(Request $request, Response $response): Response
    {
        $slug    = basename($request->getUri()->getPath());
        $view    = Twig::fromRequest($request);
        $article = (new ArticleRepository())->findBySlug($slug);

        if ($article === null) {
            LoggerService::warning('Article not found', ['slug' => $slug]);
            return $view->render($response, 'errors/404.html', [
                'path' => "/blog/{$slug}",
            ])->withStatus(404);
        }

        return $view->render($response, 'blog/article.html', [
            'url'         => $_ENV['URL_SERVER'],
            'theme'       => CookieService::getTheme(),
            'metaTitle'   => $article->metaTitle,
            'description' => $article->description,
            'img'         => $article->img,
            'path'        => $article->path,
        ]);
    }
}
```

**Rutas HTML** (`app/Routes/WebRoute.php`) — registrar junto a las existentes:

```php
use App\Features\Blog\BlogController;
use App\Features\Home\HomeController;

return function (\Slim\App $app): void {
    $app->get('/',              HomeController::class . ':index');
    $app->get('/sobre-nosotros', HomeController::class . ':aboutUs');
    $app->get('/blog',           BlogController::class . ':index');
    $app->get('/blog/{url}',     BlogController::class . ':article');
};
```

**Lista de verificación — directorio `views/`:**
```
views/
  blog.html           ← listado de artículos
  blog/
    article.html      ← plantilla de artículo individual
  errors/
    404.html          ← recibe {{ path }}
  layout.html         ← {% block content %} base
```

**Estrategia de caché:**
- `APP_ENV=production` → `cache/twig/` activo.
- `APP_ENV=development` → `false` + `auto_reload: true`, sin archivos obsoletos.
- Añadir `cache/twig/` al `.gitignore`.

---

### 11. CacheService (Symfony PSR-16)

**Template:** `templates/CacheService.php.template`
**Destino:** `src/Infrastructure/Cache/CacheService.php`

Instalar dependencias:
```bash
composer require symfony/cache psr/simple-cache
```

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Cache;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CacheService
{
    private readonly Psr16Cache $cache;

    public function __construct(
        string $directory       = 'cache/simplecache/',
        int    $defaultLifetime = 3600,
    ) {
        $this->cache = new Psr16Cache(
            new FilesystemAdapter(
                namespace:       '',
                defaultLifetime: $defaultLifetime,
                directory:       $directory,
            )
        );
    }

    public static function fromEnv(): self
    {
        return new self(
            directory:       $_ENV['CACHE_DIR'] ?? 'cache/simplecache/',
            defaultLifetime: (int) ($_ENV['CACHE_TTL'] ?? 3600),
        );
    }

    public function get(string $key): mixed  { return $this->cache->get($key); }
    public function has(string $key): bool   { return $this->cache->has($key); }
    public function delete(string $key): bool { return $this->cache->delete($key); }
    public function clear(): bool            { return $this->cache->clear(); }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->cache->set($key, $value, $ttl);
    }
}
```

**Constantes de claves por Feature** — crear `CacheKeys.php` dentro de cada Feature:

```php
// src/Features/Catalog/CacheKeys.php
<?php declare(strict_types=1);
namespace App\Features\Catalog;

final class CacheKeys
{
    const PRODUCTS     = 'catalog_products';
    const TTL_PRODUCTS = 1800; // 30 minutos
}
```

**Integración completa en un Feature repository (`src/Features/Catalog/ProductRepository.php`):**

```php
<?php declare(strict_types=1);
namespace App\Features\Catalog;

use App\Infrastructure\Cache\CacheService;
use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Logger\LoggerService;

class ProductRepository extends AbstractRepository
{
    private CacheService $cache;

    public function __construct()
    {
        parent::__construct(DB::getInstance());
        $this->cache = CacheService::fromEnv();
    }

    /** @return list<Product> */
    public function findAll(): array
    {
        $cached = $this->cache->get(CacheKeys::PRODUCTS);

        if ($cached !== null) {
            LoggerService::debug('Cache hit', ['key' => CacheKeys::PRODUCTS]);
            return $cached;
        }

        $products = $this->fetchAll(
            'SELECT id, name, price, stock FROM products WHERE active = 1',
            Product::class,
        );

        $this->cache->set(CacheKeys::PRODUCTS, $products, CacheKeys::TTL_PRODUCTS);
        LoggerService::info('Products cached', ['count' => count($products)]);

        return $products;
    }

    public function invalidate(): void
    {
        $this->cache->delete(CacheKeys::PRODUCTS);
        LoggerService::info('Products cache invalidated');
    }
}
```

**Variables de entorno adicionales (`.env`):**
```env
CACHE_DIR=cache/simplecache/
CACHE_TTL=3600
```

---

### 12. CookieService

**Template:** `templates/CookieService.php.template`
**Destino:** `src/Infrastructure/Http/CookieService.php`

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Http;

/**
 * Gestión de cookies. Estático, sin instanciación.
 * Cookies de usuario: httponly=true, Secure en producción.
 * Cookie de tema: httponly=false (JS la lee para evitar flash de contenido).
 */
class CookieService
{
    private const COOKIE_USER  = '_app_user';
    private const COOKIE_THEME = '_theme';

    private const TTL_MONTH = 2_592_000;  // 30 días
    private const TTL_YEAR  = 31_536_000; // 365 días

    // ─── User ───────────────────────────────────────────────────────────────────

    public static function saveUser(int $userId): void
    {
        setcookie(
            name:               self::COOKIE_USER,
            value:              base64_encode((string) $userId),
            expires_or_options: time() + self::TTL_MONTH,
            path:               '/',
            secure:             self::isSecure(),
            httponly:           true,
        );
    }

    public static function getUser(): ?int
    {
        if (!isset($_COOKIE[self::COOKIE_USER])) {
            return null;
        }
        $decoded = base64_decode($_COOKIE[self::COOKIE_USER], strict: true);
        return ($decoded !== false && ctype_digit($decoded)) ? (int) $decoded : null;
    }

    public static function hasUser(): bool { return isset($_COOKIE[self::COOKIE_USER]); }

    public static function removeUser(): void
    {
        setcookie(self::COOKIE_USER, '', time() - 3600, '/');
    }

    // ─── Theme ──────────────────────────────────────────────────────────────────

    public static function saveTheme(string $theme): void
    {
        $safe = in_array($theme, ['light', 'dark'], strict: true) ? $theme : 'light';
        setcookie(
            name:               self::COOKIE_THEME,
            value:              $safe,
            expires_or_options: time() + self::TTL_YEAR,
            path:               '/',
            secure:             self::isSecure(),
            httponly:           false,
        );
    }

    public static function getTheme(): string
    {
        $theme = $_COOKIE[self::COOKIE_THEME] ?? 'light';
        return in_array($theme, ['light', 'dark'], strict: true) ? $theme : 'light';
    }

    // ─── Helper ─────────────────────────────────────────────────────────────────

    private static function isSecure(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'production') === 'production';
    }
}
```

**Integración en un Feature controller — gestión de tema:**

```php
// src/Features/Home/HomeController.php
<?php declare(strict_types=1);
namespace App\Features\Home;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Infrastructure\Http\CookieService;

class HomeController
{
    public static function index(Request $request, Response $response): Response
    {
        return Twig::fromRequest($request)->render($response, 'home.html', [
            'url'     => $_ENV['URL_SERVER'],
            'version' => $_ENV['VERSION'],
            'page'    => 'home',
            'theme'   => CookieService::getTheme(), // 'light' | 'dark'
        ]);
    }

    // POST /theme — llamado por el toggle del frontend
    public static function changeTheme(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $theme = (string) ($body['theme'] ?? 'light');
        CookieService::saveTheme($theme);

        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
```

---

### 13. SessionService

**Template:** `templates/SessionService.php.template`
**Destino:** `src/Infrastructure/Http/SessionService.php`

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Http;

/**
 * Gestiona el ciclo de vida de la sesión PHP.
 * Siempre verificar session_status antes de llamar session_start().
 * Incluye mensajes flash que se autodestruyen al leer.
 */
class SessionService
{
    private const KEY_USER  = 'user';
    private const KEY_FLASH = '_flash';
    private const DEFAULT_TTL = 1440;

    // ─── Lifecycle ──────────────────────────────────────────────────────────────

    public static function start(): void
    {
        $ttl = (int) ($_ENV['TIME_LIFE_SESSION'] ?? self::DEFAULT_TTL);
        ini_set('session.gc_maxlifetime',  (string) $ttl);
        ini_set('session.cookie_lifetime', (string) $ttl);
        session_start();
    }

    public static function destroy(): void
    {
        self::ensureStarted();
        $_SESSION = [];
        session_destroy();
    }

    private static function ensureStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }
    }

    // ─── User ───────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $user */
    public static function setUser(array $user): void
    {
        self::ensureStarted();
        $_SESSION[self::KEY_USER] = $user;
    }

    /** @return array<string, mixed>|null */
    public static function getUser(): ?array
    {
        self::ensureStarted();
        $user = $_SESSION[self::KEY_USER] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function isLoggedIn(): bool { return self::getUser() !== null; }

    public static function removeUser(): void
    {
        self::ensureStarted();
        unset($_SESSION[self::KEY_USER]);
    }

    // ─── Flash messages (se destruyen al leer) ──────────────────────────────────

    public static function flash(string $key, string $message): void
    {
        self::ensureStarted();
        $_SESSION[self::KEY_FLASH][$key] = $message;
    }

    public static function getFlash(string $key): ?string
    {
        self::ensureStarted();
        $value = $_SESSION[self::KEY_FLASH][$key] ?? null;
        unset($_SESSION[self::KEY_FLASH][$key]);
        return is_string($value) ? $value : null;
    }

    // ─── Acceso raw por Feature ─────────────────────────────────────────────────

    public static function set(string $key, mixed $value): void
    {
        self::ensureStarted();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key): mixed
    {
        self::ensureStarted();
        return $_SESSION[$key] ?? null;
    }
}
```

**Integración en un Feature de autenticación (`src/Features/Auth/LoginController.php`):**

```php
<?php declare(strict_types=1);
namespace App\Features\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Http\SessionService;
use App\Infrastructure\Auth\PasswordHasher;
use App\Infrastructure\Logger\LoggerService;
use App\Shared\Exceptions\UnauthorizedException;

class LoginController
{
    public function __invoke(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $email    = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        $user = (new UserRepository())->findByEmail($email);

        if ($user === null || !(new PasswordHasher())->verify($password, $user->password)) {
            LoggerService::warning('Failed login attempt', ['email' => $email]);
            SessionService::flash('error', 'Credenciales incorrectas.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        SessionService::setUser([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ]);

        LoggerService::info('User logged in', ['user_id' => $user->id]);

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }
}
```

**Variable de entorno:**
```env
TIME_LIFE_SESSION=1440
```

---

### 14. RateLimitMiddleware (Symfony Rate Limiter)

**Template:** `templates/RateLimitMiddleware.php.template`
**Destino:** `src/Infrastructure/Middlewares/RateLimitMiddleware.php`

Instalar dependencias:
```bash
composer require symfony/rate-limiter symfony/cache
```

Middleware PSR-15 de rate limiting por IP. Usa ventana fija (`fixed_window`) con almacenamiento en archivos — sin Redis, sin base de datos. Compatible con cPanel.

```php
// src/Infrastructure/Middlewares/RateLimitMiddleware.php
new RateLimitMiddleware(
    responseFactory: $app->getResponseFactory(),
    id:              'login',   // identificador único por endpoint
    limit:           5,         // máximo de peticiones
    interval:        '1 minute' // ventana de tiempo
);
```

Responde `429 Too Many Requests` con header `Retry-After` cuando se supera el límite:
```json
{ "error": "Too Many Requests", "retryAfter": 42 }
```

**Registro en una ruta** — los middlewares se aplican en orden LIFO:

```php
// app/Routes/ApiRoute.php
$app->post('/api/login', LoginController::class)
    ->add(new ValidationMiddleware(new LoginValidator(), $app->getResponseFactory()))
    ->add(new RateLimitMiddleware($app->getResponseFactory(), id: 'login', limit: 5, interval: '1 minute'));
```

**Almacenamiento en disco:**
Los contadores por IP se guardan en `logs/rate-limiter/` (creado automáticamente en la primera petición). Añadir al `.gitignore`:
```
logs/rate-limiter/
```

**Directorio personalizado** — parámetro opcional `storageDir`:
```php
new RateLimitMiddleware($factory, storageDir: '/ruta/absoluta/cache')
```

---

### 15. LoggerService (Monolog PSR-3)

**Template:** `templates/LoggerService.php.template`
**Destino:** `src/Infrastructure/Logger/LoggerService.php`

Instalar dependencias:
```bash
composer require monolog/monolog
mkdir -p logs && chmod 775 logs
echo "logs/*.log" >> .gitignore
```

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Logger;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Singleton. Dos canales:
 *   logs/app-YYYY-MM-DD.log — DEBUG+, rotación diaria, 30 días de retención
 *   logs/error.log          — ERROR+, archivo fijo (monitorizable con tail -f)
 */
class LoggerService
{
    private static ?Logger $instance = null;
    private const LOGS_DIR  = __DIR__ . '/../../../logs';
    private const MAX_FILES = 30;

    private function __construct() {}

    public static function getInstance(): Logger
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $fmt = new LineFormatter(
            format:                     "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            dateFormat:                 'Y-m-d H:i:s',
            allowInlineLineBreaks:      true,
            ignoreEmptyContextAndExtra: true,
        );

        $rotating = new RotatingFileHandler(self::LOGS_DIR . '/app.log', self::MAX_FILES, Level::Debug);
        $rotating->setFormatter($fmt);

        $errors = new StreamHandler(self::LOGS_DIR . '/error.log', Level::Error);
        $errors->setFormatter($fmt);

        self::$instance = new Logger('app');
        self::$instance->pushHandler($rotating);
        self::$instance->pushHandler($errors);

        return self::$instance;
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void    { self::getInstance()->debug($message, $context); }
    public static function info(string $message, array $context = []): void     { self::getInstance()->info($message, $context); }
    public static function warning(string $message, array $context = []): void  { self::getInstance()->warning($message, $context); }
    public static function error(string $message, array $context = []): void    { self::getInstance()->error($message, $context); }
    public static function critical(string $message, array $context = []): void { self::getInstance()->critical($message, $context); }
}
```

**Integración en repositorios con source JSON (patrón producción real):**

```php
// src/Features/Blog/ArticleRepository.php
<?php declare(strict_types=1);
namespace App\Features\Blog;

use App\Infrastructure\Logger\LoggerService;
use App\Shared\Exceptions\IoException;

class ArticleRepository
{
    private string $path = __DIR__ . '/../../../database/articles.json';

    public function findBySlug(string $slug): ?Article
    {
        if (!file_exists($this->path)) {
            LoggerService::error('articles.json not found', ['path' => $this->path]);
            throw new IoException("Articles database not found.", 500);
        }

        $json = file_get_contents($this->path);

        if ($json === false) {
            LoggerService::error('Cannot read articles.json', ['path' => $this->path]);
            throw new IoException("Cannot read articles database.", 500);
        }

        $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        foreach ($data as $item) {
            if (($item['url'] ?? '') === $slug) {
                LoggerService::debug('Article found', ['slug' => $slug]);
                return new Article($item);
            }
        }

        LoggerService::warning('Article not found', ['slug' => $slug]);
        return null;
    }
}
```

**Integración con excepciones en un Feature controller:**

```php
try {
    $result = $repository->save($entity);
} catch (DatabaseException $e) {
    LoggerService::error('DB save failed', [
        'message'   => $e->getMessage(),
        'http_code' => $e->getHttpCode(),
    ]);
    $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($e->getHttpCode());
}
```

**Nivel mínimo de log por `APP_ENV` (ya incluido en el template):**

En producción no interesa llenar el disco con `INFO` de cada request exitoso. El template ya resuelve esto sin variables de entorno extra — reutiliza `APP_ENV`:

```
APP_ENV=production   →  rotating handler captura WARNING+
APP_ENV=development  →  rotating handler captura DEBUG+ (trazabilidad completa)
error.log            →  siempre ERROR+, independientemente del entorno
```

**`RequestLogMiddleware` — loguear cada request HTTP:**

**Template:** `templates/RequestLogMiddleware.php.template`
**Destino:** `src/Infrastructure/Middlewares/RequestLogMiddleware.php`

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Middlewares;

use App\Infrastructure\Logger\LoggerService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RequestLogMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $start    = hrtime(as_number: true);
        $response = $handler->handle($request);
        $ms       = round((hrtime(as_number: true) - $start) / 1_000_000, 2);

        $status = $response->getStatusCode();
        $method = $request->getMethod();
        $path   = $request->getUri()->getPath();

        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default        => 'info',
        };

        LoggerService::$level("[{$method}] {$path} → {$status} ({$ms}ms)");

        return $response;
    }
}
```

Registrar en `src/Infrastructure/App.php` **antes** del routing middleware (orden LIFO en Slim — se ejecuta de último):

```php
use App\Infrastructure\Middlewares\RequestLogMiddleware;

$slim->add(new RequestLogMiddleware());
$slim->addRoutingMiddleware();
```

Resultado en `logs/app-YYYY-MM-DD.log`:
```
[2026-04-04 12:00:00] app.INFO: [GET] /blog → 200 (12.4ms)
[2026-04-04 12:00:01] app.WARNING: [POST] /api/login → 401 (5.1ms)
[2026-04-04 12:00:02] app.ERROR: [GET] /api/users → 500 (8.3ms)
```

> En producción (`APP_ENV=production`) los `INFO` no se escriben en el log rotativo — solo los `WARNING` y `ERROR`. Los 200 OK no saturan el disco.

**Integrar el logger con `addErrorMiddleware()` de Slim:**

Slim 4 acepta un logger PSR-3 como cuarto argumento. Así los errores internos de Slim (excepciones no capturadas, rutas no encontradas) también pasan por Monolog en lugar de `error_log()` de PHP:

```php
// src/Infrastructure/App.php — reemplazar la línea de addErrorMiddleware
$errorMiddleware = $slim->addErrorMiddleware(
    displayErrorDetails: $_ENV['APP_ENV'] !== 'production',
    logErrors:           true,
    logErrorDetails:     true,
    logger:              LoggerService::getInstance(), // ← PSR-3 Logger
);
```

---

### 15. Excepciones de Dominio

**Template:** `templates/AppException.php.template`
**Destino:** `src/Shared/Exceptions/AppException.php`

```php
<?php declare(strict_types=1);
namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * Base para todas las excepciones de dominio.
 * $httpCode mapea directamente al status HTTP en el controller.
 * No instanciar directamente: usar clases derivadas semánticas.
 */
class AppException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpCode = 500,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
```

**Excepciones derivadas** — crear un archivo por excepción en `src/Shared/Exceptions/`:

```php
// DatabaseException.php
<?php declare(strict_types=1);
namespace App\Shared\Exceptions;
class DatabaseException    extends AppException {}

// IoException.php
<?php declare(strict_types=1);
namespace App\Shared\Exceptions;
class IoException           extends AppException {}

// NotFoundException.php
<?php declare(strict_types=1);
namespace App\Shared\Exceptions;
class NotFoundException     extends AppException {}

// UnauthorizedException.php
<?php declare(strict_types=1);
namespace App\Shared\Exceptions;
class UnauthorizedException extends AppException {}

// ConflictException.php
<?php declare(strict_types=1);
namespace App\Shared\Exceptions;
class ConflictException     extends AppException {}

// MailerException.php
<?php declare(strict_types=1);
namespace App\Shared\Exceptions;
class MailerException       extends AppException {}
```

> La `ValidationException` ya viene incluida en el scaffold en `src/Shared/Exceptions/ValidationException.php` y es lanzada automáticamente por el `ValidationMiddleware`. No duplicar.

**Patrón de captura granular en un Feature controller:**

```php
// src/Features/Articles/ArticleController.php
try {
    $article = $this->repository->findById((int) $args['id']);
} catch (NotFoundException $e) {
    // 404 → no loguear como error, es tráfico normal
    LoggerService::warning($e->getMessage(), ['http_code' => $e->getHttpCode()]);
    $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
    return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
} catch (AppException $e) {
    // Cualquier otro error de dominio → loguear como error
    LoggerService::error($e->getMessage(), ['http_code' => $e->getHttpCode()]);
    $response->getBody()->write(json_encode(['error' => 'Internal server error.']));
    return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus($e->getHttpCode());
}
```

---

### 16. Recursos Multimedia (Video / Image / JSON)

**Template:** `templates/FileController.php.template`
**Destino:** `src/Infrastructure/Controllers/FileController.php`

El controlador usa `hash_equals()` para comparación en tiempo constante, previniendo ataques de timing sobre la API key. Responde JSON en los casos de error para ser consistente con la API.

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Controllers;

use App\Infrastructure\Logger\LoggerService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FileController
{
    // Cambiar las rutas a los archivos reales del proyecto
    private const FILE_VIDEO = 'public/videos/demo.mp4';
    private const FILE_IMAGE = 'public/img/photo.webp';
    private const FILE_JSON  = 'public/json/data.json';

    public function serveVideo(Request $request, Response $response): Response
    {
        return $this->stream($request, $response, self::FILE_VIDEO);
    }

    public function serveImage(Request $request, Response $response): Response
    {
        return $this->stream($request, $response, self::FILE_IMAGE);
    }

    public function serveJson(Request $request, Response $response): Response
    {
        return $this->stream($request, $response, self::FILE_JSON, 'application/json; charset=UTF-8');
    }

    private function stream(
        Request  $request,
        Response $response,
        string   $filepath,
        ?string  $contentType = null,
    ): Response {
        if (!$this->isAuthorized($request)) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        if (!is_file($filepath)) {
            LoggerService::warning('Media file not found', ['path' => $filepath]);
            $response->getBody()->write(json_encode(['error' => 'Not found'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $handle = fopen($filepath, 'rb');

        if ($handle === false) {
            LoggerService::error('Cannot open file for streaming', ['path' => $filepath]);
            $response->getBody()->write(json_encode(['error' => 'Internal error'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $type    = $contentType ?? (mime_content_type($filepath) ?: 'application/octet-stream');
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) strlen($content));
    }

    // hash_equals: comparación en tiempo constante → protege contra timing attacks
    private function isAuthorized(Request $request): bool
    {
        $key      = (string) ($request->getQueryParams()['key'] ?? '');
        $expected = (string) ($_ENV['API_KEY_MEDIA'] ?? '');
        return $expected !== '' && hash_equals($expected, $key);
    }
}
```

**Rutas** (`app/Routes/ResourceRoute.php`) — el scaffold ya incluye este archivo:

```php
<?php declare(strict_types=1);
use App\Infrastructure\Controllers\FileController;

return function (\Slim\App $app): void {
    $app->get('/media/video', [FileController::class, 'serveVideo']);
    $app->get('/media/image', [FileController::class, 'serveImage']);
    $app->get('/media/json',  [FileController::class, 'serveJson']);
};
```

**Variables de entorno necesarias** (añadir al `.env`):
```env
API_KEY_MEDIA=genera_una_clave_segura_aqui
APP_ENV=development
TIME_LIFE_SESSION=1440
CACHE_DIR=cache/simplecache/
CACHE_TTL=3600
```

---

## Available Scripts and Resources

- Ejecuta `bash .github/skills/slim-php85-scaffold/scripts/scaffold.sh` para generar toda la estructura de carpetas y archivos base.
- Revisa las plantillas en `templates/` para inyectar componentes individualmente:

| Template | Destino generado |
|---|---|
| `composer.json.template` | `composer.json` — PHP >=8.5, Slim, dotenv, jwt, phpmailer, PHPStan |
| `AppConfig.php.template` | `src/Infrastructure/AppConfig.php` — único lector de `$_ENV`; sub-configs tipados para DB, JWT, mail, CORS, caché y sesión |
| `index.php.template` | `index.php` — entry point de 3 líneas |
| `.htaccess.template` | `.htaccess` — PHP 8.5, HTTPS, cabeceras de seguridad |
| `App.php.template` | `src/Infrastructure/App.php` — factory: `DB::boot`, CORS, 404 handler, rutas |
| `DatabaseConfig.php.template` | `src/Infrastructure/Database/DatabaseConfig.php` — value object `readonly` |
| `Database.php.template` | `src/Infrastructure/Database/DB.php` — `boot(Config)` + `getInstance()` |
| `AbstractRepository.php.template` | `src/Infrastructure/Database/AbstractRepository.php` — base tipada |
| `ValidationException.php.template` | `src/Shared/Exceptions/ValidationException.php` |
| `AbstractValidator.php.template` | `src/Infrastructure/Validation/AbstractValidator.php` — reglas declarativas |
| `ValidationMiddleware.php.template` | `src/Infrastructure/Middlewares/ValidationMiddleware.php` — responde 422 |
| `PasswordHasher.php.template` | `src/Infrastructure/Auth/PasswordHasher.php` — ARGON2ID |
| `JwtService.php.template` | `src/Infrastructure/Auth/JwtService.php` — issue/decode HS256 |
| `JwtMiddleware.php.template` | `src/Infrastructure/Middlewares/JwtMiddleware.php` — responde 401 |
| `Mailer.php.template` | `src/Infrastructure/Mail/Mailer.php` — wrapper PHPMailer con SMTP y plantillas |
| `MailMessage.php.template` | `src/Infrastructure/Mail/MailMessage.php` — fluent builder del mensaje |
| `NotFoundHandler.php.template` | `src/Infrastructure/Handlers/NotFoundHandler.php` — JSON para `/api/*`, HTML para web |
| `CorsMiddleware.php.template` | `src/Infrastructure/Middlewares/CorsMiddleware.php` |
| `404.html.template` | `views/errors/404.html` — página 404 personalizable |
| `email-layout.php.template` | `views/emails/layout.php` — layout base HTML para correos (compatible con Gmail, Outlook) |
| `email-welcome.php.template` | `views/emails/welcome.php` — plantilla de bienvenida de ejemplo |
| `SpaController.php.template` | `src/Infrastructure/Controllers/SpaController.php` — sirve el `index.html` del SPA para rutas HTML5 |
| `CacheService.php.template` | `src/Infrastructure/Cache/CacheService.php` — PSR-16 FileSystem, `fromEnv()`, `get/set/has/delete/clear` |
| `CookieService.php.template` | `src/Infrastructure/Http/CookieService.php` — user (httponly) + theme (JS-readable), `Secure` en producción |
| `SessionService.php.template` | `src/Infrastructure/Http/SessionService.php` — ciclo de vida + flash messages + `isLoggedIn()` |
| `LoggerService.php.template` | `src/Infrastructure/Logger/LoggerService.php` — Monolog: rotación diaria + `error.log` fijo |
| `RequestLogMiddleware.php.template` | `src/Infrastructure/Middlewares/RequestLogMiddleware.php` — loguea METHOD + path + status + ms |
| `AppException.php.template` | `src/Shared/Exceptions/AppException.php` — base con `$httpCode`, extender para cada tipo de error |
| `FileController.php.template` | `src/Infrastructure/Controllers/FileController.php` — streaming seguro, API key via `hash_equals()` |
| `TwigNotFoundHandler.php.template` | `src/Infrastructure/Handlers/TwigNotFoundHandler.php` — 404 Twig (web) + JSON (`/api/*`) |
