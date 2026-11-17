#!/usr/bin/env bash
# Read-only smoke check against the running local gateway or public Funnel URL.
set -euo pipefail
base="${1:-http://127.0.0.1:18081}"
base="${base%/}"

for path in / /up /dashboard /login /.env /webhooks/pixelforge /webhooks/pixelforge/; do
    status=$(curl --max-time 15 --silent --show-error --output /dev/null --write-out '%{http_code}' "$base$path")
    [[ "$status" == 404 ]] || { echo "Unexpected GET $path: $status" >&2; exit 1; }
done

for path in / /dashboard /webhooks/pixelforge/; do
    status=$(curl --max-time 15 --silent --show-error --request POST --output /dev/null --write-out '%{http_code}' "$base$path")
    [[ "$status" == 404 ]] || { echo "Unexpected POST $path: $status" >&2; exit 1; }
done

response=$(curl --max-time 15 --silent --show-error --request POST --header 'Content-Type: application/json' --data-binary '{}' --write-out $'\n%{http_code}' "$base/webhooks/pixelforge")
# An unsigned request is rejected; unconfigured provider credentials fail closed.
[[ "$response" == $'Webhook request rejected.\n400' || "$response" == $'Webhook request rejected.\n503' ]] || {
    echo 'Unsigned webhook did not produce a sanitised rejection.' >&2
    exit 1
}
echo 'Webhook-only routing and sanitised unsigned rejection pass.'
