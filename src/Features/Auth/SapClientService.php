<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;
use JsonException;

/**
 * Servicio para consultar datos de clientes en la API SAP (elitenutapp.com).
 *
 * Variables de entorno requeridas:
 *   SAP_API_URL  — URL base (ej. https://elitenutapp.com/infoclient)
 *   SAP_API_KEY  — Credencial Basic Auth (Base64)
 */
class SapClientService
{
    private readonly string $apiUrl;
    private readonly string $apiKey;
    private readonly CurlHttpClient $http;

    private function __construct(string $apiUrl, string $apiKey)
    {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->http   = new CurlHttpClient();
    }

    public static function fromEnv(): self
    {
        return new self(
            apiUrl: $_ENV['SAP_API_URL'],
            apiKey: $_ENV['SAP_API_KEY'],
        );
    }

    /**
     * Consulta los datos de un cliente por número de documento.
     *
     * @throws HttpRequestException Si la API falla o responde con error
     * @throws JsonException Si la respuesta no es JSON válido
     */
    public function fetchClient(string $documentId): SapClientResponse
    {
        $response = $this->http->request($this->apiUrl, [
            'method'  => 'GET',
            'headers' => [
                'Authorization' => 'Basic ' . $this->apiKey,
                'Accept'        => 'application/json',
            ],
            'query'   => ['id' => $documentId],
            'timeout' => 15,
        ]);

        /** @var array<string, mixed> $data */
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return SapClientResponse::fromApiResponse($data);
    }
}
