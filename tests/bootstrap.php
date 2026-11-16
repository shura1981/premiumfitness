<?php

declare(strict_types=1);

// Reuses the production bootstrap: loads .env, validates required vars,
// sets timezone. Tests run against the same DB the dev server uses.
require_once __DIR__ . '/../load/bootstrap.php';

// Las pruebas nunca envían SMS reales: los mensajes quedan en memoria (InMemorySmsChannel).
$_ENV['SMS_DRIVER'] = 'memory';

// Sin caché del catálogo: las pruebas cambian productos y leen el resultado de inmediato.
$_ENV['PRODUCT_CATALOG_CACHE_SECONDS'] = '0';

App\Infrastructure\Database\DB::boot(
    App\Infrastructure\Database\DatabaseConfig::fromEnv()
);
