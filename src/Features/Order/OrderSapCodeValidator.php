<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Validation\AbstractValidator;

class OrderSapCodeValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'sap_order_code' => ['required', 'int'],
        ];
    }
}
