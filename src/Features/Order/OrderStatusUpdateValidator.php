<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Validation\AbstractValidator;

class OrderStatusUpdateValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'status_id' => ['required', 'int'],
        ];
    }
}
