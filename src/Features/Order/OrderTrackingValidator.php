<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Validation\AbstractValidator;

class OrderTrackingValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'tracking_number' => ['required', 'string', 'max:50'],
            'carrier_name'    => ['required', 'string', 'in:SERVIENTREGA,MELONN,ENVIA'],
        ];
    }
}
