#!/bin/bash
# Build de las 3 ediciones de TourFlow (Lite / Pro / Pro Max) — v5.13.16
# Mismo pipeline: rsync del código fuente a una carpeta de stage por edición,
# exclusiones específicas, composer install --no-dev dentro del contenedor
# Docker (no hay PHP en el host), php -l recursivo, zip final.
#
# v5.13.16 (2026-11-16) — nuevo punto de extensión aditivo en el núcleo,
# pedido del cliente: poder reemplazar la plantilla COMPLETA de un email
# (no solo el bloque HTML que ya ofrece custom_html_block desde v5.12.0) sin
# tocar el núcleo. `BaseEmail::send()` ahora pasa el HTML final por
# `apply_filters('amir_email_render_html', $html, $booking, type_key(), $this)`
# justo antes de `wp_mail()` — agnóstico de tipo de email, cubre los ~15
# tipos sin otro cambio de núcleo. `verify_url()`/`calendar_url()` pasaron de
# `protected` a `public` (cambio de visibilidad, no rompe nada) para que un
# satélite pueda reusarlos sin duplicar esa lógica. Nuevo plugin satélite
# `tourflow-custom-emails/` (empaquetado aparte, build-satellites-5.13.16.sh)
# consume este filtro: plantilla HTML libre con variables {{customer_name}},
# {{pdf_url}}, etc. + botón de prueba, por ahora solo para el email de
# confirmación. Sin cambios de esquema de DB. Ver CONTRIBUTING.md y
# GUIA-PLUGINS-SATELITE-TOURFLOW.md.
#
# Cambios de empaquetado respecto de 5.13.14: se excluye también la carpeta
# del satélite nuevo, tourflow-custom-emails/ (se arma aparte).
#
set -euo pipefail

VERSION="5.13.16"
SRC="$(cd "$(dirname "$0")" && pwd)"
OUT="$SRC/dist-zips"
STAGE="$SRC/.build-stage"
PHP="/Applications/MAMP/bin/php/php8.3.30/bin/php"
COMPOSER="/Applications/MAMP/bin/php/composer"

rm -rf "$STAGE"
mkdir -p "$STAGE" "$OUT"
rm -f "$OUT"/tourflow-v${VERSION}-*.zip

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
    --exclude 'mercadopago-split-tourflow' --exclude 'tourflow-bot-connector' \
    --exclude 'tourflow-automations' --exclude 'tourflow-custom-emails' \
    --exclude '*.md' --exclude '*.html' --exclude '*.pdf' \
    --exclude 'phpunit.xml' --exclude 'composer.lock' \
    --exclude '.build-stage' --exclude 'dist-zips' \
    --exclude 'build-editions-*.sh' --exclude 'build-satellites-*.sh' \
    --exclude 'scratch' --exclude 'tools' \
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

  # composer install --no-dev: Docker venía sin responder en esta sesión
  # (daemon caído) — se usa el PHP+composer de MAMP en su lugar, mismo
  # criterio que build-satellites-5.13.16.sh.
  ( cd "$dir" && "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader -q )

  # php -l recursivo — cualquier error de sintaxis frena el build acá
  find "$dir" -name "*.php" -print0 | xargs -0 -n1 "$PHP" -l 2>&1 | grep -v "No syntax errors" || true

  local zipname="tourflow-v${VERSION}-${edition}.zip"
  ( cd "$stage_root" && zip -rq "$OUT/$zipname" "$folder" -x '*.DS_Store' )
  echo "$edition -> $(find "$dir" -type f | wc -l | tr -d ' ') archivos -> $OUT/$zipname"
}

build_edition "lite"    "amir-booking"      "TourFlow Lite"
build_edition "pro"     "amir-booking"      "TourFlow"
build_edition "pro_max" "amir-booking"      "TourFlow Pro Max"

echo "== Listo =="
ls -la "$OUT"
