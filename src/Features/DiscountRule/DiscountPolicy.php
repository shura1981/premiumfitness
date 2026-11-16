<?php

declare(strict_types=1);

namespace App\Features\DiscountRule;

use App\Infrastructure\Config\BusinessConfig;

/**
 * Descuento y comisión de un producto para quien compra (reglas 2, 6 y 14; spec negocio/01):
 *   - el entrenador compra con su descuento;
 *   - el cliente con entrenador, con el del cupón; sin entrenador, a precio completo;
 *   - la estrategia vigente del producto tiene prioridad sobre los valores por defecto.
 * Los valores por defecto son variables de entorno; el pedido guarda el porcentaje que aplicó.
 */
final readonly class DiscountPolicy
{
    public function __construct(private BusinessConfig $business) {}

    public static function fromEnv(): self
    {
        return new self(BusinessConfig::fromEnv());
    }

    public function discountPercentage(Buyer $buyer, ?DiscountRule $rule): float
    {
        if ($buyer->isTrainer()) {
            return $rule?->trainerDiscountPercentage ?? $this->business->trainerDiscountPercentage;
        }

        if ($buyer->trainerId !== null) {
            return $rule?->clientDiscountPercentage ?? $this->business->clientDiscountPercentage;
        }

        return 0.0;
    }

    /** Comisión del entrenador sobre la base sin IVA de la línea de su cliente. */
    public function commissionPercentage(?DiscountRule $rule): float
    {
        return $rule?->commissionPercentage ?? $this->business->trainerCommissionPercentage;
    }

    /**
     * Precio de una unidad con el descuento, redondeado al peso más cercano (0,5 sube):
     * el peso no tiene centavos y las pasarelas cobran enteros.
     */
    public static function finalPrice(int $price, float $discountPercentage): int
    {
        return (int) round($price * (100 - $discountPercentage) / 100);
    }
}
