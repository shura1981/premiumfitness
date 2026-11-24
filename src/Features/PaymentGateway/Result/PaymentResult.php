<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Result;

use App\Features\PaymentGateway\Normalizer\PaymentStatus;

/**
 * Resultado canónico tras procesar un payload de webhook (cualquier pasarela).
 *
 * Cada `*WebhookProcessor` produce esta estructura. Reduce las diferencias
 * entre Addi/Sistecrédito/Bold a un único contrato consumido por el
 * `PaymentEventDispatcher` y los handlers.
 */
final readonly class PaymentResult
{
    /**
     * @param string               $gateway
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public string $gateway,
        public int    $orderId,
        public string $transactionId,
        public int    $codStatus,
        public string $statusText,
        public float  $amount,
        public array  $rawPayload,
        /** Id del intento de pago (`pf-<n>`) que reportó la pasarela; null en los resultados reconstruidos. */
        public ?string $reference = null,
        /** Medio de pago que reportó la pasarela (tarjeta, PSE, Nequi…) o el nombre de la pasarela. */
        public ?string $paymentMethod = null,
    ) {}

    public function isApproved(): bool
    {
        return $this->codStatus === PaymentStatus::APROBADA;
    }
}
