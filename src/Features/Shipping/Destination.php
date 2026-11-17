<?php

declare(strict_types=1);

namespace App\Features\Shipping;

/** Ciudad a la que va un pedido, como está en `cities`. */
final readonly class Destination
{
    public function __construct(
        /** `cities.name`: el que se muestra y el que identifica a Bogotá y las ciudades principales. */
        public string $cityName,
        /** `cities.city_dept`, p. ej. `CALI (VAL)`: con él se busca la tarifa de Servientrega. */
        public string $cityDept,
    ) {}
}
