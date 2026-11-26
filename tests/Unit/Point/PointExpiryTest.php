<?php

declare(strict_types=1);

use App\Features\Point\PointExpiry;

it('vence a los meses indicados, al terminar ese día', function (string $accruedAt, string $expected): void {
    expect(PointExpiry::from(new DateTimeImmutable($accruedAt), 6)->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'compra a las 3:30 p. m.'       => ['2026-12-10 15:30:00', '2027-06-10 23:59:59'],
    'compra a la medianoche'        => ['2026-12-10 00:00:00', '2027-06-10 23:59:59'],
    '31 de agosto, año normal'      => ['2026-08-31 10:00:00', '2027-02-28 23:59:59'],
    '31 de agosto, año bisiesto'    => ['2027-08-31 10:00:00', '2028-02-29 23:59:59'],
    'cruza el año'                  => ['2026-11-30 08:00:00', '2027-05-30 23:59:59'],
]);
