<?php

declare(strict_types=1);

namespace App\Infrastructure\View;

use Slim\Views\Twig;
use Twig\TwigFilter;

/**
 * Único punto de construcción del motor de plantillas.
 *
 * La caché de Twig (plantillas compiladas a PHP) se activa sólo en producción:
 * en desarrollo se recompila en cada request para no trabajar con vistas
 * obsoletas. `auto_reload` queda activo fuera de producción.
 */
final readonly class TwigFactory
{
    private const string VIEWS_PATH = __DIR__ . '/../../../views';
    private const string CACHE_PATH = __DIR__ . '/../../../cache/twig';

    public static function fromEnv(): Twig
    {
        return self::create(($_ENV['APP_ENV'] ?? 'production') === 'production');
    }

    public static function create(bool $isProduction): Twig
    {
        $twig = Twig::create(self::VIEWS_PATH, [
            'cache'       => $isProduction ? self::CACHE_PATH : false,
            'auto_reload' => !$isProduction,
            'charset'     => 'UTF-8',
        ]);

        // {{ 24500|money }} → $24.500 — evita formatear precios en los controllers.
        $twig->getEnvironment()->addFilter(
            new TwigFilter('money', static fn (int|float|null $value): string => '$' . number_format((float) ($value ?? 0), 0, ',', '.'))
        );

        return $twig;
    }
}
