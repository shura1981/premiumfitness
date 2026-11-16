<?php

declare(strict_types=1);

namespace App\Features\Product;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use InvalidArgumentException;
use PDO;

/**
 * Adaptador del catálogo: lee `tb_productos_megaplexstars` de la intranet, la tabla que alimenta
 * la web de la empresa. Solo lectura: PremiumFitness nunca escribe en ella.
 */
final class IntranetProductCatalog extends AbstractRepository implements ProductCatalog
{
    // Solo lo que usa la app. `descuento` y `old_price` no se leen nunca.
    // Las columnas bit(1) llegan como bytes ("\x00" es verdadero en PHP): se leen como número.
    private const string COLUMNS = 'codigo, orden, producto, valor, category, presentation, ruta, intro,
        imageswebp, grupo, agotado + 0 AS agotado, nuevo + 0 AS nuevo, sap_code, sap_name';
    private const string CONTENT_COLUMNS = 'description, uso, ingredientes, invima, tablasjpg';

    // Lo que entra a la app: activo, sin promoción, con nombre y con precio.
    // En SQL, `activo = 1` compara el bit como número; un `activo` NULL no entra.
    private const string SELLABLE = "activo = 1 AND promo = 0 AND valor > 0 AND COALESCE(producto, '') <> ''";

    public function __construct(private readonly string $table)
    {
        parent::__construct(DB::getInstance());
    }

    public static function fromEnv(): self
    {
        $database = (string) $_ENV['INTRANET_DB_NAME'];

        // El nombre de la base va en el SQL (no se puede pasar como parámetro): se valida.
        if (preg_match('/^[a-z0-9_]+$/', $database) !== 1) {
            throw new InvalidArgumentException('INTRANET_DB_NAME no es válido.');
        }

        return new self("`{$database}`.`tb_productos_megaplexstars`");
    }

    public function available(?string $category = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE " . self::SELLABLE
            . ($category !== null ? ' AND category = ?' : '')
            . ' ORDER BY orden, producto';

        return $this->map($sql, $category !== null ? [$category] : []);
    }

    public function findByCode(int $code): ?CatalogProduct
    {
        $sql = 'SELECT ' . self::COLUMNS . ', ' . self::CONTENT_COLUMNS
            . " FROM {$this->table} WHERE codigo = ? AND " . self::SELLABLE;

        return $this->map($sql, [$code], withContent: true)[0] ?? null;
    }

    public function findByCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_map(intval(...), $codes)));

        if ($codes === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($codes), '?'));
        $sql   = 'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE codigo IN ({$marks}) AND " . self::SELLABLE;

        $byCode = [];
        foreach ($this->map($sql, $codes) as $product) {
            $byCode[$product->code] = $product;
        }

        return $byCode;
    }

    public function presentationsOf(int $code): array
    {
        $sql = 'SELECT ' . self::COLUMNS . " FROM {$this->table}
                WHERE grupo = (SELECT grupo FROM {$this->table} WHERE codigo = ? LIMIT 1)
                  AND COALESCE(grupo, '') <> '' AND codigo <> ? AND " . self::SELLABLE . '
                ORDER BY orden, producto';

        return $this->map($sql, [$code, $code]);
    }

    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        $sql  = 'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE " . self::SELLABLE
            . ($term !== '' ? ' AND (producto LIKE ? OR CAST(codigo AS CHAR) LIKE ?)' : '')
            . ' ORDER BY orden, producto LIMIT ' . max(1, min(50, $limit));

        // Los comodines del usuario se toman como texto.
        $like = '%' . addcslashes($term, '%_\\') . '%';

        return $this->map($sql, $term !== '' ? [$like, $like] : []);
    }

    /**
     * @param list<mixed> $params
     * @return list<CatalogProduct>
     */
    private function map(string $sql, array $params, bool $withContent = false): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // FETCH_ASSOC y no FETCH_CLASS: las columnas cambian de nombre, de tipo y de forma.
        return array_map(
            static fn (array $row): CatalogProduct => CatalogProduct::fromIntranetRow($row, $withContent),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
