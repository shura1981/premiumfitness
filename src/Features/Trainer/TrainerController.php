<?php

declare(strict_types=1);

namespace App\Features\Trainer;

use App\Features\Commission\CommissionCalendar;
use App\Features\Commission\CommissionMovementRepository;
use App\Features\Commission\ConversionService;
use App\Features\Commission\ReceiptStorage;
use App\Features\DietAccess\DietAccessService;
use App\Features\Redemption\RedemptionService;
use App\Features\Referral\ReferralLink;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use DateTimeImmutable;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Área del entrenador de la app web. Todas las rutas exigen `role = trainer` (RoleMiddleware) y leen
 * al entrenador del token. Contrato: specs/backend/03-api-de-premiumfitness.plan.md.
 */
class TrainerController
{
    use JsonResponder;

    private readonly TrainerAreaRepository $area;
    private readonly CommissionMovementRepository $commission;
    private readonly ConversionService $conversion;
    private readonly CommissionCalendar $calendar;

    public function __construct()
    {
        $this->area       = new TrainerAreaRepository();
        $this->commission = new CommissionMovementRepository();
        $this->conversion = new ConversionService();
        $this->calendar   = CommissionCalendar::fromEnv();
    }

    public function dashboard(Request $request, Response $response): Response
    {
        $trainerId = self::trainerId($request);
        $recent    = [
            ...array_map(static fn (array $lot): array => [
                'id' => 'p-' . $lot['id'], 'title' => $lot['title'], 'date' => $lot['accrued_at'], 'amount' => $lot['initial_points'], 'kind' => 'points',
            ], array_slice($this->area->lots($trainerId), 0, 5)),
            ...$this->area->commissionMovements($trainerId, 5),
        ];
        usort($recent, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $this->json($response, [
            'points'     => $this->area->pointSummary($trainerId) + ['next_expiry' => $this->area->nextExpiry($trainerId)],
            'commission' => $this->commissionHeader($trainerId),
            'recent'     => array_slice($recent, 0, 5),
            'diet'       => new DietAccessService()->statusFor($trainerId),
        ]);
    }

    public function referrals(Request $request, Response $response): Response
    {
        $trainerId = self::trainerId($request);
        $trainer   = $this->area->trainer($trainerId) ?? throw ApiException::forbidden();
        $base      = BusinessConfig::fromEnv()->webAppUrl;
        $linkId    = ReferralLink::encodeId($trainerId);

        return $this->json($response, [
            'coupon_code'  => $trainer['coupon_code'],
            'client_link'  => "{$base}/r/c/{$linkId}",
            'trainer_link' => "{$base}/r/t/{$linkId}",
            'clients'      => $this->area->clients($trainerId),
            'trainers'     => $this->area->referredTrainers($trainerId),
        ]);
    }

    public function points(Request $request, Response $response): Response
    {
        $trainerId = self::trainerId($request);
        $source    = (string) ($request->getQueryParams()['source'] ?? '');
        $source    = in_array($source, ['client_sale', 'trainer_referral', 'conversion'], true) ? $source : null;

        return $this->json($response, [
            'available'   => $this->area->pointSummary($trainerId)['available'],
            'next_expiry' => $this->area->nextExpiry($trainerId),
            'lots'        => $this->area->lots($trainerId, $source),
        ]);
    }

    public function commission(Request $request, Response $response): Response
    {
        $trainerId = self::trainerId($request);
        $header    = $this->commissionHeader($trainerId);
        $blocked   = $this->conversion->isBlocked();

        return $this->json($response, $header + [
            'can_convert'     => !$blocked && $header['balance'] > 0,
            'blocked_message' => $blocked ? ConversionService::BLOCKED_MESSAGE : null,
            'movements'       => $this->area->commissionMovements($trainerId),
            'settlements'     => array_map(static fn (array $row): array => [
                'id'           => (int) $row['id'],
                'period_label' => CommissionCalendar::label((string) $row['period']),
                'total_amount' => (float) $row['total_amount'],
                'status'       => $row['status'] === 'paid' ? 'pagada' : 'liquidada',
                'due_date'     => (string) $row['due_date'],
                'paid_at'      => $row['paid_at'] === null ? null : date(DATE_ATOM, (int) strtotime((string) $row['paid_at'])),
                // La app lo descarga con su token: `GET /trainer/commission/settlements/{id}/receipt`.
                'receipt_url'  => $row['receipt_path'] === null ? null : '/trainer/commission/settlements/' . $row['id'] . '/receipt',
            ], $this->area->settlements($trainerId)),
        ]);
    }

    /**
     * GET /api/trainer/commission/settlements/{id}/receipt — el comprobante de su pago. La app lo
     * pide con su token y lo abre como archivo; el de otro entrenador responde 404.
     */
    public function receipt(Request $request, Response $response, int $id): Response
    {
        $stmt = DB::getInstance()->prepare('SELECT receipt_path, receipt_mime FROM commission_settlements WHERE id = ? AND trainer_id = ?');
        $stmt->execute([$id, self::trainerId($request)]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $file = $row === false ? null : new ReceiptStorage()->absolutePath($row['receipt_path']);

        if ($file === null) {
            throw ApiException::notFound('No encontramos ese comprobante.');
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw ApiException::notFound('No encontramos ese comprobante.');
        }

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', (string) $row['receipt_mime'])
            ->withHeader('Content-Length', (string) filesize($file))
            ->withHeader('Content-Disposition', 'inline; filename="comprobante-' . $id . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Ventas del mes (spec negocio/06): resumen, días, ciudades y productos. `?period=AAAA-MM`;
     * sin él, el mes en curso.
     */
    public function sales(Request $request, Response $response): Response
    {
        $trainerId = self::trainerId($request);
        $period    = self::period($request);
        $sales     = new TrainerSalesRepository();

        return $this->json($response, [
            'period'       => $period,
            'period_label' => CommissionCalendar::label($period),
            'totals'       => $sales->totals($trainerId, $period),
            'last_7_days'  => $sales->lastSevenDays($trainerId),
            'days'         => $sales->days($trainerId, $period),
            'cities'       => $sales->cities($trainerId, $period),
            'products'     => $sales->products($trainerId, $period),
        ]);
    }

    /** Histórico de ventas mes a mes, con los totales de todos los meses. */
    public function salesHistory(Request $request, Response $response): Response
    {
        $months = array_map(static fn (array $month): array => $month + ['label' => CommissionCalendar::label($month['period'])], new TrainerSalesRepository()->months(self::trainerId($request)));

        return $this->json($response, [
            'totals' => [
                'sales'      => array_sum(array_column($months, 'sales')),
                'commission' => round(array_sum(array_column($months, 'commission')), 2),
                'amount'     => round(array_sum(array_column($months, 'amount')), 2),
            ],
            'months' => $months,
        ]);
    }

    public function conversionPreview(Request $request, Response $response): Response
    {
        return $this->json($response, $this->conversion->preview(self::trainerId($request)));
    }

    public function convert(Request $request, Response $response): Response
    {
        return $this->json($response, $this->conversion->convert(self::trainerId($request), self::idempotencyKey($request)));
    }

    public function dietAccess(Request $request, Response $response): Response
    {
        return $this->json($response, new DietAccessService()->statusFor(self::trainerId($request)));
    }

    public function redeemDietAccess(Request $request, Response $response): Response
    {
        return $this->json($response, new DietAccessService()->redeem(self::trainerId($request)));
    }

    public function redemptionPreview(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        return $this->json($response, new RedemptionService()->preview(self::trainerId($request), $body['items'] ?? null));
    }

    public function redeem(Request $request, Response $response): Response
    {
        $body    = (array) ($request->getParsedBody() ?? []);
        $orderId = new RedemptionService()->redeem(
            self::trainerId($request),
            $body['items'] ?? null,
            (int) ($body['address_id'] ?? 0),
            self::idempotencyKey($request),
        );

        return $this->json($response, ['order_id' => $orderId], 201);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{balance:float, period_label:string, closes_at:string, pays_at:string} */
    private function commissionHeader(int $trainerId): array
    {
        $period = CommissionCalendar::periodOf(new DateTimeImmutable());

        return [
            'balance'      => $this->commission->balance($trainerId),
            'period_label' => CommissionCalendar::label($period),
            'closes_at'    => CommissionCalendar::end($period)->format(DATE_ATOM),
            'pays_at'      => $this->calendar->dueDate($period)->format('Y-m-d'),
        ];
    }

    /** Mes pedido (`AAAA-MM`), sin pasar del actual; sin él, el actual. */
    private static function period(Request $request): string
    {
        $current = CommissionCalendar::periodOf(new DateTimeImmutable());
        $period  = (string) ($request->getQueryParams()['period'] ?? $current);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1 || $period > $current) {
            throw ApiException::invalid('Elige un mes válido.', errors: ['period' => 'Usa el formato AAAA-MM, hasta el mes actual.']);
        }

        return $period;
    }

    private static function trainerId(Request $request): int
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;

        return $userId > 0 ? $userId : throw ApiException::unauthorized();
    }

    /** Clave que genera la app por operación: repetir la petición no repite el efecto. */
    private static function idempotencyKey(Request $request): string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $key  = (string) ($body['idempotency_key'] ?? '');

        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) !== 1) {
            throw ApiException::invalid('No pudimos completar la operación. Inténtalo de nuevo.', errors: ['idempotency_key' => 'Clave no válida.']);
        }

        return $key;
    }
}
