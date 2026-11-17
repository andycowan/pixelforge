# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.4-bookworm AS php-base

RUN install-php-extensions pdo_pgsql pcntl

# Assets are built with PHP available because Wayfinder runs artisan during Vite builds.
FROM php-base AS assets

ARG NODE_VERSION=26.13.1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .

RUN composer dump-autoload --optimize --no-scripts \
    && php artisan package:discover --ansi

RUN case "$(uname -m)" in \
        x86_64|amd64) node_arch=x64 ;; \
        aarch64|arm64) node_arch=arm64 ;; \
        *) echo "Unsupported architecture: $(uname -m)" >&2; exit 1 ;; \
    esac \
    && curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${node_arch}.tar.gz" \
        | tar -xz -C /usr/local --strip-components=1

RUN npm ci --no-audit --no-fund \
    && npm run check \
    && npm run build \
    && npm run types:check \
    && node --test tests/frontend/*.test.mjs


FROM php-base AS app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-memory.ini

COPY --from=assets /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN php artisan package:discover --ansi \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

COPY Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/supervisord.conf /etc/supervisor/pixelforge.conf
COPY docker/exit-on-fatal.py /usr/local/bin/exit-on-fatal.py
COPY docker/healthcheck.sh /usr/local/bin/app-healthcheck
RUN chmod +x /usr/local/bin/app-entrypoint /usr/local/bin/app-healthcheck \
    && /usr/bin/python3 -m unittest discover -s tests/runtime

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=5s --start-period=15s --retries=3 \
    CMD app-healthcheck

ENTRYPOINT ["app-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/pixelforge.conf"]
