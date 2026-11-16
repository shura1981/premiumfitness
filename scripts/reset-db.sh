#!/usr/bin/env bash
# Recrea la base de datos LOCAL de PremiumFitness (contenedor `premiumfitness-db`):
# esquema heredado de radical + sus migraciones + datos de prueba, y la copia mínima de la intranet.
# Uso: scripts/reset-db.sh           (con datos de prueba)
#      scripts/reset-db.sh --clear   (solo el esquema)
# Nunca se usa contra el hosting: exige que DB_HOST sea local.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"

get_env_var() {
    [ -f "$ENV_FILE" ] && grep -E "^$1=" "$ENV_FILE" | head -n 1 | cut -d '=' -f 2- | sed -e 's/^["\x27]//' -e 's/["\x27]$//' -e 's/\r//' || true
}

DB_HOST=$(get_env_var DB_HOST);           DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=$(get_env_var DB_PORT);           DB_PORT=${DB_PORT:-3313}
DB_NAME=$(get_env_var DB_NAME);           DB_NAME=${DB_NAME:-crisenri_premiumfitness}
DB_USER=$(get_env_var DB_USER);           DB_USER=${DB_USER:-premiumfitness}
DB_ROOT_PASS=$(get_env_var DB_ROOT_PASS)

if [ "$DB_HOST" != "127.0.0.1" ] && [ "$DB_HOST" != "localhost" ]; then
    echo "❌ DB_HOST=$DB_HOST no es local. Este script borra la base: solo corre en local."
    exit 1
fi
if [ -z "$DB_ROOT_PASS" ]; then
    echo "❌ Falta DB_ROOT_PASS en .env (la contraseña root del contenedor local)."
    exit 1
fi

SCHEMA_FILE="$ROOT_DIR/docs/database/schema/crisenri_radical_nutrition_clear.sql"
SEED_FILE="$ROOT_DIR/docs/database/seed/dev-user-addresses.sql"
INTRANET_FILE="$ROOT_DIR/docker/intranet-local.sql"
# Migraciones posteriores al volcado del esquema, en orden: las de radical y después las de PremiumFitness.
MIGRATIONS=(
    migrate_users_id_bigint.sql
    add_retail_account_email_verification.sql
    add_products_updated_at.sql
    create_retail_checkout_support.sql
    add_bold_to_orders_payment_gateway.sql
    premiumfitness_01_der_base.sql
    premiumfitness_02_autenticacion.sql
    premiumfitness_03_catalogo_y_compras.sql
)

sql() { MYSQL_PWD="$DB_ROOT_PASS" mariadb --skip-ssl -h "$DB_HOST" -P "$DB_PORT" -u root "$@"; }

echo "🔄 Recreando '$DB_NAME' en $DB_HOST:$DB_PORT..."
sql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';"

echo "📦 Esquema..."
sql "$DB_NAME" < "$SCHEMA_FILE"

for migration in "${MIGRATIONS[@]}"; do
    echo "➕ Migración $migration"
    sql "$DB_NAME" < "$ROOT_DIR/docs/database/migrations/$migration"
done

if [ "${1:-}" != "--clear" ]; then
    echo "🌱 Datos de prueba..."
    sql "$DB_NAME" < "$SEED_FILE"
fi

echo "🏢 Copia local de la intranet (empleados y productos)..."
sql < "$INTRANET_FILE"
sql -e "GRANT SELECT ON crisenri_intranet.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"

echo "🎉 Listo."
