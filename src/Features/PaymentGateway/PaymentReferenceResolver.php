<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

/** Encuentra el pedido de una referencia de pago (`pf-<id>`). La implementa `PaymentAttemptRepository`. */
interface PaymentReferenceResolver
{
    /** null si la referencia no es de PremiumFitness o no existe. */
    public function orderIdFor(string $reference): ?int;
}
