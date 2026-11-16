<?php

declare(strict_types=1);

namespace App\Features\Auth\Validators;

use App\Infrastructure\Validation\AbstractValidator;

class PhoneCodeValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'phone' => ['required', 'regex:/^3\d{9}$/'],
            'code'  => ['required', 'regex:/^\d{6}$/'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'phone' => 'Escribe tu celular de 10 dígitos.',
            'code'  => 'Escribe el código de 6 dígitos.',
        ];
    }
}
