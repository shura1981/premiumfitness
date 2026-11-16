<?php

declare(strict_types=1);

namespace App\Features\Auth\Validators;

use App\Infrastructure\Validation\AbstractValidator;

class ChangePasswordValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128'],
            'password'         => ['required', 'string', 'min:8', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'current_password' => 'Escribe tu contraseña actual.',
            'password'         => 'La contraseña debe tener entre 8 y 128 caracteres.',
        ];
    }
}
