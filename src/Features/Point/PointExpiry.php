<?php

declare(strict_types=1);

namespace App\Features\Point;

use DateTimeImmutable;

/**
 * Vencimiento de un lote (regla 2; spec backend/03, P-38): a los `POINTS_EXPIRATION_MONTHS` meses,
 * al terminar ese día en hora de Colombia, que es la fecha que ve el entrenador. Si el mes de llegada
 * no tiene ese día, vence el último día del mes (31 de agosto → 28 o 29 de febrero).
 */
final class PointExpiry
{
    public static function from(DateTimeImmutable $accruedAt, int $months): DateTimeImmutable
    {
        // Se suma sobre el día 1 para que PHP no pase al mes siguiente (31 de agosto + 6 meses ≠ 3 de marzo).
        $month = $accruedAt->modify('first day of this month')->modify("+{$months} months");
        $day   = min((int) $accruedAt->format('j'), (int) $month->format('t'));

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->setTime(23, 59, 59);
    }
}
