# Tour Booking (Amir Booking) — Guía de instalación y despliegue

## Requisitos del servidor

| Requisito | Mínimo | Recomendado |
|-----------|--------|-------------|
| PHP       | 8.1    | 8.2+        |
| WordPress | 6.0    | 6.4+        |
| MySQL     | 5.7    | 8.0+        |
| Composer  | 2.x    | 2.x         |

PHP 8.1 es un mínimo real, no aspiracional: `endroid/qr-code` (generación del QR del voucher) requiere `^8.1` como dependencia directa.

---

## Instalación en desarrollo local

### 1. Instalar el plugin

```bash
# Copiar a WordPress
cp -r amir-booking/ /path/to/wp-content/plugins/

# Instalar dependencias PHP
cd /path/to/wp-content/plugins/amir-booking
composer install --no-dev --optimize-autoloader
```

### 2. Widget React

`assets/js/booking-widget.js` y `assets/js/admin.js` ya vienen compilados en el repositorio. El código fuente (`react-src/`) todavía no está versionado — hasta que se recupere y se agregue al repo, cualquier cambio al widget requiere reconstruir esa carpeta desde cero o localizar el original.

### 3. Activar en WordPress

WordPress Admin → Plugins → **Tour Booking** → Activar

Al activar se crean automáticamente:
- 7 tablas en la base de datos (`wp_amir_*`)
- El rol **Tour Manager**
- Los cron jobs de tareas diarias y horarias

---

## Configuración inicial

### 1. Stripe

1. Ir a **Tour Booking → Configuración**
2. Pegar las claves de Stripe (test y live)
3. Configurar el **Webhook** en el dashboard de Stripe:
   - URL: `https://tudominio.com/wp-json/amir/v1/bookings/stripe-webhook`
   - Eventos a escuchar:
     - `payment_intent.succeeded`
     - `payment_intent.payment_failed`
     - `charge.refunded`
4. Copiar el **Webhook Secret** (whsec_…) a la configuración

### 2. Crear el primer tour

1. **Tour Booking → Tours (editar) → Nuevo tour**
2. Completar:
   - Título (español) + Nombre EN
   - Imagen destacada (foto principal)
   - Galería de fotos
   - Horarios y precios
   - Punto de encuentro con coordenadas
   - Días operativos (checkbox en la meta box Disponibilidad)
3. **Publicar**

El tour queda automáticamente disponible en:
- `/tour/nombre-del-tour/` — página individual
- `/tours/` — listado de todos los tours

### 3. Agregar el widget de reserva

**Opción A — Shortcode (cualquier página o tema):**
```
[amir_booking tour_id="1"]
[amir_booking tour_id="1" lang="en"]
```

**Opción B — Elementor:**
1. Abrir la página del tour en el editor de Elementor
2. Buscar el widget **"Botón Reservar Tour"** en la categoría Amir Adventours
3. Arrastrarlo al canvas → configurar modo (inline / modal / link)

**Opción C — Template automático:**
El template `single-amir_tour.php` del plugin ya incluye el widget en la columna derecha. Funciona sin configuración adicional si el tema no tiene su propio `single-amir_tour.php`.

### 4. Crear usuario Tour Manager

```
WordPress Admin → Usuarios → Añadir nuevo
Rol: Tour Manager
```

Al hacer login irá directamente al Dashboard operativo.

---

## Templates del tema

Para personalizar la apariencia, copiar los templates del plugin al tema:

```bash
# Página individual de tour
cp plugins/amir-booking/templates/single-amir_tour.php themes/mi-tema/

# Listado de tours (/tours/)
cp plugins/amir-booking/templates/archive-amir_tour.php themes/mi-tema/
```

WordPress detecta automáticamente los templates en el tema y los usa en lugar de los del plugin.

---

## Uso con Elementor Loop Builder

1. **Elementor Pro → Templates → Loop Item → Crear nuevo**
2. En "Query" seleccionar Post Type: **Tours**
3. Usar los **Dynamic Tags** del plugin:
   - `Tour: Precio desde (MXN)` → campo de texto/precio
   - `Tour: Duración` → badge de duración
   - `Tour: Nombre EN` → para sitios en inglés
   - `Tour: Edad mínima` → chip de información
   - `Tour: URL foto principal` → imagen dinámica
4. Insertar el widget **"Tarjeta de Tour"** o **"Botón Reservar Tour"**
5. Usar el widget en cualquier página con **Loop Grid** de Elementor

---

## Despliegue en cPanel (producción)

### vendor/ ya no viaja en el repositorio

Desde esta versión, `vendor/` está en `.gitignore` — no se versiona en git. Antes de empaquetar el ZIP de despliegue hay que generarlo localmente:

```bash
composer install --no-dev --optimize-autoloader
```

La mayoría de hostings compartidos (cPanel) no dan acceso a Composer por SSH, así que el flujo correcto es: correr `composer install` en tu máquina, y **sí incluir** la carpeta `vendor/` ya generada dentro del ZIP que subes (el `.gitignore` solo aplica al repositorio, no al artefacto de despliegue).

### Subir archivos

```bash
# Desde local, con vendor/ ya generado por composer install
zip -r amir-booking-prod.zip amir-booking/ \
  --exclude "*/node_modules/*" \
  --exclude "*/react-src/src/*" \
  --exclude "*/.git/*" \
  --exclude "*/dev-notes/*" \
  --exclude "*/tests/*"
```

Subir via File Manager de cPanel a `public_html/wp-content/plugins/`

### Compilar en local, subir compilado

El directorio `react-src/` NO necesita estar en producción. Solo se necesitan los archivos compilados:
- `assets/js/booking-widget.js`
- `assets/css/booking-widget.css`

### Configurar WP-Cron

En cPanel, es recomendable deshabilitar el pseudo-cron de WordPress y usar un cron real:

1. En `wp-config.php`: `define('DISABLE_WP_CRON', true);`
2. En cPanel → Cron Jobs, agregar:
   ```
   */15 * * * * wget -q -O /dev/null https://tudominio.com/wp-cron.php?doing_wp_cron
   ```

### Permisos de directorios

```bash
chmod 755 wp-content/uploads/amir-booking/
# WordPress crea estas carpetas automáticamente al generar PDFs y QRs
```

---

## Variables de configuración (wp-options)

| Clave | Descripción | Default |
|-------|-------------|---------|
| `amir_stripe_mode` | `test` o `live` | `test` |
| `amir_stripe_pk_test` | Publishable key test | — |
| `amir_stripe_sk_test` | Secret key test | — |
| `amir_stripe_pk_live` | Publishable key live | — |
| `amir_stripe_sk_live` | Secret key live | — |
| `amir_stripe_webhook_secret` | Webhook secret | — |
| `amir_admin_email` | Email para alertas del operador | admin WP |
| `amir_wa_phone` | Número WhatsApp (solo dígitos) | `5219831649541` |
| `amir_pending_expire_mins` | Minutos para expirar pending | `15` |
| `amir_review_delay_days` | Días post-tour para email reseña | `1` |
| `amir_usd_rate_mode` | `auto` o `manual` | `auto` |
| `amir_usd_rate_manual` | Tipo de cambio manual USD→MXN | `17` |
| `amir_google_review_url` | URL reseñas Google | — |
| `amir_tripadvisor_review_url` | URL reseñas TripAdvisor | — |
| `amir_delete_data_on_uninstall` | Borrar datos al desinstalar | `0` |
| `amir_currency` | Moneda de cobro | `MXN` |
| `amir_verify_page_id` | ID de la página `/verificar-reserva/` | — (autogenerado) |
| `amir_brand_logo_url` / `amir_brand_color` / `amir_company_name` | Marca blanca para emails y voucher | — |
| `amir_license_plan` / `amir_license_key` | Plan activo del `LicenseManager` | `pro` / `saas-managed` |

---

## Shortcodes disponibles

```
[amir_booking tour_id="X"]                Widget de reserva para el tour X
[amir_booking tour_id="X" lang="en"]      Widget en inglés
[amir_tour_list]                          Grilla de todos los tours activos
[amir_tour_list lang="en" layout="list"]  Grilla o lista, en inglés
[amir_verify_booking]                     Página de verificación pública (requiere ?ref= + ?token= o ?email=)
```

La página `/verificar-reserva/` con `[amir_verify_booking]` se crea automáticamente en la activación (opción `amir_verify_page_id`).

---

## REST API endpoints

```
GET  /wp-json/amir/v1/tours                          → Listado de tours
GET  /wp-json/amir/v1/tours/{id}                     → Detalle + horarios + precios
GET  /wp-json/amir/v1/tours/{id}/schedules           → Horarios del tour
GET  /wp-json/amir/v1/tours/{id}/prices              → Precios vigentes

GET  /wp-json/amir/v1/availability/month?tour_id=X&year=Y&month=M
GET  /wp-json/amir/v1/availability/day?tour_id=X&date=YYYY-MM-DD
GET  /wp-json/amir/v1/prices?tour_id=X&date=YYYY-MM-DD

POST /wp-json/amir/v1/bookings/quote                 → Cotizar precio
POST /wp-json/amir/v1/bookings                       → Crear reserva + Stripe PI
GET  /wp-json/amir/v1/bookings/{ref}?token=…|email=…  → Consultar reserva (requiere token o email)
POST /wp-json/amir/v1/bookings/{ref}/cancel           → Cancelar (requiere token o email)
POST /wp-json/amir/v1/bookings/{ref}/request-cancel   → Solicitar cancelación (requiere token o email)
GET  /wp-json/amir/v1/bookings/{ref}/pdf?token=…|email=… → Descargar voucher PDF
GET  /wp-json/amir/v1/bookings/by-payment/{pi_id}     → Buscar por PaymentIntent
POST /wp-json/amir/v1/bookings/{id}/confirm-payment   → Confirmar pago (verificado contra Stripe)
POST /wp-json/amir/v1/bookings/{id}/status            → Cambiar estado (requiere manage_options)
POST /wp-json/amir/v1/bookings/stripe-webhook         → Webhook Stripe
```

Todos los endpoints públicos que devuelven datos de un cliente aplican throttling básico por IP — ver la sección "Seguridad de acceso público a reservas" en README.md.

---

## Estructura del código

Ver la sección "Estructura de archivos" en [README.md](README.md) — se documenta una sola vez para no quedar desalineada entre los dos archivos.
