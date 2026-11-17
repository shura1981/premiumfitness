<?php

declare(strict_types=1);

namespace App\Features\Shipping;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;
use PDO;

/**
 * Tarifas de Servientrega de la intranet (`tb_servientrega`, solo lectura) y la ciudad de destino.
 */
final class ShippingRateRepository extends AbstractRepository
{
    public function __construct(private readonly string $rates)
    {
        parent::__construct(DB::getInstance());
    }

    public static function fromEnv(): self
    {
        return new self(IntranetTables::fromEnv()->shippingRates);
    }

    public function destination(int $cityId): ?Destination
    {
        $stmt = $this->pdo->prepare('SELECT name, city_dept FROM cities WHERE id = ?');
        $stmt->execute([$cityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new Destination((string) $row['name'], (string) $row['city_dept']);
    }

    /**
     * Tarifa de la ciudad: la fila de `tb_servientrega` con el mismo nombre (`CALI (VAL)`).
     *
     * `cities` heredó de radical nombres con «?» donde iba una letra con tilde o una Ñ («PASTO (NAR)»
     * con «NARI?O», «OCA?A (NOR)»): el «?» vale por cualquier letra. Los espacios dobles no cuentan.
     * Radical buscaba también por departamento, que no coincide en varias ciudades; el nombre ya lo trae.
     *
     * @return array<string, mixed>|null
     */
    public function rateFor(Destination $to): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->rates} WHERE REPLACE(ciudad, '  ', ' ') LIKE ? ORDER BY id LIMIT 1"
        );
        $stmt->execute([self::pattern($to->cityDept)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    /** `OCA?A  (NOR)` → `OCA_A (NOR)`, con los comodines propios de LIKE escapados. */
    private static function pattern(string $cityDept): string
    {
        $text = (string) preg_replace('/\s+/', ' ', trim($cityDept));

        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '?' => '_']);
    }
}
