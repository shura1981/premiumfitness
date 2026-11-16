<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Normalizer;

/**
 * Códigos canónicos de estado de pago, comunes a todas las pasarelas.
 *
 * Cada `PaymentStatusNormalizer` traduce el estado bruto de la pasarela a
 * uno de estos códigos + texto. La regla de idempotencia del dispatcher
 * compara contra `TEXT_APROBADA`.
 */
final class PaymentStatus
{
    public const int APROBADA          = 1;
    public const int RECHAZADA         = 2;
    public const int PENDIENTE         = 3;
    public const int ERROR             = 4;
    public const int PENDIENTE_METODO  = 10;
    public const int CANCELADA         = 11;

    public const string TEXT_APROBADA          = 'APROBADA';
    public const string TEXT_RECHAZADA         = 'PAGO RECHAZADO';
    public const string TEXT_PENDIENTE         = 'PENDIENTE POR CR';
    public const string TEXT_ERROR             = 'ERROR CR';
    public const string TEXT_PENDIENTE_METODO  = 'PENDIENTE POR MÉTODO DE PAGO';
    public const string TEXT_CANCELADA         = 'Cancelada';
    public const string TEXT_ABANDONADA        = 'Abandonada';
    public const string TEXT_DESCONOCIDO       = 'DESCONOCIDO';
}
