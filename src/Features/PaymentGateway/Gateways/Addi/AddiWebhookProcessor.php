<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Addi;

use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatusNormalizer;
use App\Features\PaymentGateway\PaymentAttemptRepository;
use App\Features\PaymentGateway\PaymentReferenceResolver;
use App\Features\PaymentGateway\Result\PaymentResult;
use RuntimeException;

/**
 * Convierte el payload JSON del webhook de Addi en un `PaymentResult`.
 *
 * Campos relevantes en el body de Addi:
 *  - `orderId`         → referencia del intento de pago (`pf-<id>`).
 *  - `applicationId`   → id de transacción en Addi.
 *  - `status`          → string UPPERCASE: APPROVED / REJECTED / PENDING / …
 *  - `approvedAmount`  → monto aprobado.
 */
final class AddiWebhookProcessor
{
    public function __construct(
        private readonly PaymentStatusNormalizer $normalizer = new PaymentStatusNormalizer(),
        private ?PaymentReferenceResolver $references = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @throws RuntimeException Si faltan campos críticos.
     */
    public function process(array $payload): PaymentResult
    {
        $reference = is_scalar($payload['orderId'] ?? null) ? trim((string) $payload['orderId']) : '';
        if ($reference === '') {
            throw new RuntimeException('Addi webhook: missing orderId.');
        }

        $rawStatus = (string) ($payload['status'] ?? '');
        if ($rawStatus === '') {
            throw new RuntimeException('Addi webhook: missing status.');
        }

        $orderId = ($this->references ??= new PaymentAttemptRepository())->orderIdFor($reference)
            ?? throw ForeignPaymentReferenceException::for('Addi', $reference);

        [$codStatus, $statusText] = $this->normalizer->normalize('addi', $rawStatus);

        return new PaymentResult(
            gateway:       'addi',
            orderId:       $orderId,
            transactionId: (string) ($payload['applicationId'] ?? ''),
            codStatus:     $codStatus,
            statusText:    $statusText,
            amount:        (float) ($payload['approvedAmount'] ?? 0),
            rawPayload:    $payload,
        );
    }
}
