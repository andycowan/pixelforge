#!/bin/sh
set -eu

status=$(supervisorctl -c /etc/supervisor/pixelforge.conf status)
printf '%s\n' "$status" | awk '$2 != "RUNNING" { exit 1 } END { if (NR != 4) exit 1 }'
curl -fsS --max-time 2 http://127.0.0.1/up > /dev/null
