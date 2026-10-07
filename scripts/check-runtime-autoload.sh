#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BOOTSTRAP="$ROOT/package/modules/gateways/pagou/bootstrap.php"

[ -f "$BOOTSTRAP" ] || { echo "bootstrap runtime ausente" >&2; exit 1; }
php -l "$BOOTSTRAP" >/dev/null

if rg -n 'vendor/autoload|require.+vendor' "$ROOT/package/modules"; then
  echo "o pacote runtime nao pode depender de vendor" >&2
  exit 1
fi

php -r "require '$BOOTSTRAP'; if (!defined('PAGOU_WHMCS_AUTOLOADER_REGISTERED')) { exit(1); }"
echo "runtime autoload OK"
