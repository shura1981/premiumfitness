<?php

declare(strict_types=1);

namespace App\Features\Order;

/**
 * Traduce el estado interno de un pedido (el texto de `status_orders`, que viene de las pasarelas
 * y del call center) a los estados que ve el usuario en la app web.
 */
final class OrderStatusMapper
{
    public const string PENDING    = 'pendiente';
    public const string APPROVED   = 'aprobado';
    public const string PROCESSING = 'en_proceso';
    public const string SHIPPED    = 'enviado';
    public const string DELIVERED  = 'entregado';
    public const string REJECTED   = 'rechazado';
    public const string CANCELLED  = 'cancelado';

    private const array LABELS = [
        self::PENDING    => 'Esperando el pago',
        self::APPROVED   => 'Pago aprobado',
        self::PROCESSING => 'Preparando tu pedido',
        self::SHIPPED    => 'En camino',
        self::DELIVERED  => 'Entregado',
        self::REJECTED   => 'Pago rechazado',
        self::CANCELLED  => 'Cancelado',
    ];

    /** Camino normal de un pedido, en orden. */
    private const array PATH = [self::PENDING, self::APPROVED, self::PROCESSING, self::SHIPPED, self::DELIVERED];

    public static function forUser(?string $statusValue, bool $isApproved, bool $hasSapOrder, bool $hasTracking): string
    {
        $value = mb_strtoupper(trim((string) $statusValue));

        return match (true) {
            str_contains($value, 'ENTREGAD')                                 => self::DELIVERED,
            // Una devolución total se muestra como un pedido cancelado.
            str_contains($value, 'CANCELAD'), str_contains($value, 'ABANDONAD'), str_contains($value, 'DEVOLUCI') => self::CANCELLED,
            str_contains($value, 'RECHAZAD'), str_contains($value, 'ERROR'), str_contains($value, 'REVERSAD'),
            str_contains($value, 'EXPIRAD'), str_contains($value, 'ANTIFRAUDE') => self::REJECTED,
            !$isApproved                                                      => self::PENDING,
            $hasTracking                                                      => self::SHIPPED,
            $hasSapOrder                                                      => self::PROCESSING,
            default                                                           => self::APPROVED,
        };
    }

    /**
     * @return list<array{status:string, label:string, at:?string, done:bool}>
     */
    public static function timeline(string $status, ?string $createdAt, ?string $approvedAt, bool $isRedemption = false): array
    {
        $dates = [self::PENDING => $createdAt, self::APPROVED => $approvedAt];
        // Un canje no pasa por la pasarela: nace confirmado.
        $labels = $isRedemption ? [self::APPROVED => 'Canje confirmado'] + self::LABELS : self::LABELS;
        $path   = $isRedemption ? array_values(array_diff(self::PATH, [self::PENDING])) : self::PATH;

        if (in_array($status, [self::REJECTED, self::CANCELLED], true)) {
            $first = $isRedemption ? self::APPROVED : self::PENDING;

            return [
                self::step($first, $labels[$first], $createdAt, true),
                self::step($status, $labels[$status], null, true),
            ];
        }

        $reached = array_search($status, $path, true);

        return array_map(
            static fn (string $step, int $index): array => self::step($step, $labels[$step], $dates[$step] ?? null, $index <= $reached),
            $path,
            array_keys($path),
        );
    }

    /** @return array{status:string, label:string, at:?string, done:bool} */
    private static function step(string $status, string $label, ?string $at, bool $done): array
    {
        return ['status' => $status, 'label' => $label, 'at' => $done ? self::iso($at) : null, 'done' => $done];
    }

    /** Fecha de la base (hora de Colombia) en ISO 8601 con zona. */
    public static function iso(?string $dateTime): ?string
    {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        return date(DATE_ATOM, (int) strtotime($dateTime));
    }
}
