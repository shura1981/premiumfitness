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
 * El cuerpo y los headers siguen la integración que ya funciona en producción (api.megaplexstars):
 * Credinet responde la transacción dentro de `data`.
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
     * @param  array{invoice: string, description: string, value: int, docType: string, document: string, urlResponse?: string} $body
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

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $data    = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        $transactionId = (string) ($data['_id'] ?? '');
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
     * @param  array{invoice: string, description: string, value: int, docType: string, document: string, urlResponse?: string} $body
     * @return array<string, mixed>
     */
    private function buildPayload(array $body): array
    {
        return [
            'invoice'            => $body['invoice'],
            'description'        => $body['description'],
            'paymentMethod'      => ['paymentMethodId' => 2], // 2 = Sistecrédito en Credinet
            'currency'           => 'COP',
            'value'              => $body['value'],
            'sandbox'            => ['isActive' => $this->sandboxActive, 'status' => 'Approved'],
            'urlResponse'        => $body['urlResponse'] ?? $this->urlResponse,
            'urlConfirmation'    => $this->urlConfirmation,
            'methodConfirmation' => 'POST',
            'client'             => ['docType' => $body['docType'], 'document' => $body['document']],
            'extraData'          => ['Authentication' => ''],
        ];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return SistecreditoQueryService::headers($this->subscriptionKey, $this->applicationKey, $this->applicationToken);
    }
}
