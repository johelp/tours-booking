# Amir Booking — Guía de instalación y despliegue

## Requisitos del servidor

| Requisito | Mínimo | Recomendado |
|-----------|--------|-------------|
| PHP       | 8.1    | 8.2+        |
| WordPress | 6.0    | 6.4+        |
| MySQL     | 5.7    | 8.0+        |
| Composer  | 2.x    | 2.x         |
| Node.js   | 18+    | 20 LTS      |

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

### 2. Compilar el widget React

```bash
cd react-src
npm install
npm run build
# Genera: assets/js/booking-widget.js
#          assets/css/booking-widget.css
```

### 3. Activar en WordPress

WordPress Admin → Plugins → **Amir Booking** → Activar

Al activar se crean automáticamente:
- 7 tablas en la base de datos (`wp_amir_*`)
- El rol **Tour Manager**
- Los cron jobs de tareas diarias y horarias

---

## Configuración inicial

### 1. Stripe

1. Ir a **Amir Booking → Configuración**
2. Pegar las claves de Stripe (test y live)
3. Configurar el **Webhook** en el dashboard de Stripe:
   - URL: `https://tudominio.com/wp-json/amir/v1/bookings/stripe-webhook`
   - Eventos a escuchar:
     - `payment_intent.succeeded`
     - `payment_intent.payment_failed`
     - `charge.refunded`
4. Copiar el **Webhook Secret** (whsec_…) a la configuración

### 2. Crear el primer tour

1. **Amir Booking → Tours (editar) → Nuevo tour**
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

### Subir archivos

```bash
# Desde local, empaquetar solo lo necesario
zip -r amir-booking-prod.zip amir-booking/ \
  --exclude "*/node_modules/*" \
  --exclude "*/react-src/src/*" \
  --exclude "*/.git/*" \
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

---

## Shortcodes disponibles

```
[amir_booking tour_id="X"]           Widget de reserva para el tour X
[amir_booking tour_id="X" lang="en"] Widget en inglés
[amir_tour_list]                     Grilla de todos los tours activos
[amir_tour_list lang="en"]           Grilla en inglés
```

---

## REST API endpoints

```
GET  /wp-json/amir/v1/tours                   → Listado de tours
GET  /wp-json/amir/v1/tours/{id}              → Detalle + horarios + precios
GET  /wp-json/amir/v1/tours/{id}/schedules    → Horarios del tour
GET  /wp-json/amir/v1/tours/{id}/prices       → Precios vigentes

GET  /wp-json/amir/v1/availability/month?tour_id=X&year=Y&month=M
GET  /wp-json/amir/v1/availability/day?tour_id=X&date=YYYY-MM-DD
GET  /wp-json/amir/v1/prices?tour_id=X&date=YYYY-MM-DD

POST /wp-json/amir/v1/bookings/quote          → Cotizar precio
POST /wp-json/amir/v1/bookings                → Crear reserva + Stripe PI
GET  /wp-json/amir/v1/bookings/{ref}          → Consultar reserva
POST /wp-json/amir/v1/bookings/{ref}/request-cancel → Solicitar cancelación
GET  /wp-json/amir/v1/bookings/by-payment/{pi_id}   → Buscar por PaymentIntent
POST /wp-json/amir/v1/bookings/stripe-webhook        → Webhook Stripe
```

---

## Módulos del plugin

```
amir-booking/
├── amir-booking.php                    Entry point, constantes, autoloader
├── composer.json                       Dependencias PHP
├── README.md                           Documentación técnica
│
├── includes/
│   ├── core/
│   │   ├── class-plugin.php            Singleton: orquesta todos los módulos
│   │   ├── class-installer.php         Crea/actualiza tablas DB en activación
│   │   ├── class-availability-engine.php  Motor de disponibilidad con prioridades
│   │   ├── class-booking-manager.php   CRUD de reservas + política cancelación
│   │   ├── class-pricing-engine.php    Precios percapita/grupo + tipo cambio USD
│   │   ├── class-voucher-generator.php PDF del voucher + QR de verificación
│   │   ├── class-cron-manager.php      Tareas programadas (recordatorios, reseñas)
│   │   ├── class-tour-manager-role.php Rol WP con redirect al dashboard del plugin
│   │   ├── class-template-loader.php   Carga templates del plugin si el tema no tiene
│   │   ├── class-shortcodes.php        [amir_booking] y [amir_tour_list]
│   │   ├── class-assets.php            Registro de CSS/JS
│   │   └── class-i18n.php              Traducciones
│   │
│   ├── cpt/
│   │   └── class-tour-post-type.php    CPT amir_tour + meta boxes + sync a DB
│   │
│   ├── elementor/
│   │   └── class-elementor-integration.php  Widgets + Dynamic Tags + Loop compat.
│   │
│   ├── api/
│   │   ├── class-tours-controller.php        GET tours
│   │   ├── class-availability-controller.php GET disponibilidad
│   │   ├── class-booking-controller.php      POST/GET reservas + Stripe webhook
│   │   └── class-prices-controller.php       GET precios
│   │
│   ├── admin/
│   │   ├── class-admin-menu.php              Menú WP Admin + badge notificaciones
│   │   ├── class-dashboard-page.php          Dashboard operativo diario
│   │   ├── class-bookings-page.php           Listado + detalle + acciones reservas
│   │   ├── class-availability-page.php       Gestión visual de reglas disponibilidad
│   │   └── class-partners-settings-pages.php Partners (CRUD+stats+QR) y Ajustes
│   │
│   ├── emails/
│   │   └── class-email-dispatcher.php        Templates HTML bilingüe (4 tipos)
│   │
│   └── partners/
│       └── class-partner-tracker.php         Cookie tracking + generación QR/URLs
│
├── react-src/
│   ├── package.json
│   ├── vite.config.js
│   └── src/
│       ├── booking-widget.jsx          Entry point (monta en todos los shortcodes)
│       ├── BookingWidget.jsx           Flujo completo 7 pasos (848 líneas)
│       ├── api.js                      Cliente REST API
│       ├── i18n.js                     Traducciones ES/EN del widget
│       └── styles/widget.css           CSS mobile-first del widget
│
├── assets/
│   ├── css/
│   │   ├── tour-cards.css              CSS para tarjetas Elementor y archive
│   │   ├── booking-widget.css          (generado por Vite)
│   │   └── admin.css                   (mínimo, styles inline en las páginas PHP)
│   └── js/
│       └── booking-widget.js           (generado por Vite)
│
└── templates/
    ├── single-amir_tour.php            Página individual del tour
    └── archive-amir_tour.php           Listado /tours/
```
