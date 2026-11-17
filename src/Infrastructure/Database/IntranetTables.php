<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use InvalidArgumentException;

/**
 * Nombres completos de las tablas de la intranet que lee la app (solo lectura).
 *
 * La base sale de INTRANET_DB_NAME: `crisenri_intranet` en el hosting y en desarrollo; las pruebas
 * usan su propia base para no escribir nunca en la intranet.
 */
readonly class IntranetTables
{
    public string $employees;
    public string $products;
    public string $shippingRates;

    private function __construct(public string $database)
    {
        $this->employees = "`{$database}`.`tb_empleados`";
        $this->products  = "`{$database}`.`tb_productos_megaplexstars`";
        // Tarifas de Servientrega por ciudad desde cada bodega: las del webservice `/fletes` del sitio.
        $this->shippingRates = "`{$database}`.`tb_servientrega`";
    }

    public static function fromEnv(): self
    {
        $database = (string) $_ENV['INTRANET_DB_NAME'];

        // El nombre de la base va en el SQL (no se puede pasar como parámetro): se valida.
        if (preg_match('/^[a-z0-9_]+$/', $database) !== 1) {
            throw new InvalidArgumentException('INTRANET_DB_NAME no es válido.');
        }

        return new self($database);
    }
}
