#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Copy .env.example to .env and generate one with: printf 'base64:%s' \"\$(openssl rand -base64 32)\"" >&2
    exit 1
fi

if [ "${QUEUE_CONNECTION:-database}" != "database" ]; then
    echo "QUEUE_CONNECTION must be database for this supervised worker image." >&2
    exit 1
fi

if [ -z "${MERCURE_PUBLISHER_JWT_KEY:-}" ] || [ -z "${MERCURE_SUBSCRIBER_JWT_KEY:-}" ]; then
    echo "MERCURE_PUBLISHER_JWT_KEY and MERCURE_SUBSCRIBER_JWT_KEY are required. Generate each with: openssl rand -base64 32" >&2
    exit 1
fi

if [ "${MIGRATE_ON_START:-false}" = "true" ]; then
    php /app/artisan migrate --force --no-interaction
fi

exec "$@"
