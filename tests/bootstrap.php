<?php

declare(strict_types=1);

// Reuses the production bootstrap: loads .env, validates required vars,
// sets timezone. Tests run against the same DB the dev server uses.
require_once __DIR__ . '/../load/bootstrap.php';

// Las pruebas nunca envían SMS reales: los mensajes quedan en memoria (InMemorySmsChannel).
$_ENV['SMS_DRIVER'] = 'memory';

// Sin caché del catálogo: las pruebas cambian productos y leen el resultado de inmediato.
$_ENV['PRODUCT_CATALOG_CACHE_SECONDS'] = '0';

// Las pruebas crean productos y agentes: lo hacen en su propia base, nunca en crisenri_intranet.
$_ENV['INTRANET_DB_NAME'] = Tests\Support\TestIntranet::DATABASE;
Tests\Support\TestIntranet::prepare();

App\Infrastructure\Database\DB::boot(
    App\Infrastructure\Database\DatabaseConfig::fromEnv()
);
