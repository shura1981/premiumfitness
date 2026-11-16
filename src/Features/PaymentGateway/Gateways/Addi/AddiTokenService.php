<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Addi;

use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;
use RuntimeException;

/**
 * Obtiene el access_token OAuth 2.0 (client_credentials) de Addi.
 *
 * No cachea: el TTL del token es bajo y la latencia añade ~150 ms;
 * la simplicidad pesa más que el ahorro mientras no haya volumen alto.
 */
final readonly class AddiTokenService
{
    private const int TRANSIENT_RETRIES = 1;
    private const int RETRY_DELAY_MICROSECONDS = 250000;

    private function __construct(
        private CurlHttpClient $http,
        private string $url,
        private string $clientId,
        private string $clientSecret,
        private string $audience,
        private string $grantType,
        private int $timeout,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            http:         new CurlHttpClient(),
            url:          $_ENV['ADDI_URL_TOKEN'],
            clientId:     $_ENV['ADDI_CLIENT_ID'],
            clientSecret: $_ENV['ADDI_CLIENT_SECRET'],
            audience:     $_ENV['ADDI_AUDIENCE'],
            grantType:    $_ENV['ADDI_GRANT_TYPE'],
            timeout:      (int) $_ENV['PAYMENT_GATEWAY_TIMEOUT_SECONDS'],
        );
    }

    /**
     * @throws HttpRequestException
     * @throws RuntimeException
     */
    public function getToken(): string
    {
        $response = $this->requestToken();

        /** @var array{access_token?: string} $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Addi token endpoint did not return access_token.');
        }

        return $token;
    }

    /**
     * @throws HttpRequestException
     */
    private function requestToken(): string
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->http->request($this->url, [
                    'method'  => 'POST',
                    'headers' => ['Content-Type' => 'application/json'],
                    'payload' => [
                        'client_id'     => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'audience'      => $this->audience,
                        'grant_type'    => $this->grantType,
                    ],
                    'connectTimeout' => $this->timeout,
                    'timeout'        => $this->timeout,
                ]);
            } catch (HttpRequestException $e) {
                if ($e->statusCode() !== null || $attempt >= self::TRANSIENT_RETRIES) {
                    throw $e;
                }

                $attempt++;
                usleep(self::RETRY_DELAY_MICROSECONDS);
            }
        }
    }
}
