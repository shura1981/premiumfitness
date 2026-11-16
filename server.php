<?php

declare(strict_types=1);

/**
 * Router del servidor de desarrollo de PHP. Solo lo usa `composer run start` (`php -S`).
 *
 * Sirve como archivo únicamente lo que está en `assets/`; todo lo demás pasa por Slim, igual que
 * en el hosting, donde el `.htaccess` bloquea el resto de carpetas. Sin este filtro, `php -S`
 * entregaría cualquier archivo del proyecto que se pidiera por URL (`.env` incluido).
 */

if (php_sapi_name() !== 'cli-server') {
    die('Este archivo es exclusivo para el servidor de desarrollo CLI de PHP.');
}

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = realpath(__DIR__ . $path);

if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . '/assets/')) {
    return false; // PHP lo sirve directamente.
}

require __DIR__ . '/index.php';
