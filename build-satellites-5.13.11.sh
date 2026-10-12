#!/bin/bash
# Build de los plugins satélite de TourFlow — 2026-10-12 (núcleo v5.13.11)
# Cada satélite es un plugin independiente: se empaqueta solo, con su propia
# versión (cabecera Version + constante). No dependen de composer (no tienen
# vendor/), así que no necesitan Docker para armar el ZIP; sí se valida
# sintaxis con php -l dentro de Docker (no hay PHP en el host).
#
# Satélites incluidos:
#   redsys-for-tourflow         0.1.2  pasarela Redsys (confirma el carrito entero)
#   mercadopago-split-tourflow  0.1.2  split de MercadoPago (confirma el carrito entero)
#   tourflow-cleaner            0.1.3  limpieza de datos de prueba (+ liquidaciones de partners)
#   tourflow-bot-connector      0.1.1  API para chatbot externo (descripción de productos)
#   tourflow-automations        0.1.0  webhooks firmados a Zapier/Make/n8n (sin cambios)
#
# Compatibilidad: todos requieren el núcleo amir-booking >= 5.13.x (el filtro
# amir_payment_gateway_resolve / el hook de pago fallido existen desde 5.13.4).
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
OUT="$SRC/dist-zips"
STAGE="$SRC/.build-stage/satellites"

rm -rf "$STAGE"
mkdir -p "$STAGE" "$OUT"

SATELLITES=(
  "redsys-for-tourflow"
  "mercadopago-split-tourflow"
  "tourflow-cleaner"
  "tourflow-bot-connector"
  "tourflow-automations"
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

  # php -l recursivo — cualquier error de sintaxis frena el build
  # PHP: el del host si existe (MAMP, Docker suele estar caído), si no Docker.
  PHP_BIN=$(ls -d /Applications/MAMP/bin/php/php8.3*/bin/php 2>/dev/null | head -1 || true)
  if [ -n "$PHP_BIN" ]; then
    errors=$(find "$dir" -name "*.php" -print0 | xargs -0 -n1 "$PHP_BIN" -l | grep -v "No syntax errors" || true)
  else
    errors=$(docker run --rm -v "$dir":/app -w /app php:8.1-cli bash -c \
      'find . -name "*.php" -print0 | xargs -0 -n1 php -l' | grep -v "No syntax errors" || true)
  fi
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

echo "== Listo =="
ls -la "$OUT"
