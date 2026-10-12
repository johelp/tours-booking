#!/bin/bash
# Build de las 3 ediciones de TourFlow (Lite / Pro / Pro Max) — v5.11.0
# Mismo pipeline de siempre: rsync del código fuente a una carpeta de stage
# por edición, exclusiones específicas, composer install --no-dev dentro del
# contenedor Docker (no hay PHP en el host), php -l recursivo, zip final.
#
# v5.11.0 — Panel de gestión sin wp-admin (`includes/panel/`), 8 de 8
# secciones del alcance acordado con el cliente: Dashboard, Tours, Reservas,
# Calendario, Modo Campo, Disponibilidad, Partners, Liquidación de partners.
# Universal a las 3 ediciones (no gateado a Pro Max pese al namespace
# TourFlow\). Emails y Log de pagos quedan fuera de alcance a pedido del
# cliente. Ver PROMPT-PANEL-GESTOR.md para el diseño completo y CLAUDE.md
# "Estado al cierre" para el detalle de qué se construyó. De paso, bug real
# corregido en TourPostType::save_meta() (capability 'edit_amir_tour' que
# nunca existió — un Tour Manager podía "Actualizar" un tour sin error pero
# precio/horarios/galería quedaban sin guardarse en silencio).
#
# Nueva exclusión respecto al script anterior: 'mercadopago-split-tourflow'
# (plugin satélite, scaffolding de PROMPT-MERCADOPAGO-SPLIT.md § 7) — mismo
# criterio que redsys-for-tourflow/tourflow-cleaner, nunca debe colarse en
# el ZIP del núcleo.
set -euo pipefail

VERSION="5.11.0"
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
    --exclude 'mercadopago-split-tourflow' \
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
