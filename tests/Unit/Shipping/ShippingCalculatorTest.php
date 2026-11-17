<?php

declare(strict_types=1);

use App\Features\Shipping\Destination;
use App\Features\Shipping\ShippingCalculator;
use App\Features\Shipping\ShippingConfig;

/*
 | Flete por ciudad (spec backend/03, P-03): los valores de la tienda de radical y la fórmula del
 | webservice `/fletes` del sitio de Nutramerican.
 */

/** @param list<string> $origins */
function shippingCalculator(array $origins = ShippingConfig::WAREHOUSES): ShippingCalculator
{
    return new ShippingCalculator(new ShippingConfig(
        origins: $origins,
        minimumFee: 9900,
        mainCities: ['BARRANQUILLA', 'BUCARAMANGA', 'CALI', 'CUCUTA', 'MEDELLIN', 'PEREIRA'],
        mainCitiesFee: 9900,
        mainCitiesFreeFrom: 1000000,
        bogotaFee: 11300,
        bogotaFreeFrom: 170000,
        declaredValue: 300000,
        handlingPercentage: 0.5,
        surcharge: 2000,
        floorLimit: 11000,
        floor: 12500,
        type3Fee: 4900,
        type4Fee: 11400,
    ));
}

/**
 * Fila de `tb_servientrega`: sin servicio desde ninguna bodega, salvo las indicadas.
 *
 * @param array<string, array{0:string, 1:string}> $origins bodega => [costo, tipo]
 * @return array<string, string>
 */
function rate(array $origins): array
{
    $row = [];

    foreach (ShippingConfig::WAREHOUSES as $warehouse) {
        [$row["{$warehouse}_c"], $row["{$warehouse}_t"]] = $origins[$warehouse] ?? ['N.A.', 'N.A.'];
    }

    return $row;
}

function to(string $city): Destination
{
    return new Destination($city, "{$city} (XXX)");
}

it('cobra la tarifa de la bodega más barata, con la fórmula del webservice', function (): void {
    // 15.346,5 se lee como 15.346 (corta en la coma): 15.346 + 1.500 + 2.000 = 18.846.
    $row = rate(['cali' => ['15346,5', '1'], 'bogota' => ['16350,75', '1'], 'medallo' => ['86400', '2']]);

    expect(shippingCalculator()->amount($row, to('ABEJORRAL'), 100000))->toBe(18846);
});

it('aplica los tipos fijos y el piso de la tarifa', function (array $origin, int $expected): void {
    expect(shippingCalculator()->amount(rate(['cali' => $origin]), to('TULUA'), 100000))->toBe($expected);
})->with([
    'tipo 4: 11.400'                       => [['4972,5', '4'], 11400],
    'tipo 1 que no pasa de 11.000: 12.500' => [['6000', '1'], 12500],
    'tipo 2 por encima del piso'           => [['86400', '2'], 89900],
    // Tipo 3: 4.900, que sube al mínimo de 9.900.
    'tipo 3: sube al mínimo'               => [['3315', '3'], 9900],
]);

it('solo cotiza desde las bodegas configuradas', function (): void {
    $row = rate(['cali' => ['20000', '1'], 'bogota' => ['10000', '1']]);

    expect(shippingCalculator()->amount($row, to('ARMENIA (Q)'), 100000))->toBe(13500)
        ->and(shippingCalculator(['cali'])->amount($row, to('ARMENIA (Q)'), 100000))->toBe(23500);
});

it('Bogotá y las ciudades principales pagan su valor fijo y son gratis desde su mínimo', function (string $city, int $products, int $expected): void {
    // La tarifa más barata es la de Bogotá, tipo 3: 4.900, que sube al mínimo de 9.900.
    $row = rate(['bogota' => ['3315', '3'], 'cali' => ['6201', '1']]);

    expect(shippingCalculator()->amount($row, to($city), $products))->toBe($expected);
})->with([
    'Bogotá'                   => ['BOGOTA', 169999, 11300],
    'Bogotá desde 170.000'     => ['BOGOTA', 170000, 0],
    'Bogotá, con tilde'        => ['Bogotá', 100000, 11300],
    'Cali'                     => ['CALI', 999999, 9900],
    'Cali desde 1.000.000'     => ['CALI', 1000000, 0],
    'Medellín, con tilde'      => ['Medellín', 100000, 9900],
    // Radical buscaba «CALI» dentro del nombre y Calima pagaba como Cali.
    'Calima no es Cali'        => ['CALIMA', 1000000, 9900],
    'otra ciudad nunca es gratis' => ['ARMENIA (Q)', 5000000, 9900],
]);

it('sin servicio desde ninguna bodega, o sin tarifa, no hay envío', function (): void {
    expect(shippingCalculator()->amount(rate([]), to('CALI'), 100000))->toBeNull()
        ->and(shippingCalculator()->amount(null, to('CALI'), 100000))->toBeNull();
});
