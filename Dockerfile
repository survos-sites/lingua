# syntax=docker/dockerfile:1.7

# FrankenPHP 2 doesn't exist yet -- latest stable is on the 1.x line. Floating on
# 1 still gets patch releases on the current PHP 8.5 build.
ARG FRANKENPHP_VERSION=1
ARG PHP_VERSION=8.5

# ---- base: OS packages + PHP extensions FrankenPHP needs to run this app.
# Shared by both stages below and cached as one layer across deploys -- it only
# rebuilds when this file changes, not when application code changes.
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

WORKDIR /app

# Derived by scanning composer.lock for every `ext-*` required by ANY package, not
# just lingua's own require block. Everything omitted is already compiled into the
# base image (ctype, dom, filter, iconv, json, libxml, mbstring, pcre, pdo, phar,
# simplexml, tokenizer, xml, xmlwriter, zlib).
#
# Worth noting:
#   pdo_pgsql - the production DSN is postgresql://...; NOTHING in composer.lock
#               declares it, because the driver is only needed at runtime. Miss it
#               and the image builds clean then fails to connect.
#   sqlite3 /
#   pdo_sqlite- lingua really does use both: bin/predeploy.sh --local swaps
#               DATABASE_URL to sqlite for local runs, and folio/translation
#               artifacts are SQLite.
#   redis     - a dependency declares ext-redis, so composer install fails on
#               platform requirements without it, independently of whether lingua
#               uses Redis at runtime.
#   sodium    - lingua's own require; Symfony's secrets vault uses it.
RUN install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        pdo_sqlite \
        redis \
        sockets \
        sodium \
        sqlite3 \
        xsl \
        zip

COPY Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini $PHP_INI_DIR/conf.d/app.ini

# ---- build: composer + asset-mapper. Needs the full toolchain but none of it
# ships in the final image. No Node/npm stage -- asset-mapper compiles
# importmap-managed assets directly, unlike Encore.
FROM base AS build

RUN install-php-extensions @composer

# Everything here runs as root (no USER switch). Composer detects that and silently
# disables all plugins in --no-interaction mode unless told otherwise -- including
# symfony/runtime's, which generates the bootstrap glue bin/console needs. Without
# this, bin/console fails with "Symfony Runtime is missing" right after
# dump-autoload even though composer install reported success.
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.cache/composer \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# These move here from bin/predeploy.sh, which used to run them on every deploy.
# Order matters and two are silent when missing:
#
#   cache:clear BEFORE asset-map:compile -- survos/js-twig-bundle's
#   FosRoutingCacheWarmer generates var/js_twig_bundle/generated/fos_routes.js and
#   asset-map:compile fails without it already on disk.
#
#   assets:install is NOT optional. composer's auto-scripts normally run it, but
#   --no-scripts above skips them and public/bundles/ is gitignored, so it is absent
#   from the build context dokku pushes. Without it every bundle-provided asset 404s
#   while public/assets/ serves fine -- which is what makes it confusing.
#
#   memory_limit=-1 or the build OOMs.
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && php -d memory_limit=-1 bin/console cache:clear --env=prod --no-debug \
    && php -d memory_limit=-1 bin/console assets:install public --env=prod \
    && php -d memory_limit=-1 bin/console importmap:install --env=prod \
    && php -d memory_limit=-1 bin/console asset-map:compile --env=prod

# ---- prod: base image + the built app. No composer, no .git, no build cache.
FROM base AS prod

ENV APP_ENV=prod APP_DEBUG=0

COPY --from=build --chown=www-data:www-data /app /app

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
