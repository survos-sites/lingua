#!/bin/bash
set -e

# Defaults
RUN_MIGRATIONS=false
RUN_ASSETS=false
RUN_SECRETS=false
RUN_LOCAL=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --migrations) RUN_MIGRATIONS=true ;;
        --assets) RUN_ASSETS=true ;;
        --secrets) RUN_SECRETS=true ;;
        --prod) RUN_MIGRATIONS=true; RUN_ASSETS=true; RUN_SECRETS=true ;;
        # FrankenPHP: assets are compiled into the image at build time, so a
        # deploy only needs secrets + migrations. Running asset-map:compile here
        # would redo build work against a read-only-ish app dir on every release.
        --frankenphp) RUN_MIGRATIONS=true; RUN_SECRETS=true ;;
        --local) RUN_LOCAL=true ;;
    esac
done

# Local dev setup
if [ "$RUN_LOCAL" = true ]; then
    if ! grep -q "^DATABASE_URL=.*sqlite" .env.local 2>/dev/null; then
        echo "DATABASE_URL=\"sqlite:///%kernel.project_dir%/var/data.db\"" >> .env.local
        echo "Added sqlite DATABASE_URL to .env.local"
    fi
    php bin/console doctrine:schema:update --force
fi

# Always run these
php bin/console messenger:stop-workers --env=prod 2>/dev/null || true
if [ "$RUN_ASSETS" = true ]; then
    php bin/console importmap:install
fi
# php bin/console fos:js-routing:dump --format=js --target=public/js/fos_js_routes.js --callback="export default "

# Assets/secrets (production only)
# Secrets must be decrypted at DEPLOY time, not build time: SYMFONY_DECRYPTION_SECRET
# is a dokku config var and is not present in the build container.
if [ "$RUN_SECRETS" = true ]; then
    php bin/console secrets:decrypt-to-local --force --env=prod 2>/dev/null || true
fi

if [ "$RUN_ASSETS" = true ]; then
    php bin/console asset-map:compile
fi

# Migrations (production only)
if [ "$RUN_MIGRATIONS" = true ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

echo "predeploy complete"
