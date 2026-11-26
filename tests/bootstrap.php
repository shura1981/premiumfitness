<?php

declare(strict_types=1);

// Reuses the production bootstrap: loads .env, validates required vars,
// sets timezone. Tests run against the same DB the dev server uses.
require_once __DIR__ . '/../load/bootstrap.php';

// Las pruebas nunca envían SMS reales: los mensajes quedan en memoria (InMemorySmsChannel).
$_ENV['SMS_DRIVER'] = 'memory';

// Los avisos en tiempo real (gateway de sockets) nunca salen de la máquina: el puerto 9 de la propia
// máquina rechaza la conexión al instante, y el aviso, que no es crítico, se descarta.
$_ENV['SOCKET_NOTIFICATION_URL'] = 'http://127.0.0.1:9';

// Las pruebas nunca llaman a Addi ni a Sistecrédito: el .env local tiene las credenciales reales
// (las del sitio de Nutramerican). Igual que el socket, sus API apuntan a un puerto cerrado.
foreach (['ADDI_URL_TOKEN', 'ADDI_URL_REDIRECTION', 'SISTECREDITO_URL_CREATE_ORDER', 'SISTECREDITO_URL_QUERY_TRANSACTION'] as $gatewayUrl) {
    $_ENV[$gatewayUrl] = 'http://127.0.0.1:9/' . strtolower($gatewayUrl);
}

// Sin caché del catálogo: las pruebas cambian productos y leen el resultado de inmediato.
$_ENV['PRODUCT_CATALOG_CACHE_SECONDS'] = '0';

// Las pruebas crean productos y agentes: lo hacen en su propia base, nunca en crisenri_intranet.
$_ENV['INTRANET_DB_NAME'] = Tests\Support\TestIntranet::DATABASE;
Tests\Support\TestIntranet::prepare();

App\Infrastructure\Database\DB::boot(
    App\Infrastructure\Database\DatabaseConfig::fromEnv()
);
