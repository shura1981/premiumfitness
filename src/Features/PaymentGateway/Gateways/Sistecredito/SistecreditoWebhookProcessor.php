<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Sistecredito;

use App\Features\PaymentGateway\Normalizer\PaymentStatusNormalizer;
use App\Features\PaymentGateway\Result\PaymentResult;
use RuntimeException;

/**
 * Convierte el payload JSON del webhook de Sistecrédito en un `PaymentResult`.
 *
 * Estructura esperada (extracto):
 *   {
 *     "Invoice": "1112",
 *     "Value": 128642,
 *     "Currency": "COP",
 *     "PaymentMethodResponse": {
 *       "TransactionId":   "SC-123456",
 *       "StatusResponse":  "Approved"  // PascalCase!
 *     }
 *   }
 *
 * Sistecrédito puede mandar pings de prueba con `PaymentMethodResponse` vacío;
 * en ese caso lanzamos para que el controller responda 200 sin disparar nada.
 */
final class SistecreditoWebhookProcessor
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
        $orderId = isset($payload['Invoice']) ? (int) $payload['Invoice'] : 0;
        if ($orderId <= 0) {
            throw new RuntimeException('Sistecredito webhook: missing or invalid Invoice.');
        }

        /** @var array<string, mixed> $method */
        $method = is_array($payload['PaymentMethodResponse'] ?? null)
            ? $payload['PaymentMethodResponse']
            : [];

        $rawStatus = (string) ($method['StatusResponse'] ?? '');
        if ($rawStatus === '') {
            throw new RuntimeException('Sistecredito webhook: missing PaymentMethodResponse.StatusResponse.');
        }

        [$codStatus, $statusText] = $this->normalizer->normalize('sistecredito', $rawStatus);

        return new PaymentResult(
            gateway:       'sistecredito',
            orderId:       $orderId,
            transactionId: (string) ($method['TransactionId'] ?? ''),
            codStatus:     $codStatus,
            statusText:    $statusText,
            amount:        (float) ($payload['Value'] ?? 0),
            rawPayload:    $payload,
        );
    }
}
