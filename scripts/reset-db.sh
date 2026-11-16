#!/usr/bin/env bash
# Recrea la base LOCAL de PremiumFitness, vacía, con el esquema completo.
# Uso: scripts/reset-db.sh
# Entra con DB_USER y DB_PASS del .env, como el de radical. No toca `crisenri_intranet`.
# En el hosting no se usa: allí el esquema se importa desde phpMyAdmin.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"

get_env_var() {
    [ -f "$ENV_FILE" ] && grep -E "^$1=" "$ENV_FILE" | head -n 1 | cut -d '=' -f 2- | sed -e 's/^["\x27]//' -e 's/["\x27]$//' -e 's/\r//' || true
}

DB_HOST=$(get_env_var DB_HOST); DB_HOST=${DB_HOST:-localhost}
DB_PORT=$(get_env_var DB_PORT); DB_PORT=${DB_PORT:-3306}
DB_NAME=$(get_env_var DB_NAME); DB_NAME=${DB_NAME:-crisenri_premiumfitness}
DB_USER=$(get_env_var DB_USER); DB_USER=${DB_USER:-root}
DB_PASS=$(get_env_var DB_PASS)

if [ "$DB_HOST" != "127.0.0.1" ] && [ "$DB_HOST" != "localhost" ]; then
    echo "❌ DB_HOST=$DB_HOST no es local. Este script borra la base: solo corre en local."
    exit 1
fi

SCHEMA_FILE="$ROOT_DIR/docs/database/schema/crisenri_premiumfitness.sql"

sql() { MYSQL_PWD="$DB_PASS" mariadb --skip-ssl -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$@"; }

echo "🔄 Recreando '$DB_NAME'..."
sql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "📦 Esquema..."
sql "$DB_NAME" < "$SCHEMA_FILE"

echo "🎉 Listo."
