#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/package/VERSION")"
ARCHIVE="${1:-$ROOT/build/pagou-whmcs-$VERSION.zip}"
SUMS="$(dirname "$ARCHIVE")/SHA256SUMS"

[ -f "$ARCHIVE" ] || { echo "pacote ausente: $ARCHIVE" >&2; exit 1; }
[ -f "$SUMS" ] || { echo "SHA256SUMS ausente: $SUMS" >&2; exit 1; }

ARCHIVE_NAME="$(basename "$ARCHIVE")"
EXPECTED="$(awk -v name="$ARCHIVE_NAME" '$2 == name {print $1}' "$SUMS")"
ACTUAL="$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')"
[ -n "$EXPECTED" ] && [ "$EXPECTED" = "$ACTUAL" ] || {
  echo "checksum invalido para $ARCHIVE_NAME" >&2
  exit 1
}

python3 "$ROOT/scripts/package_archive.py" verify "$ROOT/package/modules" "$ARCHIVE" >/dev/null

FIXTURE="$(mktemp -d "${TMPDIR:-/tmp}/pagou-whmcs-install.XXXXXX")"
cleanup() { rm -rf "$FIXTURE"; }
trap cleanup EXIT HUP INT TERM
unzip -qq "$ARCHIVE" -d "$FIXTURE"

if find "$FIXTURE" -mindepth 1 -maxdepth 1 ! -name modules -print -quit | grep -q .; then
  echo "pacote contem arquivos fora de modules/" >&2
  exit 1
fi
[ -f "$FIXTURE/modules/gateways/pagou/bootstrap.php" ] || {
  echo "bootstrap nao foi instalado no fixture" >&2
  exit 1
}
[ -f "$FIXTURE/modules/addons/pagou_payments/VERSION" ] || {
  echo "VERSION instalavel ausente" >&2
  exit 1
}
[ -f "$FIXTURE/modules/addons/pagou_payments/whmcs.json" ] || {
  echo "manifesto instalavel ausente" >&2
  exit 1
}
for component in addons/pagou_payments gateways/pagou_pix gateways/pagou_boleto gateways/pagou_creditcard; do
  [ -f "$FIXTURE/modules/$component/whmcs.json" ] || {
    echo "metadados WHMCS ausentes: $component" >&2
    exit 1
  }
  [ -f "$FIXTURE/modules/$component/logo.png" ] || {
    echo "logotipo WHMCS ausente: $component" >&2
    exit 1
  }
done
php -l "$FIXTURE/modules/gateways/pagou/bootstrap.php" >/dev/null

if grep -RnE 'vendor/autoload|require.+vendor' "$FIXTURE/modules"; then
  echo "pacote instalado depende de vendor/" >&2
  exit 1
fi

echo "fixture de instalacao OK"
