<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Validators;

use App\Infrastructure\Validation\AbstractValidator;

/**
 * Validador del request hacia `POST /api/payment-gateway/consume/addi`.
 *
 * Sólo se validan los campos críticos para la integración. El resto del body
 * pasa pass-through hacia Addi.
 */
class AddiConsumeValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'orderId'            => ['required', 'int'],
            'totalAmount'        => ['required'],
            'allyTransactionDate'=> ['required', 'string', 'max:50'],
        ];
    }
}
