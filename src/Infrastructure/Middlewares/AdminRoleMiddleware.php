<?php

declare(strict_types=1);

namespace App\Infrastructure\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Exige en el admin uno de los cargos de `tb_empleados` que pueden gestionar entrenadores,
 * estrategias, el cierre mensual y los ajustes. Va después de `AdminMiddleware`:
 *
 *   $group->get('/trainers', ...)->add($managersOnly)->add($adminMiddleware);
 *
 * Los cargos son `ADMIN_MANAGER_ROLES`. Ocultar la pantalla en el admin no basta: se comprueba aquí.
 */
class AdminRoleMiddleware
{
    /** @param list<string> $roles Cargos permitidos, en mayúsculas. */
    public function __construct(
        private readonly array $roles,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public static function managersFromEnv(ResponseFactoryInterface $responseFactory): self
    {
        $roles = array_values(array_filter(array_map(
            static fn (string $role): string => mb_strtoupper(trim($role)),
            explode(',', (string) $_ENV['ADMIN_MANAGER_ROLES']),
        )));

        return new self($roles, $responseFactory);
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $claims = $request->getAttribute('admin_claims');
        $role   = is_object($claims) ? mb_strtoupper(trim((string) ($claims->cargo ?? ''))) : '';

        if (!in_array($role, $this->roles, true)) {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write(json_encode(
                ['message' => 'Tu cargo no tiene permiso para esta sección.', 'code' => 'forbidden'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
