<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Normalizer;

/**
 * Convierte el estado bruto de cada pasarela en un código + texto canónicos.
 *
 * - Sistecrédito usa PascalCase (`Approved`, `Rejected`, `Pending`, …).
 * - Addi usa UPPERCASE (`APPROVED`, `REJECTED`, `PENDING`, …).
 * - Bold usa el tipo de evento (`SALE_APPROVED`, `SALE_REJECTED`, `VOID_APPROVED`, …).
 *
 * Estados desconocidos caen en `[ERROR, TEXT_DESCONOCIDO]` para que el
 * dispatcher los persista pero ningún handler los procese como APROBADA.
 */
final class PaymentStatusNormalizer
{
    /**
     * @return array{0: int, 1: string} [codStatus, statusText]
     */
    public function normalize(string $gateway, string|int $raw): array
    {
        return match ($gateway) {
            'sistecredito' => $this->fromSistecredito((string) $raw),
            'addi'         => $this->fromAddi((string) $raw),
            'bold'         => $this->fromBold((string) $raw),
            default        => [PaymentStatus::ERROR, PaymentStatus::TEXT_DESCONOCIDO],
        };
    }

    /** @return array{0: int, 1: string} */
    private function fromSistecredito(string $raw): array
    {
        return match ($raw) {
            'Approved'                => [PaymentStatus::APROBADA, PaymentStatus::TEXT_APROBADA],
            'Rejected'                => [PaymentStatus::RECHAZADA, PaymentStatus::TEXT_RECHAZADA],
            'Pending'                 => [PaymentStatus::PENDIENTE, PaymentStatus::TEXT_PENDIENTE],
            'Failed'                  => [PaymentStatus::ERROR, PaymentStatus::TEXT_ERROR],
            'PendingForPaymentMethod' => [PaymentStatus::PENDIENTE_METODO, PaymentStatus::TEXT_PENDIENTE_METODO],
            'Cancelled'               => [PaymentStatus::CANCELADA, PaymentStatus::TEXT_CANCELADA],
            default                   => [PaymentStatus::ERROR, PaymentStatus::TEXT_DESCONOCIDO],
        };
    }

    /** @return array{0: int, 1: string} */
    private function fromAddi(string $raw): array
    {
        return match ($raw) {
            'APPROVED'       => [PaymentStatus::APROBADA, PaymentStatus::TEXT_APROBADA],
            'REJECTED'       => [PaymentStatus::RECHAZADA, PaymentStatus::TEXT_RECHAZADA],
            'PENDING'        => [PaymentStatus::PENDIENTE, PaymentStatus::TEXT_PENDIENTE],
            'INTERNAL_ERROR' => [PaymentStatus::ERROR, PaymentStatus::TEXT_ERROR],
            'ABANDONED'      => [PaymentStatus::PENDIENTE_METODO, PaymentStatus::TEXT_ABANDONADA],
            'DECLINED'       => [PaymentStatus::CANCELADA, PaymentStatus::TEXT_CANCELADA],
            default          => [PaymentStatus::ERROR, PaymentStatus::TEXT_DESCONOCIDO],
        };
    }

    /** @return array{0: int, 1: string} */
    private function fromBold(string $raw): array
    {
        return match ($raw) {
            'SALE_APPROVED'                  => [PaymentStatus::APROBADA, PaymentStatus::TEXT_APROBADA],
            'SALE_REJECTED', 'VOID_APPROVED' => [PaymentStatus::RECHAZADA, PaymentStatus::TEXT_RECHAZADA],
            default                          => [PaymentStatus::ERROR, PaymentStatus::TEXT_DESCONOCIDO],
        };
    }
}
