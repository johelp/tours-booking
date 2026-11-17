#!/bin/bash
# Build de las 3 ediciones de TourFlow (Lite / Pro / Pro Max) — v5.13.10
# Mismo pipeline: rsync del código fuente a una carpeta de stage por edición,
# exclusiones específicas, composer install --no-dev dentro del contenedor
# Docker (no hay PHP en el host), php -l recursivo, zip final.
#
# v5.13.7 → v5.13.10 — revisión completa del circuito de proveedores externos
# + habitaciones (2026-10-09/10): bifurcación proveedor generalizada a
# habitaciones, depósito parcial en habitaciones, ledger que ya NO liquida al
# proveedor cuando se cobró depósito (el depósito ES la comisión completa del
# operador, decisión confirmada con el cliente), dos bugs reales más
# corregidos en `EmailDispatcher` (`send_cancellation`/`notify_client_balance_paid`
# usaban una consulta con INNER JOIN que devolvía NULL para cualquier reserva
# de habitación — el cliente nunca recibía el aviso de cancelación/reembolso
# ni el de saldo cobrado), timing configurable de cuándo el proveedor ve los
# datos del cliente (`amir_provider_reveal_client_at`, solicitud o aprobación)
# y nav con anclas + botón "Guardar" arriba en el editor de tours del Panel
# de gestión. Ver GUIA-CIRCUITO-PROVEEDORES-EXTERNOS.md y CONTRIBUTING.md.
# AMIR_DB_VERSION → 1.43.0.
#
# v5.13.10 — nav rápida con anclas en el editor NATIVO de wp-admin para
# tours (pedido del cliente probando el editor en vivo: "optimizar la
# pantalla de carga y edición de tours para que sea más entendible,
# intuitiva"). El editor encadena hasta 11 metaboxes en una sola columna
# larga; se agregó una barra pegajosa con un link a cada sección
# (`TourPostType::render_quick_nav()`, hook `edit_form_top`) — mismo
# patrón ya probado en el editor del Panel de gestión, ahora también en
# el editor nativo que usa el operador el resto del tiempo. Sin cambios
# de esquema de DB.
#
# Cambios de empaquetado respecto de 5.13.6: ninguno — mismas exclusiones.
#
set -euo pipefail

VERSION="5.13.10"
SRC="$(cd "$(dirname "$0")" && pwd)"
OUT="$SRC/dist-zips"
STAGE="$SRC/.build-stage"

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
    --exclude 'tourflow-automations' \
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

  # composer install --no-dev dentro del contenedor (no hay PHP en el host)
  docker run --rm -v "$dir":/app -w /app composer:2 install --no-dev --optimize-autoloader -q

  # php -l recursivo — cualquier error de sintaxis frena el build acá
  docker run --rm -v "$dir":/app -w /app php:8.1-cli bash -c \
    'find . -name "*.php" -print0 | xargs -0 -n1 php -l' | grep -v "No syntax errors" || true

  local zipname="tourflow-v${VERSION}-${edition}.zip"
  ( cd "$stage_root" && zip -rq "$OUT/$zipname" "$folder" -x '*.DS_Store' )
  echo "$edition -> $(find "$dir" -type f | wc -l | tr -d ' ') archivos -> $OUT/$zipname"
}

build_edition "lite"    "amir-booking"      "TourFlow Lite"
build_edition "pro"     "amir-booking"      "TourFlow"
build_edition "pro_max" "amir-booking"      "TourFlow Pro Max"

echo "== Listo =="
ls -la "$OUT"
