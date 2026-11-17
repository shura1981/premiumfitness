<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

use RuntimeException;

/**
 * El aviso trae una referencia que no es de un intento de PremiumFitness: por ejemplo, un pago del
 * sitio web de Nutramerican, que comparte la cuenta de Bold. El webhook lo ignora.
 */
final class ForeignPaymentReferenceException extends RuntimeException
{
    public static function for(string $gateway, string $reference): self
    {
        return new self("{$gateway} webhook: la referencia «{$reference}» no es de un intento de pago de PremiumFitness.");
    }
}
