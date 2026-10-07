#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [ -x vendor/bin/phpunit ]; then
  vendor/bin/phpunit --configuration phpunit.xml
  exit 0
fi

echo "phpunit indisponivel, execute composer install antes dos testes" >&2
exit 1
