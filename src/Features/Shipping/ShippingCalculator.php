<?php

declare(strict_types=1);

namespace App\Features\Shipping;

/**
 * Valor del flete de un pedido (spec backend/03, P-03). Replica la tienda de radical
 * (`assets/js/shipping-calculator.js`) y el webservice `/fletes` del sitio de Nutramerican, que ella
 * consulta; aquí lo calcula el backend, nunca la app.
 *
 *   1. Tarifa de Servientrega: desde cada bodega con servicio a la ciudad; se toma la más barata.
 *   2. Bogotá y las ciudades principales: valor fijo, gratis desde su mínimo de compra.
 *   3. Ningún flete cobrado baja del mínimo.
 *
 * Diferencia a propósito con radical: Bogotá y las ciudades principales se reconocen por el nombre
 * exacto de la ciudad. Radical busca el texto dentro del nombre, y Calima o California pagaban como Cali.
 */
final readonly class ShippingCalculator
{
    private const string BOGOTA = 'BOGOTA';
    private const string NOT_AVAILABLE = 'N.A.';

    public function __construct(private ShippingConfig $config) {}

    /**
     * @param array<string, mixed>|null $rate          Fila de `tb_servientrega`; null si la ciudad no está.
     * @param int                       $productsTotal Lo pagado por los productos (con descuento e IVA).
     * @return int|null El flete en pesos; null si Servientrega no llega a esa ciudad.
     */
    public function amount(?array $rate, Destination $to, int $productsTotal): ?int
    {
        $remote = $rate === null ? null : $this->cheapestRate($rate);

        if ($remote === null) {
            return null;
        }

        $city   = self::normalize($to->cityName);
        $amount = match (true) {
            $city === self::BOGOTA => $productsTotal >= $this->config->bogotaFreeFrom ? 0 : $this->config->bogotaFee,
            in_array($city, $this->config->mainCities, true) => $productsTotal >= $this->config->mainCitiesFreeFrom ? 0 : $this->config->mainCitiesFee,
            default => $remote,
        };

        if ($amount === 0 || $productsTotal <= 0) {
            return 0;
        }

        return max($amount, $this->config->minimumFee);
    }

    /** `Bogotá` → `BOGOTA`: mayúsculas y sin tildes, para comparar nombres de ciudad. */
    public static function normalize(string $value): string
    {
        $upper = mb_strtoupper(trim($value), 'UTF-8');

        return strtr($upper, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    }

    /** @param array<string, mixed> $rate */
    private function cheapestRate(array $rate): ?int
    {
        $values = array_filter(array_map(
            fn (string $origin): ?int => $this->originRate((string) ($rate["{$origin}_c"] ?? self::NOT_AVAILABLE), (string) ($rate["{$origin}_t"] ?? self::NOT_AVAILABLE)),
            $this->config->origins,
        ), static fn (?int $value): bool => $value !== null && $value > 0);

        return $values === [] ? null : min($values);
    }

    /**
     * Tarifa desde una bodega, con la fórmula del webservice `/fletes`. La tabla trae el costo con coma
     * decimal («15346,5») y el webservice lo lee con `floatval`, que corta en la coma: aquí igual.
     *
     * @return int|null null si la bodega no tiene servicio a esa ciudad.
     */
    private function originRate(string $cost, string $type): ?int
    {
        if (trim($cost) === self::NOT_AVAILABLE) {
            return null;
        }

        return match (trim($type)) {
            '3'      => $this->config->type3Fee,
            '4'      => $this->config->type4Fee,
            '1', '2' => $this->carrierRate(floatval($cost)),
            default  => null,
        };
    }

    private function carrierRate(float $cost): int
    {
        $value = $cost + $this->config->declaredValue * $this->config->handlingPercentage / 100 + $this->config->surcharge;

        return $value <= $this->config->floorLimit ? $this->config->floor : (int) round($value);
    }
}
