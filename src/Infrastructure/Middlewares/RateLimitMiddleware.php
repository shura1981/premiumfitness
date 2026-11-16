<?php

declare(strict_types=1);

namespace App\Infrastructure\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Middleware PSR-15 de rate limiting por IP o, con `bodyField`, por un campo del cuerpo (el celular).
 *
 * Usa ventana fija (fixed_window) con almacenamiento en archivos.
 * No requiere Redis ni base de datos.
 *
 * Uso — proteger un endpoint sensible (ej. login):
 *
 *   $rateLimiter = new RateLimitMiddleware(
 *       responseFactory: $app->getResponseFactory(),
 *       id:              'login',
 *       limit:           5,
 *       interval:        '1 minute',
 *   );
 *
 *   $app->post('/api/login', LoginController::class)
 *       ->add(new ValidationMiddleware(new LoginValidator(), $app->getResponseFactory()))
 *       ->add($rateLimiter);
 *
 * Si se supera el límite responde 429 con el header Retry-After:
 *   { "error": "Too Many Requests", "retryAfter": 42 }
 */
class RateLimitMiddleware
{
    private readonly RateLimiterFactory $factory;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        string  $id       = 'default',
        int     $limit    = 10,
        string  $interval = '1 minute',
        ?string $storageDir = null,
        private readonly ?string $bodyField = null,
    ) {
        $this->factory = new RateLimiterFactory(
            [
                'id'       => $id,
                'policy'   => 'fixed_window',
                'limit'    => $limit,
                'interval' => $interval,
            ],
            new CacheStorage(
                new FilesystemAdapter(
                    directory: $storageDir ?? __DIR__ . '/../../../logs/rate-limiter',
                )
            )
        );
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $key = $this->key($request);

        // Sin el campo no hay a quién limitar: la validación rechaza la petición.
        if ($key === null) {
            return $handler->handle($request);
        }

        $limiter = $this->factory->create($key);
        $limit   = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

            $response = $this->responseFactory->createResponse(429);
            $response->getBody()->write(
                json_encode(
                    ['error' => 'Too Many Requests', 'retryAfter' => $retryAfter],
                    JSON_THROW_ON_ERROR
                )
            );

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        return $handler->handle($request);
    }

    /** IP del cliente o, con `bodyField`, el valor de ese campo (por ejemplo, el celular). */
    private function key(Request $request): ?string
    {
        if ($this->bodyField === null) {
            return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        }

        $body  = (array) ($request->getParsedBody() ?? []);
        $value = $body[$this->bodyField] ?? null;

        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return hash('sha256', mb_strtolower(trim((string) $value)));
    }
}
