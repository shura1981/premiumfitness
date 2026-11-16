<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Shared\Exceptions\HttpRequestException;
use JsonException;

/**
 * Wrapper de cURL para centralizar consumo de endpoints HTTP.
 *
 * Ejemplo 1: GET simple
 * --------------------------------
 * $http = new CurlHttpClient();
 * $response = $http->request('https://api.example.com/products');
 *
 * $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
 *
 * Ejemplo 2: POST JSON con headers
 * --------------------------------
 * $http = new CurlHttpClient();
 * $response = $http->request(
 *     'https://api.example.com/orders',
 *     [
 *         'method' => 'POST',
 *         'headers' => [
 *             'Authorization' => 'Bearer <token>',
 *             'Accept' => 'application/json',
 *         ],
 *         'payload' => [
 *             'sku' => 12345,
 *             'qty' => 2,
 *         ],
 *         'timeout' => 30,
 *     ]
 * );
 *
 * Ejemplo 3: Manejo de error HTTP con status y payload
 * --------------------------------
 * try {
 *     $response = $http->request('https://api.example.com/confirm', ['method' => 'POST']);
 * } catch (HttpRequestException $e) {
 *     $error = $e->toError();
 *     // ['error' => string, 'statusCode' => ?int, 'payload' => ?string]
 * }
 *
 * @phpstan-type RequestOptions array{
 *   method?: string,
 *   headers?: array<string, string>|list<string>,
 *   payload?: mixed,
 *   body?: mixed,
 *   query?: array<string, scalar|null>,
 *   connectTimeout?: int,
 *   timeout?: int,
 *   maxRedirects?: int,
 *   followLocation?: bool
 * }
 */
class CurlHttpClient
{
    /**
     * @param RequestOptions $options
     * @throws HttpRequestException
     */
    public function request(string $url, array $options = []): string
    {
        ['response' => $response, 'statusCode' => $statusCode] = $this->execute($url, $options);

        if ($statusCode < 200 || $statusCode > 299) {
            throw new HttpRequestException(
                message: 'Error al consumir endpoint HTTP.',
                statusCode: $statusCode,
                errorPayload: $response
            );
        }

        return $response;
    }

    /**
     * Ejecuta la petición sin seguir redirects y devuelve el header `Location`.
     *
     * Pensado para integraciones (p. ej. Addi) que entregan la URL final como
     * respuesta HTTP 302 — `request()` trataría el 3xx como error y se perdería
     * el `Location`.
     *
     * @param  RequestOptions $options
     * @return array{redirectUrl: ?string, statusCode: int, body: string}
     * @throws HttpRequestException
     */
    public function requestForRedirect(string $url, array $options = []): array
    {
        $options['followLocation'] = false;

        ['response' => $response, 'statusCode' => $statusCode, 'redirectUrl' => $redirectUrl] =
            $this->execute($url, $options);

        $isOk       = $statusCode >= 200 && $statusCode <= 299;
        $isRedirect = $statusCode >= 300 && $statusCode <= 399;

        if (!$isOk && !$isRedirect) {
            throw new HttpRequestException(
                message: 'Error al consumir endpoint HTTP (redirect).',
                statusCode: $statusCode,
                errorPayload: $response
            );
        }

        return [
            'redirectUrl' => $redirectUrl,
            'statusCode'  => $statusCode,
            'body'        => $response,
        ];
    }

    /**
     * @param  RequestOptions $options
     * @return array{response: string, statusCode: int, redirectUrl: ?string}
     * @throws HttpRequestException
     */
    private function execute(string $url, array $options): array
    {
        $normalizedUrl = $this->normalizeUrl($url, $options['query'] ?? []);
        $method        = strtoupper((string) ($options['method'] ?? 'GET'));

        $curl = curl_init();

        if ($curl === false) {
            throw new HttpRequestException('No fue posible inicializar cURL.');
        }

        $headers = $this->normalizeHeaders($options['headers'] ?? []);
        $body    = $this->normalizeBody($options, $headers);

        $curlOptions = [
            CURLOPT_URL            => $normalizedUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => (int) ($options['maxRedirects'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => (int) ($options['connectTimeout'] ?? 10),
            CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? 30),
            CURLOPT_FOLLOWLOCATION => (bool) ($options['followLocation'] ?? true),
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($headers !== []) {
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);

        if ($response === false) {
            $error   = curl_error($curl);
            $errorNo = curl_errno($curl);

            throw new HttpRequestException(
                message: "Error de cURL: {$error} (Código: {$errorNo})"
            );
        }

        $statusCode  = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $redirectUrl = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);

        return [
            'response'    => (string) $response,
            'statusCode'  => $statusCode,
            'redirectUrl' => $redirectUrl !== '' ? $redirectUrl : null,
        ];
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function normalizeUrl(string $url, array $query = []): string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new HttpRequestException('La URL no puede estar vacía.');
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new HttpRequestException('La URL proporcionada no es válida.');
        }

        if ($query === []) {
            return $trimmed;
        }

        $queryString = http_build_query($query);
        $separator   = str_contains($trimmed, '?') ? '&' : '?';

        return $trimmed . $separator . $queryString;
    }

    /**
     * @param array<string, string>|list<string> $headers
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $normalized[] = (string) $value;
                continue;
            }

            $normalized[] = $key . ': ' . $value;
        }

        return $normalized;
    }

    /**
     * @param RequestOptions $options
     * @param list<string> $headers
     */
    private function normalizeBody(array $options, array &$headers): ?string
    {
        $body = $options['body'] ?? $options['payload'] ?? null;

        if ($body === null) {
            return null;
        }

        if (is_string($body)) {
            return $body;
        }

        try {
            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpRequestException(
                message: 'No fue posible serializar el payload a JSON.',
                previous: $e
            );
        }

        if (!$this->hasContentTypeHeader($headers)) {
            $headers[] = 'Content-Type: application/json';
        }

        return $jsonBody;
    }

    /** @param list<string> $headers */
    private function hasContentTypeHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'content-type:')) {
                return true;
            }
        }

        return false;
    }
}
