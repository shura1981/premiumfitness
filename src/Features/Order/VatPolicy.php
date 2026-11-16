<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Config\BusinessConfig;

/**
 * IVA por línea, al comprar (spec catalogo/01). El catálogo no tiene tasa de IVA: todos los
 * productos llevan `VAT_PERCENTAGE` salvo los códigos de `VAT_EXEMPT_PRODUCT_CODES`.
 * Los precios ya incluyen el IVA.
 */
final readonly class VatPolicy
{
    /** @param list<int> $exemptCodes */
    public function __construct(
        private float $percentage,
        private array $exemptCodes,
    ) {}

    public static function fromEnv(): self
    {
        $business = BusinessConfig::fromEnv();

        return new self($business->vatPercentage, $business->vatExemptProductCodes);
    }

    public function rateFor(int $productCode): float
    {
        return in_array($productCode, $this->exemptCodes, true) ? 0.0 : $this->percentage;
    }

    /**
     * Separa lo pagado en una línea en base e IVA. La base se redondea a dos decimales y el IVA
     * es la diferencia: base + IVA da siempre lo pagado.
     *
     * @return array{rate:float, base:float, tax:float}
     */
    public function split(int $productCode, float $paid): array
    {
        $rate = $this->rateFor($productCode);
        $base = round($paid / (1 + $rate / 100), 2);

        return ['rate' => $rate, 'base' => $base, 'tax' => round($paid - $base, 2)];
    }
}
