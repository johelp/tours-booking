# TourFlow (Amir Booking) — Contexto del proyecto

Este archivo se carga automáticamente en cada sesión de Claude Code sobre este repo. Mantiene lo esencial y durable; el detalle vive en `README.md` (arquitectura), `INSTALL.md` (despliegue) y `CONTRIBUTING.md` (estado y roadmap con más contexto). Si estás retomando esto en una ventana nueva: leé este archivo primero, después `CONTRIBUTING.md`, y corré `git log --oneline main..$(git branch --show-current)` para ver el historial completo con el razonamiento de cada cambio.

## Qué es esto

Plugin de WordPress para reservas de tours y experiencias, sin WooCommerce. Nombre de producto: **TourFlow**. Cliente/operador original: Amir Adventours Bacalar (son dos cosas distintas — TourFlow es el software, Amir Adventours el negocio que lo usa; ver README § Tablas de base de datos). Corre en un WordPress Multisite con un subsitio de pruebas en `amiradventours.com/sandbox/`.

Rama de trabajo activa: **`fase1/seguridad-base-codigo2`** (no mergeada a `main` — `main` tiene código viejo v1.0.19 con vulnerabilidades reales, no trabajar ahí).

## Estado al cierre de la última sesión

- **Plugin en v2.1.1** (`AMIR_VERSION`), `AMIR_DB_VERSION` en 1.8.8 (sin cambios de esquema esta sesión). Último ZIP armado: `amir-booking-2.1.1.zip` (no queda guardado entre sesiones — el scratchpad es efímero; se rearma con `composer install --no-dev --optimize-autoloader` + `zip`, ver regla 2. Si hay cambios en `react-src/`, correr `npm run build` ahí primero). **Ojo con el tamaño del ZIP**: `vendor/` pesa ~43MB (`tecnickcom/tcpdf` + `endroid/qr-code`, dependencias reales, no basura) — armar el ZIP excluyendo también `dev-notes/` y `package-lock.json` además de lo obvio (`.git`, `node_modules`, `react-src`, `tests`, `docs-manual`, `*.md`), si no el ZIP pesa el doble sin necesidad.
- **Todo el trabajo de esta rama ya está commiteado y pusheado a `origin/fase1/seguridad-base-codigo2`.** Si en una sesión nueva `git status` muestra cambios sin commitear al arrancar, probablemente sea trabajo de ESA sesión sin cerrar, no algo perdido.
- **v2.1.0 SÍ se subió al sandbox y se probó en vivo esta sesión** — confirmado que el bug de sincronización de `content_i18n` (ver historial de commits) quedó resuelto: el tour "Yoga en el Cielo" sincronizó bien, la lista de interés generó una reserva real (`BK-2026-00009`, wishlist → awaiting_payment) y el flujo de principio a fin funciona.
- **Bug nuevo encontrado y corregido esta sesión**: el email de "tu tour ya está disponible" (`TourOpenedEmail`, se dispara desde Lista de interés → "Publicar y notificar") no llegaba a destino aunque `wp_mail()` reportaba éxito y quedaba logueado como enviado — mientras que confirmación/reprogramación/voucher sí llegan siempre con el mismo mecanismo (mismo `BaseEmail::send()`, mismo GoSMTP). Se descartó el SMTP como causa (confirmado que GoSMTP entrega bien para los demás tipos) — la sospecha es el patrón de contenido del asunto (emoji + "ya está disponible" + "completá tu pago" en la misma línea es justo el patrón que Gmail suele filtrar como spam en plantillas sin historial de envío). Se sacó el emoji y el pedido de pago del asunto (`includes/emails/class-email-dispatcher.php::TourOpenedEmail::get_subject()`) — **falta confirmar en la próxima sesión** que ahora sí llega, usando la herramienta de abajo.
- **Nueva página admin "✉️ Probar emails"** (`includes/admin/class-email-test-page.php`, menú TourFlow → Probar emails) — manda cualquiera de los 7 emails del sistema (confirmación, recordatorio, reseña, cancelación, tour disponible, link de pago, reprogramación) a una dirección elegida, con datos de reserva ficticios (`TEST-0001`), sin crear una reserva real ni esperar a que el flujo real dispare ese paso puntual. Pensada justamente para probar el fix de arriba y cualquier problema de entregabilidad futuro sin ensuciar la tabla de reservas.
- **Bug de caché encontrado y corregido**: la invalidación de transients de tours (`amir_tour_{id}_{lang}`, `amir_tours_list_{lang}`) solo limpiaba `es`/`en` a mano en 3 lugares (`TourPostType::sync_to_db()`, `ToursController::sync_all_from_cpt()`, `SettingsPage`) — con multi-idioma real ya soportado (Tarea 20), un tour editado quedaba con datos viejos hasta 10 minutos para cualquier idioma 3+ que el operador hubiera activado (it/fr/pt). Corregido iterando `Languages::active()` en los 3 lugares.
- **Calendario admin**: quedó repuesto al menú visible (`amir-calendar`) con su propia barra de navegación fullscreen (`CalendarPage::nav_links()`) — antes ocultaba el menú de WP sin dar ninguna forma de volver al resto del plugin.
- **Roadmap**: se agregó Meta Pixel + Google Ads/Analytics + descuento vía URL para partners como ítem nuevo sin priorizar (CONTRIBUTING.md § 5.4) — hoy no existe nada de tracking de píxeles, y el sistema de partners (`?ref=TOKEN`, `PartnerTracker`) ya trackea atribución/comisión pero no da descuento al cliente final, son cosas separadas a integrar.
- Antes de dar por buena cualquier corrección "en teoría", repetir el patrón que volvió a funcionar esta sesión (dos veces): comparar contra una pantalla admin que use el mismo query SQL o el mismo mecanismo de envío para descartar sin desplegar nada — así se confirmó primero el bug de `sync_to_db()` (comparando `WishlistController::get_upcoming()` contra `WishlistPage::render()`) y después que el email de wishlist SÍ usa el mismo `wp_mail()`/GoSMTP que el resto (revisando la reserva real en Amir Booking → Reservas antes de tocar código).

## Reglas críticas — leer antes de tocar código

1. **`react-src/` es la fuente del widget de reservas.** Nunca edites `assets/js/booking-widget.js` ni `assets/css/booking-widget.css` directo — son artefactos de build (`cd react-src && npm install && npm run build`). `admin.js` es la excepción: JS plano, se edita directo.
2. **No hay staging con despliegue automático.** El flujo real para probar cambios: armar ZIP del plugin (con `vendor/` generado vía `composer install --no-dev --optimize-autoloader`, no se versiona en git) → subir a `amiradventours.com/sandbox/` vía Plugins → Subir → probar ahí. Los datos viven en tablas `wp_N_amir_*`, reemplazar el plugin no los borra.
3. **Subí `AMIR_VERSION` en `amir-booking.php`** en cualquier cambio a `assets/js/*` o `assets/css/*` — si no, el navegador sirve la versión cacheada vieja.
4. **Cambios de esquema de DB**: agregar a `Installer::create_tables()` (instalaciones nuevas) *y* a `Installer::maybe_update()` con `ALTER TABLE` explícito (instalaciones existentes) — dbDelta solo no alcanza. Subir `AMIR_DB_VERSION`.
5. **Endpoints públicos que devuelven datos de un cliente**: exigir `access_token` (fuerte) o `email` (débil, compatibilidad) vía `BookingManager::authorize_public_access()`. Nunca confiar solo en `booking_ref` — es secuencial y adivinable.
6. **Pasarelas de pago**: todo pasa por `PaymentGatewayInterface` (`includes/payments/`). Para sumar una nueva, implementar la interfaz y registrarla en `PaymentGatewayFactory::make()` — el controlador no se toca.
7. **PHP 8.1 mínimo real** (lo exige `endroid/qr-code`, no es capricho). Todas las queries con `$wpdb->prepare()`. Páginas de admin: nonce + `current_user_can()` sin excepción.
8. No hay PHP instalado en el host directo, pero sí Docker Desktop — usar `docker run --rm -v "$PWD":/app -w /app php:8.1-cli ...` y `composer:2` de la misma forma para lint/tests/build. Correr `vendor/bin/phpunit` (47 tests en `tests/unit/`) antes de armar un ZIP. Si Docker no responde, `open -a Docker` y esperar ~15-30s a que el daemon levante. Node/npm sí están instalados directo en el host (no hace falta Docker para `cd react-src && npm install && npm run build`).
9. **Siempre subir la versión** (`AMIR_VERSION` + el número en el docblock) en cada ZIP que se genera, aunque el cambio sea solo PHP — así el cliente puede versionar qué probó.

## Qué ya está hecho (Fase 1 + Fase 2 parcial)

- Seguridad: `access_token` reemplaza `booking_ref` como credencial pública, `confirm_payment` fail-closed y con verificación de que el `payment_intent_id` pertenece a la reserva (bug real encontrado por `/security-review`), reembolsos conectados de verdad contra Stripe, rate limiting.
- `PaymentGatewayInterface` + `StripeGateway` + log de eventos de pago (`wp_amir_payment_events` + pantalla admin).
- Cupones (%, monto fijo, por fecha) — backend y frontend completos.
- `react-src/` recuperado y reconciliado con el bundle (ver CONTRIBUTING.md § 2 para detalle).
- Mejoras mobile (touch targets, font-size de inputs) — hechas y portadas a la fuente.
- Lista de interés ("avísame cuando abra") para tours en borrador — v2 con **reserva real** (`amir_bookings` estados `wishlist`/`awaiting_payment`), precio calculado, y link de pago real al abrir el tour (`POST /bookings/{ref}/init-payment` + `PayBooking.jsx` montado en `[amir_verify_booking]`). Endpoints en `class-wishlist-controller.php`, shortcode `[amir_wishlist]`, admin en **Amir Booking → Lista de interés**. Ver CONTRIBUTING.md § 5.1.
- Moneda configurable (MXN/ARS/USD/EUR + cualquier ISO) en Configuración — reemplaza el supuesto histórico de que todo es MXN. Helper `AmirBooking\Core\Currency`.
- Rebrand a "TourFlow" (solo nombre visible, namespace/DB prefix internos sin tocar).
- Bug crítico corregido: `setGateway` faltaba en las props del widget de reservas — rompía **toda** confirmación de reserva exitosa con "h is not a function" (preexistente, no introducido en esta rama; encontrado probando el flujo de pago de punta a punta por primera vez).
- `MercadoPagoGateway` (Checkout Pro) — implementa `PaymentGatewayInterface`, reutiliza el webhook y el paso de pago genéricos que ya existían. Ver CONTRIBUTING.md § 5.2. No probado en vivo (falta cargar credenciales de test reales).
- Bug de sincronización de tours (`content_i18n` sin `DEFAULT` válido para MySQL/MariaDB) — ver "Estado al cierre" arriba, detalle completo en CONTRIBUTING.md § 6 (Convenciones) y en el historial de commits de esta rama.
- Rebrand de textos visibles: "Amir Booking" → "TourFlow" en avisos de admin, ayudas de página y asuntos de email (los `error_log()` internos quedaron igual, no son user-facing).
- Versión del plugin visible en el Dashboard (`TourFlow v2.1.0`) y en Configuración de Red — para no perder de vista qué versión corre en el sandbox vs. local (fue justamente la confusión que alargó el diagnóstico del bug de arriba).
- Calendario (`Amir Booking → Calendario`, pantalla fullscreen) corregido: no tenía forma de volver al resto del plugin (ocultaba el menú de WP sin dar nada a cambio) — ahora tiene su propia barra de navegación.
- JSON-LD `TouristTrip` (`Core\StructuredData`, con tests) en `templates/single-amir_tour.php` — reemplaza el schema básico anterior, arma `image[]`/`duration`/`inLanguage`/`itinerary`+`geo`/`offers` a partir de los datos que ya carga cada tour.
- Meta Pixel + Google Ads/GA4 (`Core\Marketing`, Configuración → Marketing) + descuento vía URL para partners (`?coupon=CODE` precarga el campo de cupón del checkout). Ver CONTRIBUTING.md § 5.3 para el detalle completo — incluye qué eventos dispara cada paso del embudo y qué quedó fuera de alcance (`PayBooking.jsx` todavía no dispara `Purchase`).
- Multi-idioma real (N idiomas más allá de es/en) — `Core\Languages` + `content_i18n`, con traducciones base cargadas (it_IT, fr_FR, pt_PT). Trabajo de sesiones previas, recién commiteado en esta.
- Personalización visual del widget (`Core\WidgetTheme`, Configuración → 🎨 Widget de reserva) — color, tipografía, radio de esquinas, sin tocar `widget.css` a mano. También de sesiones previas, recién commiteado.
- Tours que no admiten niños/bebés (ej. solo para adultos) — columnas `allow_children`/`allow_babies`/`min_age_child` en `amir_tours`, checkboxes en el editor del tour, validación server-side en `BookingManager` (no confía solo en que el frontend oculte el contador). Ver CONTRIBUTING.md § 5.4.

## Qué sigue — orden de prioridad acordado con el cliente

Wishlist y Mercado Pago (lo más urgente) ya están hechos. Sigue: **Plantillas + Colores + Multi-idioma**, después el resto, y Redsys/GDPR al final (así lo pidió el cliente explícitamente — no reordenar sin confirmar).

| # | Tarea | Estado / notas |
|---|-------|-----------------|
| 14 | `MercadoPagoGateway` (Checkout Pro) | ✅ Hecho — ver CONTRIBUTING.md § 5.2. **Falta probarlo en vivo**: necesita credenciales de test reales de una cuenta de Mercado Pago cargadas en Configuración → Mercado Pago. Argentina: activar Mercado Pago como "Pasarela de pago activa", Stripe no liquida bien en ARS |
| 17 | Lista de interés ("avísame cuando abra") | ✅ Hecho — v2 con reserva real y link de pago. Detalle en CONTRIBUTING.md § 5.1. No confundir con lista de espera para tours llenos ya publicados — eso no se construyó, fuera de alcance por decisión del cliente |
| **19** | **Dos plantillas de tour + colores de reserva configurables** | ⏳ **Parcial** — los colores/tipografía/radio ya son configurables (`Core\WidgetTheme`, Configuración → 🎨 Widget de reserva). Falta la segunda plantilla en sí: hoy solo existe `templates/single-amir_tour.php`, sin selector en Configuración |
| **20** | **Multi-idioma más allá de ES/EN** | ✅ Hecho — `Core\Languages` + `content_i18n`, con it_IT/fr_FR/pt_PT ya cargados (`languages/`). Trabajo de sesiones previas, recién commiteado |
| 15 | "Cargar reserva + enviar link de pago" desde el admin | ⏳ Parcial — la infraestructura ya existe (`init-payment` + `PayBooking.jsx`, misma que usa la lista de interés), falta la UI de admin para cargar la reserva directo en `awaiting_payment` sin pasar por wishlist |
| 16 | Checkbox de aceptación de términos | El checkbox ya existe en el widget (`policyAccepted`) — falta validación server-side + texto configurable |
| 18 | Add-ons en checkout (alquiler de equipo, foto, etc.) | Tabla + precios backend, más UI nueva en `BookingWidget.jsx` |
| 22 | Meta Pixel + Google Ads/Analytics + descuento vía URL para partners | ✅ Hecho en v2.0.1 — ver CONTRIBUTING.md § 5.3. Falta probarlo en vivo (cargar un Pixel/GA4 de prueba y confirmar que los eventos llegan) y falta `PayBooking.jsx` (pago de reserva ya cargada) para `Purchase` |
| 23 | Tours que no admiten niños/bebés | ✅ Hecho en v2.1.0 — ver CONTRIBUTING.md § 5.4. Falta probarlo en vivo: crear/editar un tour marcándolo solo-adultos y confirmar que el widget oculta los contadores |
| 24 | Plantillas de email editables + contenido extra por tour | ⏳ Pedido del cliente, sin priorizar — ver CONTRIBUTING.md § 5.5. Ojo: confirmar primero que los emails ya salen completos y bien traducidos en el idioma del cliente (el cliente reportó dudas sobre esto) antes de sumar más superficie editable encima |
| — | Higiene: campos de reembolso/cobro con nombre heredado de Stripe (`stripe_charge_id`, hook `amir_process_stripe_refund`) | Funciona para cualquier pasarela hoy (confirmado con Mercado Pago), es solo un tema de claridad de nombres — ver CONTRIBUTING.md § 5.1 |
| 21 | GDPR + evaluar Redsys/pasarela europea | **Último**, por decisión explícita del cliente — backlog, sin diseñar |

**Horizonte más lejano** (de la auditoría original, no arrancado): grids nativos en Elementor sin depender de Elementor Pro, bloques nativos de Gutenberg, y eventualmente independencia de WordPress (API ya es REST-first, lo que más acopla hoy es `$wpdb` directo en las clases de dominio y `get_option`/`update_option` para configuración).

## Dudas de producto sin resolver

- ¿Multisite real para varios operadores (plataforma SaaS) o queda como plugin independiente por operador? El `LicenseManager` (stub) deja la puerta abierta sin comprometerse.
- Un cupón que cubra el 100% del total deja el cobro en $0 — Stripe no puede procesar eso. Pendiente decidir si vale un flujo de "reserva gratuita sin pasarela".
