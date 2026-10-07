#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE="$ROOT/package/brand/pagou-logo.svg"
TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/pagou-whmcs-logo.XXXXXX")"
OUTPUT="$TEMP_DIR/logo.png"

cleanup() { rm -rf "$TEMP_DIR"; }
trap cleanup EXIT HUP INT TERM

[ -f "$SOURCE" ] || { echo "vetor oficial ausente: $SOURCE" >&2; exit 1; }

if command -v rsvg-convert >/dev/null 2>&1; then
  rsvg-convert --width 500 "$SOURCE" --output "$OUTPUT"
elif command -v sips >/dev/null 2>&1; then
  sips -s format png --resampleWidth 500 "$SOURCE" --out "$OUTPUT" >/dev/null
else
  echo "instale librsvg (rsvg-convert) ou execute o script no macOS com sips" >&2
  exit 1
fi

php -r '
$path = $argv[1];
$png = file_get_contents($path);
if ($png === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    fwrite(STDERR, "o renderizador não produziu um PNG válido\n");
    exit(1);
}
$width = unpack("N", substr($png, 16, 4))[1];
$height = unpack("N", substr($png, 20, 4))[1];
$colorType = ord($png[25]);
if ($width !== 500 || $height !== 204 || $colorType !== 6) {
    fwrite(STDERR, "o logotipo deve ser PNG RGBA transparente de 500 x 204 pixels\n");
    exit(1);
}
$clean = substr($png, 0, 8);
$offset = 8;
while ($offset + 12 <= strlen($png)) {
    $length = unpack("N", substr($png, $offset, 4))[1];
    $type = substr($png, $offset + 4, 4);
    $chunk = substr($png, $offset, $length + 12);
    if (strlen($chunk) !== $length + 12) {
        fwrite(STDERR, "PNG truncado\n");
        exit(1);
    }
    if (!in_array($type, ["eXIf", "tEXt", "zTXt", "iTXt"], true)) {
        $clean .= $chunk;
    }
    $offset += $length + 12;
}
file_put_contents($path, $clean);
' "$OUTPUT"

for destination in \
  "$ROOT/package/modules/addons/pagou_payments/logo.png" \
  "$ROOT/package/modules/gateways/pagou_pix/logo.png" \
  "$ROOT/package/modules/gateways/pagou_boleto/logo.png" \
  "$ROOT/package/modules/gateways/pagou_creditcard/logo.png"
do
  cp "$OUTPUT" "$destination"
  chmod 0644 "$destination"
done

echo "logotipo WHMCS gerado em 500 x 204 pixels para os quatro componentes"
