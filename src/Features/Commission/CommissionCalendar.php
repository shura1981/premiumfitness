<?php

declare(strict_types=1);

namespace App\Features\Commission;

use DateTimeImmutable;

/**
 * Fechas de la comisión (spec negocio/04, reglas 7 y 10), en hora de Colombia:
 *   - el corte es el último día del mes a las 23:59:59;
 *   - se paga el día `COMMISSION_PAYMENT_DAY` del mes siguiente o, si no es hábil, el día hábil
 *     siguiente (`next`) o anterior (`previous`). Los festivos vienen de `NON_BUSINESS_DAYS`.
 */
final readonly class CommissionCalendar
{
    private const array MONTHS = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    /** @param list<string> $nonBusinessDays Festivos en formato AAAA-MM-DD. */
    public function __construct(
        private int $paymentDay,
        private string $shift,
        private array $nonBusinessDays,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            paymentDay: (int) ($_ENV['COMMISSION_PAYMENT_DAY'] ?? 4),
            shift: (string) ($_ENV['COMMISSION_PAYMENT_SHIFT'] ?? 'next'),
            nonBusinessDays: array_values(array_filter(array_map(trim(...), explode(',', (string) ($_ENV['NON_BUSINESS_DAYS'] ?? ''))))),
        );
    }

    /** Período (`AAAA-MM`) de una fecha. */
    public static function periodOf(DateTimeImmutable $date): string
    {
        return $date->format('Y-m');
    }

    public static function previousPeriod(string $period): string
    {
        return new DateTimeImmutable($period . '-01')->modify('-1 month')->format('Y-m');
    }

    public static function start(string $period): DateTimeImmutable
    {
        return new DateTimeImmutable($period . '-01 00:00:00');
    }

    /** El corte: último segundo del mes. */
    public static function end(string $period): DateTimeImmutable
    {
        return new DateTimeImmutable($period . '-01 00:00:00')->modify('last day of this month')->setTime(23, 59, 59);
    }

    /** «noviembre de 2026» */
    public static function label(string $period): string
    {
        $date = new DateTimeImmutable($period . '-01');

        return self::MONTHS[(int) $date->format('n')] . ' de ' . $date->format('Y');
    }

    /** Día en que se paga la comisión del período. */
    public function dueDate(string $period): DateTimeImmutable
    {
        $nextMonth = new DateTimeImmutable($period . '-01')->modify('first day of next month');
        $date      = $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('n'), $this->paymentDay);

        while (!$this->isBusinessDay($date)) {
            $date = $date->modify($this->shift === 'previous' ? '-1 day' : '+1 day');
        }

        return $date;
    }

    /**
     * ¿La lista de festivos (`NON_BUSINESS_DAYS`) tiene fechas de ese año? Se actualiza cada año: si
     * falta, la fecha de pago solo esquiva los fines de semana y el cierre lo avisa.
     */
    public function coversYear(int $year): bool
    {
        foreach ($this->nonBusinessDays as $day) {
            if (str_starts_with($day, $year . '-')) {
                return true;
            }
        }

        return false;
    }

    public function isBusinessDay(DateTimeImmutable $date): bool
    {
        return (int) $date->format('N') < 6 && !in_array($date->format('Y-m-d'), $this->nonBusinessDays, true);
    }
}
