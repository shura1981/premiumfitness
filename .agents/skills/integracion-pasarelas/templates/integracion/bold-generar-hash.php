<?php

declare(strict_types=1);

/**
 * Plantilla: Generación de Firma de Integridad (Hash SHA-256) y Configuración para Bold Checkout
 *
 * Basado en la integración de producción con el Botón de Pagos Bold:
 * - Genera el hash SHA-256 oficial: hash('sha256', $orderId . $amount . $currency . $secretKey)
 * - Construye la configuración completa para `new BoldCheckout(...)` en el frontend
 *
 * Variables de entorno requeridas:
 * - BOLD_IDENTITY_KEY : Llave pública de identidad (apiKey en el frontend)
 * - BOLD_SECRET_KEY   : Llave secreta del comercio (NUNCA exponer al frontend)
 * - BOLD_URL_RESPONSE : URL base de retorno al finalizar el pago
 */

final readonly class BoldCheckoutConfigBuilder
{
    public function __construct(
        private string $identityKey,
        private string $secretKey,
        private string $responseUrl,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            identityKey: (string) ($_ENV['BOLD_IDENTITY_KEY'] ?? ''),
            secretKey: (string) ($_ENV['BOLD_SECRET_KEY'] ?? ''),
            responseUrl: (string) ($_ENV['BOLD_URL_RESPONSE'] ?? ''),
        );
    }

    /**
     * Calcula la firma de integridad SHA-256 exigida por Bold.
     *
     * Regla crítica: $orderId y $amount deben ser enteros positivos sin decimales.
     */
    public function generateIntegrityHash(int|string $orderId, int|string $amount, string $currency = 'COP'): string
    {
        $normalizedOrderId = trim((string) $orderId);
        $normalizedAmount = trim((string) $amount);
        $normalizedCurrency = strtoupper(trim($currency));

        if (!preg_match('/^[1-9][0-9]*$/', $normalizedOrderId)) {
            throw new InvalidArgumentException('El identificador de pago (orderId) debe ser un entero positivo.');
        }

        if (!preg_match('/^[1-9][0-9]*$/', $normalizedAmount)) {
            throw new InvalidArgumentException('El monto (amount) debe ser un entero positivo sin decimales.');
        }

        if ($normalizedCurrency !== 'COP') {
            throw new InvalidArgumentException('La divisa para Bold debe ser COP.');
        }

        if ($this->secretKey === '') {
            throw new RuntimeException('Falta configurar BOLD_SECRET_KEY.');
        }

        return hash('sha256', $normalizedOrderId . $normalizedAmount . $normalizedCurrency . $this->secretKey);
    }

    /**
     * Construye el payload completo para abrir `new BoldCheckout(config)` en el navegador.
     *
     * @param array{
     *   order_id: int|string,
     *   amount: int|float|string,
     *   description?: string,
     *   email?: string,
     *   full_name?: string,
     *   phone?: string,
     *   dial_code?: string,
     *   document_number?: string,
     *   document_type?: string,
     *   address?: string,
     *   city?: string,
     *   state?: string,
     *   country?: string
     * } $orderData
     * @return array{
     *   type: string,
     *   gateway: string,
     *   hash: string,
     *   checkout: array<string, string>
     * }
     */
    public function buildCheckoutPayload(array $orderData): array
    {
        $orderId = (string) ((int) $orderData['order_id']);
        $amount = (string) ((int) round((float) $orderData['amount']));
        $currency = 'COP';

        $integritySignature = $this->generateIntegrityHash($orderId, $amount, $currency);

        $separator = str_contains($this->responseUrl, '?') ? '&' : '?';
        $redirectionUrl = $this->responseUrl !== ''
            ? $this->responseUrl . $separator . 'orderId=' . rawurlencode($orderId)
            : '';

        $customerData = json_encode([
            'email' => (string) ($orderData['email'] ?? ''),
            'fullName' => (string) ($orderData['full_name'] ?? ''),
            'phone' => (string) ($orderData['phone'] ?? ''),
            'dialCode' => (string) ($orderData['dial_code'] ?? '+57'),
            'documentNumber' => (string) ($orderData['document_number'] ?? ''),
            'documentType' => (string) ($orderData['document_type'] ?? 'CC'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $billingAddress = json_encode([
            'address' => (string) ($orderData['address'] ?? ''),
            'city' => (string) ($orderData['city'] ?? ''),
            'state' => (string) ($orderData['state'] ?? ''),
            'country' => (string) ($orderData['country'] ?? 'CO'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'type' => 'bold_checkout',
            'gateway' => 'bold',
            'hash' => $integritySignature,
            'checkout' => [
                'orderId' => $orderId,
                'currency' => $currency,
                'amount' => $amount,
                'apiKey' => $this->identityKey,
                'integritySignature' => $integritySignature,
                'description' => (string) ($orderData['description'] ?? "Compra pedido #{$orderId}"),
                'redirectionUrl' => $redirectionUrl,
                'renderMode' => 'embedded',
                'customerData' => $customerData,
                'billingAddress' => $billingAddress,
            ],
        ];
    }
}
