<?php

declare(strict_types=1);

namespace App\Features\Admin;

use App\Infrastructure\Validation\AbstractValidator;

class AdminLoginValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'min:4', 'max:100'],
        ];
    }
}
