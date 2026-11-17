# Correcciones al núcleo de TourFlow motivadas por el Bot Connector

Para un agente que trabaja sobre el núcleo (`includes/`, plugin `amir-booking`). Origen: primera prueba real del satélite `tourflow-bot-connector` 0.2.0 contra `book.caliafarm.com` (2026-11-05, reserva de prueba `CF-2026-00004`). Leer antes `CLAUDE.md` (reglas críticas, sobre todo 3, 4, 7 y 9).

**Reglas de este trabajo**
- Compatibilidad hacia atrás **obligatoria**: los frontends headless en producción (`caliafarm-web`, `amiradventours-web`, `visitsicilyexperiences.com`) consumen estas respuestas. Solo se **agregan** campos; nunca se quitan ni se renombran los existentes.
- Pausa de funcionalidad: hacer solo la **Fase 1**. La Fase 2 queda documentada, no construida, salvo que el cliente lo pida.
- Subir `AMIR_VERSION` y `AMIR_DB_VERSION` (si hay esquema), correr PHPUnit (161 tests en verde hoy) y armar ZIPs según `CLAUDE.md`. Documentar en `CONTRIBUTING.md` como § 16.114.
- El satélite ya cubre los puntos 2 y 3 por su cuenta desde la 0.2.1; el núcleo debe corregir la raíz para que cualquier cliente de la API (no solo el bot) se beneficie, y después el satélite puede dejar de compensar.

---

## Fase 1 — hacer

### 1. Nombres de moneda en las respuestas de cotización
**Síntoma:** `POST /amir/v1/bookings/quote` devuelve `total_mxn`, `deposit_mxn`, `remaining_mxn`, `unit_mxn`, `discount_mxn`, `addons_mxn` aunque el sitio cobre en EUR (Caliafarm). Un cliente de la API lee "MXN" y muestra la moneda equivocada.
**Dónde:** `PriceQuote::to_array()` en `includes/core/class-pricing-engine.php` (~línea 561). Revisar también `BookingResult` (`includes/core/class-booking-manager.php` ~1968) y las respuestas de `BookingController::create_booking()`.
**Corrección:** mantener los campos `*_mxn` tal cual y **agregar** un campo `currency` (usar `AmirBooking\Core\Currency::code()`) y alias neutros: `total`, `deposit`, `remaining`, `discount`, `addons`, y `unit` dentro de cada ítem de `breakdown`. No tocar el significado de `total_mxn` (reportes, voucher y liquidaciones dependen de él).
**Verificación:** test en `PricingEngineTest` que confirme que los alias son iguales a los `*_mxn` y que `currency` sigue la opción configurada.

### 2. La cotización no avisa si la fecha no tiene cupo
**Síntoma:** `POST /amir/v1/bookings/quote` para el tour 1 el 2026-12-20 devolvió `valid: true`, 300; pero `GET /amir/v1/availability/day` para esa fecha dice `available: false` ("Fecha no disponible"). Solo `create_pending()` lo rechaza, tarde.
**Dónde:** `BookingController::get_quote()` en `includes/api/class-booking-controller.php` (~línea 636) y/o `PricingEngine::quote()`.
**Corrección:** que la respuesta incluya `available` (bool) y, si es false, `availability_reason` (el mismo texto que da `AvailabilityEngine`). **No** convertirlo en error ni cambiar `valid`: el widget recotiza en vivo y hoy depende de que cotice siempre. Reusar `AvailabilityEngine`, sin lógica duplicada. Respetar `schedule_id` si viene (0 = cualquier horario); tener en cuenta `fixed_date`, `request_only` y `custom_quote` (para esos tipos `available` no aplica: devolver `null`).
**Verificación:** tests con fecha sin cupo, con cupo, con horario puntual y con tour de fecha fija.

### 3. La reserva no informa cuánto se cobra ahora
**Síntoma:** `POST /amir/v1/bookings` y `create_pending()` informan `total_mxn` (300), pero el tour 1 tiene depósito del 20%: lo que se cobra al pagar es 60. Un cliente de la API puede decirle al usuario que paga 300.
**Dónde:** respuesta de `BookingController::create_booking()` (ambas ramas: con y sin `requires_payment`). `BookingResult::charge_mxn` ya existe y tiene el valor correcto.
**Corrección:** agregar `charge_now` (= `charge_mxn`) y `balance_due` (= `total_mxn - charge_mxn`) con `currency`. Mismo agregado en `GET /bookings/{ref}` (`format_booking_public`): si hay depósito sin saldo pagado, exponer el saldo pendiente.
**Verificación:** test de reserva con y sin depósito; con depósito `charge_now < total`.

### 4. Valor `bot` en `booking_source`
**Síntoma:** las reservas creadas por el bot quedan como `direct` y no se pueden separar en Reportes ni medir conversión.
**Dónde:** ENUM de `booking_source` en `Installer::create_tables()` (`includes/core/class-installer.php` ~línea 407) y su verificador de ENUM (~937-981, `ensure_booking_status_enum()`); `BookingManager::resolve_source()`.
**Corrección:** agregar `'bot'` al ENUM (esquema nuevo **y** `maybe_update()` con `ALTER TABLE`, subir `AMIR_DB_VERSION`; aprovechar el mecanismo de autocorrección de ENUM que ya existe) y aceptarlo en `resolve_source()` sin romper la lógica de partners. Mostrar un badge en Reservas y un filtro en Reportes/CSV. Luego el satélite pasará `source => 'bot'`.
**Verificación:** instalación limpia y actualización desde la versión anterior (MariaDB y MySQL 8), test de `resolve_source('bot')`.

### 5. Límite de reservas pendientes sin pagar
**Riesgo:** una integración abierta (bot público) puede retener cupo creando reservas `pending` sin pagar; solo existe el throttle general por IP.
**Dónde:** `BookingManager::create_pending()`.
**Corrección:** tope configurable de reservas `pending` simultáneas por `customer_email` (default razonable, p. ej. 3; opción en Configuración → General) con un mensaje claro, es/en. Contar solo las que aún retienen cupo (no vencidas, ver `PendingExpiry`). No aplicar a `create_manual()`.
**Verificación:** test que la 4.ª reserva pendiente del mismo email se rechaza y que una vencida no cuenta.

---

## Fase 2 — documentar, no construir

6. **`permalink` público de habitaciones** en `GET /flow/v1/rooms` (hoy el link cae al archivo general `/rooms/`).
7. **Idioma server-side** en `GET /flow/v1/rooms` y `GET /flow/v1/addons/global`: aceptar `lang` y devolver `name`/`description` resueltos, además de los `*_es`/`*_en` actuales.
8. **Reservar habitaciones y productos digitales por API pública** para el bot (hoy usan `RoomBookingManager` y el carrito, sin endpoint equivalente a `POST /bookings`). Es feature nueva: diseñar con el cliente antes.

---

## Después del núcleo

- Satélite `tourflow-bot-connector`: pasar `source => 'bot'`, y simplificar `normalize_quote()` para usar los campos nuevos del núcleo (dejando fallback para núcleos viejos). Subir versión.
- Actualizar `GUIA-BOT-CONNECTOR-TOURFLOW.md` (§ 3 y § 10) con los campos nuevos.
- Probar de nuevo en el sandbox: `/quote` con fecha sin cupo, `/book` con depósito, y que el widget clásico y los flujos Discovery/Explore sigan idénticos.

## Entregable esperado

PR con: cambios en `includes/`, tests nuevos en `tests/unit/`, `AMIR_VERSION`/`AMIR_DB_VERSION` subidos, § 16.114 en `CONTRIBUTING.md`, y ZIPs de las 3 ediciones. Reportar qué quedó **sin probar en vivo**.
