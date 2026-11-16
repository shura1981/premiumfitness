<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Sistecredito;

use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;
use RuntimeException;

/**
 * Crea una orden de pago en Sistecrédito (Credinet) y devuelve la URL a la
 * que el SPA debe redirigir al cliente.
 *
 * Flujo:
 *   1. POST /pay/create — recibe `_id` de la transacción.
 *   2. GET  /pay/GetTransactionResponse?transactionId={_id} — devuelve la
 *      `paymentRedirectUrl`. Si llega vacía (caso documentado por Credinet),
 *      se reintenta una vez tras `sleep(5)`.
 *
 * Headers obligatorios (los 3): `Ocp-Apim-Subscription-Key`, `ApplicationKey`,
 * `ApplicationToken`. Falta de alguno produce 401 silencioso.
 */
final readonly class SistecreditoCheckoutService
{
    private function __construct(
        private CurlHttpClient $http,
        private SistecreditoQueryService $query,
        private string $createUrl,
        private string $subscriptionKey,
        private string $applicationKey,
        private string $applicationToken,
        private string $urlResponse,
        private string $urlConfirmation,
        private bool $sandboxActive,
        private int $timeout,
    ) {}

    public static function fromEnv(?SistecreditoQueryService $query = null): self
    {
        return new self(
            http:             new CurlHttpClient(),
            query:            $query ?? SistecreditoQueryService::fromEnv(),
            createUrl:        $_ENV['SISTECREDITO_URL_CREATE_ORDER'],
            subscriptionKey:  $_ENV['SISTECREDITO_SUBSCRIPTION_KEY'],
            applicationKey:   $_ENV['SISTECREDITO_APPLICATION_KEY'],
            applicationToken: $_ENV['SISTECREDITO_APPLICATION_TOKEN'],
            urlResponse:      $_ENV['SISTECREDITO_URL_RESPONSE'],
            urlConfirmation:  $_ENV['SISTECREDITO_URL_CONFIRMATION'],
            sandboxActive:    filter_var($_ENV['SISTECREDITO_SANDBOX_ACTIVE'], FILTER_VALIDATE_BOOLEAN),
            timeout:          (int) $_ENV['PAYMENT_GATEWAY_TIMEOUT_SECONDS'],
        );
    }

    /**
     * @param  array<string, mixed> $body Datos del pedido (invoice, value, customer…).
     * @return array{idTransaccion: string, paymentRedirectUrl: string}
     * @throws HttpRequestException
     * @throws RuntimeException Si tras dos intentos la URL llega vacía.
     */
    public function createOrder(array $body): array
    {
        $payload = $this->buildPayload($body);

        $response = $this->http->request($this->createUrl, [
            'method'  => 'POST',
            'headers' => $this->headers(),
            'payload' => $payload,
            'timeout' => $this->timeout,
        ]);

        /** @var array{_id?: string} $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $transactionId = (string) ($decoded['_id'] ?? '');
        if ($transactionId === '') {
            throw new RuntimeException('Sistecredito did not return _id from /pay/create.');
        }

        $redirectUrl = $this->query->fetchPaymentUrl($transactionId);
        if ($redirectUrl === '') {
            sleep(5);
            $redirectUrl = $this->query->fetchPaymentUrl($transactionId);
        }

        if ($redirectUrl === '') {
            throw new RuntimeException("Sistecredito returned empty paymentRedirectUrl after retry (transactionId={$transactionId}).");
        }

        return [
            'idTransaccion'      => $transactionId,
            'paymentRedirectUrl' => $redirectUrl,
        ];
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function buildPayload(array $body): array
    {
        $payload = $body;
        $payload['paymentMethodId']      = 2;
        $payload['urlResponse']          = $this->urlResponse;
        $payload['urlConfirmation']      = $this->urlConfirmation;
        $payload['sandbox']              = ['isActive' => $this->sandboxActive, 'status' => 'Approved'];

        return $payload;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type'              => 'application/json',
            'Accept'                    => 'application/json',
            'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
            'ApplicationKey'            => $this->applicationKey,
            'ApplicationToken'          => $this->applicationToken,
        ];
    }
}
