<?php

declare(strict_types=1);

namespace App\Features\Checkout;

/**
 * La compra calculada: líneas y totales. La app pinta estos valores; no calcula dinero.
 */
final readonly class Quote
{
    /** @param list<QuoteLine> $lines */
    public function __construct(
        public array $lines,
        public int $subtotalFull,
        public int $discountAmount,
        public float $taxBase,
        public float $taxAmount,
        /** Flete de la dirección elegida; null mientras no hay dirección o si no hay envío a ella. */
        public ?int $shippingAmount,
        public int $total,
        /** Entrenador al que se atribuye la compra de un cliente; null si no genera recompensas. */
        public ?int $trainerId,
        public ?float $rewardPercentage,
        public ?int $rewardPoints,
        public float $commissionAmount,
        public bool $canCheckout,
        public ?string $message,
        /** false si Servientrega no llega a la ciudad de la dirección elegida. */
        public bool $shippingAvailable = true,
    ) {}

    /** Lo pagado por los productos, sin el envío. */
    public function productsTotal(): int
    {
        return $this->total - ($this->shippingAmount ?? 0);
    }

    /** @return array<string, mixed> Contrato `CheckoutPreview`. */
    public function toArray(): array
    {
        return [
            'items'           => array_map(static fn (QuoteLine $line): array => $line->toArray(), $this->lines),
            'subtotal_full'   => $this->subtotalFull,
            'discount_amount' => $this->discountAmount,
            'tax_amount'      => $this->taxAmount,
            'shipping_amount' => $this->shippingAmount,
            'total'           => $this->total,
            'can_checkout'    => $this->canCheckout,
            'message'         => $this->message,
        ];
    }
}
