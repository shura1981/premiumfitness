<?php

declare(strict_types=1);

namespace App\Features\Order;

class OrderItemModel
{
    public int    $item_id;
    public int    $order_id;
    public int    $product_sku;
    public string $product_full_price;
    public ?int   $sap_code = null;
    public int    $quantity;
    public string $unit_price;
    public string $discount_percentage;
    public string $line_total;

    /** Copia del nombre del producto al comprar: si la web lo cambia después, el pedido no cambia. */
    public string $product_name = '';

    /** IVA de la línea: tasa aplicada, base e impuesto (spec catalogo/01). */
    public ?string $tax_rate = null;
    public ?string $tax_base = null;
    public ?string $tax_amount = null;

    /** Comisión del entrenador sobre la base de la línea; null si la compra no genera comisión. */
    public ?string $commission_percentage = null;
    public ?string $commission_amount = null;

    /** Costo en puntos de la línea de un canje. */
    public ?int $redemption_points = null;

    /** Datos vigentes del catálogo de la intranet (JOIN de solo lectura). Opcionales. */
    public ?string $product_presentation = null;
    public ?string $product_slug = null;
    public ?string $product_category_name = null;
}
