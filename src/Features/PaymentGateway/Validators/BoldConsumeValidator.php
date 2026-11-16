<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Validators;

use App\Infrastructure\Validation\AbstractValidator;

/**
 * Validador del request hacia `POST /api/payment-gateway/consume/bold`.
 *
 * Campos requeridos para generar la firma de integridad SHA-256 y la
 * configuración de `BoldCheckout`.
 */
class BoldConsumeValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'order_id'        => ['required', 'int', 'regex:/^[1-9][0-9]*$/'],
            'amount'          => ['required', 'int', 'regex:/^[1-9][0-9]*$/'],
            'currency'        => ['string', 'in:COP'],
            'description'     => ['string', 'max:255'],
            'redirection_url' => ['string', 'max:500'],
        ];
    }
}

