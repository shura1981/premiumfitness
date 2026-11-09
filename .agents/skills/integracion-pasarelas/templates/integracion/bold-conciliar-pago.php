<?php

declare(strict_types=1);

/**
 * Plantilla: Conciliación Manual y Cron Job de Pagos Pendientes para Bold
 *
 * Consulta el API de notificaciones de Bold (`BOLD_URL_NOTIFICATIONS`) tanto por
 * referencia externa (`orderId` / `id_visita` con `?is_external_reference=true`)
 * como por `payment_id` directo.
 *
 * Variables de entorno requeridas:
 * - BOLD_IDENTITY_KEY      : Llave de identidad enviada en `Authorization: x-api-key {BOLD_IDENTITY_KEY}`
 * - BOLD_URL_NOTIFICATIONS : https://integrations.api.bold.co/payments/webhook/notifications
 */

final readonly class BoldReconciliationClient
{
    private const array VALID_EVENTS = [
        'SALE_APPROVED',
        'SALE_REJECTED',
        'VOID_APPROVED',
        'VOID_REJECTED',
    ];

    public function __construct(
        private string $identityKey,
        private string $notificationsBaseUrl,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            identityKey: (string) ($_ENV['BOLD_IDENTITY_KEY'] ?? ''),
            notificationsBaseUrl: rtrim((string) ($_ENV['BOLD_URL_NOTIFICATIONS'] ?? ''), '/'),
        );
    }

    /**
     * Construye la URL de consulta hacia Bold.
     */
    public function buildNotificationsUrl(string $queryValue, bool $isExternalReference = true): string
    {
        $encoded = rawurlencode(trim($queryValue));
        $suffix = $isExternalReference ? '?is_external_reference=true' : '';

        return "{$this->notificationsBaseUrl}/{$encoded}{$suffix}";
    }

    /**
     * Headers requeridos por el API de notificaciones de Bold.
     *
     * @return array<string, string>
     */
    public function buildHeaders(): array
    {
        return [
            'Authorization' => 'x-api-key ' . $this->identityKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Selecciona la notificación relevante cuando existen múltiples intentos (`payment_id`)
     * o múltiples eventos sobre un mismo intento.
     *
     * Reglas:
     * 1. Agrupa por `data.payment_id` y conserva únicamente el evento más reciente (`time` mayor)
     *    de cada `payment_id` (para que un `VOID_APPROVED` posterior invalide un `SALE_APPROVED` previo).
     * 2. Entre los estados vigentes de cada intento, prioriza cualquier intento con `SALE_APPROVED`;
     *    si ninguno está aprobado, retorna el de mayor `time`.
     *
     * @param list<array<string, mixed>> $notifications
     * @return array<string, mixed>|null
     */
    public function selectRelevantNotification(array $notifications): ?array
    {
        /** @var array<string, array<string, mixed>> $latestByPaymentId */
        $latestByPaymentId = [];

        foreach ($notifications as $index => $notification) {
            $type = (string) ($notification['type'] ?? '');
            if (!in_array($type, self::VALID_EVENTS, true)) {
                continue;
            }

            $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
            $paymentId = trim((string) ($data['payment_id'] ?? ''));
            $groupKey = $paymentId !== '' ? $paymentId : 'idx_' . $index;
            $time = (int) ($notification['time'] ?? 0);

            if (!isset($latestByPaymentId[$groupKey])) {
                $latestByPaymentId[$groupKey] = $notification;
                continue;
            }

            $currentTime = (int) ($latestByPaymentId[$groupKey]['time'] ?? 0);
            if ($time >= $currentTime) {
                $latestByPaymentId[$groupKey] = $notification;
            }
        }

        if ($latestByPaymentId === []) {
            return null;
        }

        $candidates = array_values($latestByPaymentId);
        $approvedCandidates = array_values(array_filter(
            $candidates,
            static fn(array $n): bool => ($n['type'] ?? '') === 'SALE_APPROVED',
        ));

        $pool = $approvedCandidates !== [] ? $approvedCandidates : $candidates;

        usort(
            $pool,
            static fn(array $a, array $b): int => ((int) ($b['time'] ?? 0)) <=> ((int) ($a['time'] ?? 0)),
        );

        return $pool[0] ?? null;
    }

    /**
     * Interpreta la respuesta HTTP del API de notificaciones de Bold.
     *
     * @return array{
     *   status: string,
     *   notification: array<string, mixed>|null,
     *   message: string
     * }
     */
    public function parseNotificationsResponse(int $httpStatusCode, string $rawResponseBody): array
    {
        if ($httpStatusCode === 404) {
            return [
                'status' => 'SIN_NOTIFICACION',
                'notification' => null,
                'message' => 'Bold aún no registra notificaciones para esta referencia.',
            ];
        }

        if ($httpStatusCode < 200 || $httpStatusCode >= 300) {
            throw new RuntimeException("Error HTTP {$httpStatusCode} al consultar notificaciones en Bold.");
        }

        $decoded = json_decode($rawResponseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta JSON inválida desde Bold.');
        }

        $rawNotifications = $decoded['notifications'] ?? [];
        if (!is_array($rawNotifications) || $rawNotifications === []) {
            return [
                'status' => 'SIN_NOTIFICACION',
                'notification' => null,
                'message' => 'Lista de notificaciones vacía en Bold.',
            ];
        }

        /** @var list<array<string, mixed>> $typedNotifications */
        $typedNotifications = array_values(array_filter($rawNotifications, 'is_array'));
        $selected = $this->selectRelevantNotification($typedNotifications);

        if ($selected === null) {
            return [
                'status' => 'SIN_NOTIFICACION',
                'notification' => null,
                'message' => 'No se encontraron eventos válidos en las notificaciones de Bold.',
            ];
        }

        return [
            'status' => (string) ($selected['type'] ?? 'UNKNOWN'),
            'notification' => $selected,
            'message' => 'Notificación encontrada.',
        ];
    }
}
