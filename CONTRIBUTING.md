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
| 5 | "Cargar reserva + enviar link de pago" desde el admin | ⏳ Parcial — la infraestructura ya existe (`init-payment` + `PayBooking.jsx`, ver § 5.1 punto 6), falta la UI de admin para cargar la reserva directo en `awaiting_payment` |
| 6 | Checkbox de aceptación de términos | ⏳ Parcial — **el widget ya tiene el checkbox** (`ab-policy-check`, campo `policyAccepted`), falta la validación server-side y el texto configurable de la política |
| 7 | Lista de interés ("avísame cuando abra") para tours en borrador | ✅ Hecho — v2 con reserva real y link de pago, ver § 5.1. No confundir con "lista de espera" para tours llenos ya publicados (eso sigue pendiente, no se construyó) |
| 8 | Add-ons en checkout (alquiler de equipo, foto, etc.) | ⏳ Pendiente — ya no está bloqueado por falta de fuente (recuperada), pero requiere UI nueva en `BookingWidget.jsx` |
| 9 | Cupón: campo en el checkout | ✅ Hecho (backend y frontend) |
| 10 | Fix `lang_pref_hint` visible como texto crudo | ✅ Hecho |
| 11 | Mejoras mobile (touch targets, font-size inputs) | ✅ Hecho, portado a `react-src/` |
| 12 | Multi-idioma más allá de ES/EN | ⏳ Pendiente — requiere refactor real: hoy varios archivos PHP (emails, voucher, verificación) y el JS asumen literalmente 2 idiomas con `idioma==='en'?X:Y`, no una iteración sobre N idiomas. Agregar italiano/francés bien hecho es cambiar ese patrón, no solo sumar traducciones |
| 13 | Dos plantillas de detalle de tour + colores de reserva configurables | ⏳ Pendiente — bajo riesgo: `templates/single-amir_tour.php` es PHP normal (agregar una segunda plantilla + selector en Configuración), y los colores del widget ya son variables CSS (`--ab-teal`, etc.) — se pueden volcar desde una opción de Configuración sin tocar `react-src/` |
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

## 6. Convenciones del código

- PHP 8.1 mínimo real (lo exige `endroid/qr-code` como dependencia — no es un capricho).
- Todas las queries con `$wpdb->prepare()`, sin excepción.
- Endpoints REST públicos que devuelven datos de un cliente: exigir `access_token` (fuerte) o `email` (débil, compatibilidad) vía `BookingManager::authorize_public_access()` — nunca solo el `booking_ref`, es secuencial y adivinable.
- Páginas de admin: PHP plano con nonce (`wp_verify_nonce`) + `current_user_can()`, sin excepción — ver `class-coupons-page.php` como ejemplo reciente del patrón.
- Cualquier cambio de esquema de base de datos: agregar a `Installer::create_tables()` (para instalaciones nuevas) *y* a `Installer::maybe_update()` con un `ALTER TABLE` explícito (para instalaciones existentes) — dbDelta solo no alcanza para todos los casos. Subir `AMIR_DB_VERSION`.
- Tests: `tests/unit/`, bootstrap liviano sin WP real (`tests/bootstrap.php` + `FakeWpdb`) — no se pudo correr localmente en esta sesión por falta de PHP instalado; correrlos vos con `composer install && vendor/bin/phpunit` antes de confiar en que pasan.

## 7. Dudas de producto que quedaron sin resolver

- ¿Multisite real para varios operadores (plataforma) o queda como plugin independiente por operador? Se decidió no comprometerse todavía — el `LicenseManager` (stub) ya deja la puerta abierta sin complicar el resto.
- Un cupón que cubra el 100% del total deja el cobro en $0, y Stripe no puede procesar eso — pendiente decidir si vale la pena un flujo de "reserva gratuita sin pasarela".
