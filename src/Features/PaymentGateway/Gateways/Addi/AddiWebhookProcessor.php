<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Addi;

use App\Features\PaymentGateway\Normalizer\PaymentStatusNormalizer;
use App\Features\PaymentGateway\Result\PaymentResult;
use RuntimeException;

/**
 * Convierte el payload JSON del webhook de Addi en un `PaymentResult`.
 *
 * Campos relevantes en el body de Addi:
 *  - `orderId`         → id local de la orden (== `Invoice` para nosotros).
 *  - `applicationId`   → id de transacción en Addi.
 *  - `status`          → string UPPERCASE: APPROVED / REJECTED / PENDING / …
 *  - `approvedAmount`  → monto aprobado.
 */
final class AddiWebhookProcessor
{
    public function __construct(
        private readonly PaymentStatusNormalizer $normalizer = new PaymentStatusNormalizer(),
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @throws RuntimeException Si faltan campos críticos.
     */
    public function process(array $payload): PaymentResult
    {
        $orderId = isset($payload['orderId']) ? (int) $payload['orderId'] : 0;
        if ($orderId <= 0) {
            throw new RuntimeException('Addi webhook: missing or invalid orderId.');
        }

        $rawStatus = (string) ($payload['status'] ?? '');
        if ($rawStatus === '') {
            throw new RuntimeException('Addi webhook: missing status.');
        }

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
