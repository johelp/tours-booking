# Amir Booking — Plugin WordPress

Sistema de reservas y gestión de tours para **Amir Adventours Bacalar**. Sin WooCommerce.

## Estructura de archivos

```
amir-booking/
├── amir-booking.php              # Entry point, constantes, autoloader, hooks de activación
│
├── includes/
│   ├── core/
│   │   ├── class-plugin.php             # Singleton principal, orquesta módulos
│   │   ├── class-installer.php          # Crea/actualiza/elimina tablas en DB
│   │   ├── class-availability-engine.php # Motor de disponibilidad + reglas de prioridad
│   │   ├── class-pricing-engine.php     # Motor de precios (percapita + grupo)
│   │   ├── class-booking-manager.php    # Crear/confirmar/cancelar/reprogramar reservas
│   │   ├── class-cron-manager.php       # Tareas programadas (recordatorios, reseñas, mínimo pax)
│   │   ├── class-assets.php             # Registro de CSS/JS frontend
│   │   ├── class-i18n.php               # Internacionalización ES/EN
│   │   └── class-shortcodes.php         # [amir_booking] y [amir_tour_list]
│   │
│   ├── api/
│   │   ├── class-tours-controller.php       # GET /amir/v1/tours
│   │   ├── class-availability-controller.php # GET /amir/v1/availability/*
│   │   ├── class-booking-controller.php      # POST/GET /amir/v1/bookings + Stripe webhook
│   │   └── class-prices-controller.php       # GET /amir/v1/prices
│   │
│   ├── admin/
│   │   ├── class-admin-menu.php         # Menú WP Admin + badge de notificaciones
│   │   ├── class-tours-admin.php        # CRUD de tours (Fase 2)
│   │   ├── class-bookings-admin.php     # Listado y gestión de reservas (Fase 2)
│   │   ├── class-availability-admin.php # Interfaz de reglas de disponibilidad (Fase 2)
│   │   └── class-reports-admin.php      # Reportes y exportaciones (Fase 5)
│   │
│   ├── emails/
│   │   ├── class-email-dispatcher.php   # Despacha emails según eventos de WP actions
│   │   ├── class-confirmation-email.php # Email de confirmación + QR + PDF
│   │   ├── class-reminder-email.php     # Recordatorio pre-tour
│   │   ├── class-review-email.php       # Solicitud de reseña post-tour
│   │   └── class-cancellation-email.php # Confirmación de cancelación
│   │
│   └── partners/
│       └── class-partner-tracker.php    # Tracking de URLs, cookies, generación QR
│
├── assets/
│   ├── css/
│   │   └── admin.css               # Estilos del panel admin
│   └── js/
│       ├── admin.js                 # SPA React del admin (compilado)
│       └── booking-widget.js        # Widget React de reservas (compilado)
│
└── templates/
    ├── emails/
    │   ├── confirmation-es.html
    │   ├── confirmation-en.html
    │   ├── reminder-es.html
    │   ├── reminder-en.html
    │   ├── review-es.html
    │   ├── review-en.html
    │   ├── cancellation-es.html
    │   └── cancellation-en.html
    └── pdf/
        └── voucher.html             # Template del voucher PDF
```

## Tablas de base de datos

| Tabla | Descripción |
|-------|-------------|
| `wp_amir_tours` | Tours con toda su info bilingüe |
| `wp_amir_tour_schedules` | Horarios de cada tour |
| `wp_amir_prices` | Precios por tipo de persona / rango de grupo |
| `wp_amir_availability_rules` | Reglas de bloqueo/permitir con prioridades |
| `wp_amir_partners` | Partners con tokens de tracking |
| `wp_amir_bookings` | Reservas completas |
| `wp_amir_notifications` | Notificaciones admin |

## Endpoints REST API

```
GET  /wp-json/amir/v1/tours                         → Listado de tours activos
GET  /wp-json/amir/v1/tours/{id}                    → Detalle de tour

GET  /wp-json/amir/v1/availability/month?tour_id=X&year=Y&month=M
GET  /wp-json/amir/v1/availability/day?tour_id=X&date=YYYY-MM-DD
GET  /wp-json/amir/v1/availability/schedules?tour_id=X&date=YYYY-MM-DD

POST /wp-json/amir/v1/bookings/quote               → Cotizar precio
POST /wp-json/amir/v1/bookings                     → Crear reserva + Stripe PaymentIntent
GET  /wp-json/amir/v1/bookings/{ref}               → Verificar reserva (QR, portal)
POST /wp-json/amir/v1/bookings/{ref}/cancel        → Cancelar reserva
POST /wp-json/amir/v1/bookings/stripe-webhook      → Webhook Stripe (firmado)
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

1. Copiar la carpeta `amir-booking/` a `wp-content/plugins/`
2. Activar el plugin en WP Admin → Plugins
3. Las tablas se crean automáticamente al activar
4. Para compilar los assets React: `cd react-src && npm run build`

## Variables de entorno / Opciones de WordPress

| Opción WP | Descripción |
|-----------|-------------|
| `amir_stripe_mode` | `test` o `live` |
| `amir_stripe_pk_test` / `amir_stripe_sk_test` | Keys de Stripe en modo test |
| `amir_stripe_pk_live` / `amir_stripe_sk_live` | Keys de Stripe en modo live |
| `amir_stripe_webhook_secret` | Secret para verificar webhooks de Stripe |
| `amir_usd_rate_mode` | `auto` (API) o `manual` |
| `amir_usd_rate_manual` | Tipo de cambio manual USD/MXN |
| `amir_admin_email` | Email del operador para alertas |
| `amir_pending_expire_mins` | Minutos antes de liberar reservas pending (default: 15) |
| `amir_review_delay_days` | Días post-tour para enviar solicitud de reseña (default: 1) |
