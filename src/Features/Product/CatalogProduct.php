<?php

declare(strict_types=1);

namespace App\Features\Product;

/**
 * Un producto del catálogo, con la misma forma venga de donde venga.
 * No trae descuento ni precio anterior: el descuento lo da la app según el rol (spec catalogo/01).
 */
final readonly class CatalogProduct
{
    public function __construct(
        public int $code,
        public string $name,
        /** Precio completo, con IVA. */
        public int $price,
        public string $category,
        public ?string $presentation,
        public ?string $slug,
        public ?string $summary,
        public ProductImages $images,
        public ?string $group,
        public bool $isOutOfStock,
        public bool $isNew,
        /** Internos: no salen en la API de la app web. */
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
            category: trim((string) ($row['category'] ?? '')),
            presentation: self::text($row['presentation'] ?? null),
            slug: self::text($row['ruta'] ?? null),
            summary: self::text($row['intro'] ?? null),
            images: ProductImages::fromJson($row['imageswebp'] ?? null),
            group: self::text($row['grupo'] ?? null),
            isOutOfStock: (int) $row['agotado'] === 1,
            isNew: (int) ($row['nuevo'] ?? 0) === 1,
            // sap_code es NOT NULL: 0 significa que el producto no tiene artículo SAP.
            sapCode: (int) ($row['sap_code'] ?? 0) > 0 ? (int) $row['sap_code'] : null,
            sapName: self::text($row['sap_name'] ?? null),
            content: $withContent ? ProductContent::fromIntranetRow($row) : null,
        );
    }

    public function isPurchasable(): bool
    {
        return !$this->isOutOfStock && $this->price > 0;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
