<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Bold;

use App\Features\Order\OrderRepository;
use App\Features\Order\StatusOrderRepository;
use App\Features\PaymentGateway\Events\PaymentEventDispatcher;
use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;
use Throwable;

/**
 * Conciliación por lote de órdenes Bold que aún no tienen confirmación final.
 *
 * Consulta órdenes `payment_gateway = 'bold'` creadas en la fecha dada cuyo
 * estado sea `PENDIENTE%`, `INICIADA`, `ENVIADO` o `NULL`, consulta el respaldo
 * del webhook de Bold por las referencias de sus intentos de pago (`pf-<id>`,
 * `is_external_reference=true`) y despacha el evento encontrado mediante
 * `PaymentEventDispatcher`.
 */
final class BoldPendingCronService extends AbstractRepository
{
    public function __construct(
        private readonly BoldReconciliationService $reconciliation,
        private readonly PaymentEventDispatcher $dispatcher,
    ) {
        parent::__construct(DB::getInstance());
    }

    public static function fromEnv(?PaymentEventDispatcher $dispatcher = null): self
    {
        return new self(
            reconciliation: BoldReconciliationService::fromEnv(),
            dispatcher:     $dispatcher ?? new PaymentEventDispatcher(
                new OrderRepository(),
                new StatusOrderRepository(),
            ),
        );
    }

    /**
     * @return array{checked: int, processed: int, not_found: int, errors: list<string>}
     */
    public function reconcile(string $date): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.order_id
               FROM orders o
               LEFT JOIN status_orders s ON s.status_id = o.status_id
              WHERE o.payment_gateway = 'bold'
                AND DATE(o.created_at) = ?
                AND (s.value LIKE 'PENDIENTE%' OR s.value IN ('INICIADA', 'ENVIADO') OR s.value IS NULL)"
        );
        $stmt->execute([$date]);

        /** @var list<array{order_id: int|string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $notFound  = 0;
        $errors    = [];

        foreach ($rows as $row) {
            $orderId = (int) $row['order_id'];

            try {
                $outcome = $this->reconciliation->reconcile(
                    dispatcher: $this->dispatcher,
                    orderId:    $orderId,
                );

                if (($outcome['found'] ?? false) === false) {
                    $notFound++;
                    continue;
                }

                if (($outcome['processed'] ?? false) === true) {
                    $processed++;
                }
            } catch (Throwable $e) {
                $errors[] = "order_id={$orderId}: " . $e->getMessage();
            }
        }

        return [
            'checked'   => count($rows),
            'processed' => $processed,
            'not_found' => $notFound,
            'errors'    => $errors,
        ];
    }
}

