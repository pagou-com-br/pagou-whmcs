#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [ -x vendor/bin/phpstan ]; then
  vendor/bin/phpstan analyse --configuration phpstan.neon --no-progress --memory-limit=512M
  exit 0
fi

echo "phpstan indisponivel, execute composer install antes da analise" >&2
exit 1
