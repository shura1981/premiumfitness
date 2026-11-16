# Plan — Catálogo desde la tabla de productos de la intranet

Spec: [01-catalogo-desde-la-intranet.md](01-catalogo-desde-la-intranet.md).

## Enfoque

El catálogo se lee de la tabla que ya mantiene la web, detrás de una interfaz. Se combinan tres patrones que ya existen en el código de radical:

- **Repositorio con interfaz (puerto):** `ProductCatalog` es lo único que conocen la tienda, el carrito, el checkout y el canje.
- **Adaptador:** `IntranetProductCatalog` traduce las filas de `tb_productos_megaplexstars` (nombres en español, `valor` como texto, JSON de imágenes, HTML) a `CatalogProduct`, un `readonly class` con fábrica `fromIntranetRow()`, como los `fromApiResponse()` de radical.
- **Decorador:** `CachedProductCatalog` implementa la misma interfaz y añade caché con `CacheService`, sin que quien la usa lo note.

Si el catálogo cambia de lugar (otra tabla, SAP o una API), se escribe otro adaptador y el resto de la app no cambia.

### Por qué no una tabla propia

- La tabla de la intranet ya es el maestro: la leen la web, Melonn y la academia. Una copia en PremiumFitness habría que sincronizarla, y el precio o el código SAP terminarían por no coincidir.
- Ya tiene lo que la app necesita para vender y para SAP: `codigo`, `producto`, `valor`, `activo`, `agotado`, `promo`, `category`, `grupo`, `sap_code` y `sap_name`.
- Hay un precedente igual en el proyecto: `tb_empleados`, de la misma intranet, se lee sin migrarla (S-31 del DER).

## De la tabla a la estructura

| Columna de la intranet | Campo de `CatalogProduct` | Nota |
| --- | --- | --- |
| `codigo` | `code` | La clave. En los pedidos se guarda como `product_sku`. |
| `producto` | `name` | El nombre |
| `valor` | `price` | Entero, IVA incluido |
| `category`, `presentation`, `ruta`, `intro` | `category`, `presentation`, `slug`, `summary` | |
| `imageswebp` | `images` | JSON separado en tamaños |
| `grupo` | `group` | Presentaciones del mismo producto |
| `agotado`, `nuevo` | `isOutOfStock`, `isNew` | `bit(1)`: se leen como número |
| `sap_code`, `sap_name` | `sapCode`, `sapName` | Internos: no salen en la API pública. `sap_code = 0` llega como nulo. |
| `description`, `uso`, `ingredientes`, `invima`, `video`, `tablasjpg` | `content` | Solo en la ficha; HTML limpio con `HtmlSanitizer::richText()` |
| `descuento`, `old_price`, `promo` | — | No se leen. `promo` solo filtra. |

## Archivos

```
src/Features/Product/
├── ProductCatalog.php           ← interfaz
├── CatalogProduct.php           ← la estructura que siempre devuelve
├── ProductImages.php            ← imágenes del JSON imageswebp
├── ProductContent.php           ← textos de la ficha
├── IntranetProductCatalog.php   ← adaptador de tb_productos_megaplexstars
├── CachedProductCatalog.php     ← decorador con caché
├── ProductController.php
src/Features/Order/
└── VatPolicy.php                ← tasa de IVA por producto, al comprar
```

## Interfaz

```php
<?php
declare(strict_types=1);

namespace App\Features\Product;

/**
 * Catálogo de productos que se venden en la app.
 * Quien la use no sabe de dónde salen los productos: solo recibe CatalogProduct.
 */
interface ProductCatalog
{
    /** @return list<CatalogProduct> En el orden de la web. */
    public function available(?string $category = null): array;

    /** Con el contenido de la ficha; null si no existe o no se vende en la app. */
    public function findByCode(int $code): ?CatalogProduct;

    /**
     * Para el carrito, el checkout y el canje.
     * @param list<int> $codes
     * @return array<int, CatalogProduct> Indexado por código; faltan los que no se venden.
     */
    public function findByCodes(array $codes): array;

    /** @return list<CatalogProduct> Las demás presentaciones del mismo grupo. */
    public function presentationsOf(int $code): array;
}
```

## Estructura

```php
<?php
declare(strict_types=1);

namespace App\Features\Product;

final readonly class CatalogProduct implements \JsonSerializable
{
    public function __construct(
        public int $code,
        public string $name,
        public int $price,
        public string $category,
        public ?string $presentation,
        public ?string $slug,
        public ?string $summary,
        public ProductImages $images,
        public ?string $group,
        public bool $isOutOfStock,
        public bool $isNew,
        public ?int $sapCode,
        public ?string $sapName,
        public ?ProductContent $content = null,
    ) {}

    /** @param array<string, mixed> $row Fila de tb_productos_megaplexstars. */
    public static function fromIntranetRow(array $row, bool $withContent = false): self
    {
        return new self(
            code: (int) $row['codigo'],
            name: trim((string) $row['producto']),
            price: (int) $row['valor'],
            category: (string) $row['category'],
            presentation: self::text($row['presentation'] ?? null),
            slug: self::text($row['ruta'] ?? null),
            summary: self::text($row['intro'] ?? null),
            images: ProductImages::fromJson($row['imageswebp'] ?? null),
            group: self::text($row['grupo'] ?? null),
            isOutOfStock: (int) $row['agotado'] === 1,
            isNew: (int) ($row['nuevo'] ?? 0) === 1,
            // sap_code es NOT NULL: 0 significa que el producto no tiene artículo SAP.
            sapCode: (int) $row['sap_code'] > 0 ? (int) $row['sap_code'] : null,
            sapName: self::text($row['sap_name'] ?? null),
            content: $withContent ? ProductContent::fromIntranetRow($row) : null,
        );
    }

    public function isPurchasable(): bool
    {
        return !$this->isOutOfStock && $this->price > 0;
    }

    /** Lo que ve el cliente. Los datos de SAP son internos y no salen. */
    public function jsonSerialize(): array
    {
        return [
            'code'            => $this->code,
            'name'            => $this->name,
            'price'           => $this->price,
            'category'        => $this->category,
            'presentation'    => $this->presentation,
            'slug'            => $this->slug,
            'summary'         => $this->summary,
            'images'          => $this->images,
            'group'           => $this->group,
            'is_out_of_stock' => $this->isOutOfStock,
            'is_new'          => $this->isNew,
            'content'         => $this->content,
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
```

- `ProductImages::fromJson()` separa `small`, `medium`, `big` y `bigger`. Si el JSON está mal formado devuelve imágenes vacías en lugar de fallar.
- `ProductContent::fromIntranetRow()` limpia el HTML de `description`, `uso`, `ingredientes` e `invima`, porque los edita la web.

## Adaptador

```php
<?php
declare(strict_types=1);

namespace App\Features\Product;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

final class IntranetProductCatalog extends AbstractRepository implements ProductCatalog
{
    // Solo lo que usa la app. `descuento` y `old_price` no se leen nunca.
    // Las columnas bit(1) llegan como bytes ("\x00" es verdadero en PHP): se leen como número.
    private const string COLUMNS = 'codigo, orden, producto, valor, category, presentation, ruta, intro,
        imageswebp, grupo, agotado + 0 AS agotado, nuevo + 0 AS nuevo, sap_code, sap_name';
    private const string CONTENT_COLUMNS = 'description, uso, ingredientes, invima, video, tablasjpg';

    // Lo que entra a la app: activo, sin promoción, con nombre y con precio.
    // En SQL, `activo = 1` compara el bit como número; un `activo` NULL no entra.
    private const string SELLABLE = "activo = 1 AND promo = 0 AND valor > 0 AND COALESCE(producto, '') <> ''";

    public function __construct(private readonly string $table)
    {
        parent::__construct(DB::getInstance());
    }

    public static function fromEnv(): self
    {
        $database = $_ENV['INTRANET_DB_NAME'];
        // El nombre de la base va en el SQL (no se puede pasar como parámetro): se valida.
        if (!preg_match('/^[a-z0-9_]+$/', $database)) {
            throw new \InvalidArgumentException('INTRANET_DB_NAME no es válido');
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
        if ($codes === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($codes), '?'));
        $sql = 'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE codigo IN ($marks) AND " . self::SELLABLE;

        $byCode = [];
        foreach ($this->map($sql, array_values($codes)) as $product) {
            $byCode[$product->code] = $product;
        }
        return $byCode;
    }

    public function presentationsOf(int $code): array
    {
        $sql = 'SELECT ' . self::COLUMNS . " FROM {$this->table}
                WHERE grupo = (SELECT grupo FROM {$this->table} WHERE codigo = ?)
                  AND grupo <> '' AND codigo <> ? AND " . self::SELLABLE . '
                ORDER BY orden';

        return $this->map($sql, [$code, $code]);
    }

    /**
     * @param list<mixed> $params
     * @return list<CatalogProduct>
     */
    private function map(string $sql, array $params, bool $withContent = false): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $row) => CatalogProduct::fromIntranetRow($row, $withContent),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
```

Usa `FETCH_ASSOC` y no `FETCH_CLASS`, como el resto de radical, porque las columnas cambian de nombre, de tipo y de forma (JSON y HTML) al pasar a la estructura de la app.

## Caché

```php
final readonly class CachedProductCatalog implements ProductCatalog
{
    public function __construct(
        private ProductCatalog $inner,
        private CacheService $cache,
        private int $ttl,
    ) {}

    public function available(?string $category = null): array
    {
        $key = 'catalog.available.' . md5($category ?? '');
        return $this->cache->remember($key, $this->ttl, fn () => $this->inner->available($category));
    }

    public function findByCode(int $code): ?CatalogProduct
    {
        return $this->cache->remember("catalog.product.$code", $this->ttl, fn () => $this->inner->findByCode($code));
    }

    // Carrito, checkout y canje van siempre a la fuente: se cobra el precio vigente.
    public function findByCodes(array $codes): array
    {
        return $this->inner->findByCodes($codes);
    }

    public function presentationsOf(int $code): array
    {
        return $this->cache->remember("catalog.group.$code", $this->ttl, fn () => $this->inner->presentationsOf($code));
    }
}
```

Al arrancar:

```php
$catalog = new CachedProductCatalog(
    IntranetProductCatalog::fromEnv(),
    CacheService::fromEnv(),
    (int) $_ENV['PRODUCT_CATALOG_CACHE_SECONDS'],
);
```

## IVA

```php
final readonly class VatPolicy
{
    /** @param list<int> $exemptCodes */
    public function __construct(private int $percentage, private array $exemptCodes) {}

    public static function fromEnv(): self { /* lee VAT_PERCENTAGE y VAT_EXEMPT_PRODUCT_CODES */ }

    public function rateFor(int $productCode): int
    {
        return in_array($productCode, $this->exemptCodes, true) ? 0 : $this->percentage;
    }
}
```

En cada línea, sobre lo que paga el cliente con el descuento aplicado:

- **Con IVA:** base = pagado ÷ (1 + tasa/100); IVA = pagado − base.
- **Exento:** base = pagado; IVA = 0.

La comisión sale de la base (S-39 del DER). Ejemplos que deben quedar en las pruebas:

| Pagado | Tasa | Base | IVA | Comisión 15 % |
| --- | --- | --- | --- | --- |
| 119.000 | 19 | 100.000 | 19.000 | 15.000 |
| 224.910 (Bi-Pro Classic con 10 %) | 19 | 189.000 | 35.910 | 28.350 |
| 119.000 (Nutra C) | 0 | 119.000 | 0 | 17.850 |

Los importes son `decimal(12,2)`. La base se redondea a dos decimales y el IVA es la diferencia, para que base + IVA dé siempre lo pagado.

## Variables de entorno nuevas

```
INTRANET_DB_NAME=crisenri_intranet
PRODUCT_CATALOG_CACHE_SECONDS=600
VAT_PERCENTAGE=19
VAT_EXEMPT_PRODUCT_CODES=103040   # Nutra C
```

## Riesgos

| Riesgo | Mitigación |
| --- | --- |
| Las columnas `bit(1)` llegan como bytes y `(bool) "\x00"` es verdadero: un producto disponible saldría agotado. | Se leen como número en el SQL (`agotado + 0`) y se compara con `=== 1`. Hay una prueba para cada valor. |
| La web edita la tabla con SQL armado por concatenación; un dato raro puede llegar a la app. | La app convierte tipos, limpia el HTML y tolera JSON mal formado. Solo lee. |
| Un cambio de la web (precio, promoción, desactivación) cambia la app de inmediato. | Es lo que se busca: una sola fuente. El checkout vuelve a leer y rechaza lo que ya no se vende. El histórico vive en `orders_items`. |
| Un producto pasa a `promo = 1` con unidades en un carrito. | `findByCodes` no lo devuelve y el checkout avisa que salió del catálogo. |
| La conexión a otra base necesita permisos. | Usuario con `SELECT` solo sobre esa tabla; el nombre de la base se valida. |
| Las pruebas necesitan la tabla. | La migración de pruebas crea `tb_productos_megaplexstars` con las columnas que se leen. |

## Orden de trabajo

1. ~~Confirmar la estructura en la base y el código de Nutra C~~ (hecho el 15 nov 2026: 42 columnas; Nutra C = 103040).
2. Variables de entorno y su validación en el arranque.
3. `CatalogProduct`, `ProductImages`, `ProductContent` y sus pruebas unitarias.
4. `IntranetProductCatalog` con pruebas de integración contra la tabla de pruebas.
5. `CachedProductCatalog` y los endpoints.
6. `VatPolicy` y la copia de los datos del producto en `orders_items`, dentro del spec de compras.
