#!/usr/bin/env bash
# Brings a fresh checkout to a running, seeded state.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Checking required PHP extensions"
for ext in bcmath pdo_mysql redis intl mbstring; do
    php -m | grep -qi "^${ext}$" || {
        echo "MISSING PHP extension: ${ext}" >&2
        echo "bcmath in particular is mandatory - all money maths depends on it." >&2
        exit 1
    }
done

echo "==> Installing PHP dependencies"
composer install --no-interaction

if [ ! -f .env ]; then
    echo "==> Creating .env"
    cp .env.example .env
    php artisan key:generate --force
fi

echo "==> Waiting for MySQL"
for i in $(seq 1 30); do
    if php artisan db:show >/dev/null 2>&1; then break; fi
    [ "$i" = "30" ] && { echo "MySQL did not become reachable" >&2; exit 1; }
    sleep 2
done

echo "==> Running migrations"
php artisan migrate --force

echo "==> Seeding reference data"
php artisan db:seed --force

echo
echo "Ready. Useful commands:"
echo "  php artisan serve            start the API"
echo "  composer test                run the full suite"
echo "  composer test:ledger         run only the ledger invariant tests"
echo "  php artisan ledger:reconcile verify the books balance"
