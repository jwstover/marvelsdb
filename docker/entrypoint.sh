#!/bin/bash
# Bootstraps a marvelsdb dev environment: config, dependencies, database
# schema, and card data. Idempotent — safe to re-run on every container start.
set -e

cd /app

export DATABASE_HOST="${DATABASE_HOST:-db}"
export DATABASE_NAME="${DATABASE_NAME:-marvelsdb}"
export DATABASE_USER="${DATABASE_USER:-root}"
export DATABASE_PASSWORD="${DATABASE_PASSWORD:-marvelsdb}"

if [ ! -f app/config/parameters.yml ]; then
    echo ">>> Writing app/config/parameters.yml"
    cat > app/config/parameters.yml <<EOF
parameters:
    database_host:     ${DATABASE_HOST}
    database_port:     ~
    database_name:     ${DATABASE_NAME}
    database_user:     ${DATABASE_USER}
    database_password: ${DATABASE_PASSWORD}

    captcha: ~

    mailer_transport:  smtp
    mailer_host:       127.0.0.1
    mailer_user:       ~
    mailer_password:   ~

    secret:            dev-secret-not-for-production

    # units: seconds
    cache_expiration:  600

    email_sender_address: dev@localhost
    email_sender_name: MarvelsDB Dev

    website_name: MarvelsDB (dev)
    # used as the i18n routing host — a bare hostname, not a URL
    website_url: localhost
    game_name: Marvel Champions
    publisher_name: FFG

    google_analytics_tracking_code: UA-00000000-1
    google_adsense_client: ca-pub-000000000000000
    google_adsense_slot: 0000000000
EOF
fi

if [ ! -d vendor ]; then
    echo ">>> Installing composer dependencies (first run only, takes a few minutes)"
    composer install --no-interaction --prefer-dist
fi

echo ">>> Waiting for database"
until php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
    sleep 2
done

echo ">>> Ensuring database schema is up to date"
php bin/console doctrine:schema:update --force

CARD_COUNT=$(php -r '
    $pdo = new PDO(
        "mysql:host=" . getenv("DATABASE_HOST") . ";dbname=" . getenv("DATABASE_NAME"),
        getenv("DATABASE_USER"),
        getenv("DATABASE_PASSWORD")
    );
    echo $pdo->query("SELECT COUNT(*) FROM card")->fetchColumn();
' 2>/dev/null || echo 0)
if [ "${CARD_COUNT:-0}" -eq 0 ]; then
    if [ ! -d marvelsdb-json-data ]; then
        echo ">>> Cloning card data (marvelsdb-json-data)"
        git clone --depth 1 https://github.com/zzorba/marvelsdb-json-data.git
    fi
    echo ">>> Importing card data"
    php bin/console app:import:std marvelsdb-json-data/
else
    echo ">>> Card data already imported ($CARD_COUNT cards)"
fi

echo ">>> Starting: $*"
exec "$@"
