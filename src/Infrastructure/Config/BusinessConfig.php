<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Constantes de negocio (docs/reglas-de-negocio.md). Viven en variables de entorno:
 * ninguna se escribe en el código. Cada operación guarda el valor que aplicó.
 */
final readonly class BusinessConfig
{
    public function __construct(
        public float $clientDiscountPercentage,
        public float $trainerDiscountPercentage,
        public float $clientPurchasePointsPercentage,
        public float $trainerCommissionPercentage,
        public int $trainerReferralPoints,
        public int $pointsExpirationMonths,
        public float $commissionConversionPercentage,
        public float $vatPercentage,
        /** @var list<int> */
        public array $vatExemptProductCodes,
        public string $webAppUrl,
        /** Envío: se cobra `shippingFee` si los productos pagados no llegan a `shippingFreeThreshold`. */
        public int $shippingFee = 0,
        public int $shippingFreeThreshold = 0,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            clientDiscountPercentage: (float) $_ENV['CLIENT_DISCOUNT_PERCENTAGE'],
            trainerDiscountPercentage: (float) $_ENV['TRAINER_DISCOUNT_PERCENTAGE'],
            clientPurchasePointsPercentage: (float) $_ENV['CLIENT_PURCHASE_POINTS_PERCENTAGE'],
            trainerCommissionPercentage: (float) $_ENV['TRAINER_COMMISSION_PERCENTAGE'],
            trainerReferralPoints: (int) $_ENV['TRAINER_REFERRAL_POINTS'],
            pointsExpirationMonths: (int) $_ENV['POINTS_EXPIRATION_MONTHS'],
            commissionConversionPercentage: (float) $_ENV['COMMISSION_CONVERSION_PERCENTAGE'],
            vatPercentage: (float) $_ENV['VAT_PERCENTAGE'],
            vatExemptProductCodes: array_values(array_map(
                intval(...),
                array_filter(array_map(trim(...), explode(',', (string) ($_ENV['VAT_EXEMPT_PRODUCT_CODES'] ?? ''))), static fn (string $code): bool => $code !== ''),
            )),
            webAppUrl: rtrim((string) $_ENV['WEB_APP_URL'], '/'),
            shippingFee: (int) $_ENV['SHIPPING_FEE'],
            shippingFreeThreshold: (int) $_ENV['SHIPPING_FREE_THRESHOLD'],
        );
    }
}
