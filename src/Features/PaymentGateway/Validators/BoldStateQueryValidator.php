<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Validators;

use App\Infrastructure\Validation\AbstractValidator;
use App\Shared\Exceptions\ValidationException;

/**
 * Validador para consultas/conciliación de estado en Bold:
 *   - `GET /api/payment-gateway/state/bold?order_id=…` o `?payment_id=…`
 *   - `POST /api/payment-gateway/state/bold/reconcile`
 *
 * Exige al menos uno de `order_id` (entero positivo) o `payment_id`
 * (identificador alfanumérico de Bold).
 */
class BoldStateQueryValidator extends AbstractValidator
{
    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'order_id'   => ['int', 'regex:/^[1-9][0-9]*$/'],
            'payment_id' => ['string', 'regex:/^[A-Za-z0-9_-]{6,64}$/'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    public function validateQuery(array $data): void
    {
        $hasOrderId   = isset($data['order_id']) && $data['order_id'] !== '';
        $hasPaymentId = isset($data['payment_id']) && $data['payment_id'] !== '';

        if (!$hasOrderId && !$hasPaymentId) {
            throw new ValidationException([
                'query' => ['Either order_id or payment_id is required.'],
            ]);
        }

        $this->validate($data);
    }
}

