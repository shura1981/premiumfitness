<?php

declare(strict_types=1);

namespace App\Features\Reports;

class TopCategoryModel
{
    public int    $category_id;
    public string $category_name;
    public int    $product_count;
    public int    $revenue;
}
