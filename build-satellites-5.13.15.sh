#!/bin/bash
# Build del plugin satélite nuevo — 2026-11-16
#   tourflow-custom-emails  0.1.0  plantilla 100% personalizable del email de
#                                   confirmación de reserva (filtro
#                                   amir_email_render_html, núcleo v5.13.15+)
#
# Mismo patrón que build-satellites-5.13.6.sh — independiente, sin vendor/,
# php -l recursivo antes de empaquetar. Usa el PHP de MAMP en vez de Docker
# (Docker venía sin responder en esta sesión) — ver reference_php_without_docker
# en la memoria del proyecto.
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
OUT="$SRC/dist-zips"
STAGE="$SRC/.build-stage/satellites"
PHP="/Applications/MAMP/bin/php/php8.3.30/bin/php"

rm -rf "$STAGE"
mkdir -p "$STAGE" "$OUT"

SATELLITES=(
  "tourflow-custom-emails"
)

for slug in "${SATELLITES[@]}"; do
  dir="$STAGE/$slug"
  mkdir -p "$dir"
  echo "== Armando $slug"

  rsync -a \
    --exclude '.git' --exclude '.DS_Store' --exclude 'node_modules' \
    --exclude 'tests' --exclude 'vendor' --exclude '*.md' --exclude '*.html' \
    --exclude 'phpunit.xml' --exclude 'composer.lock' \
    "$SRC/$slug"/ "$dir"/

  errors=$(find "$dir" -name "*.php" -print0 | xargs -0 -n1 "$PHP" -l 2>&1 | grep -v "No syntax errors" || true)
  if [ -n "$errors" ]; then
    echo "$errors"
    echo "ERROR de sintaxis en $slug — build cancelado"
    exit 1
  fi

  version=$(sed -n 's/^ \* Version: *\([0-9.]*\)$/\1/p' "$dir/$slug.php" | head -1)
  zipname="${slug}-v${version}.zip"
  rm -f "$OUT/$zipname"
  ( cd "$STAGE" && zip -rq "$OUT/$zipname" "$slug" -x '*.DS_Store' )
  echo "$slug -> $(find "$dir" -type f | wc -l | tr -d ' ') archivos -> $OUT/$zipname"
done
