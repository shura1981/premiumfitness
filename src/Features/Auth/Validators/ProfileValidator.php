<?php

declare(strict_types=1);

namespace App\Features\Auth\Validators;

use App\Infrastructure\Validation\AbstractValidator;

class ProfileValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:5', 'max:150'],
            'email'     => ['required', 'email', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'full_name' => 'Escribe tu nombre completo.',
            'email'     => 'Escribe un correo válido.',
        ];
    }
}
