# Tour Booking — Plugin WordPress

Sistema de reservas y gestión de tours para operadoras turísticas. Sin WooCommerce. Multisite-ready.

Nació como "Amir Booking" para Amir Adventours Bacalar; el código ya está rebrandeado a un producto genérico (`LicenseManager` con planes `starter`/`pro`/`enterprise`, soporte Network Admin para instalar en varios sites de una red Multisite, opciones de marca blanca). Por ahora se distribuye como plugin único — la decisión de operarlo como plataforma Multisite para múltiples operadores está abierta y no bloquea su uso como plugin independiente.

## Estructura de archivos

Esta es la estructura **real** del código (no una referencia aspiracional — si un archivo no está listado acá, no existe en el plugin):

```
amir-booking.php                      # Entry point, constantes, autoloader, hooks de activación
composer.json / composer.lock         # Dependencias PHP (TCPDF, endroid/qr-code)
.gitignore                            # vendor/ y node_modules/ no se versionan — ver INSTALL.md

includes/
├── core/
│   ├── class-plugin.php              # Singleton principal, orquesta módulos
│   ├── class-installer.php           # Crea/actualiza/elimina tablas en DB (single + Multisite)
│   ├── class-availability-engine.php # Motor de disponibilidad + reglas de prioridad
│   ├── class-pricing-engine.php      # Motor de precios (percapita + grupo) + tipo de cambio
│   ├── class-booking-manager.php     # Crear/confirmar/cancelar/reprogramar reservas
│   ├── class-rate-limiter.php        # Throttling por IP para endpoints públicos
│   ├── class-voucher-generator.php   # PDF del voucher + QR de verificación
│   ├── class-cron-manager.php        # Tareas programadas (recordatorios, reseñas, mínimo pax)
│   ├── class-tour-manager-role.php   # Rol WP con redirect al dashboard del plugin
│   ├── class-template-loader.php     # Carga templates del plugin si el tema no tiene
│   ├── class-license-manager.php     # Stub de licencias/planes (SaaS-ready, no bloquea nada hoy)
│   ├── class-assets.php              # Registro de CSS/JS frontend
│   ├── class-i18n.php                # Internacionalización
│   └── class-shortcodes.php          # [amir_booking], [amir_tour_list], [amir_verify_booking]
│
├── cpt/
│   └── class-tour-post-type.php      # CPT amir_tour + meta boxes + sync a DB
│
├── elementor/
│   ├── class-elementor-integration.php  # Registro de widgets + Dynamic Tags + Loop compat.
│   └── elementor-widgets.php            # Botón Reservar Tour, Tarjeta de Tour, Dynamic Tags
│
├── api/
│   ├── class-tours-controller.php        # GET /amir/v1/tours
│   ├── class-availability-controller.php # GET /amir/v1/availability/*
│   ├── class-booking-controller.php      # POST/GET /amir/v1/bookings + Stripe webhook
│   └── class-prices-controller.php       # GET /amir/v1/prices
│
├── admin/
│   ├── class-admin-menu.php          # Menú WP Admin + badge de notificaciones
│   ├── class-dashboard-page.php      # Dashboard operativo diario
│   ├── class-bookings-page.php       # Listado + detalle + acciones de reservas
│   ├── class-availability-page.php   # Gestión visual de reglas de disponibilidad
│   ├── class-partners-page.php       # Partners (CRUD + stats + QR)
│   ├── class-reports-page.php        # Reportes y exportaciones
│   ├── class-settings-page.php       # Stripe, marca, tipo de cambio, sincronización de tours
│   ├── class-network-admin.php       # Panel de Network Admin (solo Multisite)
│   └── class-notification-badge.php  # Badge de notificaciones en el menú admin
│
├── emails/
│   └── class-email-dispatcher.php    # Despachador + las 4 plantillas HTML bilingües inline
│
└── partners/
    └── class-partner-tracker.php     # Tracking de URLs, cookies, generación QR

assets/
├── css/
│   ├── admin.css                     # Estilos del panel admin
│   ├── booking-widget.css            # CSS del widget de reservas (generado por build)
│   └── tour-cards.css                # CSS de tarjetas para Elementor y el archive
└── js/
    ├── admin.js                      # SPA React del admin (compilado — sin fuente en el repo)
    └── booking-widget.js             # Widget React de reservas (compilado — sin fuente en el repo)

templates/
├── single-amir_tour.php              # Página individual del tour
└── archive-amir_tour.php             # Listado /tours/

dev-notes/                            # Material de referencia, NO es código del plugin
├── stripe.md                         # Guía de buenas prácticas de integración Stripe
├── context-notes.txt                 # Notas de contexto de una sesión de desarrollo previa
└── agents-skills/                    # Skill de desarrollo WP para asistentes de IA
```

> **Nota sobre `assets/js/*.js`:** el widget de reservas y el panel de admin son React compilado a un bundle. El código fuente (`react-src/`, según las notas de desarrollo) todavía no está en este repositorio — solo existe el resultado del build. Cualquier cambio al frontend requiere recuperar ese código fuente primero; parchear el bundle minificado a mano no es viable.

## Seguridad de acceso público a reservas

Las reservas se identifican con un `booking_ref` **secuencial** (`AMIR-2026-00001`, `-00002`, …), así que nunca es suficiente por sí solo para autorizar el acceso a los datos de un cliente. Todos los puntos de acceso público a una reserva —la página `/verificar-reserva/`, `GET /bookings/{ref}`, la cancelación pública y la descarga del voucher PDF— exigen **una de estas dos credenciales**:

- `access_token`: valor aleatorio de 32 bytes generado por reserva, incluido automáticamente en los links de email, en el QR del voucher y en el link de descarga del PDF. Es la credencial fuerte y la que se debe usar siempre que sea posible.
- `email` del cliente: se mantiene como alternativa para no romper flujos donde el token todavía no llega (p. ej. mientras se actualiza el widget de React), pero es una credencial más débil.

Los endpoints públicos aplican además un throttling básico por IP (`RateLimiter`, en `class-rate-limiter.php`) para frenar intentos de fuerza bruta contra referencias consecutivas.

## Tablas de base de datos

| Tabla | Descripción |
|-------|-------------|
| `wp_amir_tours` | Tours con toda su info bilingüe |
| `wp_amir_tour_schedules` | Horarios de cada tour |
| `wp_amir_prices` | Precios por tipo de persona / rango de grupo |
| `wp_amir_availability_rules` | Reglas de bloqueo/permitir con prioridades |
| `wp_amir_partners` | Partners con tokens de tracking |
| `wp_amir_bookings` | Reservas completas (incluye `access_token`, `custom_email_note`) |
| `wp_amir_notifications` | Notificaciones admin |

En Multisite, cada site de la red tiene su propio juego de tablas (`wp_N_amir_*`).

## Endpoints REST API

```
GET  /wp-json/amir/v1/tours                         → Listado de tours activos
GET  /wp-json/amir/v1/tours/{id}                     → Detalle de tour

GET  /wp-json/amir/v1/availability/month?tour_id=X&year=Y&month=M
GET  /wp-json/amir/v1/availability/day?tour_id=X&date=YYYY-MM-DD
GET  /wp-json/amir/v1/availability/schedules?tour_id=X&date=YYYY-MM-DD

POST /wp-json/amir/v1/bookings/quote                → Cotizar precio
POST /wp-json/amir/v1/bookings                       → Crear reserva + Stripe PaymentIntent
GET  /wp-json/amir/v1/bookings/{ref}?token=…|email=…  → Verificar reserva (requiere token o email)
POST /wp-json/amir/v1/bookings/{ref}/cancel           → Cancelar reserva (requiere token o email)
POST /wp-json/amir/v1/bookings/{ref}/request-cancel   → Solicitar cancelación (requiere token o email)
GET  /wp-json/amir/v1/bookings/{ref}/pdf?token=…|email=… → Descargar voucher PDF
GET  /wp-json/amir/v1/bookings/by-payment/{pi_id}     → Buscar reserva por PaymentIntent
POST /wp-json/amir/v1/bookings/{id}/confirm-payment   → Confirmar pago (verificado contra Stripe)
POST /wp-json/amir/v1/bookings/{id}/status            → Cambiar estado (requiere manage_options)
POST /wp-json/amir/v1/bookings/stripe-webhook         → Webhook Stripe (firmado)
```

## Modelos de precio

### Modelo A: Per-capita
Precio por tipo de persona. La capacidad se controla sumando `adults + children + babies`.

### Modelo B: Grupo (Catamarán)
Precio fijo por rango de personas (ej: 1-2 → $X, 3 → $Y, 4 → $Z).
Una reserva bloquea el horario completo (tour privado).

## Motor de disponibilidad — Reglas de prioridad

Las reglas se evalúan por prioridad (mayor = gana). La primera que aplica determina el resultado.

```
Ejemplo: Kayak Amanecer — miércoles bloqueados excepto junio

Regla 1: block, weekdays=[3], priority=10, date_from=NULL, date_until=NULL
  → Bloquea todos los miércoles siempre

Regla 2: allow, weekdays=[3], priority=20, date_from=2026-06-01, date_until=2026-06-30
  → Permite los miércoles en junio

Motor evalúa → En junio: regla 2 (prioridad 20) gana → PERMITE
             → Fuera de junio: solo aplica regla 1 → BLOQUEA
```

## Política de cancelación

| Días antes del tour | Cargo | Reembolso |
|--------------------|-------|-----------|
| 7+ días | 0% | 100% |
| 3-6 días | 50% | 50% |
| 0-2 días | 100% | 0% |
| Clima / Mínimo pax | 0% | 100% |

## Desarrollo local

1. Copiar la carpeta del plugin a `wp-content/plugins/`
2. Instalar dependencias PHP: `composer install` (ver INSTALL.md — `vendor/` ya no se versiona en git)
3. Activar el plugin en WP Admin → Plugins
4. Las tablas se crean automáticamente al activar (o se migran solas si ya existían de una versión anterior)
5. Los assets de `assets/js/*.js` ya vienen compilados en el repo; el código fuente de React aún no está versionado (ver nota más arriba)
6. Tests unitarios (lógica de dominio, sin base de datos real): `composer install && vendor/bin/phpunit`. CI corre esto mismo en cada push/PR (`.github/workflows/ci.yml`).

## Variables de entorno / Opciones de WordPress

| Opción WP | Descripción |
|-----------|-------------|
| `amir_stripe_mode` | `test` o `live` |
| `amir_stripe_pk_test` / `amir_stripe_sk_test` | Keys de Stripe en modo test |
| `amir_stripe_pk_live` / `amir_stripe_sk_live` | Keys de Stripe en modo live |
| `amir_stripe_webhook_secret` | Secret para verificar webhooks de Stripe |
| `amir_currency` | Moneda de cobro (default `MXN`) |
| `amir_usd_rate_mode` | `auto` (API) o `manual` |
| `amir_usd_rate_manual` | Tipo de cambio manual USD/MXN |
| `amir_admin_email` | Email del operador para alertas |
| `amir_pending_expire_mins` | Minutos antes de liberar reservas pending (default: 15) |
| `amir_review_delay_days` | Días post-tour para enviar solicitud de reseña (default: 1) |
| `amir_verify_page_id` | ID de la página `/verificar-reserva/` creada en la activación |
| `amir_brand_logo_url` / `amir_brand_color` / `amir_company_name` | Marca blanca (logo, color, nombre) usada en emails y voucher |
| `amir_license_plan` / `amir_license_key` | Plan activo (`starter`/`pro`/`enterprise`), gestionado por `LicenseManager` |
