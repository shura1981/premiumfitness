<?php

declare(strict_types=1);

namespace App\Features\CronJob;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

final class PaymentEventJobRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function enqueue(int $orderId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO payment_event_jobs (order_id)
             SELECT o.order_id
             FROM orders o
             INNER JOIN status_orders so ON so.status_id = o.status_id
             WHERE o.order_id = ?
               AND so.value = 'APROBADA'"
        );
        $stmt->execute([$orderId]);

        $job = $this->findByOrderId($orderId);

        return $job?->job_id ?? 0;
    }

    public function findById(int $jobId): ?PaymentEventJobModel
    {
        /** @var PaymentEventJobModel|false $job */
        $job = $this->fetchOne(
            'SELECT job_id, order_id, attempts, locked_at, processed_at, last_error, created_at, updated_at
             FROM payment_event_jobs
             WHERE job_id = ?',
            PaymentEventJobModel::class,
            [$jobId]
        );

        return $job === false ? null : $job;
    }

    public function findByOrderId(int $orderId): ?PaymentEventJobModel
    {
        /** @var PaymentEventJobModel|false $job */
        $job = $this->fetchOne(
            'SELECT job_id, order_id, attempts, locked_at, processed_at, last_error, created_at, updated_at
             FROM payment_event_jobs
             WHERE order_id = ?',
            PaymentEventJobModel::class,
            [$orderId]
        );

        return $job === false ? null : $job;
    }

    /**
     * @return array{data: list<PaymentEventJobModel>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function list(int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->statusFilter($status);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM payment_event_jobs {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT job_id, order_id, attempts, locked_at, processed_at, last_error, created_at, updated_at
             FROM payment_event_jobs
             {$where}
             ORDER BY job_id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        /** @var list<PaymentEventJobModel> $data */
        $data = $stmt->fetchAll(PDO::FETCH_CLASS, PaymentEventJobModel::class);

        return [
            'data'        => $data,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /** @return list<PaymentEventJobModel> */
    public function findPendingApproved(int $limit, int $maxAttempts = 5): array
    {
        $limit = max(1, min(100, $limit));

        return $this->fetchAll(
            "SELECT pej.job_id, pej.order_id, pej.attempts, pej.locked_at, pej.processed_at,
                    pej.last_error, pej.created_at, pej.updated_at
             FROM payment_event_jobs pej
             INNER JOIN orders o ON o.order_id = pej.order_id
             INNER JOIN status_orders so ON so.status_id = o.status_id
             WHERE pej.processed_at IS NULL
               AND pej.attempts < ?
               AND so.value = 'APROBADA'
               AND (pej.locked_at IS NULL OR pej.locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
             ORDER BY pej.created_at ASC
             LIMIT {$limit}",
            PaymentEventJobModel::class,
            [$maxAttempts]
        );
    }

    public function lock(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE payment_event_jobs
             SET locked_at = NOW(), updated_at = NOW()
             WHERE job_id = ?
               AND processed_at IS NULL
               AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))'
        );
        $stmt->execute([$jobId]);

        return $stmt->rowCount() === 1;
    }

    public function markProcessed(int $jobId): bool
    {
        return $this->execute(
            'UPDATE payment_event_jobs
             SET processed_at = NOW(), locked_at = NULL, last_error = NULL, updated_at = NOW()
             WHERE job_id = ?',
            [$jobId]
        );
    }

    public function markFailed(int $jobId, string $error): bool
    {
        return $this->execute(
            'UPDATE payment_event_jobs
             SET attempts = attempts + 1, locked_at = NULL, last_error = ?, updated_at = NOW()
             WHERE job_id = ?',
            [mb_substr($error, 0, 2000), $jobId]
        );
    }

    public function reset(int $jobId): bool
    {
        return $this->execute(
            'UPDATE payment_event_jobs
             SET attempts = 0, locked_at = NULL, processed_at = NULL, last_error = NULL, updated_at = NOW()
             WHERE job_id = ?',
            [$jobId]
        );
    }

    public function delete(int $jobId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM payment_event_jobs WHERE job_id = ?');
        $stmt->execute([$jobId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function statusFilter(?string $status): array
    {
        return match ($status) {
            'pending' => ['WHERE processed_at IS NULL', []],
            'done'    => ['WHERE processed_at IS NOT NULL', []],
            'failed'  => ['WHERE processed_at IS NULL AND attempts > 0', []],
            default   => ['', []],
        };
    }
}
