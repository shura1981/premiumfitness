<?php

declare(strict_types=1);

namespace App\Features\Reports;

class DashboardKpiModel
{
    public int $total_users;
    public int $total_orders;
    public int $revenue_approved;
    public int $revenue_total;
    public int $pending_orders;
    public int $rejected_orders;
    public int $approved_orders;
    public int $total_products;
    public int $new_users_this_month;
    public int $orders_this_month;
    public int $revenue_this_month;
    public int $avg_ticket;
}
