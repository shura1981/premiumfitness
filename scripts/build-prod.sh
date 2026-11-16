#!/usr/bin/env bash

# Detener si hay un error
set -e

# Mostrar comandos
set -x

echo "🛑 Iniciando el proceso de empaquetado para Producción..."

PROJECT_DIR=$(pwd)
BUILD_DIR="${PROJECT_DIR}/build"

echo "🧹 Borrando build anterior si existe..."
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"

echo "🔄 Ejecutando Composer (solo producción) previo a copiar..."
# 1️⃣ Instala solo el vendor de producción
composer run build

echo "📦 Copiando directorios y archivos necesarios..."
# 2️⃣ Copiamos directorios
cp -r app "${BUILD_DIR}/"
cp -r assets "${BUILD_DIR}/"
cp -r cron-jobs "${BUILD_DIR}/"

# Las apps Angular no van aquí: se despliegan en sus subdominios (spec arquitectura/02).
cp -r load "${BUILD_DIR}/"
cp -r logs "${BUILD_DIR}/"
cp -r src "${BUILD_DIR}/"

# Ahora ya podemos copiar vendor porque arriba le quitamos dev
cp -r vendor "${BUILD_DIR}/"
cp -r views "${BUILD_DIR}/"

# Copiamos archivos sueltos
if [ -f ".prod.env" ]; then cp .prod.env "${BUILD_DIR}/"; fi
if [ -f ".htaccess" ]; then cp .htaccess "${BUILD_DIR}/"; fi
cp index.php "${BUILD_DIR}/"

echo "📝 Limpiando campos innecesarios de composer.json..."
# Copiar composer.json pero eliminando bloques de desarrollo ("scripts", "autoload-dev", "require-dev") usando PHP
php -r "\$data = json_decode(file_get_contents('composer.json'), true); unset(\$data['scripts'], \$data['autoload-dev'], \$data['require-dev']); file_put_contents('${BUILD_DIR}/composer.json', json_encode(\$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));"

echo "🧹 Limpiando contenido de logs..."
# Eliminar TODO el contenido de logs (archivos, carpetas ocultas, subcarpetas, etc) 
# para que /build/logs quede completamente vacío
rm -rf "${BUILD_DIR}/logs/"*
rm -rf "${BUILD_DIR}/logs/".* 2>/dev/null || true

echo "🔄 Renombrando .prod.env a .env..."
# 4️⃣ Renombrar .prod.env a .env (para el entorno en el servidor de destido)
if [ -f "${BUILD_DIR}/.prod.env" ]; then
    mv "${BUILD_DIR}/.prod.env" "${BUILD_DIR}/.env"
else
    echo "⚠️ No se encontró .prod.env para renombrar."
fi

# 5️⃣ Restaurar dependencias locales
# Como borramos (arriba) la carpeta vendor de testing (Pest/PHPStan) al usar 'composer run build', 
# la volvemos a instalar aquí para que tú puedas seguir trabajando localmente con tus tests después de generar el build de Prod.
echo "🔄 Restaurando dependencias de dev locales..."
composer run install:dev

echo "✅ Proceso completado exitosamente."
echo "➡️  Los archivos listos para subir a producción están en la carpeta: ${BUILD_DIR}"
