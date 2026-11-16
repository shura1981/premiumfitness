<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Validators;

use App\Infrastructure\Validation\AbstractValidator;

/**
 * Validador del request hacia `POST /api/payment-gateway/consume/sistecredito`.
 *
 * `Invoice` y `Value` son los campos mínimos requeridos por Credinet para
 * crear la orden y disparar el flujo de redirección.
 */
class SistecreditoConsumeValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'Invoice'  => ['required'],
            'Value'    => ['required'],
            'Currency' => ['required', 'string', 'max:5'],
        ];
    }
}
