#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_VERSION_ID="$(php -r 'echo PHP_VERSION_ID;')"
[ "$PHP_VERSION_ID" -ge 80100 ] || {
  echo "PHP 8.1 ou superior e obrigatorio" >&2
  exit 1
}

if command -v composer >/dev/null 2>&1; then
  composer check-platform-reqs --no-dev --no-interaction
else
  echo "composer indisponivel, verificando o requisito PHP diretamente" >&2
fi

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find package/modules -type f -name '*.php' -print0)

echo "compatibilidade PHP OK"
