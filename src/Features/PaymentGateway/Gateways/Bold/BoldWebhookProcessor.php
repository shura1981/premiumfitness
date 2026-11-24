<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Bold;

use App\Features\Order\OrderRepository;
use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatusNormalizer;
use App\Features\PaymentGateway\Result\PaymentResult;
use RuntimeException;

/**
 * Convierte el payload JSON del webhook o respaldo de notificaciones de Bold
 * en un `PaymentResult` canónico.
 *
 * Estructura esperada (extracto):
 *   {
 *     "type": "SALE_APPROVED",
 *     "data": {
 *       "payment_id": "BOLD-TX-123",
 *       "amount": { "total": 150000, "currency": "COP" },
 *       "metadata": { "reference": "pf-1001" }
 *     }
 *   }
 *
 * Nota: `VOID_REJECTED` indica que una anulación fue rechazada y el pago
 * conserva su estado previo; los callers deben verificar `isVoidRejected()`
 * para tratarlo como no-op (`200 OK`) sin sobreescribir el estado de la orden.
 */
final class BoldWebhookProcessor
{
    private const string SUPPORTED_CURRENCY = 'COP';

    public function __construct(
        private readonly PaymentStatusNormalizer $normalizer = new PaymentStatusNormalizer(),
        private ?OrderRepository $orders = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function isVoidRejected(array $payload): bool
    {
        return ($payload['type'] ?? null) === 'VOID_REJECTED';
    }

    /**
     * @param array<string, mixed> $payload
     * @throws RuntimeException Si faltan campos críticos o la moneda no es COP.
     */
    public function process(array $payload): PaymentResult
    {
        $rawType = isset($payload['type']) && is_string($payload['type'])
            ? trim($payload['type'])
            : '';

        if ($rawType === '') {
            throw new RuntimeException('Bold webhook: missing event type.');
        }

        if (!is_array($payload['data'] ?? null)) {
            throw new RuntimeException('Bold webhook: missing data object.');
        }

        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $reference = is_scalar($metadata['reference'] ?? null) ? trim((string) $metadata['reference']) : '';

        if ($reference === '') {
            throw new RuntimeException('Bold webhook: missing data.metadata.reference.');
        }

        $paymentId = isset($data['payment_id']) && is_scalar($data['payment_id'])
            ? trim((string) $data['payment_id'])
            : '';

        if ($paymentId === '') {
            throw new RuntimeException('Bold webhook: missing data.payment_id.');
        }

        $amountData = is_array($data['amount'] ?? null) ? $data['amount'] : [];
        $rawTotal = $amountData['total'] ?? null;
        $currency = isset($amountData['currency']) && is_string($amountData['currency'])
            ? strtoupper(trim($amountData['currency']))
            : '';

        if (!is_numeric($rawTotal)) {
            throw new RuntimeException('Bold webhook: missing or invalid data.amount.total.');
        }

        if ($currency !== self::SUPPORTED_CURRENCY) {
            throw new RuntimeException('Bold webhook: unsupported currency.');
        }

        // El pedido se busca por su id de pago (`orders.payment_code`, P-24); la cuenta se comparte con otros proyectos.
        $orderId = ($this->orders ??= new OrderRepository())->findOrderIdByPaymentCode($reference)
            ?? throw ForeignPaymentReferenceException::for('Bold', $reference);

        [$codStatus, $statusText] = $this->normalizer->normalize('bold', $rawType);

        return new PaymentResult(
            gateway:       'bold',
            orderId:       $orderId,
            transactionId: $paymentId,
            codStatus:     $codStatus,
            statusText:    $statusText,
            amount:        (float) $rawTotal,
            rawPayload:    $payload,
        );
    }
}
