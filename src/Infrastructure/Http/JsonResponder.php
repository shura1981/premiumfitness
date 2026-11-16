<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Escribe respuestas JSON de la API.
 */
trait JsonResponder
{
    /** @param array<array-key, mixed>|\JsonSerializable $data */
    private function json(Response $response, array|\JsonSerializable $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function noContent(Response $response): Response
    {
        return $response->withStatus(204);
    }
}
