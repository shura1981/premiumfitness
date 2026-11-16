<?php

declare(strict_types=1);

namespace App\Features\DietAccess;

use App\Features\Commission\CommissionCalendar;
use App\Features\Notification\PushOutbox;
use App\Features\Point\NotEnoughPointsException;
use App\Features\Point\PointLotRepository;
use App\Features\Point\PointSpender;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Acceso del entrenador a la app de dietas (spec integraciones/02). El estado se calcula en cada
 * consulta; en el mes M tiene acceso si:
 *   - está en sus días gratis desde el primer ingreso (`trial`);
 *   - en M − 1 ganó por compras de sus clientes al menos la meta de puntos (`sales_goal`); o
 *   - canjeó puntos por el mes M (`points`).
 * La meta, el costo y los días gratis son variables de entorno; cada acceso guarda lo que aplicó.
 */
class DietAccessService
{
    private readonly PointLotRepository $lots;
    private readonly int $pointsGoal;
    private readonly int $pointsCost;
    private readonly int $freeDays;
    private readonly string $renewUrl;

    public function __construct()
    {
        $this->lots       = new PointLotRepository();
        $this->pointsGoal = (int) $_ENV['DIET_ACCESS_POINTS_GOAL'];
        $this->pointsCost = (int) $_ENV['DIET_ACCESS_POINTS_COST'];
        $this->freeDays   = (int) $_ENV['DIET_FREE_DAYS'];
        $this->renewUrl   = (string) $_ENV['DIET_ACCESS_RENEW_URL'];
    }

    /**
     * Estado del acceso para la app web (contrato `DietAccess`).
     *
     * @return array<string, mixed>
     */
    public function statusFor(int $trainerId): array
    {
        $now    = new DateTimeImmutable();
        $period = CommissionCalendar::periodOf($now);
        $access = $this->evaluate($trainerId, $now);

        $available = $this->lots->availablePoints($trainerId);
        $monthEnd  = CommissionCalendar::end($period);
        // Se puede canjear si no lo cubre la meta ni un canje, y el mes gratis no llega a fin de mes.
        $coveredAllMonth = in_array($access['reason'], ['sales_goal', 'points'], true)
            || ($access['reason'] === 'trial' && $access['valid_until'] >= $monthEnd);

        return [
            'has_access'      => $access['has_access'],
            'reason'          => $access['reason'],
            'trial_available' => $access['first_access_at'] === null,
            'period'          => $period,
            'valid_until'     => $access['has_access'] ? $access['valid_until']->format(DATE_ATOM) : null,
            'free_until'      => $access['free_until']?->format(DATE_ATOM),
            'goal'            => [
                'points_required'   => $this->pointsGoal,
                'points_last_month' => $this->clientSalePoints($trainerId, CommissionCalendar::previousPeriod($period)),
                'points_this_month' => $this->clientSalePoints($trainerId, $period),
            ],
            'redeem'          => [
                'available'        => !$coveredAllMonth && $access['first_access_at'] !== null && $available >= $this->pointsCost,
                'points_cost'      => $this->pointsCost,
                'points_available' => $available,
            ],
            'checked_at'      => $now->format(DATE_ATOM),
        ];
    }

    /**
     * Canjea los puntos por el mes en curso. Un solo canje por entrenador y mes.
     *
     * @return array<string, mixed> El estado después del canje.
     * @throws ApiException
     */
    public function redeem(int $trainerId): array
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE')->execute([$trainerId]);

            $now    = new DateTimeImmutable();
            $period = CommissionCalendar::periodOf($now);
            $access = $this->evaluate($trainerId, $now);

            if ($access['reason'] === 'points') {
                // Ya canjeado este mes: repetir la solicitud no cobra de nuevo.
                $pdo->commit();

                return $this->statusFor($trainerId);
            }

            $monthEnd = CommissionCalendar::end($period);

            if ($access['reason'] === 'sales_goal' || ($access['reason'] === 'trial' && $access['valid_until'] >= $monthEnd)) {
                throw ApiException::conflict('already_active', 'Ya tienes acceso a la app de dietas este mes.');
            }

            $pdo->prepare(
                "INSERT INTO diet_access_periods (trainer_id, period, source, points_goal, points_spent, valid_from, valid_to)
                 VALUES (?, ?, 'points', ?, ?, NOW(), ?)"
            )->execute([$trainerId, $period, $this->pointsGoal, $this->pointsCost, $monthEnd->format('Y-m-d H:i:s')]);
            $periodId = (int) $pdo->lastInsertId();

            try {
                PointSpender::spend($trainerId, $this->pointsCost, 'redemption', "diet:{$periodId}", ['diet_access_period_id' => $periodId]);
            } catch (NotEnoughPointsException) {
                throw ApiException::conflict('not_enough_points', 'No tienes puntos suficientes para canjear el acceso de este mes.');
            }

            PushOutbox::enqueue(
                $trainerId,
                'diet_access_redeemed',
                'Acceso a dietas activado',
                'Canjeaste tus puntos: tienes la app de dietas hasta fin de mes.',
                ['route' => '/entrenador'],
                "diet:{$periodId}",
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $this->statusFor($trainerId);
    }

    /**
     * Respuesta para el backend de dietas, por documento (servidor a servidor). La primera consulta
     * de un entrenador inicia sus días gratis. No incluye nombre, puntos ni saldos.
     *
     * @return array<string, mixed>
     */
    public function checkByDocument(string $document): array
    {
        $now      = new DateTimeImmutable();
        $document = preg_replace('/[^A-Za-z0-9]/', '', $document) ?? '';
        $response = [
            'document'    => $document,
            'has_access'  => false,
            'status'      => 'not_found',
            'reason'      => 'none',
            'valid_until' => $now->setTime(23, 59, 59)->format(DATE_ATOM),
            'message'     => 'No encontramos un entrenador de PremiumFitness con este documento.',
            'renew_url'   => null,
            'checked_at'  => $now->format(DATE_ATOM),
        ];

        $pdo  = DB::getInstance();
        $stmt = $pdo->prepare(
            "SELECT u.id FROM users u JOIN trainers t ON t.user_id = u.id
             WHERE u.document_id = ? AND u.role = 'trainer' AND u.status = 'active'"
        );
        $stmt->execute([$document]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($document === '' || $ids === []) {
            return $response;
        }

        if (count($ids) > 1) {
            return ['status' => 'ambiguous', 'message' => 'Hay más de una cuenta con este documento. Escríbenos para revisarla.'] + $response;
        }

        $trainerId = (int) $ids[0];

        // Primer ingreso: desde aquí corren los días gratis. Repetir la consulta no lo cambia.
        $pdo->prepare('UPDATE trainers SET diet_first_access_at = NOW() WHERE user_id = ? AND diet_first_access_at IS NULL')
            ->execute([$trainerId]);

        $access = $this->evaluate($trainerId, $now);

        if (!$access['has_access']) {
            return [
                'status'    => 'inactive',
                'message'   => 'Tu acceso a la app de dietas no está activo este mes. Puedes recuperarlo en la app de PremiumFitness.',
                'renew_url' => $this->renewUrl,
            ] + $response;
        }

        return [
            'has_access'  => true,
            'status'      => 'active',
            'reason'      => $access['reason'],
            'valid_until' => $access['valid_until']->format(DATE_ATOM),
            'message'     => match ($access['reason']) {
                'trial'      => 'Estás en tu mes gratis de la app de dietas.',
                'sales_goal' => 'Tienes acceso por cumplir tu meta de ' . explode(' ', CommissionCalendar::label(CommissionCalendar::previousPeriod(CommissionCalendar::periodOf($now))))[0] . '.',
                default      => 'Tienes acceso por canjear tus puntos este mes.',
            },
        ] + $response;
    }

    /**
     * El acceso del entrenador en un momento dado, para los avisos.
     *
     * @return array{has_access:bool, reason:string, valid_until:DateTimeImmutable}
     */
    public function accessAt(int $trainerId, DateTimeImmutable $moment): array
    {
        $access = $this->evaluate($trainerId, $moment);

        return ['has_access' => $access['has_access'], 'reason' => $access['reason'], 'valid_until' => $access['valid_until']];
    }

    /**
     * @return array{has_access:bool, reason:string, valid_until:DateTimeImmutable, free_until:?DateTimeImmutable, first_access_at:?string}
     */
    private function evaluate(int $trainerId, DateTimeImmutable $now): array
    {
        $period   = CommissionCalendar::periodOf($now);
        $monthEnd = CommissionCalendar::end($period);

        $stmt = DB::getInstance()->prepare('SELECT diet_first_access_at FROM trainers WHERE user_id = ?');
        $stmt->execute([$trainerId]);
        $firstAccess = $stmt->fetchColumn();
        $firstAccess = $firstAccess === false || $firstAccess === null ? null : (string) $firstAccess;
        $freeUntil   = $firstAccess === null ? null : new DateTimeImmutable($firstAccess)->modify("+{$this->freeDays} days");

        $base = ['free_until' => $freeUntil, 'first_access_at' => $firstAccess];

        if ($this->clientSalePoints($trainerId, CommissionCalendar::previousPeriod($period)) >= $this->pointsGoal) {
            return ['has_access' => true, 'reason' => 'sales_goal', 'valid_until' => $monthEnd] + $base;
        }

        $redeemed = DB::getInstance()->prepare("SELECT 1 FROM diet_access_periods WHERE trainer_id = ? AND period = ? AND source = 'points'");
        $redeemed->execute([$trainerId, $period]);

        if ($redeemed->fetchColumn() !== false) {
            return ['has_access' => true, 'reason' => 'points', 'valid_until' => $monthEnd] + $base;
        }

        if ($freeUntil !== null && $freeUntil > $now) {
            return ['has_access' => true, 'reason' => 'trial', 'valid_until' => $freeUntil] + $base;
        }

        return ['has_access' => false, 'reason' => 'none', 'valid_until' => $now->setTime(23, 59, 59)] + $base;
    }

    /** Puntos ganados por compras de clientes en un mes: lo que cuenta para la meta. */
    private function clientSalePoints(int $trainerId, string $period): int
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT COALESCE(SUM(initial_points), 0) FROM point_lots
             WHERE trainer_id = ? AND source = 'client_sale' AND accrued_at >= ? AND accrued_at <= ?"
        );
        $stmt->execute([
            $trainerId,
            CommissionCalendar::start($period)->format('Y-m-d H:i:s'),
            CommissionCalendar::end($period)->format('Y-m-d H:i:s'),
        ]);

        return (int) $stmt->fetchColumn();
    }
}
