<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Infrastructure\Validation\AbstractValidator;

class CheckoutValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'items'           => ['required'],
            'address_id'      => ['required', 'int'],
            'payment_gateway' => ['required', 'in:bold'],
            'notes'           => ['string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'items'           => 'Tu carrito está vacío.',
            'address_id'      => 'Elige una dirección de entrega.',
            'payment_gateway' => 'Elige un medio de pago.',
            'notes'           => 'La nota puede tener hasta 500 caracteres.',
        ];
    }
}
