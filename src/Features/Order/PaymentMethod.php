<?php

declare(strict_types=1);

namespace App\Features\Order;

/**
 * Forma de pago de un pedido, como la leen el call center y los reportes: «Puntos» en un canje y
 * «Dinero (Bold)», «Dinero (Addi)» o «Dinero (Sistecrédito)» en una compra. La misma regla en SQL
 * de las listas y las exportaciones del admin.
 */
final class PaymentMethod
{
    /** Expresión SQL de la forma de pago; espera la tabla `orders` con el alias `o`. */
    public const string SQL = "CASE WHEN o.order_type = 'redemption' THEN 'Puntos'
        ELSE CONCAT('Dinero (', CASE o.payment_gateway WHEN 'bold' THEN 'Bold' WHEN 'addi' THEN 'Addi'
            WHEN 'sistecredito' THEN 'Sistecrédito' ELSE COALESCE(o.payment_gateway, 'sin pasarela') END, ')') END";
}
