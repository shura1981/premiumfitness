<?php

declare(strict_types=1);

namespace App\Features\Commission;

use App\Features\Notification\PushOutbox;
use App\Features\Point\PointExpiry;
use App\Features\Point\PointLotRepository;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Conversión de la comisión a puntos (reglas 17 y 18; spec negocio/04, regla 4).
 *
 *   puntos recibidos = C + mín(porcentaje × P, C), redondeado al entero más cercano
 *   C = saldo de comisión (siempre el total); P = puntos vigentes del entrenador.
 *
 * Va en una transacción, con la fila del entrenador bloqueada y una clave de idempotencia:
 * repetir la solicitud devuelve la misma conversión sin convertir de nuevo.
 */
class ConversionService
{
    public const string BLOCKED_MESSAGE = 'Estamos cerrando la comisión del mes. Vuelve a intentarlo en unos minutos.';

    private readonly CommissionMovementRepository $movements;
    private readonly PointLotRepository $lots;
    private readonly BusinessConfig $business;

    public function __construct()
    {
        $this->movements = new CommissionMovementRepository();
        $this->lots      = new PointLotRepository();
        $this->business  = BusinessConfig::fromEnv();
    }

    /**
     * Desde las 00:00 del día 1 hasta que termina el cierre no se convierte: esa comisión es del
     * mes que se está liquidando (regla 7).
     */
    public function isBlocked(?DateTimeImmutable $now = null): bool
    {
        $now      = $now ?? new DateTimeImmutable();
        $previous = CommissionCalendar::previousPeriod(CommissionCalendar::periodOf($now));
        $pdo      = DB::getInstance();

        $closed = $pdo->prepare('SELECT 1 FROM commission_periods WHERE period = ?');
        $closed->execute([$previous]);

        if ($closed->fetchColumn() !== false) {
            return false;
        }

        // Si no hay comisión anterior a este mes sin liquidar, no hay nada que cerrar.
        $unsettled = $pdo->prepare('SELECT 1 FROM commission_movements WHERE settlement_id IS NULL AND created_at < ? LIMIT 1');
        $unsettled->execute([CommissionCalendar::start(CommissionCalendar::periodOf($now))->format('Y-m-d H:i:s')]);

        return $unsettled->fetchColumn() !== false;
    }

    /**
     * Lo que pasaría al convertir ahora.
     *
     * @return array<string, mixed> Contrato `ConversionPreview`.
     * @throws ApiException 409 si está bloqueada o no hay comisión
     */
    public function preview(int $trainerId): array
    {
        $this->assertOpen();

        return $this->calculate($this->movements->balance($trainerId), $this->lots->availablePoints($trainerId));
    }

    /**
     * @return array<string, mixed> Contrato `ConversionPreview`, con lo aplicado.
     * @throws ApiException
     */
    public function convert(int $trainerId, string $idempotencyKey): array
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE')->execute([$trainerId]);

            $existing = $this->findByKey($pdo, $trainerId, $idempotencyKey);

            if ($existing !== null) {
                $pdo->commit();

                return $existing;
            }

            $this->assertOpen();

            $commission = $this->movements->balance($trainerId);
            $basePoints = $this->lots->availablePoints($trainerId);
            $result     = $this->calculate($commission, $basePoints);

            $pdo->prepare(
                'INSERT INTO commission_conversions
                    (trainer_id, commission_amount, base_points, conversion_percentage, bonus_points, points_granted, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $trainerId, $commission, $basePoints, $this->business->commissionConversionPercentage,
                $result['bonus_points'], $result['points_granted'], $idempotencyKey,
            ]);
            $conversionId = (int) $pdo->lastInsertId();

            // Un débito de comisión y un crédito de puntos por conversión (`conversion_id` único en los dos).
            $this->movements->record($trainerId, 'conversion_debit', -$commission, "conversion:{$conversionId}", ['conversion_id' => $conversionId]);
            $this->lots->credit($trainerId, 'conversion', $result['points_granted'], $this->business->pointsExpirationMonths, "conversion:{$conversionId}", ['conversion_id' => $conversionId]);

            PushOutbox::enqueue(
                $trainerId,
                'commission_converted',
                'Convertiste tu comisión',
                sprintf('Recibiste %s puntos por convertir tu comisión.', number_format($result['points_granted'], 0, ',', '.')),
                ['route' => '/coach/puntos'],
                "conversion:{$conversionId}",
            );

            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @throws ApiException */
    private function assertOpen(): void
    {
        if ($this->isBlocked()) {
            throw ApiException::conflict('conversion_blocked', self::BLOCKED_MESSAGE);
        }
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException 409 si no hay comisión que convertir
     */
    private function calculate(float $commission, int $basePoints): array
    {
        if ($commission <= 0) {
            throw ApiException::conflict('nothing_to_convert', 'No tienes comisión para convertir.');
        }

        $bonus   = min($basePoints * $this->business->commissionConversionPercentage / 100, $commission);
        $granted = (int) round($commission + $bonus);

        return [
            'commission_amount' => $commission,
            'base_points'       => $basePoints,
            'bonus_points'      => (int) round($bonus),
            'points_granted'    => $granted,
            'resulting_points'  => $basePoints + $granted,
            'expires_at'        => PointExpiry::from(new DateTimeImmutable(), $this->business->pointsExpirationMonths)->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed>|null La conversión ya hecha con esa clave, si existe. */
    private function findByKey(PDO $pdo, int $trainerId, string $idempotencyKey): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT c.trainer_id, c.commission_amount, c.base_points, c.bonus_points, c.points_granted, l.expires_at
             FROM commission_conversions c
             LEFT JOIN point_lots l ON l.conversion_id = c.id
             WHERE c.idempotency_key = ?'
        );
        $stmt->execute([$idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // La clave es de quien la creó: otro entrenador no puede leer esa conversión.
        if ((int) $row['trainer_id'] !== $trainerId) {
            throw ApiException::conflict('idempotency_key_reused', 'No pudimos completar la conversión. Inténtalo de nuevo.');
        }

        return [
            'commission_amount' => (float) $row['commission_amount'],
            'base_points'       => (int) $row['base_points'],
            'bonus_points'      => (int) $row['bonus_points'],
            'points_granted'    => (int) $row['points_granted'],
            'resulting_points'  => (int) $row['base_points'] + (int) $row['points_granted'],
            'expires_at'        => date(DATE_ATOM, (int) strtotime((string) $row['expires_at'])),
        ];
    }
}
