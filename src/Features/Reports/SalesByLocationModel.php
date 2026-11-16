<?php

declare(strict_types=1);

namespace App\Features\Reports;

class SalesByLocationModel
{
    public ?int    $city_id = null;
    public string  $city;
    public string  $department;
    public int     $order_count;
    public int     $revenue;
}
