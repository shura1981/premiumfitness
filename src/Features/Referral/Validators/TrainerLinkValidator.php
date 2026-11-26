<?php

declare(strict_types=1);

namespace App\Features\Referral\Validators;

use App\Infrastructure\Validation\AbstractValidator;

/** El id del enlace del entrenador (`/r/c/<id>`), en base64 para URL (regla 12). */
class TrainerLinkValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'link_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_=-]+$/'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'link_id' => 'Este enlace no es válido.',
        ];
    }
}
