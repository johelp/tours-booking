# Guía de arquitectura — TourFlow

Esta guía es para alguien que sabe React/JS pero nunca tocó WordPress. Te da lo mínimo de WordPress que necesitás para no perderte, y después el detalle real de cómo está armado este plugin específico. Todo lo que describe acá está verificado contra el código actual (no es un documento aspiracional).

Si en algún punto necesitás más profundidad de la que da esta guía: `README.md` (arquitectura formal), `CONTRIBUTING.md` (historial de decisiones y roadmap), `INSTALL.md` (despliegue).

---

## 1. Qué es esto, en una frase

Un plugin de WordPress que agrega un sistema completo de reservas de tours (catálogo, disponibilidad, precios, pagos, emails, panel de operación) **sin usar WooCommerce**. Todo el modelo de datos de reservas vive en tablas propias (`wp_amir_*`), no en el sistema nativo de WordPress. WordPress acá es, básicamente, el framework que da hosting, login/roles, base de datos y un editor de contenido — el negocio (reservas, precios, disponibilidad) es código nuestro.

## 2. WordPress en 10 minutos (lo mínimo para no perderte)

Podés saltear esta sección si ya conocés estos conceptos.

- **Hooks (`actions` y `filters`)**: es el mecanismo de extensión de WordPress. Un *action* es "cuando pase X, ejecutá esta función" (`add_action('rest_api_init', fn)`). Un *filter* es "dejame modificar este valor antes de que se use" (`add_filter('template_include', fn)`). Todo el plugin se engancha a WordPress así, no hay un `main()` tradicional.
- **Custom Post Type (CPT)**: WordPress ya sabe manejar "Entradas" (posts) con título, contenido, imagen destacada, editor visual. Un CPT es un tipo de contenido propio que reusa toda esa infraestructura — acá es `amir_tour` (el editor de tours que ves en WP Admin es esto). Importante: el CPT es la interfaz de carga de datos, pero **los datos reales de negocio (precio, horarios, disponibilidad) viven en tablas SQL propias**, no en el post. Hay un paso de sincronización que copia del CPT a esas tablas.
- **`wp_options`**: una tabla clave-valor genérica que usa WordPress para configuración (`get_option('algo')` / `update_option('algo', $valor)`). Todo lo que configurás en las pantallas de Configuración/Personalización del plugin (claves de Stripe, color de marca, moneda, etc.) se guarda ahí.
- **Roles y capabilities**: cada usuario tiene un rol (`administrator`, `editor`, o roles custom como el que agrega este plugin, `Tour Manager`) y cada rol tiene una lista de "capabilities" (`manage_options`, `edit_posts`, etc.). Las pantallas de admin chequean `current_user_can('algo')` antes de mostrar nada sensible.
- **REST API**: WordPress ya trae un framework de REST API (`WP_REST_Server`). Este plugin registra sus propias rutas bajo el namespace `amir/v1` (ej. `/wp-json/amir/v1/tours`). Es la única superficie que consume el widget de React — nunca habla con `$wpdb` directo desde el navegador.
- **`$wpdb`**: el cliente de base de datos que trae WordPress. Cualquier query directa a las tablas propias del plugin (`wp_amir_*`) pasa por acá, siempre con `$wpdb->prepare()` (el equivalente a queries parametrizadas — nunca se concatena input del usuario en SQL).
- **Nonces**: tokens anti-CSRF que WordPress genera y verifica (`wp_verify_nonce`). Cualquier formulario de admin que hace un cambio los usa.
- **Multisite**: una instalación de WordPress puede correr varios "sites" (subsitios) compartiendo el mismo código de plugin pero con tablas de base de datos separadas por sitio (`wp_2_amir_tours`, `wp_3_amir_tours`, …). Este plugin soporta Multisite pero hoy se usa como plugin independiente en un solo sitio.

## 3. Estructura real de carpetas

```
amir-booking.php          # Entry point: constantes (AMIR_VERSION, etc.), autoloader, activación/desactivación

includes/
├── core/                 # El "dominio" del negocio — no depende de HTTP ni de admin
│   ├── class-plugin.php          # Bootstrap: engancha TODOS los módulos a hooks de WP (ver abajo)
│   ├── class-installer.php       # Crea/migra las tablas SQL propias
│   ├── class-booking-manager.php # Crear/confirmar/cancelar/reprogramar una reserva — el corazón del sistema
│   ├── class-availability-engine.php  # Calcula qué fechas/horarios están disponibles
│   ├── class-pricing-engine.php  # Calcula precio (percapita o por grupo) + tipo de cambio
│   ├── class-coupon-engine.php   # Aplica cupones (%, monto fijo, por fecha)
│   ├── class-currency.php        # Moneda configurable (MXN/ARS/USD/EUR/...)
│   ├── class-languages.php       # Qué idiomas están activos + fallback de contenido
│   ├── class-widget-theme.php    # Color/tipografía/radio del widget, sin tocar CSS a mano
│   ├── class-marketing.php       # Meta Pixel / Google Ads / GA4
│   ├── class-structured-data.php # JSON-LD (schema.org TouristTrip) para SEO
│   ├── class-voucher-generator.php # PDF del voucher + QR
│   ├── class-rate-limiter.php    # Throttling por IP en endpoints públicos
│   ├── class-cron-manager.php    # Tareas programadas (recordatorios, vencimiento de aprobación, etc.)
│   ├── class-tour-manager-role.php # Rol WP "Tour Manager" y sus permisos
│   └── class-shortcodes.php      # Registra [flow_booking], [flow_tour_list], etc. (alias legacy: amir_*)
│
├── cpt/
│   └── class-tour-post-type.php  # El editor de tours (CPT amir_tour) + sincronización a la tabla SQL
│
├── api/                  # Controladores REST — la ÚNICA puerta de entrada para el frontend público
│   ├── class-tours-controller.php
│   ├── class-availability-controller.php
│   ├── class-booking-controller.php     # El más grande: crear reserva, pagos, cancelación, PDF, webhooks
│   ├── class-prices-controller.php
│   ├── class-wishlist-controller.php
│   └── class-notifications-controller.php
│
├── admin/                # Pantallas de wp-admin (una clase = una pantalla, PHP que imprime HTML)
│   ├── class-admin-menu.php
│   ├── class-dashboard-page.php
│   ├── class-bookings-page.php
│   ├── class-calendar-page.php
│   ├── class-availability-page.php
│   ├── class-coupons-page.php
│   ├── class-field-page.php          # "Modo campo" — check-in por escaneo de voucher
│   ├── class-partners-page.php
│   ├── class-providers-page.php      # Marketplace de proveedores externos
│   ├── class-provider-payouts-page.php
│   ├── class-wishlist-page.php
│   ├── class-settings-page.php       # Configuración operativa (Stripe, moneda, sync de tours)
│   ├── class-personalization-page.php # Personalización visual (widget, marca, emails)
│   ├── class-payment-log-page.php
│   ├── class-email-test-page.php
│   ├── class-reports-page.php
│   ├── class-network-admin.php       # Solo Multisite
│   └── class-notification-badge.php
│
├── payments/              # Un gateway = una clase que implementa PaymentGatewayInterface
│   ├── class-payment-gateway-interface.php
│   ├── class-payment-gateway-factory.php
│   ├── class-stripe-gateway.php
│   ├── class-mercado-pago-gateway.php
│   └── class-payment-event-logger.php / class-payment-event.php / ...
│
├── emails/
│   └── class-email-dispatcher.php    # Las plantillas de email (bilingües, HTML inline)
│
├── elementor/             # Integración opcional con el page builder Elementor (si está activo)
└── partners/
    └── class-partner-tracker.php     # Tracking de links de partners (cookies, QR, comisiones)

assets/
├── css/admin.css          # CSS del panel admin — design tokens en :root (--ab-teal, --ab-radius, etc.)
├── css/booking-widget.css # ARTEFACTO DE BUILD — no editar, se sobreescribe (ver react-src/)
├── css/tour-cards.css     # CSS de tarjetas para Elementor / archive
├── js/admin.js            # JS plano del panel admin — SU PROPIA FUENTE, se edita directo
└── js/booking-widget.js   # ARTEFACTO DE BUILD — no editar, ver react-src/

templates/
├── single-amir_tour.php   # Página individual de un tour
└── archive-amir_tour.php  # Listado /tours/

react-src/                 # Fuente del widget de reservas — React 18 + Vite
└── src/
    ├── booking-widget.jsx  # Entry point — busca elementos con data-attributes y monta componentes
    ├── BookingWidget.jsx   # Flujo de reserva completo (selección → datos → pago → confirmación)
    ├── TourList.jsx        # Grilla de tours ([flow_tour_list])
    ├── WishlistList.jsx    # Grilla de "próximamente" (lista de interés)
    ├── PayBooking.jsx      # Pagar una reserva ya cargada (desde link de email)
    ├── api.js               # Cliente de la REST API (fetch wrapper)
    ├── i18n.js               # Traducciones ES/EN del widget
    ├── marketing.js          # Dispara eventos de Meta Pixel / GA4 en cada paso
    └── styles/widget.css     # FUENTE del CSS del widget — acá se edita, no en assets/css/
```

## 4. Cómo arranca todo

Todo el plugin se registra desde un único punto: `includes/core/class-plugin.php`, método `init()`. Es la mejor forma de entender "qué hace este plugin" de un vistazo — es literalmente la lista de todos los módulos:

```php
public function init(): void {
    ( new TourPostType() )->register();               // CPT
    TourManagerRole::register_hooks();                 // Rol
    ( new ElementorIntegration() )->register();        // Elementor (si está activo)
    ( new Assets() )->register();                      // Encolar CSS/JS
    add_filter( 'template_include', [TemplateLoader::class, 'load'] );

    add_action( 'rest_api_init', function () {          // Todas las rutas REST se registran acá
        ( new ToursController() )->register_routes();
        ( new BookingController() )->register_routes();
        // ...
    } );

    if ( is_admin() ) {
        ( new AdminMenu() )->register();                // Pantallas de wp-admin
    }

    add_shortcode( 'amir_booking', ... );               // Shortcodes
    ( new CronManager() )->register();                  // Tareas programadas
    ( new EmailDispatcher() )->register();
    Marketing::init();
}
```

No hay "un" archivo por feature que se auto-descubre mágicamente: si agregás un módulo nuevo, tenés que registrarlo acá a mano.

## 5. El widget de React — cómo se monta en una página de WordPress

WordPress no sabe nada de React. Lo que pasa es:

1. `class-shortcodes.php` registra `[flow_booking tour_id="1"]` (y `[amir_booking ...]` como alias legacy, mismo callback). Cuando alguien pone ese shortcode en una página, WordPress imprime un `<div data-amir-booking data-tour-id="1" data-lang="es">` vacío en el HTML.
2. `class-assets.php` encola `assets/js/booking-widget.js` (el bundle compilado) en esa página.
3. Al cargar la página, `booking-widget.jsx` (el entry point de React) busca en el DOM todos los `[data-amir-booking]`, `[data-amir-tour-list]`, `[data-amir-wishlist]`, `[data-amir-pay-booking]` y monta el componente correspondiente con `createRoot(el).render(...)` usando los `data-*` como props iniciales.
4. Desde ahí es una SPA normal: `api.js` habla con `/wp-json/amir/v1/*`, no hay más contacto con PHP hasta el siguiente `POST`.

Cuatro puntos de montaje, cuatro componentes:

| data-attribute | Componente | Cuándo se usa |
|---|---|---|
| `data-amir-booking` | `BookingWidget.jsx` | Reservar un tour (flujo completo: fecha → personas → datos → pago) |
| `data-amir-tour-list` | `TourList.jsx` | Grilla de tours (`[flow_tour_list]`) |
| `data-amir-wishlist` | `WishlistList.jsx` | Grilla de "próximamente" para tours en borrador |
| `data-amir-pay-booking` | `PayBooking.jsx` | Pagar una reserva que ya existe (viene de un link de email, `awaiting_payment`) |

**Regla que no tiene excepción**: nunca se edita `assets/js/booking-widget.js` ni `assets/css/booking-widget.css` a mano — son artefactos de build. Se edita `react-src/src/**` y se compila:

```bash
cd react-src && npm install && npm run build
```

Esto regenera los dos archivos de `assets/`. `admin.js` es la excepción: es JS plano (sin build), se edita directo.

## 6. El panel de administración

No hay framework de admin — cada pantalla es una clase PHP en `includes/admin/` que:
1. Se registra en el menú de WP Admin (`class-admin-menu.php`).
2. Chequea `current_user_can(...)` antes de mostrar nada.
3. Si recibe un `POST`, valida el nonce y procesa (`handle_submit()` o similar).
4. Imprime HTML directo (no hay motor de templates tipo Blade/Twig — es PHP con HTML inline, patrón estándar de WordPress).

El estilo visual comparte una base común: `assets/css/admin.css` define design tokens en `:root` (`--ab-teal`, `--ab-text`, `--ab-radius`, `--ab-danger`, etc.) y clases reusables (`.ab-card`, `.ab-btn-*`, `.ab-badge-*`). Si vas a construir o rediseñar una pantalla de admin, arrancá reusando esas clases en vez de escribir CSS nuevo — así se mantiene consistente con el resto del panel.

`admin.js` (JS plano, sin build) maneja interactividad del lado admin: confirmaciones, toggles, llamadas AJAX puntuales.

## 7. Base de datos

Dos mundos distintos, y es importante no confundirlos:

- **CPT `amir_tour`** (tabla nativa `wp_posts`): es la *interfaz de carga* — el editor visual donde alguien completa título, descripción, fotos, horarios. Vive en WP Admin → Tours.
- **Tablas propias `wp_amir_*`** (`wp_amir_tours`, `wp_amir_bookings`, `wp_amir_prices`, etc.): acá vive el dato real que consume la REST API y toda la lógica de negocio. Hay un paso de sincronización que copia del CPT a `wp_amir_tours` cada vez que se guarda un tour.

Por qué dos sistemas: el CPT te da gratis el editor visual de WordPress (WYSIWYG, subida de imágenes, revisiones); las tablas propias te dan un modelo relacional real para precios/horarios/reservas, que un CPT no puede modelar bien.

**Si tocás el esquema de una tabla `wp_amir_*`**: hay que actualizar dos lugares — `Installer::create_tables()` (instalaciones nuevas) y `Installer::maybe_update()` con un `ALTER TABLE` explícito (instalaciones existentes, `dbDelta` solo no alcanza para todos los casos) — y subir `AMIR_DB_VERSION`.

## 8. La REST API — la única superficie pública

```
GET  /wp-json/amir/v1/tours                          Listado de tours activos
GET  /wp-json/amir/v1/tours/{id}?lang=es              Detalle de un tour
GET  /wp-json/amir/v1/tours/{id}/schedules
GET  /wp-json/amir/v1/tours/{id}/prices
GET  /wp-json/amir/v1/tours/upcoming                  Tours en borrador (para wishlist)
POST /wp-json/amir/v1/tours/{id}/wishlist             Registrar interés

GET  /wp-json/amir/v1/availability/month?tour_id=&year=&month=
GET  /wp-json/amir/v1/availability/day?tour_id=&date=
GET  /wp-json/amir/v1/availability/schedules?tour_id=&date=

GET  /wp-json/amir/v1/prices

POST /wp-json/amir/v1/bookings/quote                  Cotizar (aplica cupón si viene)
POST /wp-json/amir/v1/bookings                        Crear reserva + iniciar pago
GET  /wp-json/amir/v1/bookings/{ref}?token=…|email=…   Ver una reserva
POST /wp-json/amir/v1/bookings/{ref}/cancel
POST /wp-json/amir/v1/bookings/{ref}/request-cancel
POST /wp-json/amir/v1/bookings/{ref}/init-payment      Generar link de pago (wishlist → awaiting_payment)
GET  /wp-json/amir/v1/bookings/by-payment/{pi_id}
POST /wp-json/amir/v1/bookings/{id}/confirm-payment    Confirma contra Stripe (verificado server-side)
POST /wp-json/amir/v1/bookings/{id}/confirm-mp         Confirma contra Mercado Pago
GET  /wp-json/amir/v1/bookings/{ref}/pdf?token=…|email=…
POST /wp-json/amir/v1/bookings/{id}/status             requiere manage_options
POST /wp-json/amir/v1/bookings/stripe-webhook
POST /wp-json/amir/v1/bookings/mercadopago-webhook

GET  /wp-json/amir/v1/notifications                    requiere estar logueado (admin)
POST /wp-json/amir/v1/notifications/mark-read
```

Todos los endpoints públicos tienen `permission_callback => '__return_true'` (son públicos a propósito) **excepto** los que devuelven datos de un cliente específico (identificación de reserva) o cambian estado — esos exigen `access_token` o `email` vía `BookingManager::authorize_public_access()`, más rate limiting por IP. Nunca confían solo en el `booking_ref` (es secuencial y adivinable: `AMIR-2026-00001`, `-00002`...).

## 9. Cómo se prueban los cambios (no hay staging automático)

No existe un entorno de staging con deploy automático. El flujo real:

1. Editar código.
2. Si tocaste `react-src/`: `cd react-src && npm run build`.
3. Correr tests: `docker run --rm -v "$PWD":/app -w /app php:8.1-cli vendor/bin/phpunit` (Docker porque no hay PHP instalado directo en el host de desarrollo — puede no ser tu caso, si tenés PHP 8.1 local podés correr `vendor/bin/phpunit` directo).
4. Armar el ZIP de despliegue: `vendor/` se regenera **sin** dependencias de dev (`composer install --no-dev --optimize-autoloader`) en una copia aparte, excluyendo `react-src/`, `tests/`, `.git`, `node_modules`, docs internas — solo el código que WordPress necesita en runtime.
5. Subir el ZIP manualmente por WP Admin → Plugins → Subir plugin, en el sitio de pruebas (`amiradventours.com/sandbox/`). Reemplazar el plugin no borra los datos (viven en las tablas `wp_N_amir_*`).

**Antes de cualquier ZIP**: subir `AMIR_VERSION` en `amir-booking.php` (constante y docblock) si tocaste algo en `assets/js/*` o `assets/css/*` — si no, el navegador sirve la versión vieja cacheada.

## 10. Reglas de seguridad que no tienen excepción

- Toda query SQL directa (`$wpdb->query`, `$wpdb->get_row`, etc.) usa `$wpdb->prepare()` — nunca se concatena input de usuario en el string SQL.
- Toda pantalla de admin chequea nonce (formularios) + `current_user_can(...)` antes de hacer nada.
- Ningún endpoint que devuelve datos de una reserva específica confía solo en el `booking_ref` — exige `access_token` (32 bytes random) o `email` vía `authorize_public_access()`.
- Pagos: todo pasa por `PaymentGatewayInterface` (`includes/payments/`). Para sumar una pasarela nueva, implementás la interfaz y la registrás en `PaymentGatewayFactory::make()` — no se toca el controlador.
- PHP 8.1 es un mínimo real (lo exige una dependencia, `endroid/qr-code`), no aspiracional.

## 11. Cómo modificar una pantalla o un diseño — ejemplo concreto

**Cambiar un color/tipografía del widget de reservas** (lo que ve el cliente final al reservar): no se toca CSS a mano — hay una pantalla de admin (`Personalización → 🎨 Widget de reserva`) que genera variables CSS dinámicamente vía `Core\WidgetTheme`. Si el pedido es "que el widget tenga otro color", primero mirá si ya es configurable ahí antes de tocar `react-src/src/styles/widget.css`.

**Cambiar el layout/contenido de una pantalla de admin** (ej. el Dashboard): es la clase correspondiente en `includes/admin/` (ej. `class-dashboard-page.php`) — HTML inline en PHP, reusando las clases `.ab-*` de `assets/css/admin.css`. Se edita, se sube el ZIP, se prueba en el sandbox (no hay hot-reload — cada cambio de PHP requiere recargar la pantalla en WP con el plugin actualizado).

**Cambiar un paso del flujo de reserva** (ej. agregar un campo en "Tus datos"): es JSX en `react-src/src/BookingWidget.jsx`. Se corre `npm run dev` (Vite) para iterar rápido en local si tenés forma de apuntar el fetch de `api.js` a un WordPress real (ver nota de CORS abajo — hoy el build de producción asume que se sirve desde el mismo dominio que WordPress, así que para desarrollo local contra un WP remoto vas a necesitar habilitar CORS o proxy).

## 12. Qué NO vas a encontrar (para no buscarlo de más)

- No hay TypeScript — todo `react-src/` es JS/JSX plano.
- No hay Redux/Zustand/etc — el estado del widget es local a cada componente (`useState`), no hay store global.
- No hay testing de frontend (Jest/Testing Library) — los 61 tests de PHPUnit (`tests/unit/`) cubren solo lógica de dominio en PHP (motor de precios, disponibilidad, cancelaciones, etc.), sin base de datos real.
- No hay CI/CD que despliegue — sí hay CI (`.github/workflows/ci.yml`) que corre los tests en cada push, pero no publica nada.
