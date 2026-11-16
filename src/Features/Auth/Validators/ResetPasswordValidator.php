<?php

declare(strict_types=1);

namespace App\Features\Auth\Validators;

use App\Infrastructure\Validation\AbstractValidator;

class ResetPasswordValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'reset_token' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'password'    => ['required', 'string', 'min:8', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'reset_token' => 'El enlace para cambiar la contraseña venció. Pide un código nuevo.',
            'password'    => 'La contraseña debe tener entre 8 y 128 caracteres.',
        ];
    }
}
