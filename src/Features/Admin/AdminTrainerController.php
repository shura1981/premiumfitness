<?php

declare(strict_types=1);

namespace App\Features\Admin;

use App\Features\Audit\AuditLog;
use App\Features\Auth\RefreshToken\RefreshTokenRepository;
use App\Features\Commission\CommissionMovementRepository;
use App\Features\Trainer\TrainerAreaRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Entrenadores en el admin (contrato: specs/backend/03-api-de-premiumfitness.plan.md).
 *
 *   GET   /api/admin/trainers?search=&status=&page=&per_page=
 *   GET   /api/admin/trainers/{id}
 *   PATCH /api/admin/trainers/{id}/status   → suspender o reactivar, con motivo
 */
class AdminTrainerController
{
    use JsonResponder;

    private const string ROW = "
        SELECT u.id, u.full_name, u.num_cell AS phone, u.email, u.document_id, t.coupon_code, u.status, t.joined_at,
               (SELECT COUNT(*) FROM client_referrals cr WHERE cr.trainer_id = u.id) AS clients_count,
               (SELECT COALESCE(SUM(l.remaining_points), 0) FROM point_lots l
                 WHERE l.trainer_id = u.id AND l.status = 'available' AND l.expires_at > NOW()) AS points_available,
               (SELECT COALESCE(SUM(m.amount), 0) FROM commission_movements m WHERE m.trainer_id = u.id) AS commission_balance
        FROM trainers t JOIN users u ON u.id = t.user_id";

    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::getInstance();
    }

    public function list(Request $request, Response $response): Response
    {
        [$page, $perPage, $offset] = AdminRequest::pagination($request);
        $query  = $request->getQueryParams();
        $where  = ["u.role = 'trainer'"];
        $params = [];

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like    = '%' . addcslashes(mb_substr($search, 0, 60), '%_\\') . '%';
            $where[] = '(u.full_name LIKE ? OR u.num_cell LIKE ? OR u.email LIKE ? OR u.document_id LIKE ? OR t.coupon_code LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        if (in_array($query['status'] ?? '', ['pending', 'active', 'suspended'], true)) {
            $where[]  = 'u.status = ?';
            $params[] = $query['status'];
        }

        $condition = ' WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM trainers t JOIN users u ON u.id = t.user_id' . $condition);
        $count->execute($params);

        $stmt = $this->pdo->prepare(self::ROW . $condition . " ORDER BY t.joined_at DESC, u.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);

        return $this->json($response, [
            'data'     => array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total'    => (int) $count->fetchColumn(),
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public function show(Request $request, Response $response, int $id): Response
    {
        $row  = $this->find($id);
        $area = new TrainerAreaRepository();

        $referrer = $this->pdo->prepare('SELECT u.id, u.full_name FROM trainer_referrals tr JOIN users u ON u.id = tr.referrer_id WHERE tr.referred_id = ?');
        $referrer->execute([$id]);
        $referrer = $referrer->fetch(PDO::FETCH_ASSOC);

        $clients = $this->pdo->prepare(
            'SELECT u.id, u.full_name, u.num_cell AS phone, cr.linked_at AS since,
                    COUNT(o.order_id) AS orders_count, COALESCE(SUM(o.amount - o.shipping_amount), 0) AS total_paid
             FROM client_referrals cr
             JOIN users u ON u.id = cr.client_id
             LEFT JOIN orders_coupons oc ON oc.trainer_id = cr.trainer_id
             LEFT JOIN orders o ON o.order_id = oc.order_id AND o.user_id = u.id AND o.approved_at IS NOT NULL
             WHERE cr.trainer_id = ?
             GROUP BY u.id, u.full_name, u.num_cell, cr.linked_at
             ORDER BY cr.linked_at DESC LIMIT 200'
        );
        $clients->execute([$id]);

        $statusChange = $this->pdo->prepare(
            "SELECT data, created_at FROM audit_logs WHERE entity = 'trainer' AND entity_id = ? AND action = 'trainer.status' ORDER BY id DESC LIMIT 1"
        );
        $statusChange->execute([$id]);
        $statusChange = $statusChange->fetch(PDO::FETCH_ASSOC);
        /** @var array{reason?:string} $changeData */
        $changeData = $statusChange === false ? [] : (json_decode((string) $statusChange['data'], true) ?: []);

        $summary = $area->pointSummary($id);

        return $this->json($response, self::row($row) + [
            'referrer'          => $referrer === false ? null : ['id' => (int) $referrer['id'], 'full_name' => (string) $referrer['full_name']],
            'status_reason'     => $changeData['reason'] ?? null,
            'status_changed_at' => $statusChange === false ? null : date(DATE_ATOM, (int) strtotime((string) $statusChange['created_at'])),
            'points'            => [
                'available'          => $summary['available'],
                'client_available'   => $summary['client_available'],
                'referral_available' => $summary['referral_available'],
                'referral_pending'   => $summary['referral_pending'],
            ],
            'clients'           => array_map(static fn (array $client): array => [
                'id'           => (int) $client['id'],
                'full_name'    => (string) $client['full_name'],
                'phone'        => (string) $client['phone'],
                'since'        => date(DATE_ATOM, (int) strtotime((string) $client['since'])),
                'orders_count' => (int) $client['orders_count'],
                'total_paid'   => (float) $client['total_paid'],
            ], $clients->fetchAll(PDO::FETCH_ASSOC)),
            'referred_trainers' => array_map(static fn (array $trainer): array => [
                'id' => $trainer['id'], 'full_name' => $trainer['full_name'], 'status' => $trainer['status'],
                'points' => $trainer['points'], 'registered_at' => $trainer['registered_at'],
            ], $area->referredTrainers($id)),
            'movements'         => $this->movements($area, $id),
            'settlements'       => array_map(static fn (array $settlement): array => [
                'id'           => (int) $settlement['id'],
                'period_id'    => (int) $settlement['period_id'],
                'period'       => (string) $settlement['period'],
                'total_amount' => (float) $settlement['total_amount'],
                'status'       => (string) $settlement['status'],
                'due_date'     => (string) $settlement['due_date'],
                'paid_at'      => $settlement['paid_at'] === null ? null : date(DATE_ATOM, (int) strtotime((string) $settlement['paid_at'])),
                'has_receipt'  => $settlement['receipt_path'] !== null,
                'is_overdue'   => $settlement['status'] !== 'paid' && (string) $settlement['due_date'] < date('Y-m-d'),
            ], $this->settlements($id)),
        ]);
    }

    public function updateStatus(Request $request, Response $response, int $id): Response
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $status = (string) ($body['status'] ?? '');
        $reason = trim((string) ($body['reason'] ?? ''));

        if (!in_array($status, ['active', 'suspended'], true) || mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: array_filter([
                'status' => in_array($status, ['active', 'suspended'], true) ? null : 'Elige suspender o reactivar.',
                'reason' => mb_strlen($reason) < 5 || mb_strlen($reason) > 500 ? 'Escribe el motivo (entre 5 y 500 caracteres).' : null,
            ]));
        }

        $current = (string) $this->find($id)['status'];

        // Una cuenta sin verificar solo se activa verificando su celular.
        if ($current === 'pending') {
            throw ApiException::conflict('phone_not_verified', 'Esta cuenta aún no ha verificado su celular.');
        }

        if ($current !== $status) {
            $this->pdo->beginTransaction();

            try {
                $this->pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);

                if ($status === 'suspended') {
                    // Cierra sus sesiones: no espera a que venza el access token para dejar de renovar.
                    new RefreshTokenRepository()->revokeAllForUser($id);
                }

                AuditLog::byAgent(AdminRequest::agentId($request), 'trainer.status', 'trainer', $id, ['from' => $current, 'to' => $status, 'reason' => $reason]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        return $this->json($response, self::row($this->find($id)));
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $stmt = $this->pdo->prepare(self::ROW . " WHERE u.id = ? AND u.role = 'trainer'");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? throw ApiException::notFound('No encontramos ese entrenador.') : $row;
    }

    /** @return list<array<string, mixed>> */
    private function settlements(int $trainerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.period_id, p.period, s.total_amount, s.status, s.due_date, s.paid_at, s.receipt_path
             FROM commission_settlements s JOIN commission_periods p ON p.id = s.period_id
             WHERE s.trainer_id = ? ORDER BY p.period DESC LIMIT 36'
        );
        $stmt->execute([$trainerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> Los últimos movimientos de puntos y de comisión, mezclados por fecha. */
    private function movements(TrainerAreaRepository $area, int $trainerId): array
    {
        $movements = [
            ...array_map(static fn (array $lot): array => [
                'id' => 'p-' . $lot['id'], 'title' => $lot['title'], 'date' => $lot['accrued_at'], 'amount' => $lot['initial_points'], 'kind' => 'points',
            ], array_slice($area->lots($trainerId), 0, 50)),
            ...$area->commissionMovements($trainerId, 50),
        ];
        usort($movements, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return array_slice($movements, 0, 50);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> Contrato `TrainerRow`.
     */
    private static function row(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'full_name'          => (string) $row['full_name'],
            'phone'              => (string) $row['phone'],
            'email'              => (string) $row['email'],
            'document_id'        => (string) $row['document_id'],
            'coupon_code'        => (string) $row['coupon_code'],
            'status'             => (string) $row['status'],
            'joined_at'          => date(DATE_ATOM, (int) strtotime((string) $row['joined_at'])),
            'clients_count'      => (int) $row['clients_count'],
            'points_available'   => (int) $row['points_available'],
            'commission_balance' => (float) $row['commission_balance'],
        ];
    }
}
