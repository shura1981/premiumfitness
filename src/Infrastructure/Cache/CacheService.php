<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Caché PSR-16 sobre disco (sin Redis, compatible con cPanel).
 *
 * Se usa para datos de lectura pública que toleran unos minutos de desfase,
 * como el catálogo de la tienda. Nunca para datos por usuario.
 */
final readonly class CacheService
{
    private const string DEFAULT_DIR = __DIR__ . '/../../../cache/data';
    private const int DEFAULT_TTL = 300;

    private CacheInterface $cache;

    public function __construct(string $directory = self::DEFAULT_DIR, int $defaultLifetime = self::DEFAULT_TTL)
    {
        $this->cache = new Psr16Cache(
            new FilesystemAdapter(namespace: '', defaultLifetime: $defaultLifetime, directory: $directory)
        );
    }

    public static function fromEnv(): self
    {
        return new self(
            directory: (string) ($_ENV['CACHE_DIR'] ?? self::DEFAULT_DIR),
            defaultLifetime: (int) ($_ENV['CACHE_TTL'] ?? self::DEFAULT_TTL),
        );
    }

    /**
     * Devuelve el valor cacheado o ejecuta $producer y lo guarda.
     *
     * @template T
     * @param callable(): T $producer
     * @return T
     */
    public function remember(string $key, int $ttl, callable $producer): mixed
    {
        /** @var T|null $cached */
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        $this->cache->set($key, $value, $ttl);

        return $value;
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }
}
