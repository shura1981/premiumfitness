#!/bin/bash

set -euo pipefail

# Vertical Slicing & Screaming Architecture Scaffold Script
# For Slim PHP 8.5 Projects

echo "🚀 Initializing Slim PHP 8.5 Scaffold..."

SKILL_DIR=".github/skills/slim-php85-scaffold"

# ---------------------------------------------------------------------------
# Directory structure
# ---------------------------------------------------------------------------
mkdir -p \
    src/Features/Example \
    src/Infrastructure/Auth \
    src/Infrastructure/Controllers \
    src/Infrastructure/Database \
    src/Infrastructure/Handlers \
    src/Infrastructure/Mail \
    src/Infrastructure/Middlewares \
    src/Infrastructure/Validation \
    src/Shared/Exceptions \
    app/Routes \
    load \
    logs/errors \
    logs/requests \
    spa \
    views/emails \
    views/errors \
    .vscode

# ---------------------------------------------------------------------------
# Copy static templates
# ---------------------------------------------------------------------------
cp "$SKILL_DIR/templates/composer.json.template"            composer.json
cp "$SKILL_DIR/templates/index.php.template"                index.php
cp "$SKILL_DIR/templates/server.php.template"               server.php
cp "$SKILL_DIR/templates/.htaccess.template"                .htaccess
cp "$SKILL_DIR/templates/App.php.template"                    src/Infrastructure/App.php
cp "$SKILL_DIR/templates/DatabaseConfig.php.template"         src/Infrastructure/Database/DatabaseConfig.php
cp "$SKILL_DIR/templates/Database.php.template"               src/Infrastructure/Database/DB.php
cp "$SKILL_DIR/templates/AbstractRepository.php.template"     src/Infrastructure/Database/AbstractRepository.php
cp "$SKILL_DIR/templates/CorsMiddleware.php.template"         src/Infrastructure/Middlewares/CorsMiddleware.php
cp "$SKILL_DIR/templates/ValidationMiddleware.php.template"   src/Infrastructure/Middlewares/ValidationMiddleware.php
cp "$SKILL_DIR/templates/JwtMiddleware.php.template"          src/Infrastructure/Middlewares/JwtMiddleware.php
cp "$SKILL_DIR/templates/NotFoundHandler.php.template"        src/Infrastructure/Handlers/NotFoundHandler.php
cp "$SKILL_DIR/templates/AbstractValidator.php.template"      src/Infrastructure/Validation/AbstractValidator.php
cp "$SKILL_DIR/templates/ValidationException.php.template"    src/Shared/Exceptions/ValidationException.php
cp "$SKILL_DIR/templates/PasswordHasher.php.template"         src/Infrastructure/Auth/PasswordHasher.php
cp "$SKILL_DIR/templates/JwtService.php.template"             src/Infrastructure/Auth/JwtService.php
cp "$SKILL_DIR/templates/Mailer.php.template"                 src/Infrastructure/Mail/Mailer.php
cp "$SKILL_DIR/templates/MailMessage.php.template"            src/Infrastructure/Mail/MailMessage.php
cp "$SKILL_DIR/templates/404.html.template"                   views/errors/404.html
cp "$SKILL_DIR/templates/email-layout.php.template"           views/emails/layout.php
cp "$SKILL_DIR/templates/email-welcome.php.template"          views/emails/welcome.php
cp "$SKILL_DIR/templates/SpaController.php.template"          src/Infrastructure/Controllers/SpaController.php

# ---------------------------------------------------------------------------
# VS Code Configuration (.vscode)
# ---------------------------------------------------------------------------
cp "$SKILL_DIR/templates/tasks.json.template"                 .vscode/tasks.json
cp "$SKILL_DIR/templates/launch.json.template"                .vscode/launch.json

# ---------------------------------------------------------------------------
# load/bootstrap.php
# ---------------------------------------------------------------------------
cat <<'PHP' > load/bootstrap.php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$dotenv->required(['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'CORS_ALLOWED_ORIGINS'])->notEmpty();
$dotenv->required(['JWT_SECRET', 'JWT_TTL_SECONDS'])->notEmpty();
$dotenv->required('JWT_SECRET')->assert(fn (\$v) => strlen(\$v) >= 32, 'JWT_SECRET must be at least 32 characters.');
$dotenv->required(['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM', 'MAIL_FROM_NAME', 'MAIL_ENCRYPTION'])->notEmpty();

date_default_timezone_set('America/Bogota');
PHP

# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------
cat <<'PHP' > app/Routes/ApiRoute.php
<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

return static function (\Slim\App $app): void {
    $app->group('/api', function (RouteCollectorProxy $group): void {
        $group->get('/health', function (Request $request, Response $response): Response {
            $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });
    });
};
PHP

cat <<'PHP' > app/Routes/WebRoute.php
<?php

declare(strict_types=1);

use App\Infrastructure\Controllers\SpaController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return static function (\Slim\App $app): void {
    // Ruta raíz
    $app->get('/', function (Request $request, Response $response): Response {
        $response->getBody()->write('<h1>Welcome to Slim PHP 8.5</h1>');
        return $response;
    });

    // -------------------------------------------------------------------------
    // SPAs Angular / React / Vue
    //
    // Flujo:
    //   1. El asset existe en disco (main.abc.js) → Apache lo sirve directo (.htaccess !-f).
    //   2. La ruta no existe como archivo (/app/dashboard) → llega a Slim → devuelve index.html.
    //   3. El frontend toma el control y su router renderiza la vista correcta.
    //
    // Pasos para activar:
    //   ng build --output-path=spa/app --base-href=/app/    (Angular)
    //   VITE_BASE=/app/ npm run build && cp -r dist/* spa/app/  (Vite / React)
    //
    // Descomentar la(s) línea(s) correspondiente(s):
    // -------------------------------------------------------------------------

    // $app->get('/app[/{path:.*}]', new SpaController(__DIR__ . '/../../spa/app'));
    // $app->get('/admin[/{path:.*}]', new SpaController(__DIR__ . '/../../spa/admin'));
};
PHP

cat <<'PHP' > app/Routes/ResourceRoute.php
<?php

declare(strict_types=1);

return static function (\Slim\App $app): void {
    // Register media / static resource routes here.
};
PHP

# ---------------------------------------------------------------------------
# Example Feature
# ---------------------------------------------------------------------------
cat <<'PHP' > src/Features/Example/ExampleModel.php
<?php

declare(strict_types=1);

namespace App\Features\Example;

class ExampleModel
{
    public int    $id;
    public string $name;
}
PHP

cat <<'PHP' > src/Features/Example/ExampleRepository.php
<?php

declare(strict_types=1);

namespace App\Features\Example;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class ExampleRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<ExampleModel> */
    public function findAll(): array
    {
        return $this->fetchAll('SELECT id, name FROM examples', ExampleModel::class);
    }

    public function findById(int $id): ExampleModel|false
    {
        return $this->fetchOne('SELECT id, name FROM examples WHERE id = ?', ExampleModel::class, [$id]);
    }
}
PHP

# ---------------------------------------------------------------------------
# Config files
# ---------------------------------------------------------------------------
cat <<'ENV' > .env.example
APP_ENV=development

DB_HOST=localhost
DB_NAME=database
DB_USER=root
DB_PASS=

# Comma-separated list of allowed CORS origins
CORS_ALLOWED_ORIGINS=http://localhost,http://localhost:8000

# JWT
JWT_SECRET=change-this-to-a-random-secret-of-at-least-32-chars
JWT_TTL_SECONDS=3600

# SMTP (phpmailer)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=you@example.com
MAIL_PASSWORD=your-app-password
MAIL_FROM=no-reply@yourdomain.com
MAIL_FROM_NAME="Your App"
MAIL_ENCRYPTION=tls
ENV

cat <<'GIT' > .gitignore
/vendor/
/.env
/logs/
*.log
.DS_Store
GIT

echo ""
echo "✅ Scaffold complete!"
echo ""
echo "👉 Next steps:"
echo "   1. composer install"
echo "   2. cp .env.example .env  &&  edit .env with your credentials"
echo "   3. Happy coding!"
