#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for command in php composer python3 node unzip shasum; do
  command -v "$command" >/dev/null || { echo "ferramenta obrigatória ausente: $command" >&2; exit 1; }
done
for binary in phpunit phpcs phpstan; do
  [ -x "$ROOT/vendor/bin/$binary" ] || { echo "execute composer install: $binary ausente" >&2; exit 1; }
done
