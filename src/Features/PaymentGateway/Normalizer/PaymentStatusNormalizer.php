<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Normalizer;

use App\Features\Adjustment\AdjustmentService;

/**
 * Convierte el estado bruto de cada pasarela en un código + texto canónicos.
 *
 * - Sistecrédito usa PascalCase (`Approved`, `Rejected`, `Pending`, …).
 * - Addi usa UPPERCASE (`APPROVED`, `REJECTED`, `PENDING`, …).
 * - Bold usa el tipo de evento (`SALE_APPROVED`, `SALE_REJECTED`, `VOID_APPROVED`, …).
 *
 * Un estado que la pasarela manda y no está en esta tabla se guarda con su propio nombre (por ejemplo
 * `EXPIRED`): el dispatcher lo crea en `status_orders` si no existe. Va con el código `ERROR`, así que
 * nunca aprueba el pedido. Solo una pasarela desconocida cae en `[ERROR, TEXT_DESCONOCIDO]`.
 */
final class PaymentStatusNormalizer
{
    /** Largo de `status_orders.value`. */
    private const int MAX_STATUS_LENGTH = 50;

    /**
     * Estados que pone la propia app: el aprobado del webhook y el de la reversión (spec backend/03, P-26).
     * Un nombre desconocido de la pasarela no puede caer en ellos (la tabla no distingue mayúsculas): un
     * pedido «APROBADA» sin pagar haría que el webhook ignorara el pago real.
     */
    private const array RESERVED = [PaymentStatus::TEXT_APROBADA, AdjustmentService::STATUS_REVERTED];

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

    /**
     * Estado que no está en la tabla: se guarda con el nombre que mandó la pasarela. Vacío o igual a un
     * estado que pone la app, `DESCONOCIDO`.
     *
     * @return array{0: int, 1: string}
     */
    private static function unmapped(string $raw): array
    {
        $name     = mb_substr(trim($raw), 0, self::MAX_STATUS_LENGTH);
        $reserved = array_map(mb_strtoupper(...), self::RESERVED);

        return $name === '' || in_array(mb_strtoupper($name), $reserved, true)
            ? [PaymentStatus::ERROR, PaymentStatus::TEXT_DESCONOCIDO]
            : [PaymentStatus::ERROR, $name];
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
            default                   => self::unmapped($raw),
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
            default          => self::unmapped($raw),
        };
    }

    /** @return array{0: int, 1: string} */
    private function fromBold(string $raw): array
    {
        return match ($raw) {
            'SALE_APPROVED'                  => [PaymentStatus::APROBADA, PaymentStatus::TEXT_APROBADA],
            'SALE_REJECTED', 'VOID_APPROVED' => [PaymentStatus::RECHAZADA, PaymentStatus::TEXT_RECHAZADA],
            default                          => self::unmapped($raw),
        };
    }
}
