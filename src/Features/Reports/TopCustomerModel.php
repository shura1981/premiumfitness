<?php

declare(strict_types=1);

namespace App\Features\Reports;

class TopCustomerModel
{
    public int    $user_id;
    public string $full_name;
    public string $email;
    public int    $order_count;
    public int    $total_spent;
    public ?float $avg_ticket = null;
}
