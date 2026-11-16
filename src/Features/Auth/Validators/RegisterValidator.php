<?php

declare(strict_types=1);

namespace App\Features\Auth\Validators;

use App\Infrastructure\Validation\AbstractValidator;

class RegisterValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'full_name'        => ['required', 'string', 'min:5', 'max:150'],
            'document_type_id' => ['required', 'int'],
            'document_id'      => ['required', 'regex:/^[A-Za-z0-9-]{5,20}$/'],
            'phone'            => ['required', 'regex:/^3\d{9}$/'],
            'email'            => ['required', 'email', 'max:150'],
            'password'         => ['required', 'string', 'min:8', 'max:128'],
            'role'             => ['required', 'in:client,trainer'],
            // Opcional, solo entrenadores: letras y números, sin espacios.
            'coupon_code'      => ['regex:/^[A-Za-z0-9]{4,20}$/'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'full_name'        => 'Escribe tu nombre completo.',
            'document_type_id' => 'Elige un tipo de documento.',
            'document_id'      => 'Escribe tu número de documento, sin puntos ni espacios.',
            'phone'            => 'Escribe tu celular de 10 dígitos.',
            'email'            => 'Escribe un correo válido.',
            'password'         => 'La contraseña debe tener entre 8 y 128 caracteres.',
            'role'             => 'Elige si eres cliente o entrenador.',
            'coupon_code'      => 'El cupón debe tener entre 4 y 20 letras o números, sin espacios.',
        ];
    }
}
