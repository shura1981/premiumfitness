<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Bold;

use App\Features\PaymentGateway\ForeignPaymentReferenceException;
use App\Features\PaymentGateway\Normalizer\PaymentStatusNormalizer;
use App\Features\PaymentGateway\PaymentAttemptRepository;
use App\Features\PaymentGateway\PaymentReferenceResolver;
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
        private ?PaymentReferenceResolver $references = null,
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

        // La referencia es la del intento de pago (`pf-<id>`); la cuenta se comparte con el sitio web.
        $orderId = $this->references()->orderIdFor($reference)
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
            reference:     $reference,
            paymentMethod: self::paymentMethod($data),
        );
    }

    /** @param array<string, mixed> $data `CARD` → «Tarjeta»; el resto como lo manda Bold (PSE, NEQUI…). */
    private static function paymentMethod(array $data): string
    {
        $method = is_string($data['payment_method'] ?? null) ? strtoupper(trim($data['payment_method'])) : '';

        return match ($method) {
            ''                  => 'Bold',
            'CARD', 'CARD_WEB'  => 'Tarjeta',
            'NEQUI'             => 'Nequi',
            'DAVIPLATA'         => 'Daviplata',
            'BOTON_BANCOLOMBIA' => 'Botón Bancolombia',
            default             => $method,
        };
    }

    private function references(): PaymentReferenceResolver
    {
        return $this->references ??= new PaymentAttemptRepository();
    }
}
