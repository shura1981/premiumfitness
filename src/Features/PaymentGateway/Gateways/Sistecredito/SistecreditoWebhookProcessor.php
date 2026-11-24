<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Sistecredito;

use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatusNormalizer;
use App\Features\PaymentGateway\PaymentAttemptRepository;
use App\Features\PaymentGateway\PaymentReferenceResolver;
use App\Features\PaymentGateway\Result\PaymentResult;
use RuntimeException;

/**
 * Convierte el payload JSON del webhook de Sistecrédito en un `PaymentResult`.
 *
 * Estructura esperada (extracto):
 *   {
 *     "Invoice": "pf-1112",   // referencia del intento de pago
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
        private ?PaymentReferenceResolver $references = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @throws RuntimeException Si faltan campos críticos.
     */
    public function process(array $payload): PaymentResult
    {
        $reference = is_scalar($payload['Invoice'] ?? null) ? trim((string) $payload['Invoice']) : '';
        if ($reference === '') {
            throw new RuntimeException('Sistecredito webhook: missing Invoice.');
        }

        /** @var array<string, mixed> $method */
        $method = is_array($payload['PaymentMethodResponse'] ?? null)
            ? $payload['PaymentMethodResponse']
            : [];

        $rawStatus = (string) ($method['StatusResponse'] ?? '');
        if ($rawStatus === '') {
            throw new RuntimeException('Sistecredito webhook: missing PaymentMethodResponse.StatusResponse.');
        }

        $orderId = ($this->references ??= new PaymentAttemptRepository())->orderIdFor($reference)
            ?? throw ForeignPaymentReferenceException::for('Sistecrédito', $reference);

        [$codStatus, $statusText] = $this->normalizer->normalize('sistecredito', $rawStatus);

        return new PaymentResult(
            gateway:       'sistecredito',
            orderId:       $orderId,
            transactionId: (string) ($method['TransactionId'] ?? ''),
            codStatus:     $codStatus,
            statusText:    $statusText,
            amount:        (float) ($payload['Value'] ?? 0),
            rawPayload:    $payload,
            reference:     $reference,
            paymentMethod: 'Sistecrédito',
        );
    }
}
