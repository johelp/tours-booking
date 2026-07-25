# TourFlow (Amir Booking) — Contexto del proyecto

Este archivo se carga automáticamente en cada sesión de Claude Code sobre este repo. Mantiene lo esencial y durable; el detalle vive en `README.md` (arquitectura), `INSTALL.md` (despliegue) y `CONTRIBUTING.md` (estado y roadmap con más contexto). Si estás retomando esto en una ventana nueva: leé este archivo primero, después `CONTRIBUTING.md`, y corré `git log --oneline main..$(git branch --show-current)` para ver el historial completo con el razonamiento de cada cambio.

## Qué es esto

Plugin de WordPress para reservas de tours y experiencias, sin WooCommerce. Nombre de producto: **TourFlow**. Cliente/operador original: Amir Adventours Bacalar (son dos cosas distintas — TourFlow es el software, Amir Adventours el negocio que lo usa; ver README § Tablas de base de datos). Corre en un WordPress Multisite con un subsitio de pruebas en `amiradventours.com/sandbox/`.

Rama de trabajo activa: **`fase1/seguridad-base-codigo2`** (no mergeada a `main` — `main` tiene código viejo v1.0.19 con vulnerabilidades reales, no trabajar ahí).

## Estado al cierre de la última sesión

- **Plugin en v1.6.0** (`AMIR_VERSION`/`AMIR_DB_VERSION`). Último ZIP armado: `amir-booking-1.6.0.zip` (no queda guardado entre sesiones — el scratchpad es efímero; si hace falta, se rearma con `composer install --no-dev --optimize-autoloader` + `zip`, ver regla 2).
- **Probado en vivo en el sandbox**: moneda (MXN confirmado por el cliente), el flujo de reserva/pago con Stripe de punta a punta (encontró y arregló el bug de `setGateway`), Wishlist v1 simple. El Wishlist v2 (reserva real + link de pago) y Mercado Pago **se armaron en esta sesión y no se probaron en vivo todavía** — son lo primero a validar en la próxima sesión si el cliente no lo hizo antes.
- Antes de dar por buena cualquier corrección "en teoría", repetir el patrón que funcionó esta sesión: reproducir en vivo con el Browser tool contra `amiradventours.com/sandbox/` (login no hace falta para el flujo público de reserva) y, si algo fallla sin pista clara, pedir un HAR file en vez de asumir — así se encontró la causa real del bug de `setGateway` cuando las respuestas de red parecían normales.

## Reglas críticas — leer antes de tocar código

1. **`react-src/` es la fuente del widget de reservas.** Nunca edites `assets/js/booking-widget.js` ni `assets/css/booking-widget.css` directo — son artefactos de build (`cd react-src && npm install && npm run build`). `admin.js` es la excepción: JS plano, se edita directo.
2. **No hay staging con despliegue automático.** El flujo real para probar cambios: armar ZIP del plugin (con `vendor/` generado vía `composer install --no-dev --optimize-autoloader`, no se versiona en git) → subir a `amiradventours.com/sandbox/` vía Plugins → Subir → probar ahí. Los datos viven en tablas `wp_N_amir_*`, reemplazar el plugin no los borra.
3. **Subí `AMIR_VERSION` en `amir-booking.php`** en cualquier cambio a `assets/js/*` o `assets/css/*` — si no, el navegador sirve la versión cacheada vieja.
4. **Cambios de esquema de DB**: agregar a `Installer::create_tables()` (instalaciones nuevas) *y* a `Installer::maybe_update()` con `ALTER TABLE` explícito (instalaciones existentes) — dbDelta solo no alcanza. Subir `AMIR_DB_VERSION`.
5. **Endpoints públicos que devuelven datos de un cliente**: exigir `access_token` (fuerte) o `email` (débil, compatibilidad) vía `BookingManager::authorize_public_access()`. Nunca confiar solo en `booking_ref` — es secuencial y adivinable.
6. **Pasarelas de pago**: todo pasa por `PaymentGatewayInterface` (`includes/payments/`). Para sumar una nueva, implementar la interfaz y registrarla en `PaymentGatewayFactory::make()` — el controlador no se toca.
7. **PHP 8.1 mínimo real** (lo exige `endroid/qr-code`, no es capricho). Todas las queries con `$wpdb->prepare()`. Páginas de admin: nonce + `current_user_can()` sin excepción.
8. No hay PHP instalado en el host directo, pero sí Docker Desktop — usar `docker run --rm -v "$PWD":/app -w /app php:8.1-cli ...` y `composer:2` de la misma forma para lint/tests/build. Correr `vendor/bin/phpunit` (23 tests en `tests/unit/`) antes de armar un ZIP. Si Docker no responde, `open -a Docker` y esperar ~15-30s a que el daemon levante.
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

## Qué sigue — orden de prioridad acordado con el cliente

Wishlist y Mercado Pago (lo más urgente) ya están hechos. Sigue: **Plantillas + Colores + Multi-idioma**, después el resto, y Redsys/GDPR al final (así lo pidió el cliente explícitamente — no reordenar sin confirmar).

| # | Tarea | Estado / notas |
|---|-------|-----------------|
| 14 | `MercadoPagoGateway` (Checkout Pro) | ✅ Hecho — ver CONTRIBUTING.md § 5.2. **Falta probarlo en vivo**: necesita credenciales de test reales de una cuenta de Mercado Pago cargadas en Configuración → Mercado Pago. Argentina: activar Mercado Pago como "Pasarela de pago activa", Stripe no liquida bien en ARS |
| 17 | Lista de interés ("avísame cuando abra") | ✅ Hecho — v2 con reserva real y link de pago. Detalle en CONTRIBUTING.md § 5.1. No confundir con lista de espera para tours llenos ya publicados — eso no se construyó, fuera de alcance por decisión del cliente |
| **19** | **Dos plantillas de tour + colores de reserva configurables** | ⏳ **Próximo** — bajo riesgo: PHP normal (`templates/`) + variables CSS ya existentes (`--ab-teal`, etc.), no toca `react-src/` para los colores |
| **20** | **Multi-idioma más allá de ES/EN** | ⏳ **Próximo** — refactor real, no una tarea chica: hoy varios archivos PHP (emails, voucher, verificación) y el JS asumen literalmente 2 idiomas con `idioma==='en'?X:Y`, no una iteración sobre N idiomas. Agregar italiano/francés bien hecho implica cambiar ese patrón en toda la base, no solo sumar traducciones |
| 15 | "Cargar reserva + enviar link de pago" desde el admin | ⏳ Parcial — la infraestructura ya existe (`init-payment` + `PayBooking.jsx`, misma que usa la lista de interés), falta la UI de admin para cargar la reserva directo en `awaiting_payment` sin pasar por wishlist |
| 16 | Checkbox de aceptación de términos | El checkbox ya existe en el widget (`policyAccepted`) — falta validación server-side + texto configurable |
| 18 | Add-ons en checkout (alquiler de equipo, foto, etc.) | Tabla + precios backend, más UI nueva en `BookingWidget.jsx` |
| — | Higiene: campos de reembolso/cobro con nombre heredado de Stripe (`stripe_charge_id`, hook `amir_process_stripe_refund`) | Funciona para cualquier pasarela hoy (confirmado con Mercado Pago), es solo un tema de claridad de nombres — ver CONTRIBUTING.md § 5.1 |
| 21 | GDPR + evaluar Redsys/pasarela europea | **Último**, por decisión explícita del cliente — backlog, sin diseñar |

**Horizonte más lejano** (de la auditoría original, no arrancado): grids nativos en Elementor sin depender de Elementor Pro, bloques nativos de Gutenberg, y eventualmente independencia de WordPress (API ya es REST-first, lo que más acopla hoy es `$wpdb` directo en las clases de dominio y `get_option`/`update_option` para configuración).

## Dudas de producto sin resolver

- ¿Multisite real para varios operadores (plataforma SaaS) o queda como plugin independiente por operador? El `LicenseManager` (stub) deja la puerta abierta sin comprometerse.
- Un cupón que cubra el 100% del total deja el cobro en $0 — Stripe no puede procesar eso. Pendiente decidir si vale un flujo de "reserva gratuita sin pasarela".
