<?php

declare(strict_types=1);

namespace App\Features\Carrier;

use App\Features\Carrier\Exceptions\CarrierTrackingException;
use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;
use JsonException;

class ServientregaTrackingService
{
    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $authToken,
        private readonly CurlHttpClient $http,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            apiUrl:    (string) ($_ENV['SERVIENTREGA_API_URL'] ?? ''),
            authToken: (string) ($_ENV['SERVIENTREGA_AUTH_TOKEN'] ?? ''),
            http:      new CurlHttpClient(),
        );
    }

    /**
     * @return array<string, mixed>
     * @throws CarrierTrackingException
     */
    public function getTrackingDetail(string $trackingNumber): array
    {
        try {
            $response = $this->http->request($this->apiUrl(), [
                'method'  => 'GET',
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Basic ' . $this->authToken(),
                ],
                'query'   => ['numGuia' => $this->trackingNumber($trackingNumber)],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            /** @var mixed $data */
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                throw new CarrierTrackingException('Servientrega respondió con un formato inválido.', 502);
            }

            return $data;
        } catch (CarrierTrackingException $e) {
            throw $e;
        } catch (HttpRequestException $e) {
            throw new CarrierTrackingException($this->errorMessage($e), $this->statusCode($e), $e);
        } catch (JsonException $e) {
            throw new CarrierTrackingException('Servientrega respondió con JSON inválido.', 502, $e);
        }
    }

    private function apiUrl(): string
    {
        $apiUrl = trim($this->apiUrl);
        if ($apiUrl === '') {
            throw new CarrierTrackingException('SERVIENTREGA_API_URL no está configurada.', 500);
        }

        return $apiUrl;
    }

    private function authToken(): string
    {
        $authToken = trim($this->authToken);
        if ($authToken === '') {
            throw new CarrierTrackingException('SERVIENTREGA_AUTH_TOKEN no está configurado.', 500);
        }

        return $authToken;
    }

    private function trackingNumber(string $trackingNumber): string
    {
        $normalizedTrackingNumber = trim($trackingNumber);
        if ($normalizedTrackingNumber === '') {
            throw new CarrierTrackingException('La guía es obligatoria.', 422);
        }

        return $normalizedTrackingNumber;
    }

    private function errorMessage(HttpRequestException $exception): string
    {
        return match ($exception->statusCode()) {
            401 => 'Error de autorización al consultar Servientrega.',
            404 => 'Guía no encontrada en Servientrega.',
            default => 'No fue posible consultar la guía en Servientrega.',
        };
    }

    private function statusCode(HttpRequestException $exception): int
    {
        $statusCode = $exception->statusCode();

        return $statusCode === 404 ? 404 : 502;
    }
}
