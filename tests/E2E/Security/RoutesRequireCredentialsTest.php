<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

/*
 | Ninguna ruta de la API responde sin su credencial (spec backend/03, criterio 2).
 | Recorre todas las rutas registradas en la app: una ruta nueva que olvide su middleware falla aquí.
 */

/** Rutas que no piden sesión, a propósito. Cualquier otra debe responder 401 o 403 sin credencial. */
const PUBLIC_ROUTES = [
    'GET /api/health',
    'POST /api/auth/register',
    'POST /api/auth/verify-phone',
    'POST /api/auth/resend-code',
    'POST /api/auth/login',
    'POST /api/auth/refresh',
    'POST /api/auth/logout',
    'POST /api/auth/forgot-password',
    'POST /api/auth/verify-reset-code',
    'POST /api/auth/reset-password',
    'GET /api/document-types',
    'GET /api/cities/search',
    'GET /api/referrals/trainers/{id:[A-Za-z0-9_=-]{1,32}}',
    // Los llama la pasarela, no la app: no llevan sesión. Bold se valida por su firma; en los tres,
    // lo pagado debe cubrir el pedido y el call center confirma el pago en la plataforma.
    'POST /api/payment-gateway/webhook/bold',
    'POST /api/payment-gateway/webhook/addi',
    'POST /api/payment-gateway/webhook/sistecredito',
    'POST /api/admin/auth/login',
    'POST /api/admin/auth/refresh',
    'POST /api/admin/auth/logout',
];

/** @return list<array{0:string, 1:string, 2:string}> método, patrón y ruta con los parámetros rellenos */
function apiRoutes(): array
{
    $routes = [];

    foreach (AppFactory::make()->getRouteCollector()->getRoutes() as $route) {
        $pattern = $route->getPattern();

        if (!str_starts_with($pattern, '/api')) {
            continue;
        }

        foreach ($route->getMethods() as $method) {
            // Rellena cada parámetro con «1», también los que llevan llaves en su patrón ({code:[0-9]{1,12}}).
            $routes[] = [$method, $pattern, (string) preg_replace('/\{\w+(?::(?:[^{}]|\{[^{}]*\})+)?\}/', '1', $pattern)];
        }
    }

    return $routes;
}

it('tiene rutas registradas que revisar', function (): void {
    expect(count(apiRoutes()))->toBeGreaterThan(40);
});

it('toda ruta de la API exige credencial, salvo las públicas del contrato', function (): void {
    $open = [];

    foreach (apiRoutes() as [$method, $pattern, $path]) {
        if (in_array("{$method} {$pattern}", PUBLIC_ROUTES, true)) {
            continue;
        }

        $status = AppFactory::dispatch(AppFactory::request($method, $path, $method === 'GET' ? null : []))->getStatusCode();

        if (!in_array($status, [401, 403], true)) {
            $open[] = "{$method} {$pattern} → {$status}";
        }
    }

    expect($open)->toBe([]);
});

it('las rutas públicas declaradas existen', function (): void {
    $registered = array_map(static fn (array $route): string => "{$route[0]} {$route[1]}", apiRoutes());

    expect(array_values(array_diff(PUBLIC_ROUTES, $registered)))->toBe([]);
});

it('el admin no acepta la sesión de un usuario de la app web', function (): void {
    $headers = JwtFactory::authHeader(Seed::trainer());
    $allowed = [];

    foreach (apiRoutes() as [$method, $pattern, $path]) {
        if (!str_starts_with($pattern, '/api/admin/') || str_starts_with($pattern, '/api/admin/auth/')) {
            continue;
        }

        $status = AppFactory::dispatch(AppFactory::request($method, $path, $method === 'GET' ? null : [], $headers))->getStatusCode();

        if ($status !== 403) {
            $allowed[] = "{$method} {$pattern} → {$status}";
        }
    }

    expect($allowed)->toBe([]);
});

it('la app web no acepta la sesión de un agente del admin', function (): void {
    $headers = JwtFactory::adminHeader();
    $allowed = [];

    foreach (apiRoutes() as [$method, $pattern, $path]) {
        if (str_starts_with($pattern, '/api/admin/') || in_array("{$method} {$pattern}", PUBLIC_ROUTES, true)) {
            continue;
        }

        $status = AppFactory::dispatch(AppFactory::request($method, $path, $method === 'GET' ? null : [], $headers))->getStatusCode();

        if (!in_array($status, [401, 403], true)) {
            $allowed[] = "{$method} {$pattern} → {$status}";
        }
    }

    expect($allowed)->toBe([]);
});

it('las rutas de radical sin sesión ya no existen', function (string $method, string $path): void {
    $response = AppFactory::dispatch(AppFactory::request($method, $path, $method === 'GET' ? null : [], JwtFactory::authHeader(Seed::user())));

    expect($response->getStatusCode())->toBeIn([404, 405]);
})->with([
    ['GET', '/api/users'],
    ['POST', '/api/users'],
    ['DELETE', '/api/users/1'],
    ['GET', '/api/users/1/orders'],
    ['GET', '/api/addresses'],
    ['POST', '/api/products'],
    ['DELETE', '/api/products/1'],
    ['POST', '/api/categories'],
    ['POST', '/api/webhooks/orders'],
    ['PATCH', '/api/orders/1/status'],
    ['POST', '/api/credit-balances'],
    ['POST', '/api/retail/account/login'],
    ['POST', '/api/payment-gateway/consume/bold'],
    ['POST', '/api/checkout/finalize'],
    ['POST', '/api/auth/verify-code'],
    ['GET', '/checkout'],
    ['GET', '/reset-password'],
]);
