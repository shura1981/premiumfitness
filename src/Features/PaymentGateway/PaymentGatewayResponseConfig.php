<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

/**
 * Configuración compartida para las páginas HTML de respuesta de pasarela.
 */
final readonly class PaymentGatewayResponseConfig
{
    public function __construct(
        public string $spaReturnUrl,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            spaReturnUrl: (string) $_ENV['PAYMENT_GATEWAY_SPA_RETURN_URL'],
        );
    }
}

