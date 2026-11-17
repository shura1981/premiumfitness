<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Sistecredito;

use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;

/**
 * Consulta el estado de una transacción de Sistecrédito.
 *
 * Usado por `SistecreditoCheckoutService` para obtener `paymentRedirectUrl`
 * tras crear la orden, y disponible para flujos de polling externos.
 */
final readonly class SistecreditoQueryService
{
    private function __construct(
        private CurlHttpClient $http,
        private string $queryUrl,
        private string $subscriptionKey,
        private string $applicationKey,
        private string $applicationToken,
        private int $timeout,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            http:             new CurlHttpClient(),
            queryUrl:         $_ENV['SISTECREDITO_URL_QUERY_TRANSACTION'],
            subscriptionKey:  $_ENV['SISTECREDITO_SUBSCRIPTION_KEY'],
            applicationKey:   $_ENV['SISTECREDITO_APPLICATION_KEY'],
            applicationToken: $_ENV['SISTECREDITO_APPLICATION_TOKEN'],
            timeout:          (int) $_ENV['PAYMENT_GATEWAY_TIMEOUT_SECONDS'],
        );
    }

    /**
     * @return array<string, mixed>
     * @throws HttpRequestException
     */
    public function fetchTransaction(string $transactionId): array
    {
        $response = $this->http->request($this->queryUrl, [
            'method'  => 'GET',
            'headers' => self::headers($this->subscriptionKey, $this->applicationKey, $this->applicationToken),
            'query'   => ['transactionId' => $transactionId],
            'timeout' => $this->timeout,
        ]);

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        // Credinet envuelve la transacción en `data`.
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return $data;
    }

    /**
     * Devuelve `paymentRedirectUrl` o cadena vacía si Credinet aún no la ha
     * generado (caller decide reintentar).
     */
    public function fetchPaymentUrl(string $transactionId): string
    {
        $data   = $this->fetchTransaction($transactionId);
        $method = is_array($data['paymentMethodResponse'] ?? null) ? $data['paymentMethodResponse'] : [];

        return (string) ($method['paymentRedirectUrl'] ?? '');
    }

    /**
     * Headers de Credinet, los mismos de la integración que ya funciona en producción
     * (api.megaplexstars). Sin las tres llaves responde 401 sin detalle.
     *
     * @return array<string, string>
     */
    public static function headers(string $subscriptionKey, string $applicationKey, string $applicationToken): array
    {
        return [
            'Content-Type'              => 'application/json',
            'Accept'                    => 'application/json',
            'SCOrigen'                  => 'Production',
            'country'                   => 'co',
            'SCLocation'                => '0,0',
            'Ocp-Apim-Subscription-Key' => $subscriptionKey,
            'ApplicationKey'            => $applicationKey,
            'ApplicationToken'          => $applicationToken,
        ];
    }
}
