<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Features\DiscountRule\Buyer;
use App\Features\DiscountRule\DiscountPolicy;
use App\Features\DiscountRule\DiscountRuleRepository;
use App\Features\Order\VatPolicy;
use App\Features\Product\ProductCatalog;
use App\Infrastructure\Config\BusinessConfig;

/**
 * Calcula una compra (reglas 1, 2, 6, 7 y 14; specs negocio/01 y catalogo/01).
 *
 *   - Precio y disponibilidad salen de la fuente del catálogo, sin caché.
 *   - Descuento por línea según quién compra y la estrategia del producto.
 *   - IVA por línea sobre lo pagado; la comisión del entrenador, sobre la base sin IVA.
 *   - Puntos del entrenador: una sola vez, sobre lo pagado por los productos (con IVA, sin envío).
 *   - Solo la compra de un cliente con entrenador genera comisión y puntos.
 */
final readonly class QuoteCalculator
{
    public const int MAX_LINES    = 50;
    public const int MAX_QUANTITY = 99;

    public function __construct(
        private ProductCatalog $catalog,
        private DiscountRuleRepository $rules,
        private DiscountPolicy $discounts,
        private VatPolicy $vat,
        private BusinessConfig $business,
    ) {}

    /**
     * @param list<array{code:int, quantity:int}> $items Ya normalizados: códigos únicos y cantidades válidas.
     */
    public function quote(Buyer $buyer, array $items): Quote
    {
        $codes    = array_column($items, 'code');
        $products = $this->catalog->findByCodes($codes);
        $rules    = $this->rules->activeFor($codes);
        // Solo la compra de un cliente con entrenador genera recompensas.
        $rewarded = !$buyer->isTrainer() && $buyer->trainerId !== null;

        $lines = [];

        foreach ($items as $item) {
            $product = $products[$item['code']] ?? null;

            if ($product === null) {
                $lines[] = new QuoteLine($item['code'], 'Producto no disponible', null, null, $item['quantity'], 0, 0, 0.0, 0, false);
                continue;
            }

            $rule      = $rules[$product->code] ?? null;
            $discount  = $this->discounts->discountPercentage($buyer, $rule);
            $unitFinal = DiscountPolicy::finalPrice($product->price, $discount);
            $lineTotal = $unitFinal * $item['quantity'];
            $tax       = $this->vat->split($product->code, (float) $lineTotal);

            $commissionPercentage = $rewarded ? $this->discounts->commissionPercentage($rule) : null;

            $lines[] = new QuoteLine(
                code: $product->code,
                name: $product->name,
                image: $product->images->card(),
                presentation: $product->presentation,
                quantity: $item['quantity'],
                unitPrice: $product->price,
                finalUnitPrice: $unitFinal,
                discountPercentage: $discount,
                lineTotal: $lineTotal,
                available: $product->isPurchasable(),
                sapCode: $product->sapCode,
                taxRate: $tax['rate'],
                taxBase: $tax['base'],
                taxAmount: $tax['tax'],
                commissionPercentage: $commissionPercentage,
                commissionAmount: $commissionPercentage === null ? null : round($tax['base'] * $commissionPercentage / 100, 2),
            );
        }

        return $this->totals($lines, $rewarded ? $buyer->trainerId : null);
    }

    /** @param list<QuoteLine> $lines */
    private function totals(array $lines, ?int $trainerId): Quote
    {
        $sellable     = array_filter($lines, static fn (QuoteLine $line): bool => $line->available);
        $unavailable  = count($lines) - count($sellable);
        $subtotalFull = array_sum(array_map(static fn (QuoteLine $line): int => $line->unitPrice * $line->quantity, $sellable));
        $products     = array_sum(array_map(static fn (QuoteLine $line): int => $line->lineTotal, $sellable));
        $shipping     = $products > 0 && $products < $this->business->shippingFreeThreshold ? $this->business->shippingFee : 0;

        $rewardPercentage = $trainerId === null ? null : $this->business->clientPurchasePointsPercentage;

        return new Quote(
            lines: $lines,
            subtotalFull: $subtotalFull,
            discountAmount: $subtotalFull - $products,
            taxBase: round(array_sum(array_map(static fn (QuoteLine $line): float => $line->taxBase, $sellable)), 2),
            taxAmount: round(array_sum(array_map(static fn (QuoteLine $line): float => $line->taxAmount, $sellable)), 2),
            shippingAmount: $shipping,
            total: $products + $shipping,
            trainerId: $trainerId,
            rewardPercentage: $rewardPercentage,
            // Regla 1: una sola vez sobre el total pagado por los productos; 0,5 sube.
            rewardPoints: $rewardPercentage === null ? null : (int) round($products * $rewardPercentage / 100),
            commissionAmount: round(array_sum(array_map(static fn (QuoteLine $line): float => $line->commissionAmount ?? 0.0, $sellable)), 2),
            canCheckout: $lines !== [] && $unavailable === 0,
            message: match (true) {
                $lines === []    => 'Tu carrito está vacío.',
                $unavailable > 0 => 'Hay productos que ya no están disponibles. Quítalos para continuar.',
                default          => null,
            },
        );
    }
}
