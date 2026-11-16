<?php

declare(strict_types=1);

namespace App\Infrastructure\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Validación condicional con ETag (RFC 9110).
 *
 * Calcula un ETag fuerte a partir del cuerpo renderizado y lo compara con el
 * `If-None-Match` del cliente: si coinciden responde `304 Not Modified` sin
 * cuerpo, así el navegador reutiliza su copia y sólo se transfieren cabeceras.
 *
 * Complementa —no sustituye— a `Cache-Control`: mientras `max-age` esté vigente
 * el navegador ni pregunta; al expirar, revalida y normalmente recibe un 304.
 */
final class ETagMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        if (!$this->isCacheable($request, $response)) {
            return $response;
        }

        $etag = '"' . hash('xxh128', (string) $response->getBody()) . '"';
        $response = $response->withHeader('ETag', $etag);

        if (!$this->matches($request->getHeaderLine('If-None-Match'), $etag)) {
            return $response;
        }

        // 304 no lleva cuerpo ni cabeceras de representación.
        return $response
            ->withStatus(304)
            ->withBody((new StreamFactory())->createStream(''))
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length');
    }

    private function isCacheable(Request $request, Response $response): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && $response->getStatusCode() === 200
            && !str_contains($response->getHeaderLine('Cache-Control'), 'no-store');
    }

    /**
     * `If-None-Match` admite una lista de validadores y el prefijo débil `W/`,
     * que se ignora en la comparación (RFC 9110 §8.8.3.2).
     */
    private function matches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '') {
            return false;
        }

        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (ltrim(trim($candidate), 'W/') === $etag) {
                return true;
            }
        }

        return false;
    }
}
