<?php

declare(strict_types=1);

namespace App\Features\Admin;

use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Datos de la sesión del admin que deja `AdminMiddleware` en la petición.
 */
final class AdminRequest
{
    /** Id del agente (`tb_empleados.id_usuario`): sale del token, nunca del cuerpo. */
    public static function agentId(Request $request): int
    {
        $claims = $request->getAttribute('admin_claims');
        $id     = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;

        return $id > 0 ? $id : throw ApiException::unauthorized();
    }

    public static function agentName(Request $request): string
    {
        $claims = $request->getAttribute('admin_claims');

        return is_object($claims) ? (string) ($claims->usuario ?? '') : '';
    }

    /** @return array{0:int, 1:int, 2:int} página, tamaño y desplazamiento */
    public static function pagination(Request $request, int $default = 20, int $max = 100): array
    {
        $query   = $request->getQueryParams();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min($max, (int) ($query['per_page'] ?? $default)));

        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
