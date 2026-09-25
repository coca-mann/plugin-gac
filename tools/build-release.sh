#!/usr/bin/env bash
# Builds dist/gac-<version>.zip, ready to be unpacked into GLPI's plugins/ folder.
# Env: COMPOSER_BIN (default "composer"; do not use COMPOSER: Composer itself reads that variable), EXPECTED_VERSION (fails when it differs from setup.php).
set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSER_BIN="${COMPOSER_BIN:-composer}"

VERSION="$(sed -nE "s/.*define\('PLUGIN_GAC_VERSION', '([^']+)'.*/\1/p" setup.php | head -1)"
XML_VERSION="$(sed -nE 's/.*<num>([^<]+)<\/num>.*/\1/p' gac.xml | head -1)"

[ -n "$VERSION" ] || { echo "Could not read PLUGIN_GAC_VERSION from setup.php" >&2; exit 1; }
[ "$VERSION" = "$XML_VERSION" ] || { echo "Version mismatch: setup.php=$VERSION gac.xml=$XML_VERSION" >&2; exit 1; }
if [ -n "${EXPECTED_VERSION:-}" ] && [ "$VERSION" != "$EXPECTED_VERSION" ]; then
    echo "Version mismatch: tag=$EXPECTED_VERSION setup.php=$VERSION" >&2
    exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/gac"

# Copy the plugin without development files (vendor/ is rebuilt below from the lock file).
tar --exclude-from=.release-exclude -cf - . | tar -xf - -C "$STAGE/gac"

( cd "$STAGE/gac" && $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction )

# mPDF ships ~88 MB of fonts; the report only uses DejaVu Sans.
FONTS="$STAGE/gac/vendor/mpdf/mpdf/ttfonts"
if [ -d "$FONTS" ]; then
    find "$FONTS" -type f \
        ! -name 'DejaVuSans.ttf' ! -name 'DejaVuSans-Bold.ttf' \
        ! -name 'DejaVuSans-Oblique.ttf' ! -name 'DejaVuSans-BoldOblique.ttf' \
        -delete
fi
rm -f "$STAGE/gac/composer.lock"

mkdir -p dist
OUT="$(pwd)/dist/gac-$VERSION.zip"
rm -f "$OUT"
if command -v zip >/dev/null 2>&1; then
    ( cd "$STAGE" && zip -qr "$OUT" gac )
else
    PY=""
    for candidate in python3 python; do
        if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c 'import zipfile' >/dev/null 2>&1; then
            PY="$candidate"
            break
        fi
    done
    [ -n "$PY" ] || { echo "Neither zip nor a working python was found" >&2; exit 1; }
    ( cd "$STAGE" && "$PY" -m zipfile -c "$OUT" gac )
fi

echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
