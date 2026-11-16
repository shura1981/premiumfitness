<?php

declare(strict_types=1);

namespace App\Features\Reports;

class SalesByPeriodModel
{
    public string $period;
    public int $order_count;
    public int $revenue;
    public ?float $avg_ticket = null;
}
