# TourFlow (Amir Booking) — Contexto del proyecto

Este archivo se carga automáticamente en cada sesión de Claude Code sobre este repo. Mantiene lo esencial y durable; el detalle vive en `README.md` (arquitectura), `INSTALL.md` (despliegue) y `CONTRIBUTING.md` (estado y roadmap con más contexto). Si estás retomando esto en una ventana nueva: leé este archivo primero, después `CONTRIBUTING.md`, y corré `git log --oneline main..$(git branch --show-current)` para ver el historial completo con el razonamiento de cada cambio.

## Qué es esto

Plugin de WordPress para reservas de tours y experiencias, sin WooCommerce. Nombre de producto: **TourFlow**. Cliente/operador original: Amir Adventours Bacalar (son dos cosas distintas — TourFlow es el software, Amir Adventours el negocio que lo usa; ver README § Tablas de base de datos). Corre en un WordPress Multisite con un subsitio de pruebas en `amiradventours.com/sandbox/`.

Rama de trabajo activa: **`fase1/seguridad-base-codigo2`** (no mergeada a `main` — `main` tiene código viejo v1.0.19 con vulnerabilidades reales, no trabajar ahí).

## Estado al cierre de la última sesión

- **Plugin en v2.2.1** (`AMIR_VERSION`), `AMIR_DB_VERSION` subió de `1.8.8` a `1.9.0` (schema nuevo del marketplace de proveedores, ver abajo). Ningún ZIP armado todavía esta sesión — rearmar con `composer install --no-dev --optimize-autoloader` + `zip` antes de subir (regla 2). **Ojo con el tamaño del ZIP**: `vendor/` pesa ~43MB — excluir también `dev-notes/` y `package-lock.json`.
- **Todo el trabajo de esta rama ya está commiteado y pusheado a `origin/fase1/seguridad-base-codigo2`.** *(confirmar con `git status`/`git log` al retomar — si esta sesión terminó sin pushear, hacerlo antes de seguir.)*
- **Feature grande de esta sesión: marketplace de proveedores externos, implementado de punta a punta** (spec cerrado en sesión previa, documentado en detalle en `CONTRIBUTING.md § 11` — leer ahí antes de tocar nada de esto). Resumen: un tour puede tener un `provider_id` asignado; sus reservas pagadas quedan en `pending_provider_approval` hasta que el proveedor aprueba/rechaza por un link de email sin login (con pantalla de confirmación intermedia — GET no ejecuta la acción, solo el POST, para no quedar expuesto a que un scanner de email la dispare por prefetch); recordatorio a 24h y auto-cancelación+reembolso a 48h configurables vía cron; ledger de liquidación (`amir_provider_payouts`) generado automáticamente al aprobar, con pantalla admin para marcarlo pagado. Pantallas nuevas: Amir Booking → 🤝 Proveedores (CRUD) y → 💸 Liquidación. Dropdown de proveedor + costo por persona/grupo en el editor de tour. Badge discreto (texto placeholder, sin nombrar al proveedor) en la ficha del tour. 14 tests nuevos, los 61 pasan.
- **NADA DE ESTO SE SUBIÓ AL SANDBOX TODAVÍA** — es lo primero para la próxima sesión: armar el ZIP y probar en vivo el flujo end-to-end completo (crear un proveedor de prueba, asignarlo a un tour con costo cargado, reservar y pagar de verdad, confirmar que queda en `pending_provider_approval` y no `confirmed`, aprobar/rechazar desde el link real del email, confirmar que el ledger se genera al aprobar). Ver la lista de "Pendiente" al final de `CONTRIBUTING.md § 11` para el detalle completo, incluido el texto del badge (placeholder, sin validar con el cliente).
- **Pendientes de sesiones anteriores que siguen sin confirmarse en vivo** (no se tocaron esta sesión, se centró en el marketplace):
  1. Borrar/cancelar la reserva de prueba `id=48` ("Test Admin Booking", `johelpastorino+admintest@gmail.com`, Kayak al Amanecer 2026-08-10) en el sandbox.
  2. Confirmar con "✉️ Probar emails" si el fix del asunto de `TourOpenedEmail` resolvió la entrega.
  3. Probar "📱 Modo campo" desde el celular real.
- Patrón que siguió sirviendo esta sesión: antes de escribir código de una feature grande con ambigüedades de implementación, preguntarle al cliente/usuario las decisiones de diseño que quedaron abiertas en el spec (esta vez vía `AskUserQuestion`, no asumir) — evitó tener que rehacer el flujo GET/POST de aprobación o el criterio de generación del ledger a mitad de camino.

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
- Marketplace de proveedores externos (tours de terceros que TourFlow revende con margen propio) — un tour puede tener `provider_id`; sus reservas pagadas quedan en `pending_provider_approval` hasta que el proveedor aprueba/rechaza por un link de email sin login, con recordatorio a 24h y auto-cancelación+reembolso a 48h configurables. Ledger de liquidación (`amir_provider_payouts`) y pantallas admin nuevas (🤝 Proveedores, 💸 Liquidación). Ver CONTRIBUTING.md § 11 para el detalle completo — **no probado en vivo todavía**.

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
| 25 | Marketplace de proveedores externos (tours de terceros) | ✅ Hecho en v2.2.0/2.2.1 — ver CONTRIBUTING.md § 11 para el detalle completo. **Falta probarlo en vivo**: no se subió al sandbox todavía, y el texto del badge en la ficha del tour es un placeholder sin validar con el cliente |
| 21 | GDPR + evaluar Redsys/pasarela europea | **Último**, por decisión explícita del cliente — backlog, sin diseñar |

**Horizonte más lejano** (de la auditoría original, no arrancado): grids nativos en Elementor sin depender de Elementor Pro, bloques nativos de Gutenberg, y eventualmente independencia de WordPress (API ya es REST-first, lo que más acopla hoy es `$wpdb` directo en las clases de dominio y `get_option`/`update_option` para configuración).

## Dudas de producto sin resolver

- ¿Multisite real para varios operadores (plataforma SaaS) o queda como plugin independiente por operador? El `LicenseManager` (stub) deja la puerta abierta sin comprometerse.
- Un cupón que cubra el 100% del total deja el cobro en $0 — Stripe no puede procesar eso. Pendiente decidir si vale un flujo de "reserva gratuita sin pasarela".
