<?php

declare(strict_types=1);

namespace App\Features\Trainer;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

/**
 * Lecturas del área del entrenador (spec negocio/03). No hay tabla de saldos: cada cifra se suma
 * de los lotes y movimientos con un índice que cubre la consulta.
 */
class TrainerAreaRepository extends AbstractRepository
{
    /** Días antes del vencimiento en que un lote se muestra «por vencer». */
    private const int EXPIRING_SOON_DAYS = 30;

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /**
     * @return array{available:int, client_total:int, client_available:int, referral_available:int, referral_pending:int}
     */
    public function pointSummary(int $trainerId): array
    {
        $available = $this->pairs(
            "SELECT source, SUM(remaining_points) FROM point_lots
             WHERE trainer_id = ? AND status = 'available' AND expires_at > NOW() GROUP BY source",
            [$trainerId]
        );
        $history = $this->pairs('SELECT source, SUM(initial_points) FROM point_lots WHERE trainer_id = ? GROUP BY source', [$trainerId]);

        $pending = $this->pdo->prepare('SELECT COALESCE(SUM(referral_points), 0) FROM trainer_referrals WHERE referrer_id = ? AND activated_at IS NULL');
        $pending->execute([$trainerId]);

        return [
            'available'          => array_sum($available),
            'client_total'       => $history['client_sale'] ?? 0,
            'client_available'   => $available['client_sale'] ?? 0,
            'referral_available' => $available['trainer_referral'] ?? 0,
            'referral_pending'   => (int) $pending->fetchColumn(),
        ];
    }

    /** @return array{points:int, expires_at:string}|null El próximo lote que vence. */
    public function nextExpiry(int $trainerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT SUM(remaining_points) AS points, expires_at FROM point_lots
             WHERE trainer_id = ? AND status = 'available' AND expires_at > NOW() AND remaining_points > 0
             GROUP BY DATE(expires_at) ORDER BY expires_at LIMIT 1"
        );
        $stmt->execute([$trainerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['points' => (int) $row['points'], 'expires_at' => self::iso($row['expires_at'])];
    }

    /**
     * @return list<array<string, mixed>> Contrato `PointLot`, el más reciente primero.
     */
    public function lots(int $trainerId, ?string $source = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.source, l.accrued_at, l.initial_points, l.remaining_points, l.expires_at, l.status, l.order_id,
                    ru.full_name AS referred_name
             FROM point_lots l
             LEFT JOIN trainer_referrals tr ON tr.id = l.trainer_referral_id
             LEFT JOIN users ru ON ru.id = tr.referred_id
             WHERE l.trainer_id = ?' . ($source !== null ? ' AND l.source = ?' : '') . '
             ORDER BY l.accrued_at DESC, l.id DESC
             LIMIT 200'
        );
        $stmt->execute($source !== null ? [$trainerId, $source] : [$trainerId]);

        return array_map(static function (array $lot): array {
            $expired = $lot['status'] === 'expired' || ($lot['status'] === 'available' && strtotime((string) $lot['expires_at']) <= time());

            return [
                'id'               => (int) $lot['id'],
                'source'           => $lot['source'] === 'restitution' ? 'client_sale' : (string) $lot['source'],
                'title'            => match ($lot['source']) {
                    'client_sale'      => 'Compra de un cliente' . ($lot['order_id'] !== null ? ' · pedido #' . $lot['order_id'] : ''),
                    'trainer_referral' => 'Referido: ' . ($lot['referred_name'] ?? 'entrenador'),
                    'conversion'       => 'Conversión de comisión',
                    default            => 'Puntos restituidos',
                },
                'accrued_at'       => self::iso($lot['accrued_at']),
                'initial_points'   => (int) $lot['initial_points'],
                'remaining_points' => (int) $lot['remaining_points'],
                'expires_at'       => self::iso($lot['expires_at']),
                'status'           => match (true) {
                    $expired                                                                                  => 'vencido',
                    $lot['status'] === 'used' || (int) $lot['remaining_points'] === 0                           => 'usado',
                    strtotime((string) $lot['expires_at']) <= strtotime('+' . self::EXPIRING_SOON_DAYS . ' days') => 'por_vencer',
                    default                                                                                   => 'disponible',
                },
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id:string, title:string, date:string, amount:float, kind:string}> Contrato `Movement`. */
    public function commissionMovements(int $trainerId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, amount, order_id, created_at FROM commission_movements
             WHERE trainer_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$trainerId]);

        return array_map(static fn (array $row): array => [
            'id'     => 'c-' . $row['id'],
            'title'  => match ($row['type']) {
                'sale_credit'      => 'Comisión del pedido #' . $row['order_id'],
                'conversion_debit' => 'Conversión a puntos',
                'settlement_debit' => 'Liquidación del mes',
                default            => 'Ajuste' . ($row['order_id'] !== null ? ' del pedido #' . $row['order_id'] : ''),
            },
            'date'   => self::iso($row['created_at']),
            'amount' => (float) $row['amount'],
            'kind'   => 'money',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> Liquidaciones del entrenador, la más reciente primero. */
    public function settlements(int $trainerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, p.period, s.total_amount, s.status, s.due_date, s.paid_at, s.receipt_path
             FROM commission_settlements s JOIN commission_periods p ON p.id = s.period_id
             WHERE s.trainer_id = ? ORDER BY p.period DESC LIMIT 36'
        );
        $stmt->execute([$trainerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{coupon_code:string}|null */
    public function trainer(int $trainerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT coupon_code FROM trainers WHERE user_id = ?');
        $stmt->execute([$trainerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['coupon_code' => (string) $row['coupon_code']];
    }

    /** @return list<array{id:int, full_name:string, since:string, orders_count:int}> */
    public function clients(int $trainerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.full_name, cr.linked_at,
                    (SELECT COUNT(*) FROM orders_coupons oc JOIN orders o ON o.order_id = oc.order_id
                     WHERE oc.trainer_id = cr.trainer_id AND o.user_id = u.id AND o.approved_at IS NOT NULL) AS orders_count
             FROM client_referrals cr JOIN users u ON u.id = cr.client_id
             WHERE cr.trainer_id = ? ORDER BY cr.linked_at DESC LIMIT 500'
        );
        $stmt->execute([$trainerId]);

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'full_name'    => (string) $row['full_name'],
            'since'        => self::iso($row['linked_at']),
            'orders_count' => (int) $row['orders_count'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> Entrenadores referidos, con el estado de sus puntos. */
    public function referredTrainers(int $trainerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.full_name, tr.referral_points, tr.registered_at, tr.activated_at,
                    l.remaining_points, l.status AS lot_status, l.expires_at
             FROM trainer_referrals tr
             JOIN users u ON u.id = tr.referred_id
             LEFT JOIN point_lots l ON l.trainer_referral_id = tr.id
             WHERE tr.referrer_id = ? ORDER BY tr.registered_at DESC LIMIT 500'
        );
        $stmt->execute([$trainerId]);

        return array_map(static function (array $row): array {
            $expired = $row['lot_status'] === 'expired' || ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time());

            return [
                'id'               => (int) $row['id'],
                'full_name'        => (string) $row['full_name'],
                'status'           => match (true) {
                    $row['activated_at'] === null                                     => 'pendiente',
                    $expired                                                          => 'vencido',
                    $row['lot_status'] === 'used' || (int) $row['remaining_points'] === 0 => 'usado',
                    default                                                           => 'activo',
                },
                'points'           => (int) $row['referral_points'],
                'remaining_points' => (int) ($row['remaining_points'] ?? 0),
                'registered_at'    => self::iso($row['registered_at']),
                'expires_at'       => $row['expires_at'] === null ? null : self::iso($row['expires_at']),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<mixed> $params
     * @return array<string, int>
     */
    private function pairs(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private static function iso(mixed $dateTime): string
    {
        return date(DATE_ATOM, (int) strtotime((string) $dateTime));
    }
}
