<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Features\PaymentGateway\PaymentReferenceResolver;

/** Referencias de pago sin base: `pf-<n>` es el pedido n; cualquier otra es ajena. */
final class FakePaymentReferences implements PaymentReferenceResolver
{
    public function orderIdFor(string $reference): ?int
    {
        return preg_match('/\Apf-([1-9][0-9]*)\z/', $reference, $match) === 1 ? (int) $match[1] : null;
    }
}
