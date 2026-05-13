#!/usr/bin/env bash
set -euo pipefail

cd /app

mkdir -p "var/cache/${APP_ENV:-dev}" "var/cache/${APP_ENV:-dev}/profiler" var/log public/uploads
chmod -R a+rwX var public/uploads

if [[ "${WAIT_FOR_DB:-1}" == "1" ]]; then
  until pg_isready -h "${DB_HOST:-database}" -p "${DB_PORT:-5432}" -U "${DB_USER:-app}" >/dev/null 2>&1; do
    echo "Waiting for database..."
    sleep 2
  done
fi

if [[ ! -f vendor/autoload.php || composer.lock -nt vendor/autoload.php ]]; then
  composer install --prefer-dist --no-interaction
fi

if [[ "${RUN_MIGRATIONS:-0}" == "1" ]]; then
  php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec "$@"
