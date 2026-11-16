<?php

declare(strict_types=1);

namespace App\Features\DietAccess;

use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/integrations/diet-access — lo consulta el backend de la app de dietas, servidor a
 * servidor, con `X-Api-Key` (spec integraciones/02). El documento va en el cuerpo, no en la URL,
 * para que no quede en los registros del servidor.
 */
class DietAccessIntegrationController
{
    use JsonResponder;

    public function check(Request $request, Response $response): Response
    {
        $expected = (string) $_ENV['DIET_ACCESS_API_KEY'];
        $provided = $request->getHeaderLine('X-Api-Key');

        // Comparación en tiempo constante; la credencial no vale para ningún otro endpoint.
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw ApiException::unauthorized('Credencial no válida.');
        }

        $body     = (array) ($request->getParsedBody() ?? []);
        $document = $body['document'] ?? null;

        if (!is_scalar($document) || trim((string) $document) === '' || mb_strlen((string) $document) > 30) {
            throw ApiException::invalid('Falta el documento.', errors: ['document' => 'Envía el número de documento.']);
        }

        return $this->json($response, new DietAccessService()->checkByDocument((string) $document))
            ->withHeader('Cache-Control', 'no-store');
    }
}
