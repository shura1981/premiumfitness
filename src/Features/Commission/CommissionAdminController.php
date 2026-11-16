<?php

declare(strict_types=1);

namespace App\Features\Commission;

use App\Features\Admin\AdminRequest;
use App\Features\Audit\AuditLog;
use App\Features\Notification\PushOutbox;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Throwable;

/**
 * Cierre mensual y pago de la comisión en el admin (spec negocio/04, regla 10, y negocio/05).
 *
 *   GET  /api/admin/commission/periods
 *   GET  /api/admin/commission/periods/{id}
 *   GET  /api/admin/commission/periods/{id}/report        → el Excel del cierre
 *   POST /api/admin/commission/settlements/{id}/pay       → registra el pago con su comprobante
 *   POST /api/admin/commission/settlements/{id}/payment   → corrige un pago mal registrado
 *   GET  /api/admin/commission/settlements/{id}/receipt   → el comprobante
 */
class CommissionAdminController
{
    use JsonResponder;

    private readonly PDO $pdo;
    private readonly ReceiptStorage $receipts;

    public function __construct()
    {
        $this->pdo      = DB::getInstance();
        $this->receipts = new ReceiptStorage();
    }

    public function periods(Request $request, Response $response): Response
    {
        $rows = $this->pdo->query(self::periodSql() . ' GROUP BY p.id ORDER BY p.period DESC LIMIT 120')->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, ['data' => array_map(self::presentPeriod(...), $rows)]);
    }

    public function period(Request $request, Response $response, int $id): Response
    {
        $stmt = $this->pdo->prepare(self::periodSql() . ' WHERE p.id = ? GROUP BY p.id');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw ApiException::notFound('No encontramos ese cierre.');
        }

        $settlements = $this->pdo->prepare(self::settlementSql() . ' WHERE s.period_id = ? ORDER BY u.full_name');
        $settlements->execute([$id]);

        return $this->json($response, self::presentPeriod($row) + [
            'settlements' => array_map(self::presentSettlement(...), $settlements->fetchAll(PDO::FETCH_ASSOC)),
        ]);
    }

    public function report(Request $request, Response $response, int $id): Response
    {
        $period = $this->pdo->prepare('SELECT period FROM commission_periods WHERE id = ?');
        $period->execute([$id]);
        $name = $period->fetchColumn();

        if ($name === false) {
            throw ApiException::notFound('No encontramos ese cierre.');
        }

        $report = new CloseReport();
        // Si el Excel no se generó al cerrar (o se perdió), se genera ahora: no cambia ningún importe.
        $file = $report->pathOf($id) ?? $report->generate($id);

        AuditLog::byAgent(AdminRequest::agentId($request), 'commission.report_download', 'commission_period', $id);

        return $this->file($response, $file, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "cierre-comision-{$name}.xlsx", 'attachment');
    }

    public function pay(Request $request, Response $response, int $id): Response
    {
        return $this->registerPayment($request, $response, $id, correction: false);
    }

    public function correctPayment(Request $request, Response $response, int $id): Response
    {
        return $this->registerPayment($request, $response, $id, correction: true);
    }

    public function receipt(Request $request, Response $response, int $id): Response
    {
        $settlement = $this->findSettlement($id);
        $file       = $this->receipts->absolutePath($settlement['receipt_path']);

        if ($file === null) {
            throw ApiException::notFound('Esta liquidación no tiene comprobante.');
        }

        return $this->file($response, $file, (string) $settlement['receipt_mime'], 'comprobante-' . $id . '.' . pathinfo($file, PATHINFO_EXTENSION), 'inline');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function registerPayment(Request $request, Response $response, int $id, bool $correction): Response
    {
        $body      = (array) ($request->getParsedBody() ?? []);
        $reference = trim((string) ($body['payment_reference'] ?? ''));
        $paidAt    = strtotime((string) ($body['paid_at'] ?? ''));
        $errors    = array_filter([
            'payment_reference' => $reference === '' || mb_strlen($reference) > 100 ? 'Escribe la referencia de la transacción.' : null,
            'paid_at'           => $paidAt === false || $paidAt > strtotime('tomorrow') ? 'Indica la fecha del pago; no puede ser futura.' : null,
        ]);

        if ($errors !== []) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: $errors);
        }

        $settlement = $this->findSettlement($id);

        if (!$correction && $settlement['status'] === 'paid') {
            throw ApiException::conflict('already_paid', 'Esta liquidación ya tiene un pago registrado. Para cambiarlo, corrige el pago.');
        }

        if ($correction && $settlement['status'] !== 'paid') {
            throw ApiException::conflict('not_paid', 'Esta liquidación aún no tiene un pago registrado.');
        }

        $file = $request->getUploadedFiles()['receipt'] ?? null;
        // Al corregir, el comprobante es opcional: se puede cambiar solo la fecha o la referencia.
        $stored = $correction && ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE)
            ? null
            : $this->receipts->store($id, $file);

        $agentId = AdminRequest::agentId($request);
        $this->pdo->beginTransaction();

        try {
            // Bloquea la fila: dos personas no registran el mismo pago a la vez.
            $lock = $this->pdo->prepare('SELECT status FROM commission_settlements WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);

            if (!$correction && $lock->fetchColumn() === 'paid') {
                throw ApiException::conflict('already_paid', 'Esta liquidación ya tiene un pago registrado. Para cambiarlo, corrige el pago.');
            }

            $this->pdo->prepare(
                "UPDATE commission_settlements
                 SET status = 'paid', paid_at = ?, payment_reference = ?, paid_by_agent_id = ?,
                     receipt_path = COALESCE(?, receipt_path), receipt_mime = COALESCE(?, receipt_mime)
                 WHERE id = ?"
            )->execute([date('Y-m-d H:i:s', (int) $paidAt), $reference, $agentId, $stored['path'] ?? null, $stored['mime'] ?? null, $id]);

            AuditLog::byAgent($agentId, $correction ? 'commission.payment_corrected' : 'commission.paid', 'commission_settlement', $id, [
                'payment_reference' => $reference,
                'paid_at'           => date('Y-m-d', (int) $paidAt),
                'receipt'           => $stored['path'] ?? null,
                'previous'          => $correction ? [
                    'payment_reference' => $settlement['payment_reference'],
                    'paid_at'           => $settlement['paid_at'],
                    'receipt'           => $settlement['receipt_path'],
                ] : null,
            ]);

            if (!$correction) {
                PushOutbox::enqueue(
                    (int) $settlement['trainer_id'],
                    'commission_paid',
                    'Te pagamos tu comisión',
                    sprintf(
                        'Te pagamos $%s de la comisión de %s.',
                        number_format((float) $settlement['total_amount'], 0, ',', '.'),
                        explode(' ', CommissionCalendar::label((string) $settlement['period']))[0],
                    ),
                    ['route' => '/entrenador/comision', 'settlement_id' => $id],
                    "settlement:{$id}:paid",
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // El archivo recién subido no queda huérfano si la operación falla.
            $this->receipts->delete($stored['path'] ?? null);

            throw $e;
        }

        // El comprobante anterior se conserva en disco: la corrección queda auditada, no se borra.
        $stmt = $this->pdo->prepare(self::settlementSql() . ' WHERE s.id = ?');
        $stmt->execute([$id]);

        return $this->json($response, self::presentSettlement((array) $stmt->fetch(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed> */
    private function findSettlement(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, p.period FROM commission_settlements s JOIN commission_periods p ON p.id = s.period_id WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? throw ApiException::notFound('No encontramos esa liquidación.') : $row;
    }

    private function file(Response $response, string $path, string $mime, string $downloadName, string $disposition): Response
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ApiException::notFound('No encontramos el archivo.');
        }

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', sprintf('%s; filename="%s"', $disposition, $downloadName))
            // Un archivo subido nunca se interpreta como otra cosa en el navegador.
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private static function periodSql(): string
    {
        return "SELECT p.*, MIN(s.due_date) AS due_date,
                       COALESCE(SUM(s.status = 'paid'), 0) AS paid_count,
                       COALESCE(SUM(IF(s.status = 'paid', s.total_amount, 0)), 0) AS paid_amount
                FROM commission_periods p LEFT JOIN commission_settlements s ON s.period_id = p.id";
    }

    private static function settlementSql(): string
    {
        return 'SELECT s.*, u.full_name AS trainer_name, u.document_id, u.num_cell AS phone, e.usuario AS paid_by
                FROM commission_settlements s
                JOIN users u ON u.id = s.trainer_id
                LEFT JOIN ' . IntranetTables::fromEnv()->employees . ' e ON e.id_usuario = s.paid_by_agent_id';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> Contrato `CommissionPeriod`.
     */
    private static function presentPeriod(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'period'         => (string) $row['period'],
            'period_label'   => CommissionCalendar::label((string) $row['period']),
            'period_start'   => (string) $row['period_start'],
            'period_end'     => (string) $row['period_end'],
            'closed_at'      => date(DATE_ATOM, (int) strtotime((string) $row['closed_at'])),
            // Una liquidación por entrenador: es también el número de liquidaciones del período.
            'trainers_count' => (int) $row['trainers_count'],
            'orders_count'   => (int) $row['orders_count'],
            'total_amount'   => (float) $row['total_amount'],
            'paid_count'     => (int) $row['paid_count'],
            'paid_amount'    => (float) $row['paid_amount'],
            'due_date'       => $row['due_date'] === null ? null : (string) $row['due_date'],
            'report_sent_at' => $row['report_sent_at'] === null ? null : date(DATE_ATOM, (int) strtotime((string) $row['report_sent_at'])),
            'has_report'     => $row['file_name'] !== null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> Contrato `Settlement`.
     */
    private static function presentSettlement(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'trainer_id'         => (int) $row['trainer_id'],
            'trainer_name'       => (string) $row['trainer_name'],
            'document_id'        => (string) $row['document_id'],
            'phone'              => (string) $row['phone'],
            'orders_count'       => (int) $row['orders_count'],
            'commission_amount'  => (float) $row['commission_amount'],
            'conversions_amount' => (float) $row['conversions_amount'],
            'adjustments_amount' => (float) $row['adjustments_amount'],
            'total_amount'       => (float) $row['total_amount'],
            'due_date'           => (string) $row['due_date'],
            'status'             => (string) $row['status'],
            // Vencida: pasó el día de pago y no tiene un pago registrado (spec negocio/04, regla 10).
            'is_overdue'         => $row['status'] !== 'paid' && (string) $row['due_date'] < date('Y-m-d'),
            'paid_at'            => $row['paid_at'] === null ? null : date('Y-m-d', (int) strtotime((string) $row['paid_at'])),
            'payment_reference'  => $row['payment_reference'],
            'has_receipt'        => $row['receipt_path'] !== null,
            'paid_by'            => $row['paid_by'],
        ];
    }
}
