<?php

declare(strict_types=1);

namespace App\Features\Auth\Validators;

use App\Infrastructure\Validation\AbstractValidator;

class CouponValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'coupon_code' => ['required', 'string', 'min:3', 'max:30'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'coupon_code' => 'Escribe el cupón de tu entrenador.',
        ];
    }
}
