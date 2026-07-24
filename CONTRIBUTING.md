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
3. Corrigió dos bugs encontrados probando en vivo en el sandbox: el formulario de Configuración no guardaba nada (un `<form>` anidado dentro de otro, HTML inválido) y el widget perdía el foco en cada tecla en mobile.
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
| 4 | `MercadoPagoGateway` (Checkout Pro, activable por país: México/Argentina/Chile — Argentina solo Mercado Pago, Stripe no liquida bien en ARS) | ⏳ Pendiente — **el frontend ya está listo** (`StepPaymentMP` en `BookingWidget.jsx`), ver § 2 |
| 5 | "Cargar reserva + enviar link de pago" desde el admin | ⏳ Pendiente |
| 6 | Checkbox de aceptación de términos | ⏳ Parcial — **el widget ya tiene el checkbox** (`ab-policy-check`, campo `policyAccepted`), falta la validación server-side y el texto configurable de la política |
| 7 | Lista de espera | ⏳ Pendiente |
| 8 | Add-ons en checkout (alquiler de equipo, foto, etc.) | ⏳ Pendiente — ya no está bloqueado por falta de fuente (recuperada), pero requiere UI nueva en `BookingWidget.jsx` |
| 9 | Cupón: campo en el checkout | ✅ Hecho (backend y frontend) |
| 10 | Fix `lang_pref_hint` visible como texto crudo | ✅ Hecho |
| 11 | Mejoras mobile (touch targets, font-size inputs) | ✅ Hecho, portado a `react-src/` |
| 12 | Multi-idioma más allá de ES/EN | ⏳ Pendiente — requiere refactor real: hoy varios archivos PHP (emails, voucher, verificación) y el JS asumen literalmente 2 idiomas con `idioma==='en'?X:Y`, no una iteración sobre N idiomas. Agregar italiano/francés bien hecho es cambiar ese patrón, no solo sumar traducciones |
| 13 | Dos plantillas de detalle de tour + colores de reserva configurables | ⏳ Pendiente — bajo riesgo: `templates/single-amir_tour.php` es PHP normal (agregar una segunda plantilla + selector en Configuración), y los colores del widget ya son variables CSS (`--ab-teal`, etc.) — se pueden volcar desde una opción de Configuración sin tocar `react-src/` |
| 14 | GDPR (mercado europeo) + evaluar Redsys u otra pasarela europea | ⏳ Backlog, sin diseñar todavía |

Para agregar una pasarela nueva: implementar `PaymentGatewayInterface` (en `includes/payments/`), registrarla en `PaymentGatewayFactory::make()`. El controlador (`class-booking-controller.php`) no necesita cambios — ya está escrito contra la interfaz, no contra Stripe directamente.

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
