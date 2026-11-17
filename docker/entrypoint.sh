#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Copy .env.example to .env and generate one with: printf 'base64:%s' \"\$(openssl rand -base64 32)\"" >&2
    exit 1
fi

exec "$@"
