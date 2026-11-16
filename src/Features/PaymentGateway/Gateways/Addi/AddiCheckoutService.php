<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Addi;

use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;
use RuntimeException;

/**
 * Inicia un checkout en Addi y devuelve la URL a la que el SPA debe redirigir
 * al usuario.
 *
 * Addi devuelve **HTTP 302** y la URL final está en el header `Location`.
 * Por eso usamos `CurlHttpClient::requestForRedirect()` (no `request()`).
 *
 * El body del request es pass-through del e-commerce. El callback siempre
 * conserva el webhook central del backend. La URL de retorno puede definirse
 * por checkout; si no se envía, se usa el retorno mayorista configurado.
 */
final readonly class AddiCheckoutService
{
    private const int TRANSIENT_RETRIES = 1;
    private const int RETRY_DELAY_MICROSECONDS = 250000;

    private function __construct(
        private CurlHttpClient $http,
        private AddiTokenService $tokens,
        private string $url,
        private string $callbackUrl,
        private string $redirectUrl,
        private int $timeout,
    ) {}

    public static function fromEnv(?AddiTokenService $tokens = null): self
    {
        return new self(
            http:        new CurlHttpClient(),
            tokens:      $tokens ?? AddiTokenService::fromEnv(),
            url:         $_ENV['ADDI_URL_REDIRECTION'],
            callbackUrl: $_ENV['ADDI_URL_CALLBACK'],
            redirectUrl: $_ENV['ADDI_URL_REDIRECT'],
            timeout:     (int) $_ENV['PAYMENT_GATEWAY_TIMEOUT_SECONDS'],
        );
    }

    /**
     * @param  array<string, mixed> $body Payload pass-through del e-commerce.
     * @throws HttpRequestException
     * @throws RuntimeException Si Addi no devuelve la URL esperada.
     */
    public function obtainRedirectUrl(array $body): string
    {
        $token = $this->tokens->getToken();
        $requestedRedirect = $body['allyUrlRedirection']['redirectionUrl'] ?? null;
        $redirectUrl = is_string($requestedRedirect) && trim($requestedRedirect) !== ''
            ? trim($requestedRedirect)
            : $this->redirectUrl;

        $body['allyUrlRedirection'] = [
            'callbackUrl'    => $this->callbackUrl,
            'redirectionUrl' => $redirectUrl,
        ];

        $result = $this->requestRedirect($token, $body);

        $url = $result['redirectUrl'];
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Addi did not return a redirect URL (Location header missing).');
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{redirectUrl: ?string, statusCode: int, body: string}
     * @throws HttpRequestException
     */
    private function requestRedirect(string $token, array $body): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->http->requestForRedirect($this->url, [
                    'method'  => 'POST',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                    ],
                    'payload'        => $body,
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
