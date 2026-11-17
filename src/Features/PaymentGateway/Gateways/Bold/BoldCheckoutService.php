<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Bold;

use InvalidArgumentException;
use RuntimeException;

/**
 * Genera la firma de integridad SHA-256 y la configuración completa para el
 * Botón de pagos Bold (`BoldCheckout` en el navegador).
 *
 * Fórmula oficial de Bold:
 *   SHA-256(orderId . amount . currency . secretKey)
 * sin separadores, con `amount` entero en pesos colombianos (sin decimales).
 * La llave secreta nunca sale del servidor; al frontend únicamente viaja la
 * llave pública de identidad (`apiKey`) junto con `integritySignature`.
 */
final readonly class BoldCheckoutService
{
    private const string DEFAULT_CURRENCY = 'COP';
    private const string DEFAULT_RENDER_MODE = 'embedded';

    public function __construct(
        private string $identityKey,
        private string $secretKey,
        private string $defaultResponseUrl,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            identityKey:        trim((string) $_ENV['BOLD_IDENTITY_KEY']),
            secretKey:          trim((string) $_ENV['BOLD_SECRET_KEY']),
            defaultResponseUrl: trim((string) $_ENV['BOLD_URL_RESPONSE']),
        );
    }

    public function generateIntegrityHash(
        int|string $orderId,
        int|string $amount,
        string $currency = self::DEFAULT_CURRENCY,
    ): string {
        if ($this->secretKey === '') {
            throw new RuntimeException('Bold secret key is not configured.');
        }

        $normalizedOrderId = trim((string) $orderId);
        $normalizedAmount  = trim((string) $amount);
        $normalizedCurrency = strtoupper(trim($currency));

        // Regla de Bold para la referencia: letras, números, `-` y `_`, hasta 60 caracteres.
        if (preg_match('/^[A-Za-z0-9_-]{1,60}$/', $normalizedOrderId) !== 1) {
            throw new InvalidArgumentException('Bold orderId must be 1-60 letters, digits, "-" or "_".');
        }

        if (preg_match('/^[1-9][0-9]*$/', $normalizedAmount) !== 1) {
            throw new InvalidArgumentException('Bold amount must be a positive integer without decimals.');
        }

        if ($normalizedCurrency === '') {
            throw new InvalidArgumentException('Bold currency cannot be empty.');
        }

        return hash('sha256', $normalizedOrderId . $normalizedAmount . $normalizedCurrency . $this->secretKey);
    }

    /**
     * Construye el objeto de opciones listo para `new BoldCheckout(config)`.
     *
     * @param array<string, mixed> $params
     * @return array{
     *   apiKey: string,
     *   orderId: string,
     *   amount: string,
     *   currency: string,
     *   integritySignature: string,
     *   description: string,
     *   redirectionUrl: string,
     *   renderMode: string,
     *   customerData?: string,
     *   billingAddress?: string
     * }
     */
    public function buildCheckoutConfig(array $params): array
    {
        if ($this->identityKey === '') {
            throw new RuntimeException('Bold identity key is not configured.');
        }

        $rawOrderId = $params['order_id'] ?? $params['orderId'] ?? null;
        if (!is_int($rawOrderId) && !is_string($rawOrderId)) {
            throw new InvalidArgumentException('Bold order_id is required.');
        }

        $rawAmount = $params['amount'] ?? null;
        if (!is_numeric($rawAmount)) {
            throw new InvalidArgumentException('Bold amount must be numeric.');
        }

        $orderId  = trim((string) $rawOrderId);
        $amount   = (string) (int) round((float) $rawAmount);
        $currency = isset($params['currency']) && is_string($params['currency']) && trim($params['currency']) !== ''
            ? strtoupper(trim($params['currency']))
            : self::DEFAULT_CURRENCY;

        $integritySignature = $this->generateIntegrityHash($orderId, $amount, $currency);

        $description = isset($params['description']) && is_string($params['description']) && trim($params['description']) !== ''
            ? trim($params['description'])
            : sprintf('Pedido PremiumFitness #%s', $orderId);

        $rawRedirectionUrl = $params['redirection_url'] ?? $params['redirectionUrl'] ?? null;
        $baseRedirectionUrl = is_string($rawRedirectionUrl) && trim($rawRedirectionUrl) !== ''
            ? trim($rawRedirectionUrl)
            : $this->defaultResponseUrl;
        $redirectionUrl = $this->appendOrderIdQuery($baseRedirectionUrl, $orderId);

        $rawRenderMode = $params['render_mode'] ?? $params['renderMode'] ?? null;
        $renderMode = is_string($rawRenderMode) && trim($rawRenderMode) !== ''
            ? trim($rawRenderMode)
            : self::DEFAULT_RENDER_MODE;

        $config = [
            'apiKey'             => $this->identityKey,
            'orderId'            => $orderId,
            'amount'             => $amount,
            'currency'           => $currency,
            'integritySignature' => $integritySignature,
            'description'        => $description,
            'redirectionUrl'     => $redirectionUrl,
            'renderMode'         => $renderMode,
        ];

        $customerData = $this->encodeOptionalJsonField(
            $params['customer'] ?? $params['customer_data'] ?? $params['customerData'] ?? null
        );
        if ($customerData !== null) {
            $config['customerData'] = $customerData;
        }

        $billingAddress = $this->encodeOptionalJsonField(
            $params['billing_address'] ?? $params['billingAddress'] ?? null
        );
        if ($billingAddress !== null) {
            $config['billingAddress'] = $billingAddress;
        }

        return $config;
    }

    private function appendOrderIdQuery(string $url, string $orderId): string
    {
        if ($url === '') {
            throw new RuntimeException('Bold redirection URL is not configured.');
        }

        if (preg_match('/(?:[?&])orderId=/', $url) === 1) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'orderId=' . rawurlencode($orderId);
    }

    private function encodeOptionalJsonField(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_array($value) && $value !== []) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return null;
    }
}

