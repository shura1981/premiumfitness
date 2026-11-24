<?php

declare(strict_types=1);

namespace App\Features\Reports;

use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Reporte de canjes con puntos (spec negocio/07), solo para gerentes:
 *
 *   GET /api/admin/reports/redemptions?date_from=&date_to=          → totales, días, productos, entrenadores y lista
 *   GET /api/admin/reports/redemptions/export?date_from=&date_to=   → una fila por producto canjeado, para Excel
 *
 * Sin fechas, los últimos 30 días.
 */
final class RedemptionReportController
{
    use JsonResponder;

    private readonly RedemptionReportRepository $report;

    public function __construct()
    {
        $this->report = new RedemptionReportRepository();
    }

    public function summary(Request $request, Response $response): Response
    {
        [$from, $to] = self::range($request);

        return $this->json($response, [
            'date_from'   => $from,
            'date_to'     => $to,
            'totals'      => $this->report->totals($from, $to),
            'days'        => $this->report->days($from, $to),
            'products'    => $this->report->products($from, $to),
            'trainers'    => $this->report->trainers($from, $to),
            'redemptions' => $this->report->redemptions($from, $to),
        ]);
    }

    public function export(Request $request, Response $response): Response
    {
        [$from, $to] = self::range($request);

        return $this->json($response, $this->report->exportRows($from, $to));
    }

    /**
     * @return array{0:string, 1:string}
     * @throws ApiException 422 si una fecha no es válida o «desde» es mayor que «hasta».
     */
    private static function range(Request $request): array
    {
        $query = $request->getQueryParams();
        $today = new DateTimeImmutable('today');
        $from  = self::date($query['date_from'] ?? null) ?? $today->modify('-30 days');
        $to    = self::date($query['date_to'] ?? null) ?? $today;

        if ($from > $to) {
            throw ApiException::invalid('La fecha «desde» no puede ser mayor que la fecha «hasta».', errors: ['date_from' => 'Revisa el rango de fechas.']);
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /** @throws ApiException 422 si llega una fecha que no es AAAA-MM-DD. */
    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ApiException::invalid('La fecha no es válida. Usa el formato AAAA-MM-DD.', errors: ['date' => 'Fecha no válida.']);
        }

        return $date;
    }
}
