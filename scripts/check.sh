#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
bash scripts/check-toolchain.sh
composer validate --strict --no-check-publish
bash scripts/check-php-compat.sh
bash scripts/lint.sh
bash scripts/test.sh
bash scripts/typecheck.sh
node --test tests/UI/*.test.cjs
bash scripts/build-package.sh
bash scripts/verify-package.sh
bash scripts/check-public-tree.sh
echo "check público completo OK"
