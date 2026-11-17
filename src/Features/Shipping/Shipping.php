<?php

declare(strict_types=1);

namespace App\Features\Shipping;

/**
 * Flete de un pedido según la ciudad de entrega (spec backend/03, P-03).
 */
final readonly class Shipping
{
    public function __construct(
        private ShippingRateRepository $rates,
        private ShippingCalculator $calculator,
    ) {}

    public static function fromEnv(): self
    {
        return new self(ShippingRateRepository::fromEnv(), new ShippingCalculator(ShippingConfig::fromEnv()));
    }

    /** Ciudad de entrega; null si la ciudad no existe. */
    public function destination(int $cityId): ?Destination
    {
        return $this->rates->destination($cityId);
    }

    /**
     * @param int $productsTotal Lo pagado por los productos (con descuento e IVA).
     * @return int|null El flete; null si Servientrega no llega a esa ciudad.
     */
    public function amount(Destination $to, int $productsTotal): ?int
    {
        return $this->calculator->amount($this->rates->rateFor($to), $to, $productsTotal);
    }
}
