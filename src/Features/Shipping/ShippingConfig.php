<?php

declare(strict_types=1);

namespace App\Features\Shipping;

/**
 * Valores del flete (spec backend/03, P-03). Son los de la tienda de radical y viven en variables de
 * entorno: ninguno se escribe en el código.
 *
 * - La tarifa de Servientrega se calcula con la fórmula del webservice `/fletes` del sitio de
 *   Nutramerican (`SHIPPING_RATE_*`), desde cada bodega de `SHIPPING_ORIGINS`; se cobra la más barata.
 * - Bogotá y las ciudades principales tienen un valor fijo y un mínimo para el envío gratis.
 * - Ningún flete baja de `SHIPPING_MINIMUM_FEE`, salvo el gratis.
 */
final readonly class ShippingConfig
{
    /** Bodegas: columnas `<bodega>_c` (costo) y `<bodega>_t` (tipo) de `tb_servientrega`. */
    public const array WAREHOUSES = ['barranquilla', 'bogota', 'bucaros', 'cali', 'cucuta', 'medallo'];

    /**
     * @param list<string> $origins    Bodegas desde las que se cotiza (de WAREHOUSES).
     * @param list<string> $mainCities Nombres de `cities.name`, en mayúsculas y sin tildes.
     */
    public function __construct(
        public array $origins,
        public int $minimumFee,
        public array $mainCities,
        public int $mainCitiesFee,
        public int $mainCitiesFreeFrom,
        public int $bogotaFee,
        public int $bogotaFreeFrom,
        public int $declaredValue,
        public float $handlingPercentage,
        public int $surcharge,
        public int $floorLimit,
        public int $floor,
        public int $type3Fee,
        public int $type4Fee,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            origins: array_values(array_intersect(self::list('SHIPPING_ORIGINS', lower: true), self::WAREHOUSES)),
            minimumFee: (int) $_ENV['SHIPPING_MINIMUM_FEE'],
            mainCities: array_map(ShippingCalculator::normalize(...), self::list('SHIPPING_MAIN_CITIES')),
            mainCitiesFee: (int) $_ENV['SHIPPING_MAIN_CITIES_FEE'],
            mainCitiesFreeFrom: (int) $_ENV['SHIPPING_MAIN_CITIES_FREE_FROM'],
            bogotaFee: (int) $_ENV['SHIPPING_BOGOTA_FEE'],
            bogotaFreeFrom: (int) $_ENV['SHIPPING_BOGOTA_FREE_FROM'],
            declaredValue: (int) $_ENV['SHIPPING_RATE_DECLARED_VALUE'],
            handlingPercentage: (float) $_ENV['SHIPPING_RATE_HANDLING_PERCENTAGE'],
            surcharge: (int) $_ENV['SHIPPING_RATE_SURCHARGE'],
            floorLimit: (int) $_ENV['SHIPPING_RATE_FLOOR_LIMIT'],
            floor: (int) $_ENV['SHIPPING_RATE_FLOOR'],
            type3Fee: (int) $_ENV['SHIPPING_RATE_TYPE3_FEE'],
            type4Fee: (int) $_ENV['SHIPPING_RATE_TYPE4_FEE'],
        );
    }

    /** @return list<string> */
    private static function list(string $name, bool $lower = false): array
    {
        $values = array_map(trim(...), explode(',', (string) ($_ENV[$name] ?? '')));

        return array_values(array_filter(
            $lower ? array_map(strtolower(...), $values) : $values,
            static fn (string $value): bool => $value !== '',
        ));
    }
}
