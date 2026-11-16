<?php

declare(strict_types=1);

namespace App\Features\Auth\Validators;

use App\Infrastructure\Validation\AbstractValidator;

class LoginValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'phone'    => ['required', 'regex:/^3\d{9}$/'],
            'password' => ['required', 'string', 'max:128'],
            'role'     => ['required', 'in:client,trainer'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'phone'    => 'Escribe tu celular de 10 dígitos.',
            'password' => 'Escribe tu contraseña.',
            'role'     => 'Entra por el acceso de clientes o el de entrenadores.',
        ];
    }
}
