<?php

declare(strict_types=1);

namespace App\Features\Product;

use App\Features\DiscountRule\Buyer;
use App\Features\DiscountRule\DiscountPolicy;
use App\Features\DiscountRule\DiscountRule;

/**
 * Da a un producto la forma del contrato de la API (`Product` y `ProductDetail`), con el precio
 * de quien consulta. No expone el descuento de la web, el precio anterior ni los datos de SAP.
 */
final readonly class ProductPresenter
{
    public function __construct(private DiscountPolicy $policy) {}

    /** @return array<string, mixed> */
    public function summary(CatalogProduct $product, Buyer $buyer, ?DiscountRule $rule): array
    {
        $discount = $this->policy->discountPercentage($buyer, $rule);

        return [
            'code'                => $product->code,
            'name'                => $product->name,
            'category'            => $product->category,
            'presentation'        => $product->presentation,
            'image'               => $product->images->card(),
            'price'               => $product->price,
            'final_price'         => DiscountPolicy::finalPrice($product->price, $discount),
            'discount_percentage' => $discount,
            'is_out_of_stock'     => $product->isOutOfStock,
            'is_new'              => $product->isNew,
            // El canje es a precio completo: 1 punto = 1 peso.
            'redemption_points'   => $product->price,
        ];
    }

    /**
     * @param list<CatalogProduct>     $presentations
     * @param array<int, DiscountRule> $rules
     * @return array<string, mixed>
     */
    public function detail(CatalogProduct $product, array $presentations, Buyer $buyer, array $rules): array
    {
        $images = array_values(array_filter([
            $product->images->full(),
            $product->content?->nutritionTable->full(),
        ]));

        return $this->summary($product, $buyer, $rules[$product->code] ?? null) + [
            'summary'       => $product->summary,
            'images'        => $images,
            'sections'      => $product->content->sections ?? [],
            'presentations' => array_map(
                fn (CatalogProduct $other): array => [
                    'code'            => $other->code,
                    'presentation'    => $other->presentation,
                    'final_price'     => DiscountPolicy::finalPrice(
                        $other->price,
                        $this->policy->discountPercentage($buyer, $rules[$other->code] ?? null),
                    ),
                    'is_out_of_stock' => $other->isOutOfStock,
                ],
                $presentations,
            ),
        ];
    }
}
