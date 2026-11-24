<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events;

use App\Features\Order\OrderRepository;
use App\Features\Order\StatusOrderRepository;
use App\Features\PaymentGateway\Events\Handlers\PaymentEventHandlerInterface;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use App\Features\PaymentGateway\PaymentAttemptRepository;
use App\Features\PaymentGateway\Result\PaymentResult;
use App\Features\Reward\AmountMismatchException;
use App\Features\Reward\PurchaseApproval;

/**
 * Punto único de entrada para los webhooks ya normalizados.
 *
 * Responsabilidades:
 *   1. Cargar la orden referenciada en el `PaymentResult`.
 *   2. Aplicar la regla de idempotencia: si la orden ya está APROBADA,
 *      ningún evento posterior puede modificarla ni disparar handlers.
 *   3. Persistir el estado y el payload crudo (`payment_state_json`).
 *   4. Iterar la lista ordenada de handlers síncronos; cada uno decide vía
 *      `supports()` si actúa para este evento.
 *
 * Los handlers se inyectan en el constructor — añadir uno nuevo no requiere
 * tocar esta clase (Open/Closed). Errores aislados: si un handler lanza,
 * se captura para no impedir la ejecución de los siguientes ni revertir
 * la actualización de la orden.
 */
final class PaymentEventDispatcher
{
    /** @param list<PaymentEventHandlerInterface> $handlers */
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly StatusOrderRepository $statuses,
        private readonly array $handlers = [],
        private readonly ?PurchaseApproval $approval = null,
        private readonly ?PaymentAttemptRepository $attempts = null,
    ) {}

    /**
     * @return array{processed: bool, reason?: string, order_id?: int, status?: string, handler_errors?: list<string>}
     */
    public function dispatch(PaymentResult $result): array
    {
        $order = $this->orders->findById($result->orderId);
        if ($order === false) {
            return ['processed' => false, 'reason' => 'order_not_found'];
        }

        // El intento guarda lo que reportó la pasarela, aunque el pedido ya esté aprobado o el valor no coincida:
        // es lo que muestra la página de respuesta con el id de pago (spec integraciones/03).
        if ($result->reference !== null) {
            ($this->attempts ?? new PaymentAttemptRepository())->recordResult(
                $result->reference,
                $result->statusText,
                $result->transactionId,
                $result->paymentMethod,
                $result->amount,
            );
        }

        if ($order->status_value === PaymentStatus::TEXT_APROBADA) {
            return [
                'processed' => false,
                'reason'    => 'already_approved',
                'order_id'  => $order->order_id,
            ];
        }

        $statusId = $this->statuses->findOrCreateIdByValue($result->statusText);

        if ($result->isApproved() && $this->approval !== null) {
            // Aprobación, comisión y puntos en una sola transacción; los avisos corren después.
            try {
                $approvedNow = $this->approval->approve(
                    $order->order_id,
                    $statusId,
                    $result->gateway,
                    $result->transactionId,
                    $result->amount,
                    $result->rawPayload,
                );
            } catch (AmountMismatchException) {
                return ['processed' => false, 'reason' => 'amount_mismatch', 'order_id' => $order->order_id];
            }

            if (!$approvedNow) {
                return ['processed' => false, 'reason' => 'already_approved', 'order_id' => $order->order_id];
            }
        } else {
            $this->orders->markPaymentState($order->order_id, $result->rawPayload, $statusId);
        }

        $order->status_id    = $statusId;
        $order->status_value = $result->statusText;

        $event  = new PaymentEvent($order, $result);
        $errors = (new PaymentEventHandlerRunner($this->handlers))->run($event);

        $response = [
            'processed' => true,
            'order_id'  => $order->order_id,
            'status'    => $result->statusText,
        ];

        if ($errors !== []) {
            $response['handler_errors'] = $errors;
        }

        return $response;
    }
}
