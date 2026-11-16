<?php

declare(strict_types=1);

namespace App\Features\Account;

use App\Infrastructure\Validation\AbstractValidator;

class AddressValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'min:2', 'max:100'],
            'address'     => ['required', 'string', 'min:5', 'max:200'],
            'city_id'     => ['required', 'int'],
            'locality_id' => ['int'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'name'        => 'Ponle un nombre a la dirección (por ejemplo, Casa).',
            'address'     => 'Escribe la dirección completa.',
            'city_id'     => 'Elige una ciudad de la lista.',
            'locality_id' => 'Elige una localidad de la lista.',
        ];
    }
}
