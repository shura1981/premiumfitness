<?php

declare(strict_types=1);

namespace App\Features\DiscountRule;

/**
 * Estrategia de un producto (`product_discount_rules`). Un valor nulo usa el valor por defecto.
 */
final readonly class DiscountRule
{
    public function __construct(
        public int $productSku,
        public ?float $clientDiscountPercentage,
        public ?float $trainerDiscountPercentage,
        public ?float $commissionPercentage,
    ) {}
}
