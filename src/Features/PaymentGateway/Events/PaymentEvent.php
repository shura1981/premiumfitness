<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events;

use App\Features\Order\OrderModel;
use App\Features\PaymentGateway\Result\PaymentResult;

/**
 * Evento que reciben los handlers tras una actualización de pago.
 *
 * Empareja la orden ya cargada con el `PaymentResult` normalizado para que
 * cada handler tenga ambos en una sola firma.
 */
final readonly class PaymentEvent
{
    public function __construct(
        public OrderModel    $order,
        public PaymentResult $result,
    ) {}
}
