<?php

declare(strict_types=1);

namespace App\Infrastructure\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Exige un rol en el token de la app web. Va después de `JwtMiddleware`:
 *
 *   $group->get('/trainer/dashboard', ...)->add(new RoleMiddleware('trainer', $factory))->add($jwt);
 *
 * Los guards de Angular solo ocultan pantallas: el rol se exige aquí (spec arquitectura/02).
 */
class RoleMiddleware
{
    public function __construct(
        private readonly string $role,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(Request $request, Handler $handler): Response
    {
        $claims = $request->getAttribute('jwt_claims');

        if (!is_object($claims) || ($claims->role ?? null) !== $this->role) {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write(json_encode(
                ['message' => 'Esta sección no está disponible para tu cuenta.', 'code' => 'forbidden'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
