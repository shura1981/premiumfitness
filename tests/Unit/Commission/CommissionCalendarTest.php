<?php

declare(strict_types=1);

use App\Features\Commission\CommissionCalendar;

/*
 | Fechas de la comisión (spec negocio/04, reglas 7 y 10).
 */

it('el corte es el último segundo del mes', function (string $period, string $expected): void {
    expect(CommissionCalendar::end($period)->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    ['2026-11', '2026-11-30 23:59:59'],
    ['2026-12', '2026-12-31 23:59:59'],
    ['2027-02', '2027-02-28 23:59:59'],
    ['2028-02', '2028-02-29 23:59:59'],
]);

it('paga el día 4 del mes siguiente o el siguiente día hábil', function (string $period, string $expected): void {
    $calendar = new CommissionCalendar(4, 'next', ['2027-01-04']);

    expect($calendar->dueDate($period)->format('Y-m-d'))->toBe($expected);
})->with([
    'viernes 4 dic 2026: se paga ese día'              => ['2026-11', '2026-12-04'],
    'domingo 4 oct 2026: pasa al lunes 5 (spec)'        => ['2026-09', '2026-10-05'],
    'sábado 4 jul 2026: pasa al lunes 6'                => ['2026-06', '2026-07-06'],
    'lunes 4 ene 2027 festivo: pasa al martes 5'        => ['2026-12', '2027-01-05'],
    'sábado 4 dic 2027: pasa al lunes 6 (spec)'         => ['2027-11', '2027-12-06'],
]);

it('sabe si la lista de festivos tiene fechas de un año', function (): void {
    $calendar = new CommissionCalendar(4, 'next', ['2026-12-25', '2027-01-01']);

    expect($calendar->coversYear(2026))->toBeTrue()
        ->and($calendar->coversYear(2027))->toBeTrue()
        ->and($calendar->coversYear(2028))->toBeFalse();
});

it('con el corrimiento hacia atrás paga el día hábil anterior', function (): void {
    expect(new CommissionCalendar(4, 'previous', [])->dueDate('2026-09')->format('Y-m-d'))->toBe('2026-10-02');
});

it('nombra el período en español y conoce el anterior', function (): void {
    expect(CommissionCalendar::label('2026-11'))->toBe('noviembre de 2026')
        ->and(CommissionCalendar::previousPeriod('2027-01'))->toBe('2026-12')
        ->and(CommissionCalendar::periodOf(new DateTimeImmutable('2026-11-15 10:00:00')))->toBe('2026-11');
});
