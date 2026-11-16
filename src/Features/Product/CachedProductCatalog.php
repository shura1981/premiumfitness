<?php

declare(strict_types=1);

namespace App\Features\Product;

use App\Infrastructure\Cache\CacheService;

/**
 * Decorador con caché del catálogo. El listado y la ficha se guardan `PRODUCT_CATALOG_CACHE_SECONDS`;
 * el carrito, el checkout y el canje (`findByCodes`) van siempre a la fuente: se cobra el precio vigente.
 */
final readonly class CachedProductCatalog implements ProductCatalog
{
    public function __construct(
        private ProductCatalog $inner,
        private CacheService $cache,
        private int $ttl,
    ) {}

    /** El catálogo de la app: el de la intranet, con caché si `PRODUCT_CATALOG_CACHE_SECONDS` es mayor que 0. */
    public static function fromEnv(): ProductCatalog
    {
        $ttl   = (int) ($_ENV['PRODUCT_CATALOG_CACHE_SECONDS'] ?? 0);
        $inner = IntranetProductCatalog::fromEnv();

        return $ttl > 0 ? new self($inner, CacheService::fromEnv(), $ttl) : $inner;
    }

    public function available(?string $category = null): array
    {
        return $this->cache->remember('catalog.available.' . md5($category ?? ''), $this->ttl, fn (): array => $this->inner->available($category));
    }

    public function findByCode(int $code): ?CatalogProduct
    {
        // La caché no distingue «no existe» de «no guardado»: se guarda en una lista.
        return $this->cache->remember("catalog.product.{$code}", $this->ttl, fn (): array => [$this->inner->findByCode($code)])[0];
    }

    public function findByCodes(array $codes): array
    {
        return $this->inner->findByCodes($codes);
    }

    public function presentationsOf(int $code): array
    {
        return $this->cache->remember("catalog.group.{$code}", $this->ttl, fn (): array => $this->inner->presentationsOf($code));
    }

    public function search(string $term, int $limit = 20): array
    {
        return $this->inner->search($term, $limit);
    }
}
