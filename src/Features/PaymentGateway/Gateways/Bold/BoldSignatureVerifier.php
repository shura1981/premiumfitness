<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Bold;

/**
 * Verifica la firma autenticada (`x-bold-signature`) de los webhooks de Bold.
 *
 * Fórmula oficial de Bold:
 *   HMAC-SHA256(base64_encode($rawBody), $secretKey)
 *
 * Se debe pasar el cuerpo HTTP crudo exacto antes de re-serializar JSON, y
 * comparar con `hash_equals` para evitar ataques de timing.
 */
final readonly class BoldSignatureVerifier
{
    public function __construct(
        private string $secretKey,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            secretKey: trim((string) $_ENV['BOLD_SECRET_KEY']),
        );
    }

    public function verify(string $rawBody, string $signatureHeader): bool
    {
        $received = strtolower(trim($signatureHeader));

        if ($this->secretKey === '' || $received === '' || trim($rawBody) === '') {
            return false;
        }

        $expected = hash_hmac('sha256', base64_encode($rawBody), $this->secretKey);

        return hash_equals($expected, $received);
    }
}

