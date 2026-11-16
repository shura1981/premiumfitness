<?php

declare(strict_types=1);

namespace App\Features\CronJob;

use App\Features\Commission\CloseReport;
use App\Features\Commission\CommissionCalendar;
use App\Features\Commission\MonthlyCloseService;
use App\Features\DietAccess\DietAccessNoticeService;
use App\Features\Notification\PushSender;
use App\Features\Point\PointExpirationService;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Tareas programadas de PremiumFitness. Las llama el cron de cPanel con `X-Cron-Job-Token`:
 * nunca se programan dentro de la app, porque LiteSpeed levanta varios procesos y cada uno las
 * ejecutaría (spec negocio/05). Todas se pueden repetir sin duplicar efectos.
 *
 *   POST /api/cron-job/commission/close   → cierre mensual (día 1, 01:00); 409 si ya está cerrado
 *   POST /api/cron-job/points/expire      → vencimiento de lotes y avisos (diario)
 *   POST /api/cron-job/diet-access/notify → avisos del acceso a dietas por vencer o desactivado (diario)
 *   POST /api/cron-job/push/send          → envío de las push pendientes (cada minuto)
 */
class ScheduledJobsController
{
    use JsonResponder;

    /**
     * Cuerpo opcional: `{ period?: "AAAA-MM", skip_email?: bool, force?: bool }`. Sin `period` cierra el
     * mes anterior. `force` no cambia un mes cerrado: solo regenera el Excel y reenvía el correo.
     */
    public function closeCommission(Request $request, Response $response): Response
    {
        $body      = (array) ($request->getParsedBody() ?? []);
        $period    = is_string($body['period'] ?? null) && $body['period'] !== ''
            ? $body['period']
            : CommissionCalendar::previousPeriod(CommissionCalendar::periodOf(new DateTimeImmutable()));
        $skipEmail = filter_var($body['skip_email'] ?? false, FILTER_VALIDATE_BOOL);
        $force     = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOL);
        $report    = new CloseReport();

        try {
            $result = new MonthlyCloseService()->close($period);
        } catch (ApiException $e) {
            if (!$force || $e->status() !== 409) {
                throw $e;
            }

            $result = ['period_id' => $this->periodId($period), 'period' => $period, 'regenerated' => true];
        }

        // El cierre ya quedó guardado: un fallo del Excel o del correo no lo deshace y se puede repetir con `force`.
        $warnings = [];

        // La comisión se paga el mes siguiente: si faltan los festivos de ese año, la fecha puede caer en uno.
        $paymentYear = (int) new DateTimeImmutable($period . '-01')->modify('+1 month')->format('Y');

        if (!CommissionCalendar::fromEnv()->coversYear($paymentYear)) {
            $warnings[] = "holidays_missing_{$paymentYear}";
        }

        try {
            $report->generate($result['period_id']);
        } catch (Throwable $e) {
            $warnings[] = 'report_failed';
        }

        if (!$skipEmail) {
            try {
                $result['emailed_to'] = count($report->sendEmail($result['period_id']));
            } catch (Throwable $e) {
                $warnings[] = 'email_failed';
            }
        }

        return $this->json($response, $result + ['warnings' => $warnings]);
    }

    public function expirePoints(Request $request, Response $response): Response
    {
        return $this->json($response, new PointExpirationService()->run());
    }

    public function notifyDietAccess(Request $request, Response $response): Response
    {
        return $this->json($response, new DietAccessNoticeService()->run());
    }

    public function sendPush(Request $request, Response $response): Response
    {
        return $this->json($response, new PushSender()->sendPending());
    }

    private function periodId(string $period): int
    {
        $stmt = \App\Infrastructure\Database\DB::getInstance()->prepare('SELECT id FROM commission_periods WHERE period = ?');
        $stmt->execute([$period]);
        $id = $stmt->fetchColumn();

        return $id === false ? throw ApiException::notFound('Ese período no existe.') : (int) $id;
    }
}
