#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [ -x vendor/bin/phpcs ]; then
  vendor/bin/phpcs --standard=phpcs.xml
  exit 0
fi

echo "phpcs indisponivel, execute composer install" >&2
exit 1
