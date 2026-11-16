<?php

declare(strict_types=1);

namespace App\Features\Reports;

class TopProductModel
{
    public int    $product_sku;
    public string $product_name;
    public string $category_name;
    public int    $total_sold;
    public int    $revenue;
}
