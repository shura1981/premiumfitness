<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Validation\AbstractValidator;

class OrderAgentIdValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'agent_id' => ['required', 'int'],
        ];
    }
}
