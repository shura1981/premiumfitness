<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Infrastructure\Database\DatabaseConfig;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Handlers\ApiExceptionHandler;
use App\Infrastructure\Handlers\NotFoundHandler;
use App\Infrastructure\Middlewares\CorsMiddleware;
use App\Infrastructure\Http\TypedArgsStrategy;
use App\Infrastructure\View\TwigFactory;
use App\Shared\Exceptions\ApiException;
use Slim\App as SlimApp;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Views\TwigMiddleware;

class App
{
    public static function create(): SlimApp
    {
        DB::boot(DatabaseConfig::fromEnv());

        $slim = AppFactory::create();

        $slim->addBodyParsingMiddleware();
        $slim->addRoutingMiddleware();
        $slim->getRouteCollector()->setDefaultInvocationStrategy(new TypedArgsStrategy());

        // Twig disponible en los controllers vía Twig::fromRequest($request).
        $slim->add(TwigMiddleware::create($slim, TwigFactory::fromEnv()));

        $displayErrorDetails = $_ENV['APP_ENV'] !== 'production';
        $errorMiddleware     = $slim->addErrorMiddleware($displayErrorDetails, true, true);

        // CORS — middleware más externo (LIFO): intercepta OPTIONS antes del router.
        // Dominios leídos desde CORS_ALLOWED_ORIGINS en .env (validado en bootstrap).
        $slim->add(new CorsMiddleware(
            array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS']))
        ));

        $errorMiddleware->setErrorHandler(ApiException::class, new ApiExceptionHandler($slim->getResponseFactory()));
        $errorMiddleware->setErrorHandler(
            HttpNotFoundException::class,
            new NotFoundHandler($slim->getResponseFactory(), $displayErrorDetails)
        );

        (require __DIR__ . '/../../app/Routes/WebRoute.php')($slim);
        (require __DIR__ . '/../../app/Routes/ApiRoute.php')($slim);
        (require __DIR__ . '/../../app/Routes/AdminRoute.php')($slim);
        (require __DIR__ . '/../../app/Routes/ResourceRoute.php')($slim);

        return $slim;
    }
}
