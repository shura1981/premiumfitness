<?php

declare(strict_types=1);

namespace App\Features\Product;

/**
 * Catálogo de productos que se venden en la app (spec catalogo/01).
 * Quien la use no sabe de dónde salen los productos: solo recibe `CatalogProduct`.
 */
interface ProductCatalog
{
    /** @return list<CatalogProduct> En el orden de la web. */
    public function available(?string $category = null): array;

    /** Con el contenido de la ficha; null si no existe o no se vende en la app. */
    public function findByCode(int $code): ?CatalogProduct;

    /**
     * Para el carrito, el checkout y el canje: siempre de la fuente, sin caché.
     *
     * @param list<int> $codes
     * @return array<int, CatalogProduct> Indexado por código; faltan los que no se venden.
     */
    public function findByCodes(array $codes): array;

    /** @return list<CatalogProduct> Las demás presentaciones del mismo grupo. */
    public function presentationsOf(int $code): array;

    /** @return list<CatalogProduct> Búsqueda por nombre o código, para el admin. */
    public function search(string $term, int $limit = 20): array;
}
