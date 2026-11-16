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
            'headers' => [
                'Accept'                    => 'application/json',
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
                'ApplicationKey'            => $this->applicationKey,
                'ApplicationToken'          => $this->applicationToken,
            ],
            'query'   => ['transactionId' => $transactionId],
            'timeout' => $this->timeout,
        ]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Devuelve `paymentRedirectUrl` o cadena vacía si Credinet aún no la ha
     * generado (caller decide reintentar).
     */
    public function fetchPaymentUrl(string $transactionId): string
    {
        $data = $this->fetchTransaction($transactionId);

        return (string) ($data['paymentRedirectUrl'] ?? '');
    }
}
