# Guía para continuar el desarrollo — TourFlow (Amir Booking)

Este documento es el punto de partida si te sumás a este proyecto sin haber estado en las conversaciones previas. `README.md` documenta la arquitectura y `INSTALL.md` el despliegue — esto de acá es "qué está pasando ahora y cómo seguir".

## 1. Qué es esto

Plugin de WordPress para reservas de tours (Amir Adventours Bacalar), sin WooCommerce. Rebrandeado a **TourFlow** como nombre de producto (el operador turístico sigue siendo Amir Adventours — son dos cosas distintas, ver README § Tablas). Corre en un WordPress Multisite, en un subsitio de pruebas en `amiradventours.com/sandbox/`.

## 2. `react-src/` ya está recuperado — leé esto antes de tocar el widget

El código fuente de React (`react-src/`) apareció y se reconcilió con el bundle compilado (commit `1a9b62d`). Para trabajar en el widget de reservas:

```bash
cd react-src
npm install
npm run build   # genera assets/js/booking-widget.js y assets/css/booking-widget.css
```

- **Nunca edites `assets/js/booking-widget.js` ni `assets/css/booking-widget.css` directo** — son artefactos de build, se sobreescriben. Editá `react-src/src/*` y compilá.
- `admin.js` (panel de admin) es la excepción: es JS plano de 56 líneas, no pasa por este build, se edita directo en `assets/js/admin.js`.
- `react-src/src/BookingWidget.jsx` ya tiene un componente `StepPaymentMP` y manejo de `result.gateway === 'mercadopago'` en `StepSummary` — el frontend para Mercado Pago **ya está escrito**, esperando que el backend (Tarea 14) devuelva `gateway`, `preference_id`, `init_point`, `sandbox_init_point`. Revisar esa función antes de diseñar la respuesta de `MercadoPagoGateway::create_payment()`.
- `react-src/src/TourList.jsx` es la grilla de `[amir_tour_list]` — ya existe y está montada, no es algo por construir.
- Antes de esto, hubo que parchear `booking-widget.js` compilado a mano tres veces (ver README § nota sobre assets/js) mientras no teníamos la fuente — ya está todo portado a `react-src/`, no hace falta repetir eso.
- **Si algo en la fuente parece desactualizado respecto a lo que corre en el sandbox**, no asumas que la fuente es la verdad — compará contra el bundle compilado antes de construir encima (así se encontraron los ajustes responsive de CSS que la fuente no tenía).

## 3. Estado actual (rama `fase1/seguridad-base-codigo2`)

Se está trabajando sobre una rama que todavía no se mergeó a `main`. `main` tiene el código viejo (v1.0.19, con vulnerabilidades de seguridad reales — ver más abajo). Esta rama:

1. Adoptó una versión más avanzada del plugin (`CODIGO2`, multisite, licencias) como base.
2. Cerró varios hallazgos de seguridad: PII expuesta sin autenticación en la verificación pública de reservas, reembolsos que nunca se ejecutaban de verdad contra Stripe, un endpoint de confirmación de pago que se podía usar para confirmar reservas ajenas sin pagarlas (encontrado por `/security-review`, commit `8b7bd55`).
3. Corrigió tres bugs encontrados probando en vivo en el sandbox: el formulario de Configuración no guardaba nada (un `<form>` anidado dentro de otro, HTML inválido), el widget perdía el foco en cada tecla en mobile, y **toda confirmación de reserva exitosa rompía con "h is not a function"** — `stepProps` en `BookingWidget.jsx` armaba `gateway` pero nunca incluía `setGateway`, así que `StepSummary` llamaba a un setter `undefined` justo después de que el backend creaba la reserva bien (confirmado con el bundle real desplegado + un HAR: `POST /bookings` siempre devolvía `201` con datos válidos, la reserva quedaba creada en la base, y el crash pasaba en el cliente al querer avanzar al paso de pago). Bug preexistente, no introducido en esta rama — recién se vio al probar el flujo de pago de punta a punta por primera vez. Fix en `assets/js/booking-widget.js` v1.4.2.
4. Empezó a construir la Fase 2 (ver § 5).

Corré `git log --oneline main..fase1/seguridad-base-codigo2` para ver todos los commits con su razonamiento — cada uno explica el *por qué*, no solo el qué.

## 4. Cómo probar cambios

No hay entorno de staging con despliegue automático. El flujo real usado hasta ahora:

1. Hacer los cambios en el repo local.
2. Armar un ZIP del plugin (carpeta `amir-booking/` con `includes/`, `assets/`, `templates/`, `amir-booking.php`, `composer.json/lock`, y `vendor/` ya generado con `composer install --no-dev --optimize-autoloader` — sin esto el ZIP no sirve, `vendor/` no se versiona en git).
3. Subir el ZIP a `amiradventours.com/sandbox/` vía Plugins → Subir (reemplaza la versión activa; los datos viven en las tablas `wp_N_amir_*`, no en los archivos, así que reemplazar no pierde nada).
4. Probar ahí — no hay otra forma de ver el resultado real, la carpeta local no corre WordPress.
5. **Siempre subir `AMIR_VERSION` en `amir-booking.php`** en cualquier cambio a `assets/js/*` o `assets/css/*` — si no, el navegador puede seguir sirviendo la versión cacheada del archivo viejo.

## 5. Qué falta (Fase 2 — Mercado Pago y mejoras)

Trabajo ya en curso, en este orden:

| # | Qué | Estado |
|---|-----|--------|
| 1 | `PaymentGatewayInterface` + Stripe migrado a esa interfaz | ✅ Hecho |
| 2 | Log de eventos de pago (`wp_amir_payment_events` + pantalla admin) | ✅ Hecho |
| 3 | Cupones (%, monto fijo, por fecha) | ✅ Hecho |
| 4 | `MercadoPagoGateway` (Checkout Pro) | ✅ Hecho — ver § 5.2. País/moneda se maneja con la moneda ya configurable (§ 5.3 más abajo, o el selector de Configuración) — Argentina debería usar Mercado Pago como pasarela activa, Stripe no liquida bien en ARS |
| 5 | "Cargar reserva + enviar link de pago" desde el admin | ✅ Hecho — `BookingsPage::render_new_booking_form()`, checkbox "El cliente todavía no pagó" → `create_manual()` con `awaiting_payment` → `send_payment_link_notice()` (mismo mecanismo que wishlist) |
| 6 | Checkbox de aceptación de términos | ✅ Hecho — validación server-side en `BookingManager::create_pending()` (`policy_accepted`) + texto configurable en Configuración (ES/EN, vacío = default de siempre) |
| 7 | Lista de interés ("avísame cuando abra") para tours en borrador | ✅ Hecho — v2 con reserva real y link de pago, ver § 5.1. No confundir con "lista de espera" para tours llenos ya publicados (eso sigue pendiente, no se construyó) |
| 8 | Add-ons en checkout (alquiler de equipo, foto, etc.) | ✅ Hecho — `StepExtras` en `BookingWidget.jsx`, conectado a `ToursController::fetch_addons()` |
| 9 | Cupón: campo en el checkout | ✅ Hecho (backend y frontend) |
| 10 | Fix `lang_pref_hint` visible como texto crudo | ✅ Hecho |
| 11 | Mejoras mobile (touch targets, font-size inputs) | ✅ Hecho, portado a `react-src/` |
| 12 | Multi-idioma más allá de ES/EN | ✅ Hecho — `Core\Languages` + `content_i18n`, ver README/CLAUDE.md § "Qué ya está hecho". Traducciones base cargadas: it_IT, fr_FR, pt_PT (`languages/`) |
| 13 | Dos plantillas de detalle de tour + colores de reserva configurables | ⏳ **Parcial** — los colores/tipografía/radio del widget ya son configurables (`Core\WidgetTheme`, Configuración → 🎨 Widget de reserva). Falta la parte de la segunda plantilla: hoy solo existe `templates/single-amir_tour.php`, no hay selector de plantilla en Configuración |
| 15 | Meta Pixel + Google Ads/Analytics + descuento vía URL para partners | ✅ Hecho — ver § 5.3 |
| 16 | Tours que no admiten niños/bebés (ej. solo adultos) | ✅ Hecho — ver § 5.4 |
| 17 | Plantillas de email editables + contenido extra por tour | ⏳ Pedido del cliente, sin priorizar todavía — ver § 5.5 para el spec |
| 14 | GDPR (mercado europeo) + evaluar Redsys u otra pasarela europea | ⏳ Backlog, sin diseñar todavía |

Para agregar una pasarela nueva: implementar `PaymentGatewayInterface` (en `includes/payments/`), registrarla en `PaymentGatewayFactory::make()`. El controlador (`class-booking-controller.php`) no necesita cambios — ya está escrito contra la interfaz, no contra Stripe directamente.

### 5.1 Lista de interés (wishlist) — v2, reserva real

Aprovecha que un tour en `amir_tours.status='draft'` ya es invisible para el público (`ToursController` solo devuelve `status='active'`) — no hizo falta un estado nuevo para el tour. Lo que sí es nuevo son dos estados en `amir_bookings.status`: **`wishlist`** (interés registrado, sin cobrar) y **`awaiting_payment`** (tour ya abierto, cliente tiene el link pero no hizo clic todavía) — ninguno de los dos lo toca `BookingManager::release_expired_pending()`, que solo mira `status='pending'`. El flujo completo:

1. Al editar un tour en el CPT (`class-tour-post-type.php`), el operador tilda **"Activar lista de interés"**, pone un **umbral** opcional y sobre todo la **fecha del tour/retiro** (`wishlist_date`) — no hay calendario de disponibilidad mientras el tour sigue en borrador (`AvailabilityEngine::get_tour()` exige `status='active'`), así que la fecha la fija el operador de antemano.
2. `[amir_wishlist]` (`WishlistList.jsx`) muestra esos tours en modo "Próximamente" con un mini-flujo real: horario (si el tour tiene más de uno), personas, precio estimado (reutiliza `POST /bookings/quote`, que no filtra por status), datos del cliente. Pega en `GET /tours/upcoming` y `POST /tours/{id}/wishlist` (`class-wishlist-controller.php`).
3. Cada anotación llama a **`BookingManager::create_wishlist()`** — crea una fila real en `amir_bookings` (estado `wishlist`), sin pasar por `AvailabilityEngine` a propósito (mientras el tour está en borrador se quiere poder medir demanda incluso por encima del cupo configurado). Si se llega al umbral, notificación en `amir_notifications` + email al admin, una sola vez por tour (`amir_tours.wishlist_notified_at`).
4. **Amir Booking → Lista de interés** (`class-wishlist-page.php`): progreso por tour + detalle de las reservas anotadas (con precio ya calculado). El botón **"Publicar y notificar"** publica el tour (`wp_update_post` → dispara el `sync_to_db` normal del CPT) y pasa cada reserva `wishlist` → `awaiting_payment`, mandando `TourOpenedEmail` con el link de pago real (`verify_url()`, mismo patrón que el resto de los emails).
5. Si el admin publica el tour a mano desde el editor de WP (sin pasar por ese botón), la página detecta reservas `wishlist` en tours ya activos y muestra "Notificar ahora" aparte.
6. El link de pago cae en la página `[amir_verify_booking]` de siempre (`/verificar-reserva/?ref=...&token=...`) — si el estado es `awaiting_payment`/`pending`, se monta `PayBooking.jsx` ahí mismo. Recién en ese momento (cuando el cliente hace clic) se llama a **`POST /bookings/{ref}/init-payment`**, que crea el cobro real con la pasarela (reutiliza `PaymentGatewayFactory` + `PaymentGatewayInterface::create_payment()`, misma forma de respuesta que `POST /bookings`) y pasa la reserva a `pending` — ahí es donde arranca el cronómetro real de expiración de 15 min, no antes. `PayBooking.jsx` reutiliza `StepPayment`/`StepPaymentMP` de `BookingWidget.jsx` tal cual (se exportaron para esto).

No hay auto-apertura: llegar al umbral solo avisa al admin, la decisión de publicar sigue siendo manual. La Tarea "cargar reserva + enviar link de pago desde el admin" queda prácticamente resuelta con esta misma infraestructura (`init-payment` + `PayBooking.jsx`) — falta solo la UI en el admin para cargar la reserva directamente en `awaiting_payment` sin pasar por el flujo de wishlist.

**Pendiente de higiene, no bloqueante:** el campo que usa `cancel()`/reembolsos para saber si una reserva tiene un cobro real es `stripe_charge_id` — nombre heredado, en la práctica ya funciona para cualquier pasarela (lo llena `BookingManager::confirm()` sin importar cuál), pero conviene renombrar/consolidar con `gateway_charge_id` (hoy huérfano). El hook `amir_process_stripe_refund` (en `class-plugin.php`) tiene el mismo problema de nombre. Se confirmó que ya funciona igual para Mercado Pago (ver § 5.2) sin tocar nada de esto — sigue siendo solo un tema de claridad, no de funcionalidad.

### 5.2 Mercado Pago (`MercadoPagoGateway`)

Implementa `PaymentGatewayInterface` igual que Stripe — el controlador (`class-booking-controller.php`) no sabe cuál de las dos está activa, todo pasa por `PaymentGatewayFactory`. Un detalle no obvio de cómo encaja con el resto del código ya existente:

- **El "reference" que devuelve `create_payment()` es el `booking_ref`, no el id de la preferencia de MP.** `BookingController::create_booking()` guarda ese reference tal cual en `amir_bookings.gateway_reference` sin saber qué pasarela es. El webhook de MP solo trae el id del *pago* (que no existe todavía cuando se crea la preferencia, se genera recién cuando el cliente paga) — la única forma de volver a encontrar la reserva desde el webhook es el `external_reference` que se manda al crear la preferencia. Usando el `booking_ref` para las dos cosas, el lookup genérico que ya tenía `BookingController::handle_gateway_webhook()` (`WHERE gateway_reference = %s`) funciona sin cambiar una línea de ese código.
- **Confirmación con dos caminos**: el webhook (`POST /bookings/mercadopago-webhook`, reutiliza `handle_gateway_webhook()`) es el camino principal, pero como Checkout Pro redirige a una pestaña nueva, el frontend (`StepPaymentMP`, ya existía en `BookingWidget.jsx`) hace polling a `POST /bookings/{id}/confirm-mp` — que primero mira si el webhook ya confirmó, y si no, consulta activamente `GET /v1/payments/search?external_reference=...` como respaldo (útil en sandbox, donde a veces no hay webhook configurado).
- **Configuración**: Amir Booking → Configuración → sección "Mercado Pago" — modo test/live, Access Token por modo, Webhook Secret (lo genera MP en Tus integraciones → Webhooks), y un selector "Pasarela de pago activa" (`amir_default_gateway`) que decide con cuál se cobran las reservas nuevas por defecto.
- **Moneda**: usa la que esté configurada en la sección Moneda (mismo `amir_currency` de toda la app) — Mercado Pago la recibe como `currency_id` al crear la preferencia. Para Argentina, tiene que estar en ARS y con Mercado Pago como pasarela activa (Stripe no liquida bien en pesos argentinos).
- **Tests**: `tests/unit/MercadoPagoGatewayTest.php` cubre la verificación de firma del webhook (HMAC real, sin red) y el mapeo de estados (`approved`/`rejected`/`pending` → `PaymentEvent`), mockeando `wp_remote_get()` a nivel de namespace para no pegarle a la API real.
- **No probado en vivo todavía** — hace falta cargar credenciales de test de una cuenta de Mercado Pago real en Configuración para probar el flujo de punta a punta en el sandbox.

### 5.3 Meta Pixel + Google Ads/Analytics + descuento vía URL para partners (Tarea 15)

Pedido del cliente para poder correr campañas medibles (Meta/Google Ads) y links de partner que además den descuento al cliente final, no solo comisión. Implementado en v2.0.1.

**Píxeles (Meta Pixel + Google Ads/GA4)** — `Core\Marketing` (`includes/core/class-marketing.php`).
- **Configuración → Marketing**: Meta Pixel ID, Google Ads Conversion ID + Conversion Label, GA4 Measurement ID — todos opcionales; sin ningún ID cargado no se imprime ningún script (`Marketing::print_base_scripts()` corta temprano).
- Scripts base (`fbq`/`gtag`) se inyectan en `wp_head`, sitio entero — igual que cualquier instalación estándar de estos píxeles (remarketing/audiencias necesitan verlos en todas las páginas, no solo en la del tour).
- Tres eventos del embudo, mapeados al estándar de e-commerce de cada plataforma:
  1. **Vista de tour** → `ViewContent` (Meta) / `view_item` (GA4) — disparado server-side desde `templates/single-amir_tour.php`, ya tiene `$db_id`/`$title`/`$price_from` a mano, no depende del widget.
  2. **Widget de reserva montado** → `InitiateCheckout` / `begin_checkout` — `BookingFlow` en `BookingWidget.jsx`, un solo `useEffect` sin deps.
  3. **Reserva confirmada** → `Purchase` (Meta) / `purchase` (GA4) / evento `conversion` de Google Ads (con `send_to: "{gadsConversionId}/{gadsConversionLabel}"`) — `StepConfirm`, usa `quote.total_mxn` y `Currency::code()` como valor/moneda reales, con un `useRef` para que un re-render no lo dispare dos veces.
  - Funciones auxiliares en `react-src/src/marketing.js` (`trackInitiateCheckout`, `trackPurchase`, `getCouponFromUrl`) — todas chequean `window.fbq`/`window.gtag` antes de llamar, así que si no hay píxeles configurados no truena nada, simplemente no hacen nada.
  - Los IDs que el JS necesita (`gadsConversionId`/`gadsConversionLabel`, para el `send_to` de la conversión) viajan en `window.amirBooking.marketing`, agregado al `wp_localize_script` que ya arma `class-shortcodes.php`.
  - **Pendiente, no cubierto todavía**: `PayBooking.jsx` (pago de una reserva de wishlist/awaiting_payment ya cargada, ver § 5.1) no dispara `Purchase` — no tiene a mano el `tour.id`/monto sin una llamada extra, quedó fuera de alcance de este cierre para no mandar datos de conversión incompletos/incorrectos.

**Descuento vía URL para partners** — no confundir con el sistema de partners que ya existía (`includes/partners/class-partner-tracker.php`): ese `?ref=TOKEN` trackea atribución + **comisión para el partner** (cookie 30 días, `amir_partners.commission_type/value`), eso sigue igual, no se tocó.
- Lo nuevo es independiente: `?coupon=CODE` en la URL del tour precarga el campo de cupón que **ya existía** en el checkout (`couponCode` en `BookingWidget.jsx`, antes 100% manual) — `getCouponFromUrl()` en `marketing.js` lee el query param al inicializar el estado del formulario. Así "reservá con 10% con este link" queda en un solo clic, sin que el cliente escriba nada.
- Se descartó a propósito la opción de atar el cupón al partner (`amir_partners.coupon_code`, columna nueva) — no había una decisión tomada sobre si el cliente quiere reportar conversión por partner o solo repartir códigos sueltos, y el camino de `?coupon=` directo no bloquea sumar eso después si hace falta.

### 5.4 Tours que no admiten niños/bebés (Tarea 16)

El frontend (`StepPeople` en `BookingWidget.jsx`) ya tenía la lógica lista desde antes (`tour.allow_children !== false`, etc., default admite ambos) pero esperaba datos que la API nunca mandaba. Implementado en v2.1.0:

- **Columnas nuevas en `amir_tours`**: `allow_children TINYINT(1) DEFAULT 1`, `allow_babies TINYINT(1) DEFAULT 1`, `min_age_child TINYINT UNSIGNED DEFAULT 4` (el piso de la franja "niño" que se muestra junto al contador, ej. "4–12 años" — por debajo cuenta como bebé). Migración en `Installer::maybe_update()` + `create_tables()`, `AMIR_DB_VERSION` → 1.8.8.
- **Editor del tour**: sección nueva "👶 Restricciones de edad" en `class-tour-post-type.php` (checkboxes "Admite niños"/"Admite bebés" + edad mínima "niño"), junto al campo de Edad mínima que ya existía. **Cuidado si tocás este código**: los checkboxes desmarcados no llegan en `$_POST`, y como PHP trata `'0'` como falsy, un `?: '1'` ingenuo en `get_meta()`/`sync_to_db()` volvería a mostrar/guardar "admite" aunque el operador lo haya desmarcado — hay que distinguir explícitamente `''` (meta nunca guardado, default admite) de `'0'` (guardado como no admite) comparando con `===`, no con `?:`.
- **API**: `ToursController::format_tour_full()` expone los tres campos — `get_tour()` ya hacía `SELECT *`, no hizo falta tocar la query.
- **Validación server-side**: `BookingManager::child_baby_policy_error()`, llamada desde `create_pending()` y `create_wishlist()` — rechaza la reserva si pide niños/bebés en un tour que no los admite, sin depender de que el frontend los haya ocultado. `create_manual()` (reservas cargadas por el admin, por teléfono/WhatsApp) queda sin esta restricción a propósito — un admin puede tener motivo para hacer una excepción puntual.
- **Visibilidad de la edad mínima**: ya se mostraba en las tarjetas de listado y en la ficha del tour (`amir-chip`), pero no durante el flujo de reserva en sí — se agregó un aviso (`.ab-age-notice`) en `StepPeople` mismo, junto al título del paso, para que quede visible justo donde el cliente elige cuántos niños/bebés lleva.

### 5.5 Plantillas de email editables + contenido extra por tour (Tarea 17, sin construir)

Pedido del cliente: hoy el contenido de los emails (confirmación, recordatorio, "tu tour abrió", etc. — `EmailDispatcher`) ya sale en el idioma correcto del cliente (`Core\Languages`, ver § "Qué ya está hecho"), pero el *contenido* en sí es fijo en código PHP, más allá de las secciones ya configurables (recomendaciones de Configuración → Contenido del email). El cliente quiere:

1. **Ver y editar las plantillas de email desde el admin** — no solo las listas de recomendaciones que ya existen en Configuración, sino el cuerpo completo de cada tipo de email (confirmación, recordatorio, cancelación, tour abierto, etc.).
2. **Contenido extra personalizado por tour** — ej. "este tour requiere llevar pasaporte" o cualquier requisito puntual de un tour específico, que se sume al email de confirmación de ESE tour sin tocar la plantilla general.

**Bug real confirmado al investigar el pedido del cliente sobre emails "incompletos" en el idioma seleccionado**: el motor central de traducción de emails (`EmailDispatcher::send()` corre `get_subject()`/`get_body_content()` dentro de `Languages::run_in( $this->lang, ... )`, que hace `switch_to_locale()` — cualquier `__()`/`_e()` adentro traduce bien a it/fr/pt) **funciona correctamente**. El hueco real es la sección "Recomendaciones" (`EmailDispatcher::recs_html()`, línea ~358) y su equivalente en el voucher PDF (`VoucherGenerator`, líneas ~247 y ~534): ambas usan `$is_en = $this->lang === 'en'` y leen directo de `amir_email_recs_es`/`amir_email_recs_en` (u homólogos de voucher) — **hardcodeado a solo 2 idiomas**, sin pasar por `Languages`. Para un cliente en it/fr/pt, esa sección específica cae en silencio al español mientras el resto del email ya está bien traducido. Arreglar esto (agregar `amir_email_recs_{lang}` dinámico por idioma activo, como ya hace `Core\Languages` para el contenido de tours) debería ir junto con el resto de este ítem, no aparte — mismo tipo de cambio.

Sin diseñar el resto todavía. Puntos a resolver antes de construirlo:
- Qué tan editable: ¿un editor de texto simple con placeholders (`{{customer_name}}`, `{{tour_name}}`, etc., como hacen la mayoría de los plugins de email transaccional) o control total sobre el HTML? Un editor con placeholders es mucho más simple de construir y más seguro (no hay forma de romper el layout), pero menos flexible.
- El contenido extra por tour probablemente sea un campo nuevo en el meta box del tour (`class-tour-post-type.php`, similar a `what_to_expect`) — multi-idioma igual que el resto (`content_i18n`), inyectado en `EmailDispatcher::send_confirmation()` si no está vacío.
- Si se permite editar el HTML/diseño de las plantillas, hay que decidir si eso vive en `amir_options` (como el resto de la configuración) o si conviene una tabla dedicada tipo `amir_email_templates` — más limpio para versionar cambios, pero es una tabla nueva y una migración más.

## 6. Convenciones del código

- PHP 8.1 mínimo real (lo exige `endroid/qr-code` como dependencia — no es un capricho).
- Todas las queries con `$wpdb->prepare()`, sin excepción.
- Endpoints REST públicos que devuelven datos de un cliente: exigir `access_token` (fuerte) o `email` (débil, compatibilidad) vía `BookingManager::authorize_public_access()` — nunca solo el `booking_ref`, es secuencial y adivinable.
- Páginas de admin: PHP plano con nonce (`wp_verify_nonce`) + `current_user_can()`, sin excepción — ver `class-coupons-page.php` como ejemplo reciente del patrón.
- Cualquier cambio de esquema de base de datos: agregar a `Installer::create_tables()` (para instalaciones nuevas) *y* a `Installer::maybe_update()` con un `ALTER TABLE` explícito (para instalaciones existentes) — dbDelta solo no alcanza para todos los casos. Subir `AMIR_DB_VERSION`.
- Tests: `tests/unit/`, bootstrap liviano sin WP real (`tests/bootstrap.php` + `FakeWpdb`) — no se pudo correr localmente en esta sesión por falta de PHP instalado; correrlos vos con `composer install && vendor/bin/phpunit` antes de confiar en que pasan.
- **`amir_bookings.schedule_id` puede ser `0`** ("sin horario específico" — tours de un solo horario, o reservas manuales cargadas sin horario). Cualquier query que haga `JOIN {$wpdb->prefix}amir_tour_schedules` en vez de `LEFT JOIN` deja esas reservas invisibles en el resultado (bug real encontrado y corregido en `class-dashboard-page.php`, `class-reports-page.php` y `class-bookings-page.php` en jul-2026) — siempre `LEFT JOIN` para esa tabla.

## 7. Dudas de producto que quedaron sin resolver

- ¿Multisite real para varios operadores (plataforma) o queda como plugin independiente por operador? Se decidió no comprometerse todavía — el `LicenseManager` (stub) ya deja la puerta abierta sin complicar el resto.
- Un cupón que cubra el 100% del total deja el cobro en $0, y Stripe no puede procesar eso — pendiente decidir si vale la pena un flujo de "reserva gratuita sin pasarela".

## 8. Personalización visual del widget de reserva (Tarea 19)

El widget (`react-src/src/styles/widget.css`) está construido casi enteramente sobre variables CSS — nunca hace falta tocar colores/tamaños a mano en el CSS, se ajustan desde **Configuración → 🎨 Widget de reserva** (`amir_widget_color`, `amir_widget_font`, `amir_widget_font_scale`, `amir_widget_radius`). `includes/core/class-widget-theme.php` (`WidgetTheme`) lee esas opciones y arma el bloque `:root{...}` que `Shortcodes::enqueue_widget_assets()` inyecta vía `wp_add_inline_style()` — el CSS compilado por el build de React (`assets/css/booking-widget.css`) nunca se toca, el override cae en cascada después.

**Variables disponibles** (todas en `:root` de `widget.css`):

| Variable | Qué controla | Origen |
|---|---|---|
| `--ab-teal` | Color principal (botones, acentos, selección) | Configuración → color principal |
| `--ab-teal-dark` / `--ab-teal-light` / `--ab-teal-mid` | Variantes oscuro/claro/medio del color principal | Calculadas automáticamente por `WidgetTheme` (nunca se editan a mano — mismo criterio que `[amir_tour_list]`, para que no se pueda elegir una combinación ilegible) |
| `--ab-text` / `--ab-muted` / `--ab-border` / `--ab-bg` / `--ab-bg2` | Texto, texto secundario, bordes, fondos | Fijos (no expuestos en Configuración todavía) |
| `--ab-red` / `--ab-amber` | Estados de error / advertencia | Fijos |
| `--ab-font` | Familia tipográfica | Configuración → tipografía (lista curada: Sistema, Inter, Poppins, Nunito, Roboto, Lato — no texto libre, para no cargar una fuente que no se lea bien en el ancho chico del widget) |
| `--ab-font-scale` | Multiplicador de tamaño de texto (0.92 / 1 / 1.08) | Configuración → tamaño de texto. **Los campos de formulario (`.ab-input`/`.ab-textarea`/`.ab-select`) nunca bajan de 16px reales** aunque se elija "Compacto" — es el mínimo que evita el zoom automático de iOS Safari al enfocar un input. Los touch targets (contadores +/-, navegación del calendario, toggle de idioma — todos de 44×44px) tampoco escalan con el texto: son medidas de accesibilidad, no tipografía. |
| `--ab-radius` / `--ab-radius-sm` | Radio de esquinas (tarjetas / botones-inputs) | Configuración → radio de esquinas |
| `--ab-shadow` | Sombra del contenedor principal | Fijo |

**Para agregar un control nuevo** (ej. exponer `--ab-text` en Configuración): agregar la opción en `class-settings-page.php`, un caso en `WidgetTheme::render_inline_css()`, y listo — no hace falta tocar `widget.css` salvo que la variable todavía no exista ahí.

### Arquitectura de pasos de `BookingWidget.jsx`

El wizard es una lista de pasos en el array `STEPS` (línea ~9), filtrada dinámicamente en `activeSteps` según el tour:

```
StepDate → StepSchedule* → StepPeople → StepExtras† → StepDetails → StepSummary → StepPayment/StepPaymentMP‡ → StepConfirm
```

- **`*` StepSchedule** se salta si el tour tiene un solo horario (`needsScheduleStep()`).
- **`†` StepExtras** se salta si el tour no tiene servicios extra cargados (`needsExtrasStep()`, agregada junto con la Tarea 18/add-ons).
- **`‡` StepPayment` vs `StepPaymentMP`**: cuál se monta depende de `gateway` (estado de `BookingFlow`), no de la pasarela configurada globalmente — se decide con la respuesta de `POST /bookings` (campo `gateway`).

Cada `Step*` es una función de nivel de módulo (no anidada dentro de otro componente — ver el comentario de `CustomerField` en el mismo archivo sobre por qué eso importa para no perder el foco de los inputs en mobile) y recibe todo su estado compartido (`form`, `patchForm`, `quote`, `t`, etc.) como props vía el objeto `stepProps` armado en `BookingFlow`. Para agregar un paso nuevo: sumarlo a `STEPS`, un filtro condicional en `activeSteps` si corresponde saltarlo, una rama de render en el JSX de `BookingFlow`, y su componente `function StepNuevo(...)` — mismo patrón que `StepExtras`.

## 9. Idea de producto: Afiliados externos ("TourFlow Affiliate Hub")

Disparada por un proyecto nuevo, **Visit Sicily Experiences** (instalación de WordPress separada de Amir Adventours, público internacional EN/DE) — no es parte del roadmap acordado con Amir Adventours (§5), pero si se construye, se construye como feature genérica del plugin, no como customización de un solo sitio, porque funciona igual en la versión multisite.

**Aclaración clave de alcance**: estos afiliados son proveedores **externos** (Booking.com, GetYourGuide, Viator, Discover Cars, etc.) — TourFlow no es dueño de esa cuenta ni de la relación comercial con el cliente final, y no reparte ingresos con el operador. El plugin únicamente **administra la configuración y la muestra en el sitio** (credenciales, visualización, redirect al proveedor en el paso de pago). Cada instalación carga sus propias credenciales de partner, igual que ya hace con Stripe/Mercado Pago.

Spec propuesto (mismo patrón que `PaymentGatewayInterface`):

| Pieza | Qué hace |
|---|---|
| `AffiliateProviderInterface` | Contrato común: credenciales, tipo de servicio (hoteles/autos/actividades/seguros), método para generar el link o el embed |
| Implementaciones v1 | `BookingComAffiliate`, `DiscoverCarsAffiliate`, `GetYourGuideAffiliate`, `ViatorAffiliate`, `TiqetsAffiliate`, `WorldNomadsAffiliate` |
| Configuración | Nueva pestaña "Afiliados" en Ajustes, per-site (mismo patrón `wp_N_options` que ya usan Stripe/Mercado Pago) — nada a nivel de red multisite |
| Frontend | Shortcode nuevo con estética de TourFlow, redirect al proveedor solo en el paso de checkout, atribución visible ("en alianza con X") — casi todos los TOS de estos programas lo exigen y evita reclamos de soporte cuando el operador no gestiona esa reserva. **Uso libre en cualquier post/página, no solo en la plantilla de tour** — el tráfico que hay que monetizar entra sobre todo por contenido/blog SEO ("cómo moverse en Sicilia"), así que el shortcode tiene que poder insertarse dentro de un artículo, no solo en la ficha de un tour puntual |
| Fuera de alcance v1 | Vuelos (Amadeus self-service se dio de baja en jul-2026, sin alternativa simple hoy), traslados (comisiones sin validar todavía), liquidación compartida entre TourFlow y el operador |
| Opcional, no bloqueante | Log propio de clics por proveedor/artículo (sin datos personales, no es conversión — eso lo trackea cada red por su cuenta) para que el operador vea qué contenido genera intención de compra. Reutilizaría el patrón de `class-payment-log-page.php` |

Verificar comisiones/condiciones vigentes de cada proveedor antes de implementar — cambian con frecuencia y lo relevado en jul-2026 puede haber variado.

### 9.1 Plan de contenido/SEO/GEO para Visit Sicily Experiences

El modelo de negocio depende de tráfico de contenido (no solo de fichas de tour), así que conviene planear la estructura de clusters desde el arranque del sitio, cada uno mapeado a qué monetiza:

| Cluster | Artículos tipo | Qué monetiza |
|---|---|---|
| Cómo moverte en Sicilia | "Car rental vs. transporte público", "Alquilar auto en Sicilia" (peajes A18/A20, ZTL en centros históricos), "Ruta en auto 7/10/14 días" | Widget de **alquiler de autos** (Discover Cars/Rentalcars) insertado directo en el texto — el cluster más alineado con la realidad de Sicilia (transporte público limitado) |
| Itinerarios por región | Sudeste (Taormina, Catania, Siracusa, Etna, Agrigento), Oeste (Palermo, Erice, Trapani), Barroco UNESCO (Ragusa, Modica, Noto) | Tours propios primero, **GetYourGuide/Viator** para lo que Visit Sicily no ofrece en esa zona, widget de **hoteles** para dónde alojarse |
| Etna (alta intención de búsqueda) | Guía de senderismo/teleférico/jeep tours, comparativa de tours desde Taormina/Catania, cata de vinos en las laderas | Tour propio destacado primero, alternativas de Viator/GetYourGuide debajo |
| Logística de llegada | Guías de aeropuerto de Catania y Palermo, "Dónde alojarse según tu itinerario" | Widget de **traslados** + **hoteles** |
| FAQ prácticas | "Mejor época para visitar", "¿Necesito seguro de viaje?", "Reglas de manejo para turistas" | Formato ideal para citación por IA (ver GEO abajo) + **seguros** (World Nomads) |

**Keywords orientativas** (sin acceso a datos reales de volumen, dirección validada por volumen de contenido competidor): EN — "sicily itinerary 7/10 days", "should I rent a car in sicily", "mount etna day trip from taormina", "sicily road trip", "best time to visit sicily"; DE — "Sizilien Rundreise Mietwagen", "Ätna Tagesausflug Taormina", "Sizilien beste Reisezeit", "Flughafen Catania Transfer".

**GEO (citación por buscadores de IA)**: combinación de mayor impacto es **estadísticas concretas + redacción fluida** (precios reales de peajes, distancias, horarios) en vez de generalidades; `FAQPage` schema en todo el cluster de preguntas prácticas (mayor tasa de citación en Perplexity/Google AI Overview); `robots.txt` debe permitir explícitamente `PerplexityBot`, `ClaudeBot`, `GPTBot`, `Bingbot` desde el lanzamiento — algunos plugins de seguridad de WordPress los bloquean por defecto.

## 10. Idea de producto: schema markup automático para tours (TourFlow en general, no solo Sicilia)

Ya que `amir_tours` tiene estructurados precio, duración, imágenes y ubicación de cada tour propio, generar automáticamente el JSON-LD `TouristTrip`/`Product`/`Offer` desde esos datos —sin que el operador toque nada— sería una mejora barata y **genérica** de TourFlow: cualquier instalación (Amir Adventours incluido) gana visibilidad en Google/buscadores de IA en sus fichas de tour existentes, gratis, porque el dato ya está en la base. Bajo esfuerzo, no depende de Visit Sicily ni de ningún módulo de afiliados — se puede construir independientemente.

## 11. Marketplace de proveedores externos (tours de terceros) — spec acordado, sin construir

Planteado por el cliente el 2026-07-27, dialogado y cerrado en la misma sesión. **No confundir con §9 (Afiliados externos)**: ahí TourFlow solo redirige al proveedor (Booking.com, GetYourGuide) y nunca es dueño de la venta; acá TourFlow **sí es el vendedor de registro** — el cliente paga a TourFlow a un precio propio, y TourFlow le liquida después al proveedor su costo. La diferencia es de negocio, no de detalle técnico: comisión de referido vs. reventa con margen propio.

**Por qué es distinto de `amir_partners` (ya existente)**: un partner de hoy *refiere* clientes a tours *nuestros* y cobra comisión (`PartnerTracker`, `?ref=TOKEN`). Acá el proveedor *es dueño* del tour — se modela aparte, `amir_providers`, aunque el criterio de "liquidación pendiente/pagada" se pueda reusar de forma similar.

### Decisiones cerradas en el diálogo

| Punto | Decisión |
|---|---|
| Quién carga el tour del proveedor | **El equipo de TourFlow, a mano** — el proveedor manda los datos (WhatsApp/email/planilla), previamente acordado con él, y se carga con el editor de tours actual. Un portal de autogestión ("Vendé tus experiencias en nuestro portal") queda documentado como visión a futuro, no en esta etapa — no bloquea nada de lo de abajo si se construye después |
| Aprobación de cada reserva | **Link por email, sin login** — mismo patrón que `verify_url()` de wishlist (token en la URL, sin cuenta de usuario). Panel/calendario propio del proveedor para bloquear disponibilidad de antemano queda para v2 — para v1, el propio paso de aprobación ya cumple esa función: si el proveedor está lleno, rechaza esa reserva puntual |
| Plazo de respuesta | Recordatorio a las **24h**, cancelación automática + reembolso a las **48h** sin respuesta — configurable en Configuración (no hardcodeado), para poder ajustarlo por acuerdo comercial sin tocar código |
| Precio/margen | Costo del proveedor separado del precio de venta (no una comisión %) — `amir_prices` suma una columna de costo junto al `price_mxn` que ya existe por tipo de persona/grupo. Margen = venta − costo |
| Liquidación | **Manual para v1** — ledger simple de "liquidado/pendiente", igual que quedó anotado para partners. Un payout automático tipo transferencia desde Stripe (Stripe Connect u similar) quedó explícitamente como "lujo, no necesario ahora" — no bloquea la v1 si se suma después |
| Transparencia al cliente | Aviso **discreto** en la ficha del tour (no en el email de confirmación) — algo como "sujeto a confirmación del operador local", **sin nombrar al proveedor** ni invitar a buscarlo por fuera de TourFlow (riesgo de que el cliente reserve directo con el proveedor la próxima vez, salteando la plataforma) |

### Flujo de reserva

1. Cliente reserva y paga normal, al precio de TourFlow (nada cambia en el checkout).
2. Si el tour tiene `provider_id`, la reserva no pasa a `confirmed` directo — entra en un estado nuevo, `pending_provider_approval`.
3. Email al proveedor ("Recibiste una nueva reserva desde TourFlow") con botones Aprobar/Rechazar vía link tokenizado, y con los datos de contacto del cliente (nombre, teléfono/email, fecha, personas, pedidos especiales) — el proveedor puede necesitar contactar al cliente directo con info adicional (punto de encuentro puntual, etc.), más allá de la confirmación estándar de TourFlow.
4. **Aprueba** → pasa a `confirmed`, dispara el email de confirmación y voucher de siempre — para el cliente no cambia nada.
5. **Rechaza**, o pasan 48h sin respuesta → se cancela y se reembolsa automático (reusa `PaymentGatewayInterface::refund()`, ya integrado).
6. Cron (mismo mecanismo que ya libera `pending` vencidos): recordatorio a las 24h, auto-cancelación a las 48h.

### Modelo de datos (sketch, sin implementar)

- `amir_providers`: id, nombre, contacto, email, teléfono, notas, activo.
- `amir_tours.provider_id` (NULL = tour propio, como hoy).
- `amir_prices`: + `provider_cost_mxn` junto al `price_mxn` existente, por tipo de persona/grupo.
- `amir_bookings`: nuevo status `pending_provider_approval` en el ENUM, + `provider_response_token`, `provider_notified_at`, `provider_reminder_sent_at`, `provider_responded_at`.
- `amir_provider_payouts`: ledger manual (proveedor, monto, nota, fecha) — igual de simple que lo pendiente para `amir_partners`.
- Configuración nueva: `amir_provider_reminder_hours` (24), `amir_provider_response_hours` (48).

Sin empezar a construir — queda documentado para cuando se priorice.
