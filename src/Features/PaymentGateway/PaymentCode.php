<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

/**
 * Id de pago que recibe la pasarela: `pf-<número del pedido>` (spec backend/03, P-24). Se guarda en
 * `orders.payment_code` al crear el pedido. Un pedido = un pago = un id, como en radical.
 *
 * El prefijo separa los pagos de PremiumFitness de los de otros proyectos que comparten las cuentas
 * de las pasarelas y usan el número solo.
 */
final class PaymentCode
{
    public const string PREFIX = 'pf-';

    public static function forOrder(int $orderId): string
    {
        return self::PREFIX . $orderId;
    }
}
