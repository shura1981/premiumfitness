<?php

declare(strict_types=1);

namespace App\Features\Checkout;

/**
 * Una línea de la compra con todos sus valores ya calculados. Es lo que se guarda en `orders_items`:
 * si después cambian el precio, el descuento o el IVA, el pedido no cambia.
 */
final readonly class QuoteLine
{
    public function __construct(
        public int $code,
        public string $name,
        public ?string $image,
        public ?string $presentation,
        public int $quantity,
        /** Precio completo de una unidad, con IVA. */
        public int $unitPrice,
        /** Precio de una unidad para quien compra. */
        public int $finalUnitPrice,
        public float $discountPercentage,
        public int $lineTotal,
        /** false: agotado o ya no se vende en la app. */
        public bool $available,
        public ?int $sapCode = null,
        public float $taxRate = 0.0,
        public float $taxBase = 0.0,
        public float $taxAmount = 0.0,
        /** null si la compra no genera comisión. */
        public ?float $commissionPercentage = null,
        public ?float $commissionAmount = null,
    ) {}

    /** @return array<string, mixed> Contrato `CheckoutPreview.items`. */
    public function toArray(): array
    {
        return [
            'code'                => $this->code,
            'name'                => $this->name,
            'image'               => $this->image,
            'presentation'        => $this->presentation,
            'quantity'            => $this->quantity,
            'unit_price'          => $this->unitPrice,
            'final_unit_price'    => $this->finalUnitPrice,
            'discount_percentage' => $this->discountPercentage,
            'line_total'          => $this->lineTotal,
            'available'           => $this->available,
        ];
    }
}
