<?php

declare(strict_types=1);

namespace App\Infrastructure\Middlewares;

use App\Infrastructure\Auth\JwtService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use RuntimeException;

/**
 * Middleware PSR-15 de autenticación JWT.
 *
 * Lee el token del header: Authorization: Bearer <token>
 * Si es válido y es de la app web (`app = web`), inyecta los claims en el request como atributo 'jwt_claims'.
 * Si es inválido, falta o es de otra app, responde 401.
 *
 * Uso (proteger un grupo de rutas):
 *
 *   $jwtMiddleware = new JwtMiddleware(JwtService::fromEnv(), $app->getResponseFactory());
 *   $app->group('/api/private', function (RouteCollectorProxy $group) { ... })
 *       ->add($jwtMiddleware);
 *
 * Leer claims en el controller:
 *
 *   $claims = $request->getAttribute('jwt_claims'); // stdClass
 *   $userId = $claims->sub;
 */
class JwtMiddleware
{
    public function __construct(
        private readonly JwtService               $jwtService,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Missing or malformed Authorization header.');
        }

        try {
            $claims = $this->jwtService->decode(substr($header, 7));
        } catch (RuntimeException $e) {
            return $this->unauthorized($e->getMessage());
        }

        if (($claims->app ?? null) !== 'web' || !isset($claims->sub)) {
            return $this->unauthorized('Token is not valid for this app.');
        }

        $request = $request->withAttribute('jwt_claims', $claims);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = $this->responseFactory->createResponse(401);
        $response->getBody()->write(
            // `message` es lo que ve la persona; el detalle técnico va aparte.
            json_encode(
                ['message' => 'Tu sesión terminó. Vuelve a entrar.', 'code' => 'unauthorized', 'detail' => $message],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );

        return $response->withHeader('Content-Type', 'application/json');
    }
}
