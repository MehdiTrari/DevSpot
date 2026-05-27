#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p var/cache var/log public/uploads var/share
chown -R www-data:www-data var public/uploads

runtime_env_conf="/etc/apache2/conf-available/devspot-runtime-env.conf"

{
  echo "# Generated at container startup"

  if [[ -n "${MAILER_DSN:-}" ]]; then
    printf 'SetEnv MAILER_DSN "%s"\n' "$MAILER_DSN"
  fi
} > "$runtime_env_conf"

a2enconf devspot-runtime-env >/dev/null

if [[ "${WAIT_FOR_DB:-1}" == "1" ]]; then
  until pg_isready -h "${DB_HOST:-database}" -p "${DB_PORT:-5432}" -U "${DB_USER:-app}" >/dev/null 2>&1; do
    echo "Waiting for database..."
    sleep 2
  done
fi

if [[ "${RUN_MIGRATIONS:-0}" == "1" ]]; then
  php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration
fi

exec "$@"
