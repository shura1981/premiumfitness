<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

use RuntimeException;

/**
 * El aviso trae un id de pago que no es de un pedido de PremiumFitness (`orders.payment_code`): por
 * ejemplo, un pago de otro proyecto que comparte la cuenta de la pasarela. El webhook lo ignora.
 */
final class ForeignPaymentReferenceException extends RuntimeException
{
    public static function for(string $gateway, string $reference): self
    {
        return new self("{$gateway} webhook: la referencia «{$reference}» no es de un pedido de PremiumFitness.");
    }
}
