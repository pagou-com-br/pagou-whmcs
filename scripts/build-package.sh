#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE="$ROOT/package/modules"
VERSION_FILE="$ROOT/package/VERSION"
OUTPUT_DIR="$ROOT/build"

[ -d "$SOURCE" ] || { echo "modules de instalacao ausente" >&2; exit 1; }
[ -f "$VERSION_FILE" ] || { echo "arquivo VERSION ausente" >&2; exit 1; }
VERSION="$(tr -d '[:space:]' < "$VERSION_FILE")"
case "$VERSION" in
  ''|*[!0-9A-Za-z.+-]*) echo "versao invalida" >&2; exit 1 ;;
esac

INSTALLED_VERSION="$ROOT/package/modules/addons/pagou_payments/VERSION"
INSTALLED_MANIFEST="$ROOT/package/modules/addons/pagou_payments/whmcs.json"
[ -f "$INSTALLED_VERSION" ] && [ -f "$INSTALLED_MANIFEST" ] || {
  echo "metadados instalaveis ausentes" >&2
  exit 1
}
[ "$(tr -d '[:space:]' < "$INSTALLED_VERSION")" = "$VERSION" ] || {
  echo "versoes do pacote estao divergentes" >&2
  exit 1
}

bash "$ROOT/scripts/check-runtime-autoload.sh"
mkdir -p "$OUTPUT_DIR"
ARCHIVE="$OUTPUT_DIR/pagou-whmcs-$VERSION.zip"
SUMS="$OUTPUT_DIR/SHA256SUMS"

python3 "$ROOT/scripts/package_archive.py" create "$SOURCE" "$ARCHIVE" >/dev/null

ARCHIVE_NAME="$(basename "$ARCHIVE")"
ARCHIVE_SHA="$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')"
printf '%s  %s\n' "$ARCHIVE_SHA" "$ARCHIVE_NAME" > "$SUMS"

echo "pacote criado: $ARCHIVE"
echo "checksum criado: $SUMS"
