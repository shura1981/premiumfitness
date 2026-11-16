<?php

declare(strict_types=1);

namespace App\Features\Auth;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Bloquea el login de una cuenta tras 10 contraseñas fallidas en 15 minutos (spec seguridad/01,
 * regla 9). Cuenta por cuenta y no por IP, para que repartir los intentos entre varias IP no sirva.
 * Los contadores van en disco: los comparten todos los procesos de LiteSpeed.
 */
class LoginThrottle
{
    private const MAX_FAILURES = 10;
    private const WINDOW       = '15 minutes';

    private readonly RateLimiterFactory $factory;

    public function __construct(string $id = 'login-failures', ?string $storageDir = null)
    {
        $this->factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => self::MAX_FAILURES, 'interval' => self::WINDOW],
            new CacheStorage(new FilesystemAdapter(directory: $storageDir ?? __DIR__ . '/../../../logs/rate-limiter')),
        );
    }

    /** Segundos que faltan para poder intentar de nuevo; 0 si no está bloqueada. */
    public function retryAfter(string $account): int
    {
        $limit = $this->factory->create($this->key($account))->consume(0);

        if ($limit->getRemainingTokens() > 0) {
            return 0;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    public function registerFailure(string $account): void
    {
        $this->factory->create($this->key($account))->consume(1);
    }

    public function clear(string $account): void
    {
        $this->factory->create($this->key($account))->reset();
    }

    private function key(string $account): string
    {
        return hash('sha256', mb_strtolower($account));
    }
}
