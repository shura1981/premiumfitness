<?php

declare(strict_types=1);

namespace App\Infrastructure\Middlewares;

use App\Infrastructure\Auth\JwtService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use RuntimeException;
use stdClass;

/**
 * Middleware PSR-15 para rutas de administración.
 *
 * Decodifica el JWT igual que JwtMiddleware, pero además exige que el token
 * contenga claims de empleado (cargo, departamento, etc.) emitidos por
 * AdminAuthController. Si el token no es de tipo admin, responde 403.
 *
 * Inyecta los claims en el request como atributo 'admin_claims': stdClass.
 */
class AdminMiddleware
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

        // Un token de la app web no sirve en el admin (spec seguridad/01).
        if (($claims->app ?? null) !== 'admin' || !isset($claims->id_empleado)) {
            return $this->forbidden('Admin access required.');
        }

        $request = $request->withAttribute('admin_claims', $claims);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = $this->responseFactory->createResponse(401);
        $response->getBody()->write(
            json_encode(['error' => 'Unauthorized', 'message' => $message], JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function forbidden(string $message): Response
    {
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write(
            json_encode(['error' => 'Forbidden', 'message' => $message], JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json');
    }
}
