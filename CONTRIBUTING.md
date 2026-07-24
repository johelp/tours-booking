# Guía para continuar el desarrollo — TourFlow (Amir Booking)

Este documento es el punto de partida si te sumás a este proyecto sin haber estado en las conversaciones previas. `README.md` documenta la arquitectura y `INSTALL.md` el despliegue — esto de acá es "qué está pasando ahora y cómo seguir".

## 1. Qué es esto

Plugin de WordPress para reservas de tours (Amir Adventours Bacalar), sin WooCommerce. Rebrandeado a **TourFlow** como nombre de producto (el operador turístico sigue siendo Amir Adventours — son dos cosas distintas, ver README § Tablas). Corre en un WordPress Multisite, en un subsitio de pruebas en `amiradventours.com/sandbox/`.

## 2. Lo más importante que tenés que saber antes de tocar nada

**El código fuente de React del widget de reservas no existe en este repositorio.** Solo están los bundles ya compilados (`assets/js/booking-widget.js`, `assets/js/admin.js`). Esto es un problema real, no un detalle:

- Cualquier cambio de fondo al widget de reservas (nuevo paso, nuevo campo, cambio de UI) requiere ese código fuente, que se perdió en algún momento del desarrollo anterior. Si en algún momento aparece (buscar `react-src/`, puede estar en la máquina de otra persona), avisar y agregarlo al repo antes de seguir construyendo sobre el bundle.
- **Excepción:** `admin.js` (el JS del panel de admin) resultó ser JS plano de 56 líneas, no un bundle — ese sí es editable directamente, sin problema.
- Ya hubo que parchear `booking-widget.js` a mano una vez (bug de foco perdido en mobile, ver commit `d97cc52`) leyendo el bundle minificado con `js-beautify` para ubicar el patrón exacto y editando el archivo original con cuidado, validando con `node --check`. Es factible pero laborioso — reservalo para bugs puntuales y localizables, no para features nuevas.
- El CSS del widget (`assets/css/booking-widget.css`) **sí** es código fuente normal, se edita como cualquier CSS.

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
| 4 | `MercadoPagoGateway` (Checkout Pro, activable por país: México/Argentina/Chile — Argentina solo Mercado Pago, Stripe no liquida bien en ARS) | ⏳ Pendiente |
| 5 | "Cargar reserva + enviar link de pago" desde el admin | ⏳ Pendiente |
| 6 | Checkbox de aceptación de términos | ⏳ Parcial — **el widget ya tiene el checkbox** (`ab-policy-check` en el CSS, campo `policyAccepted` en el bundle), falta la validación server-side y el texto configurable de la política |
| 7 | Lista de espera | ⏳ Pendiente |
| 8 | Add-ons en checkout (alquiler de equipo, foto, etc.) | ⏳ Pendiente — backend solamente; la selección visual en el widget necesita el punto 2 (react-src) |

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
