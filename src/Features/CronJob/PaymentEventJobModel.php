<?php

declare(strict_types=1);

namespace App\Features\CronJob;

final class PaymentEventJobModel
{
    public int $job_id;
    public int $order_id;
    public int $attempts = 0;
    public ?string $locked_at = null;
    public ?string $processed_at = null;
    public ?string $last_error = null;
    public string $created_at;
    public ?string $updated_at = null;
}
