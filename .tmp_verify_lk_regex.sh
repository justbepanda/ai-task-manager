#!/usr/bin/env bash
set -euo pipefail
cd /home/mof/ai-tasks-manager
docker compose exec -T php php -r '
require " vendor/autoload.php\;
\
