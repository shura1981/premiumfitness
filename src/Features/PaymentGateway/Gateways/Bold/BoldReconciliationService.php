<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Gateways\Bold;

use App\Features\PaymentGateway\Events\PaymentEventDispatcher;
use App\Features\PaymentGateway\PaymentCode;
use App\Infrastructure\Http\CurlHttpClient;
use App\Shared\Exceptions\HttpRequestException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Consulta el API de respaldo de notificaciones de Bold y concilia el estado
 * real de un pago cuando el webhook no llegó a tiempo.
 *
 * Permite consultar por:
 *   - Referencia externa: el id de pago del pedido (`pf-<pedido>`, spec backend/03, P-24)
 *     → `?is_external_reference=true`.
 *   - ID de pago Bold (`payment_id`)
 *
 * Selección de evento cuando existen múltiples notificaciones/intentos:
 *   1. Conserva únicamente el evento con mayor `time` por cada `payment_id`
 *      (para no aplicar una aprobación que posteriormente fue anulada).
 *   2. Entre distintos intentos (`payment_id`) del mismo pedido, prioriza el
 *      que quedó en `SALE_APPROVED`; si ninguno está aprobado, toma el más
 *      reciente.
 */
final readonly class BoldReconciliationService
{
    public function __construct(
        private CurlHttpClient $http,
        private string $identityKey,
        private string $notificationsUrl,
        private int $timeoutSeconds,
    ) {}

    public static function fromEnv(?CurlHttpClient $http = null): self
    {
        return new self(
            http:             $http ?? new CurlHttpClient(),
            identityKey:      trim((string) $_ENV['BOLD_IDENTITY_KEY']),
            notificationsUrl: rtrim(trim((string) $_ENV['BOLD_URL_NOTIFICATIONS']), '/'),
            timeoutSeconds:   max(5, (int) $_ENV['PAYMENT_GATEWAY_TIMEOUT_SECONDS']),
        );
    }

    /**
     * @return array<string, mixed>|null `null` cuando Bold responde 404 o no hay coincidencias.
     */
    public function fetchNotification(string $queryValue, bool $isExternalReference): ?array
    {
        if ($this->identityKey === '' || $this->notificationsUrl === '') {
            throw new RuntimeException('Bold reconciliation credentials are not configured.');
        }

        $trimmed = trim($queryValue);
        // La referencia sigue la regla de Bold: letras, números, `-` y `_`, hasta 60 caracteres.
        $pattern = $isExternalReference ? '/^[A-Za-z0-9_-]{1,60}$/' : '/^[A-Za-z0-9_-]{6,64}$/';

        if (preg_match($pattern, $trimmed) !== 1) {
            throw new InvalidArgumentException('Invalid Bold reconciliation query parameter.');
        }

        $url = $this->notificationsUrl . '/' . rawurlencode($trimmed);
        $query = $isExternalReference ? ['is_external_reference' => 'true'] : [];

        try {
            $rawResponse = $this->http->request($url, [
                'method'         => 'GET',
                'query'          => $query,
                'headers'        => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'x-api-key ' . $this->identityKey,
                ],
                'timeout'        => $this->timeoutSeconds,
                'connectTimeout' => min(10, $this->timeoutSeconds),
            ]);
        } catch (HttpRequestException $e) {
            if ($e->statusCode() === 404) {
                return null;
            }

            throw $e;
        }

        $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return self::selectRelevantNotification($decoded, $trimmed, $isExternalReference);
    }

    /**
     * Notificación de Bold de un pedido: se consulta por su id de pago (`pf-<pedido>`), que es la
     * referencia externa que recibió Bold. En radical se consulta con el número del pedido.
     *
     * @return array<string, mixed>|null
     */
    public function fetchForOrder(int $orderId): ?array
    {
        return $this->fetchNotification(PaymentCode::forOrder($orderId), true);
    }

    /**
     * Concilia el pago en local despachando el evento seleccionado a través de
     * `PaymentEventDispatcher`.
     *
     * @return array<string, mixed>
     */
    public function reconcile(
        PaymentEventDispatcher $dispatcher,
        ?int $orderId = null,
        ?string $paymentId = null,
    ): array {
        $normalizedPaymentId = $paymentId !== null ? trim($paymentId) : '';

        $notification = $normalizedPaymentId !== ''
            ? $this->fetchNotification($normalizedPaymentId, false)
            : $this->fetchForOrder($orderId ?? 0);

        if ($notification === null || !is_array($notification['data'] ?? null)) {
            return [
                'found'     => false,
                'processed' => false,
                'reason'    => 'notification_not_found',
            ];
        }

        $processor = new BoldWebhookProcessor();

        if ($processor->isVoidRejected($notification)) {
            return [
                'found'        => true,
                'processed'    => false,
                'reason'       => 'void_rejected',
                'status'       => 'VOID_REJECTED',
                'notification' => $notification,
            ];
        }

        $result  = $processor->process($notification);
        $outcome = $dispatcher->dispatch($result);

        return ['found' => true] + $outcome + [
            'transaction_id' => $result->transactionId,
            'notification'   => $notification,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>|null
     */
    public static function selectRelevantNotification(
        array $decoded,
        string $queryValue,
        bool $isExternalReference,
    ): ?array {
        $notifications = isset($decoded['notifications']) && is_array($decoded['notifications'])
            ? $decoded['notifications']
            : [$decoded];

        /** @var array<string, array{time: float, event: array<string, mixed>}> $latestByPayment */
        $latestByPayment = [];

        foreach ($notifications as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemData = is_array($item['data'] ?? null) ? $item['data'] : [];
            $metadata = is_array($itemData['metadata'] ?? null) ? $itemData['metadata'] : [];
            $candidate = $isExternalReference
                ? ($metadata['reference'] ?? null)
                : ($itemData['payment_id'] ?? null);

            if (!is_scalar($candidate) || strcasecmp((string) $candidate, $queryValue) !== 0) {
                continue;
            }

            $paymentId = isset($itemData['payment_id']) && is_scalar($itemData['payment_id'])
                ? (string) $itemData['payment_id']
                : '';
            $time = isset($item['time']) && is_numeric($item['time'])
                ? (float) $item['time']
                : 0.0;

            if (!isset($latestByPayment[$paymentId]) || $time >= $latestByPayment[$paymentId]['time']) {
                /** @var array<string, mixed> $item */
                $latestByPayment[$paymentId] = [
                    'time'  => $time,
                    'event' => $item,
                ];
            }
        }

        $selected    = null;
        $bestTime    = -1.0;
        $hasApproved = false;

        foreach ($latestByPayment as $entry) {
            $event      = $entry['event'];
            $isApproved = ($event['type'] ?? null) === 'SALE_APPROVED';

            if ($hasApproved && !$isApproved) {
                continue;
            }

            if ($isApproved && !$hasApproved) {
                $hasApproved = true;
                $bestTime    = -1.0;
            }

            if ($entry['time'] >= $bestTime) {
                $bestTime = $entry['time'];
                $selected = $event;
            }
        }

        return $selected;
    }
}

