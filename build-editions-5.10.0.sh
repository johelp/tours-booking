#!/bin/bash
# Build de las 3 ediciones de TourFlow (Lite / Pro / Pro Max) — v5.10.0
# Mismo pipeline de siempre: rsync del código fuente a una carpeta de stage
# por edición, exclusiones específicas, composer install --no-dev dentro del
# contenedor Docker (no hay PHP en el host), php -l recursivo, zip final.
#
# v5.10.0 (CONTRIBUTING.md §§ 16.94-16.95): FAQ opcional por tour (v5.9.0,
# metabox nuevo "❓ Preguntas frecuentes", universal a las 3 ediciones,
# acordeón en las dos plantillas de detalle) + [flow_product addon_id="X"]
# (v5.10.0, Pro Max) — venta suelta de un producto digital sin reservar
# ningún tour, item_type='product' nuevo en amir_bookings, reusa checkout/
# pasarela/descarga tokenizada existentes. AMIR_DB_VERSION → 1.37.0
# (faq_items LONGTEXT en amir_tours + item_type ENUM con 'product').
set -euo pipefail

VERSION="5.10.0"
SRC="$(cd "$(dirname "$0")" && pwd)"
OUT="$SRC/dist-zips"
STAGE="$SRC/.build-stage"

rm -rf "$STAGE" "$OUT"
mkdir -p "$STAGE" "$OUT"

# ── Archivos/carpetas 100% propios de Pro Max — excluidos de Lite Y de Pro ──
PROMAX_ONLY=(
  "includes/rooms"
  "includes/cart"
  "includes/discovery"
  "includes/admin/class-global-addons-page.php"
  "templates/single-flow_room.php"
  "templates/archive-flow_room.php"
  "templates/parts/room-data.php"
)

# ── Archivos 100% propios de Pro/Pro Max — excluidos solo de Lite (§14.2) ──
LITE_EXTRA=(
  "includes/api/class-wishlist-controller.php"
  "includes/api/class-config-controller.php"
  "includes/admin/class-wishlist-page.php"
  "includes/admin/class-providers-page.php"
  "includes/admin/class-provider-payouts-page.php"
  "includes/core/class-marketing.php"
  "templates/single-amir_tour-immersive.php"
)

build_edition () {
  local edition="$1"      # lite | pro | pro_max
  local folder="$2"       # nombre de carpeta interna del plugin (dentro del ZIP)
  local plugin_name="$3"  # texto del header "Plugin Name:"
  # stage_root es único por edición aunque $folder se repita entre ediciones
  # (Pro y Pro Max comparten "amir-booking") — si no, la segunda pisaría a la
  # primera al escribir en el mismo path de stage.
  local stage_root="$STAGE/$edition"
  local dir="$stage_root/$folder"

  echo "== Armando $edition ($folder) =="
  mkdir -p "$dir"

  rsync -a \
    --exclude '.git' --exclude '.github' --exclude '.claude' \
    --exclude '.DS_Store' --exclude '.gitignore' --exclude '.phpunit.result.cache' \
    --exclude 'node_modules' --exclude 'react-src' --exclude 'tests' \
    --exclude 'vendor' --exclude 'dev-notes' --exclude 'docs-manual' \
    --exclude 'redsys-for-tourflow' --exclude 'redsyspur' --exclude 'tourflow-cleaner' \
    --exclude '*.md' --exclude '*.html' --exclude '*.pdf' \
    --exclude 'phpunit.xml' --exclude 'composer.lock' \
    --exclude '.build-stage' --exclude 'dist-zips' \
    --exclude 'build-editions-*.sh' \
    "$SRC"/ "$dir"/

  # AMIR_EDITION por defecto + Plugin Name del header
  sed -i '' "s/define( 'AMIR_EDITION', 'pro' )/define( 'AMIR_EDITION', '$edition' )/" "$dir/amir-booking.php"
  sed -i '' "s/^ \* Plugin Name:  TourFlow$/ * Plugin Name:  $plugin_name/" "$dir/amir-booking.php"

  if [ "$edition" != "pro_max" ]; then
    for f in "${PROMAX_ONLY[@]}"; do rm -rf "${dir:?}/$f"; done
  fi
  if [ "$edition" = "lite" ]; then
    for f in "${LITE_EXTRA[@]}"; do rm -f "${dir:?}/$f"; done
  fi

  # composer install --no-dev dentro del contenedor (no hay PHP en el host)
  docker run --rm -v "$dir":/app -w /app composer:2 install --no-dev --optimize-autoloader -q

  # php -l recursivo — cualquier error de sintaxis frena el build acá
  docker run --rm -v "$dir":/app -w /app php:8.1-cli bash -c \
    'find . -name "*.php" -print0 | xargs -0 -n1 php -l' | grep -v "No syntax errors" || true

  local zipname="tourflow-v${VERSION}-${edition}.zip"
  ( cd "$stage_root" && zip -rq "$OUT/$zipname" "$folder" -x '*.DS_Store' )
  echo "$edition -> $(find "$dir" -type f | wc -l | tr -d ' ') archivos -> $OUT/$zipname"
}

build_edition "lite"    "amir-booking-lite" "TourFlow Lite"
build_edition "pro"     "amir-booking"      "TourFlow"
build_edition "pro_max" "amir-booking"      "TourFlow Pro Max"

echo "== Listo =="
ls -la "$OUT"
