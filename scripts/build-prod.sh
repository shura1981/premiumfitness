#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname -- "${SCRIPT_DIR}")"
BUILD_DIR="${PROJECT_DIR}/build"
ENV_FILE="${PROJECT_DIR}/.env.prod"

cd -- "${PROJECT_DIR}"

if [[ ! -f "${ENV_FILE}" ]]; then
    echo "❌ Falta .env.prod en la raíz del proyecto. Consulta docs/despliegue.md." >&2
    exit 1
fi

echo "📦 Preparando el build de producción de PremiumFitness..."
STAGING_DIR="$(mktemp -d "${PROJECT_DIR}/.build-prod.XXXXXX")"
trap 'rm -rf -- "${STAGING_DIR}"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Solo se copia código de ejecución. Los datos locales de escritura quedan fuera.
for directory in app assets cron-jobs load src views; do
    cp -R -- "${directory}" "${STAGING_DIR}/"
done
cp -- index.php .htaccess composer.json composer.lock "${STAGING_DIR}/"
cp -- "${ENV_FILE}" "${STAGING_DIR}/.env"
chmod 600 "${STAGING_DIR}/.env"
mkdir -p -- "${STAGING_DIR}/cache/twig" "${STAGING_DIR}/cache/data" \
    "${STAGING_DIR}/logs" "${STAGING_DIR}/temp" \
    "${STAGING_DIR}/storage/private/reports" "${STAGING_DIR}/storage/private/receipts"

echo "🔄 Instalando dependencias de producción desde composer.lock..."
(
    cd -- "${STAGING_DIR}"
    # Se reutiliza el comando de dependencias, aislado del vendor/ de desarrollo.
    composer --no-interaction run build

    echo "🔎 Validando la configuración de producción..."
    php /dev/stdin "${PROJECT_DIR}" <<'PHP'
<?php

declare(strict_types=1);

// El bootstrap solo valida el entorno; no levanta la app ni conecta con la base.
require 'load/bootstrap.php';

$environment = Dotenv\Dotenv::parse(file_get_contents('.env'));
if ($environment['APP_ENV'] !== 'production' || ($environment['SMS_DRIVER'] ?? '') !== 'relax') {
    fwrite(STDERR, "❌ .env.prod debe usar APP_ENV=production y SMS_DRIVER=relax.\n");
    exit(1);
}

// Estas rutas deben permanecer dentro del paquete y protegidas por .htaccess.
foreach (['REPORTS_STORAGE_PATH', 'RECEIPTS_STORAGE_PATH', 'FIREBASE_CREDENTIALS_PATH'] as $key) {
    $path = $environment[$key];
    if (!str_starts_with($path, 'storage/private/') || str_contains($path, '..')) {
        fwrite(STDERR, "❌ {$key} debe ser una ruta relativa dentro de storage/private/.\n");
        exit(1);
    }

    $directory = $key === 'FIREBASE_CREDENTIALS_PATH' ? dirname($path) : $path;
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
        throw new RuntimeException("No se pudo crear el directorio de {$key}.");
    }
}

$credentialPath = $environment['FIREBASE_CREDENTIALS_PATH'];
$credentialSource = $argv[1] . '/' . $credentialPath;
if (is_file($credentialSource)) {
    if (!copy($credentialSource, $credentialPath) || !chmod($credentialPath, 0600)) {
        throw new RuntimeException('No se pudo copiar la credencial de Firebase.');
    }
} else {
    fwrite(STDERR, "⚠️ Falta la cuenta de servicio de Firebase; el envío push requiere configurarla en el servidor.\n");
}

// Igual que en el repositorio de referencia: manifiesto sin comandos ni bloques de desarrollo.
$manifest = json_decode(file_get_contents('composer.json'), true, flags: JSON_THROW_ON_ERROR);
unset($manifest['scripts'], $manifest['autoload-dev'], $manifest['require-dev']);
file_put_contents('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
// El lock original ya se usó para instalar; no corresponde al manifiesto reducido.
unlink('composer.lock');
PHP
)

# El build anterior solo se sustituye después de instalar y validar el nuevo.
rm -rf -- "${BUILD_DIR}"
mv -- "${STAGING_DIR}" "${BUILD_DIR}"

echo "✅ Build generado en: ${BUILD_DIR}"
du -sh -- "${BUILD_DIR}"
echo "📌 En el hosting: permite escritura de PHP en cache/, logs/, temp/ y storage/private/."
echo "📌 Revisa las credenciales heredadas y los valores pendientes de .env.prod (docs/despliegue.md)."
