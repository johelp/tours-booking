# Circuito de proveedores externos (tours y habitaciones) — cómo funciona hoy, y el hueco encontrado

**Por qué este documento.** El cliente pidió, pensando en el sitio nuevo de Caliafarm (donde habitaciones y experiencias de proveedores externos van a ser un punto fuerte): (1) revisar que el circuito completo funcione perfecto, mejorarlo si se puede, y (2) un documento que explique qué pasa cuando alguien reserva, por ejemplo, una experiencia propia de Caliafarm **+** una habitación de un proveedor externo en el mismo carrito — quién cobra, qué recibe el proveedor, cómo confirma.

**Resultado de la revisión**: el circuito de **tours** de proveedores externos ya estaba completo y bien construido — cobro, aviso al proveedor, aprobación/rechazo con link propio, vencimiento automático con reembolso, liquidación. El de **habitaciones** tenía el campo "Proveedor externo" en el editor desde el día uno, pero **nunca se conectó al resto del mecanismo** — una habitación de proveedor se cobraba y confirmaba igual que una propia, sin que el proveedor se enterara. Quedaba documentado en el propio código (`includes/rooms/class-room-post-type.php`): *"no evaluado todavía si el flujo de aprobación aplica igual a habitaciones"*.

> **✅ Estado: corregido en v5.13.7-v5.13.9 (2026-10), verificado y empaquetado.** La sección 3 queda como registro de lo que estaba roto y por qué (con evidencia archivo:línea); la sección 4 documenta el circuito de aprobación en sí (v5.13.7); la sección 6 documenta dos rondas más: un segundo bug real encontrado al revisar el carrito mixto a pedido del cliente (v5.13.8-5.13.9), y la configuración nueva de cuándo el proveedor ve los datos del cliente. Docker volvió a responder — **`php -l` limpio en los 13 archivos tocados y 161 tests en verde (315 aserciones) corridos con el PHP 8.1 real de Docker** (no el fallback de MAMP de la verificación anterior), plugin activado sin fatales en un WordPress real (MariaDB) con la versión 5.13.9, y los 3 ZIPs de edición (`dist-zips/tourflow-v5.13.9-{lite,pro,pro_max}.zip`) armados. **Sigue pendiente** una prueba funcional en vivo del circuito nuevo (crear un proveedor, reservar una habitación suya con depósito, aprobar/rechazar desde el link, confirmar la fila de liquidación) — ver el checklist al final del documento, ítems que siguen sin marcar.

---

## 1. El circuito de un tour de proveedor externo (hoy, funcionando)

Ejemplo: un cliente reserva en `caliafarm.com` la experiencia **"Snorkel en Favignana"**, que Caliafarm no opera — la revende de un proveedor externo con margen propio.

### Cómo se configura (una vez, por el operador)

- **TourFlow → 🤝 Proveedores**: se da de alta el proveedor (nombre, email de contacto, teléfono) y queda `active`.
- En el tour, **Proveedor externo**: se asigna ese proveedor + **Modo de cobro**:
  - **Inmediato** (`provider_charge_mode = 'immediate'`): se cobra al cliente como cualquier tour, y recién al momento de confirmar el pago se bifurca a "esperando al proveedor".
  - **Al aprobar** (`'on_approval'`): no se cobra nada hasta que el proveedor confirma — nace directo en "esperando al proveedor", sin cargo.
- En la pestaña de precios, además del precio de venta, hay un campo **costo del proveedor** (`amir_prices.provider_cost_mxn`) por cada franja de precio (adulto/niño/grupo) — es lo que Caliafarm le paga al proveedor. La diferencia con el precio de venta es el margen de Caliafarm, y se ve calculado en vivo en el editor.

### Qué pasa cuando alguien reserva (modo inmediato, el más común)

1. El cliente completa el checkout y paga el total — igual que cualquier otra reserva, sin pantallas distintas.
2. Al confirmarse el pago (`BookingManager::confirm()`), el sistema ve que el tour tiene un proveedor activo y, **en vez de marcar la reserva como `confirmed`**, la deja en **`pending_provider_approval`** — el dinero ya está cobrado.
3. Se disparan automáticamente 3 avisos (`includes/emails/class-email-dispatcher.php:23-25`):
   - **Al proveedor** (`ProviderNoticeEmail`): nombre del tour, fecha y horario, cantidad de personas, datos del cliente, solicitudes especiales, y dos botones — **✅ Confirmar** / **❌ Rechazar** — con un link propio y de un solo uso (`provider_response_token`, distinto del token del cliente). El email también dice cuántas horas tiene para responder.
   - **Al cliente** (`ProviderPendingNoticeEmail`): un aviso discreto de que su pago se procesó y la reserva se está confirmando — **sin nombrar al proveedor**.
   - **Al operador**: notificación en el Dashboard.
4. El proveedor entra al link (sin login) y confirma o rechaza:
   - **Confirma** → la reserva pasa a `confirmed` de verdad (email real de confirmación + voucher + Google Calendar al cliente), y se genera automáticamente una fila en el **ledger de liquidación** (`amir_provider_payouts`) por el costo del proveedor de esa reserva puntual (cantidad de personas × costo configurado). El operador ve esto en **TourFlow → 💸 Liquidación de proveedores** y marca como pagado cuando le transfiere al proveedor.
   - **Rechaza** (o no responde): la reserva se cancela con **reembolso del 100%** al cliente — no es su culpa que el proveedor no tuviera lugar.
5. Si el proveedor no responde dentro del plazo (24h recordatorio, 48h vencimiento — configurable, `amir_provider_reminder_hours`/`amir_provider_response_hours`), un cron horario lo hace por él: manda un recordatorio a las 24h y, si sigue sin respuesta, cancela+reembolsa automáticamente a las 48h — mismo resultado que un rechazo explícito.

### Modo "al aprobar" (cobro diferido)

Mismo circuito, pero invertido: la reserva nace en `pending_provider_approval` **sin cobrar nada**. Si el proveedor aprueba, recién ahí se le manda al cliente el link de pago real (mismo mecanismo que la Lista de interés). Si el cliente paga ese link, ahí sí se genera la fila de liquidación — nunca antes, porque hasta que no paga no hay nada que liquidarle al proveedor. Pensado para proveedores que prefieren comprometerse solo si ya saben que van a cobrar.

---

## 2. El ejemplo del cliente: experiencia propia + habitación externa, en el mismo carrito

Carrito de ejemplo: **"Snorkel en la bahía"** (experiencia propia de Caliafarm, $1.500) + **"Suite vista al mar"** (habitación de un proveedor externo, $2.000/noche × 2 noches = $4.000). Total del carrito: **$5.500**, un solo pago.

### Cómo funcionaba ANTES del fix (el hueco encontrado)

1. El cliente completa el checkout del carrito y paga **$5.500 de una sola vez** — un solo cargo en Stripe/Mercado Pago.
2. Al confirmarse el pago, **las dos líneas se confirman igual, sin distinción**:
   - La experiencia propia → `confirmed`. Correcto, es lo esperado.
   - La habitación del proveedor externo → **también `confirmed` directo**. Esto es el hueco: el sistema nunca llega a preguntarse si esa habitación tiene un proveedor asignado.
3. **El proveedor de la habitación no recibe ningún aviso.** No hay email, no hay link de aprobar/rechazar, no sabe que alguien reservó su habitación para esas fechas.
4. **No se genera ninguna fila de liquidación.** El ledger de proveedores (`amir_provider_payouts`) solo se llena desde la aprobación de un TOUR (`amir_provider_booking_approved`, que siempre hace `JOIN amir_tours` — nunca `JOIN flow_rooms`). Lo que hay que pagarle al proveedor de la habitación queda **fuera del sistema por completo** — a cargo de que alguien se acuerde de avisarle manualmente.
5. El voucher/email del carrito muestra la habitación como "confirmada" — técnicamente correcto en cuanto al cobro, pero el cliente recibe la confirmación sin que el proveedor real haya dicho que sí tiene la habitación disponible esas fechas.

**Riesgo real**: Caliafarm le cobra al cliente una habitación que el proveedor podría no tener disponible (sin overbooking real detectado, porque el proveedor nunca confirma nada acá), y no queda ningún registro de cuánto hay que pagarle.

### Cómo funciona AHORA (construido, ver § 4)

1. El cliente paga **$5.500** en un solo cargo, igual que hoy — el checkout no cambia.
2. Al confirmarse el pago:
   - La experiencia propia → `confirmed` (igual que hoy).
   - La habitación externa → **`pending_provider_approval`**, igual que ya pasa con un tour de proveedor.
3. El proveedor de la habitación recibe el mismo tipo de email que ya reciben los proveedores de tours: fechas de check-in/check-out, huéspedes, datos del cliente, y los botones Confirmar/Rechazar.
4. El cliente recibe el voucher del carrito mostrando la experiencia como confirmada y la habitación como "esperando confirmación del proveedor" — (`CartConfirmationEmail`). **Corrección (2026-10-08):** esto NO era así — ese email listaba solo lo `confirmed` y el ítem pendiente no aparecía en ningún lado; ver § 6.
5. El proveedor confirma → se genera la fila de liquidación con el costo de esa habitación. Rechaza o no responde en el plazo → se cancela esa línea puntual con reembolso de su parte del total (la experiencia propia sigue confirmada).

---

## 3. El hueco, con evidencia archivo:línea

| Pieza | Tours (proveedor externo) | Habitaciones (proveedor externo) |
|---|---|---|
| Columna en la tabla | `amir_tours.provider_id` + `provider_charge_mode` | `flow_rooms.provider_id` existe (`class-installer.php`, tabla `flow_rooms`) — **sin** `provider_charge_mode` ni costo del proveedor |
| Campo en el editor | Selector de proveedor + modo de cobro | Selector de proveedor nada más (`class-room-post-type.php:184-191`) — el propio hint del campo dice que el flujo de aprobación no está evaluado todavía |
| ¿La creación de la reserva respeta el proveedor? | Sí (`class-booking-manager.php:243-267`, bifurca a `pending_provider_approval` o cobro diferido) | **No** — `RoomBookingManager::create_pending()` inserta `'status' => 'pending'` **fijo** (`class-room-booking-manager.php:107`), nunca lee `$room->provider_id` pese a tenerlo ya cargado en memoria (`SELECT *` en la línea 40) |
| ¿`confirm()` bifurca al proveedor? | Sí, `tour_has_active_provider()` (`class-booking-manager.php:976`) | **No** — esa función solo mira `amir_tours.provider_id` vía `tour_id`; para una reserva de habitación `tour_id` es `NULL`, así que nunca entra a esa rama |
| Emails al proveedor | `ProviderNoticeEmail`, con recordatorio y aviso de vencimiento | No existen — y si una reserva de habitación llegara a `pending_provider_approval` por otra vía, `ProviderNoticeEmail::get_body_content()` fallaría: usa `$b->tour_name`/`$b->time_start`/`$this->pax_summary()`, campos que una reserva de habitación no tiene armados así |
| Ledger de liquidación | `amir_provider_payouts`, generado automático al aprobar (`class-plugin.php:275-326`, `JOIN amir_tours`) | No se genera nunca para habitaciones — el listener no conoce `flow_rooms` |
| Costo del proveedor (margen) | `amir_prices.provider_cost_mxn`, por franja de precio | No existe ningún campo equivalente en `flow_rooms` — ni siquiera hay dónde cargarlo |
| Vencimiento automático / recordatorio (cron) | Sí, `expire_provider_pending()`/`send_provider_reminders()` (`class-cron-manager.php`) — consulta genérica sobre `amir_bookings`, ya agnóstica de tipo | Funcionaría automáticamente una vez que una reserva de habitación pudiera llegar a `pending_provider_approval` — no hace falta tocar el cron |
| Voucher/email del carrito mixto | Distinguía `confirmed` de `pending_provider_approval` **ocultando** el pendiente (no lo mostraba) — corregido, ver § 6 | La consulta ya resolvía habitaciones — `CartConfirmationEmail`/`CartVoucherGenerator` ya hacen `LEFT JOIN flow_rooms` y ya filtran por status genérico, sin asumir que todo lo de una habitación esté confirmado |
| Pantalla de aprobación del proveedor (`[flow_provider_action]`) | Funciona contra cualquier `booking_ref` (`class-shortcodes.php:557-574`, no filtra por `item_type`) | **Ya funcionaría tal cual** para una reserva de habitación, una vez que esa reserva pudiera llegar a `pending_provider_approval` — el texto que muestra (ver `provider_action_confirm_screen()`) sí necesita ajustarse para mostrar fechas de estadía en vez de horario de tour |

**Conclusión de la revisión**: el hueco está muy acotado. La mayoría del circuito (cron, pantalla de aprobación del proveedor, emails/voucher del carrito) **ya es genérico** y no necesita tocarse — fue escrito pensando en `amir_bookings` en general, no en tours específicamente. Lo que falta es puntual: que `RoomBookingManager` y `BookingManager::confirm()` lean `flow_rooms.provider_id` igual que ya leen `amir_tours.provider_id`, un email de proveedor que hable de estadías en vez de horarios, y un campo de costo del proveedor por noche para poder liquidar.

---

## 4. Qué se construyó (v5.13.7, 2026-10)

Decisiones confirmadas por el cliente: **modo inmediato** (se cobra ya, igual que hoy — el checkout no cambia), **costo del proveedor fijo por noche** (no por temporada), **mismas horas de recordatorio/vencimiento que tours** (reusa `amir_provider_reminder_hours`/`amir_provider_response_hours`, sin configuración nueva). Mirror exacto del mecanismo de tours, con los cambios puntuales que hacía falta:

1. **3 columnas nuevas en `flow_rooms`** (`AMIR_DB_VERSION` → `1.43.0`): `provider_cost_per_night` (lo que se le paga al proveedor), `deposit_enabled`/`deposit_pct` — ver punto 7.
2. **`BookingManager::tour_has_active_provider()`** generalizada a **`booking_has_active_provider( object $booking )`** — ahora resuelve `$booking->room_id` → `flow_rooms.provider_id` cuando `tour_id` es `NULL`, además del camino de tours que ya tenía. Es el único punto donde `confirm()` decide bifurcar a `pending_provider_approval`, así que generalizar acá alcanzó — **no hizo falta tocar `RoomBookingManager::create_pending()`** para esto: en modo inmediato la reserva nace `pending` igual que siempre, y `confirm()` la desvía después de cobrar, exactamente como ya pasa con tours.
3. **El hallazgo más importante de la implementación**: `EmailDispatcher::get_booking_with_tour()` (la función que arma el booking con los datos del tour+proveedor para los 3 emails del circuito de aprobación) hace un **`JOIN` interno contra `amir_tours`** — para una reserva de habitación (`tour_id` NULL) esa consulta no devuelve NADA, así que aunque el punto 2 ya bifurcara bien, **los 3 emails de proveedor habrían fallado en silencio** (sin ningún error visible). Se agregó **`get_booking_with_provider_context()`**, que resuelve lo mismo contra `flow_rooms` cuando corresponde, y los 3 métodos (`send_provider_notice`, `send_provider_pending_notice`, `notify_admin_provider_pending`) pasaron a usarla.
4. **`ProviderNoticeEmail`** (el email al proveedor con los botones Aprobar/Rechazar): rama `item_type === 'room'` que muestra check-in/check-out/huéspedes en vez de fecha/horario/personas de tour.
5. **Ledger**: el listener de `amir_provider_booking_approved` en `class-plugin.php` ahora resuelve el proveedor y el costo por cualquiera de las dos rutas (`JOIN amir_tours` para tours, `JOIN flow_rooms` + `noches × provider_cost_per_night` para habitaciones) en vez de asumir siempre tour.
6. **Pantalla pública de aprobación** (`[flow_provider_action]`): muestra también la fecha de check-out cuando la reserva es de una habitación.
7. **Depósito parcial para habitaciones** (pedido del cliente en la misma revisión, no estaba en el alcance original): mismo mecanismo que ya tienen los tours (`deposit_enabled`/`deposit_pct`) — pensado sobre todo para que, en una habitación de proveedor externo, el huésped pague solo una parte por TourFlow y arregle el resto directo con el proveedor. `RoomBookingManager::create_pending()` ahora calcula `charge_mxn` (lo que se cobra ahora) por separado de `total_mxn` (el precio real), igual que tours. La pantalla de "Marcar saldo cobrado" del admin ya era genérica — no necesitó ningún cambio.
8. **Editor de habitaciones**: selector de costo del proveedor junto al selector de proveedor, y un bloque colapsable de depósito parcial — mismo patrón visual que ya usa el editor de tours.

### Archivos tocados

`includes/core/class-booking-manager.php`, `includes/core/class-installer.php`, `includes/core/class-plugin.php`, `includes/rooms/class-room-booking-manager.php`, `includes/rooms/class-room-post-type.php`, `includes/emails/class-email-dispatcher.php`, `includes/core/class-shortcodes.php`, `includes/cpt/class-tour-post-type.php` (un texto desactualizado nada más), `tests/unit/BookingManagerProviderApprovalTest.php` (test nuevo). `AMIR_VERSION` → `5.13.7`.

### Pendiente antes de dar esto por cerrado

- ~~`php -l` y `vendor/bin/phpunit`~~ — ✅ hecho: 161 tests en verde (315 aserciones), lint limpio en los 13 archivos tocados. Ver § 7.
- **Probar en el sandbox**: crear un proveedor, asignarlo a una habitación con costo por noche, reservarla, confirmar que el email le llega al proveedor con los datos correctos, aprobar desde el link, y confirmar que aparece la fila en TourFlow → 💸 Liquidación de proveedores. **Sigue sin hacerse** — el plugin ya está activo en el sandbox a v5.13.9 sin fatales, pero falta el recorrido funcional completo.
- **Probar el carrito mixto real** (el caso que pidió el cliente): una experiencia propia + una habitación de proveedor en el mismo checkout, un solo cobro, confirmar que el voucher general distingue "confirmado" de "esperando al proveedor" por ítem. **Sigue sin hacerse en vivo.**
- **Reimportar/revisar providers.php** — por las dudas, confirmar que `amir_providers` no necesita ningún cambio (no debería, es la misma tabla que ya usan los tours).

## 5. Fase 2 (idea, sin construir): automatizar el pago al proveedor con Stripe Connect

El cliente preguntó si la liquidación al proveedor se puede automatizar con split payments de Stripe, en vez del ledger manual ("marcar como pagado" a mano). Es viable y más simple que el split de MercadoPago que ya se evaluó (`PROMPT-MERCADOPAGO-SPLIT.md`):

- Cada proveedor conecta su propia cuenta de Stripe (onboarding "Express", unos minutos).
- Caliafarm sigue cobrando el total como hoy; en el momento en que el proveedor **aprueba** la reserva (el mismo evento que ya genera la fila en `amir_provider_payouts`), se podría disparar automáticamente una transferencia de Stripe a su cuenta por el monto del ledger — en vez de que el operador la marque "pagado" a mano.
- Mismo límite real que ya se encontró con MercadoPago: si hay que reembolsar después de que la transferencia ya se hizo, Stripe la revierte solo si el proveedor tiene saldo suficiente — no se puede forzar.
- Se construiría como **plugin satélite aparte** (mismo criterio que `mercadopago-split-tourflow/`), reusando el ledger que ya existe — el pago manual sigue funcionando para cualquier proveedor que no se conecte.

**Decisión del cliente (2026-10)**: dejarlo para después — seguir con el ledger manual por ahora. Documentado acá para no perderlo.

---

## 6. Carrito mixto: qué recibe el cliente, paso a paso (revisión 2026-10-08)

**Requisito del cliente**: si el carrito mezcla ítems propios con ítems de proveedores externos, se reservan **todos**; el cliente recibe el detalle completo **indicando cuál está pendiente de confirmación**, y después recibe la confirmación — o el aviso de que no pudo confirmarse.

Revisado siguiendo el código de punta a punta (checkout → pago → webhook → respuesta del proveedor → cron). **Verificación (2026-10-10)**: Docker seguía sin responder, así que se validó con el PHP de MAMP (`/Applications/MAMP/bin/php/php8.3.30` y `php8.5.2`): `php -l` limpio en los 5 archivos tocados y **161 tests en verde** (10 nuevos: `tests/unit/CartConfirmationEmailTest.php` renderiza el email con ítems de ejemplo — pendiente marcado, total cobrado, botones, descarga de extras — y `tests/unit/CartConfirmGroupTest.php` comprueba que `confirm_group()` solo toca lo pendiente y es idempotente). **Sigue sin probarse contra un WordPress real, una pasarela ni un cliente de correo.**

### Lo que se encontró

| # | Problema | Efecto para el cliente |
|---|---|---|
| 1 | `CartConfirmationEmail` listaba solo `status='confirmed'` | Pagaba el total (propio + proveedor), pero el email mostraba solo lo propio, con un total menor y el título "¡Tu reserva está confirmada!". El ítem pendiente solo tenía un aviso suelto (`ProviderPendingNoticeEmail`) sin nombre del servicio, fecha ni precio |
| 2 | Al aprobar el proveedor (o al pagar el link en modo "al aprobar"), la confirmación individual se suprimía por tener `cart_group_id` | Nunca llegaba el "ya está confirmada". **Esto afectaba incluso a una compra de UN solo tour de proveedor desde el sitio nuevo**, que siempre pasa por el endpoint de carrito |
| 3 | El webhook de la pasarela confirmaba **una sola** reserva del carrito (`get_row` por referencia de pago compartida) | Si el cliente cerraba la pestaña antes de volver al sitio, el carrito quedaba cobrado pero a medias: un ítem confirmado y el resto `pending` hasta que el cron los vencía. Tampoco salía el email del carrito |
| 4 | Un carrito donde TODO era de proveedor no mandaba el email del carrito | Solo recibía el aviso genérico por ítem |

### Lo que se corrigió

- **#1 y #4**: `CartConfirmationEmail` lista confirmados **y** pendientes; cada pendiente lleva la marca "⏳ Pendiente de confirmación" (y "no se cobra hasta que se confirme" si es modo "al aprobar"); asunto e introducción cambian según haya pendientes; el total es lo realmente cobrado; el PDF general solo se ofrece si hay algo confirmado; el link de descarga de un extra digital solo sale cuando su ítem dueño está confirmado. El aviso interino genérico se omite dentro de un carrito pagado (lo reemplaza este email).
- **#3**: nueva `CartController::confirm_group()`, usada por el endpoint `/confirm-payment` **y** por el webhook. Confirma todas las reservas del carrito y manda el email **una sola vez** (si nada cambió de estado en esa llamada, no reenvía).
- **#2**: corregido en la misma ronda (otra sesión, mismo criterio): si `provider_responded_at` está seteado la confirmación individual sí sale, aunque la reserva venga de un carrito (`EmailDispatcher::send_confirmation()` y `RoomConfirmationEmail::send_for()`).
- Rechazo o vencimiento: ya funcionaba — `CancellationEmail` no se suprime en carritos y el reembolso es del monto **de ese ítem** (`calculate_refund()` usa su propio `total_mxn`/depósito), el resto del carrito sigue confirmado.

### Limitaciones conocidas (sin resolver)

- Una carrera entre el webhook y el POST del cliente casi simultáneos podría mandar el email del carrito dos veces (cada `confirm()` es atómico por reserva, pero el envío no tiene lock a nivel carrito).
- Los **satélites de pago** (`redsys-for-tourflow`, `mercadopago-split-tourflow`) confirman con su propio handler y no pasan por `confirm_group()`: el mismo hueco del #3 puede seguir ahí para carritos pagados con esas pasarelas.
- Los **extras globales** se cuelgan de la primera reserva pagable del carrito: si esa fuera la del proveedor y se rechaza, el reembolso de ese ítem incluye los extras.
- Capacidad: la cuenta de cupo (`status IN ('pending','confirmed')`) no incluye `pending_provider_approval`; para un tour de proveedor el cupo real lo decide el proveedor, pero un ítem propio nunca está en ese estado.

### Probar antes de dar esto por cerrado

1. Carrito: 1 experiencia propia + 1 tour de proveedor (modo inmediato) → pagar con tarjeta de prueba → **un solo email** con ambos ítems, el del proveedor marcado como pendiente, y el total igual a lo cobrado.
2. Repetir cerrando la pestaña antes de volver al sitio (solo webhook) → los dos ítems deben quedar cargados (uno `confirmed`, otro `pending_provider_approval`) y salir el mismo email.
3. Aprobar desde el link del proveedor → llega la confirmación individual al cliente con el voucher.
4. Rechazar → llega la cancelación con reembolso del 100% **solo de ese ítem**; el otro sigue confirmado.
5. Repetir con el proveedor en modo "al aprobar": al crear no se cobra ese ítem; tras aprobar, el cliente recibe el link de pago; al pagarlo, recibe la confirmación.
6. Comprar un tour de proveedor **solo**, desde `caliafarm-web` → debe llegar el email de recibido y, tras aprobar, el de confirmado.

---

## 7. Segunda ronda (v5.13.8-5.13.9, 2026-10-09/10): datos del cliente al proveedor, depósito = comisión del operador, y dos bugs más de email

Pedido del cliente que disparó esta ronda: (1) que el proveedor reciba los datos del cliente (días, cantidad de personas) — configurable si los ve al pedirle la reserva o recién al confirmarla; (2) revisar **IMPORTANTE** que el carrito mixto reserve ambos ítems y el cliente reciba el detalle completo marcando el pendiente, con la confirmación o el rechazo después (cubierto en el código ya existente, auditado de nuevo acá); (3) tras confirmar que un cliente real va a usar activamente proveedores externos: el cliente propuso que, para simplificar y evitar errores, el huésped pague **solo el depósito** por TourFlow y arregle el resto **directo con el proveedor** (efectivo o tarjeta).

### Decisión de negocio confirmada (`AskUserQuestion`)

Pregunta abierta: si una reserva de proveedor se paga con depósito, ¿qué le corresponde liquidar al proveedor? **Respuesta del cliente: nada — el depósito cobrado por TourFlow ES la comisión completa del operador.** El proveedor cobra el resto directo del huésped (efectivo o tarjeta), así que no hay ningún saldo pendiente de liquidar por esa reserva puntual. Implementado como guard en `class-plugin.php`: si `booking->deposit_pct > 0`, el listener de `amir_provider_booking_approved` retorna antes de generar ninguna fila en `amir_provider_payouts`.

### Dos bugs reales más, mismo patrón que el hallazgo de § 4.3

Auditando **todos** los call sites de `EmailDispatcher::get_booking_with_tour()` (la consulta con `JOIN` interno contra `amir_tours`, que devuelve `NULL` para cualquier reserva de habitación) para confirmar que el "detalle completo" que pide el cliente realmente le llega, aparecieron dos más que no se habían tocado en la primera ronda:

- **`send_cancellation()`** — el aviso de cancelación + reembolso (rechazo del proveedor, vencimiento por el cron, o cancelación manual) nunca le llegaba al cliente si la reserva era de una habitación. Exactamente el escenario que preocupaba al cliente ("luego recibirá la confirmación... o no") — sin este fix, el "o no" pasaba en silencio, sin ningún rastro.
- **`notify_client_balance_paid()`** — el aviso de "tu saldo fue cobrado" (depósito parcial) tampoco le llegaba al cliente de una habitación.

Ambos corregidos pasando de `get_booking_with_tour()` a `get_booking_with_provider_context()` (la función ya generalizada en § 4.3). `send_payment_failed_notice()` se revisó también — ya tenía su propio fallback para habitaciones desde una sesión anterior, no necesitó cambios.

### Timing configurable de los datos del cliente (`amir_provider_reveal_client_at`)

Nueva opción (`TourFlow → 🤝 Proveedores → ⚙ Configuración del marketplace`, junto a las horas de recordatorio/vencimiento — que tampoco tenían UI propia hasta ahora, otro hueco encontrado de paso):

- **"Al solicitar"** (`'request'`, default): `ProviderNoticeEmail` muestra nombre/email/teléfono/solicitudes especiales del cliente en el mismo email donde se le pide aprobar — el comportamiento que ya existía.
- **"Al aprobar"** (`'approval'`): `ProviderNoticeEmail` oculta esos datos (nota en cursiva en su lugar) y, recién cuando el proveedor aprueba (`amir_provider_booking_approved`), se dispara una **nueva clase `ProviderClientDetailsEmail`** con los datos completos. Pensado para proveedores que el operador prefiere que decidan solo por fecha/cupo, sin ver quién es el cliente hasta comprometerse.

### Depósito parcial en habitaciones de proveedor — ya cubierto en § 4.7, con el guard del ledger de esta ronda

El mecanismo de depósito en habitaciones (editor, cálculo de `charge_mxn`) se construyó en v5.13.7 (§ 4.7); lo que se agregó en esta ronda es el guard de negocio de arriba (no liquidar nada al proveedor cuando hubo depósito).

### Usabilidad: nav del editor de tours en el Panel de gestión

Sin relación directa con proveedores, pero tocado en la misma ronda (pedido explícito del cliente: "revisa la pantalla de gestión de tours para que sea usable y entendible"). El editor de un tour en `/gestor/` renderiza ~9-11 metaboxes de wp-admin seguidos, en una sola página larga, sin ninguna forma de saltar entre secciones. Se agregó una barra de navegación pegajosa (`<nav class="tfp-tour-nav">`, `includes/panel/class-tours-section.php`) con un link por metabox (reusa `metabox_list()`, cada metabox gana un `id` que coincide) y un botón "💾 Guardar" duplicado arriba (vía `form="tfp-tour-form"`, sin JS) para no obligar a bajar hasta el final para guardar. CSS nuevo en `assets/css/panel.css`.

### Archivos tocados (esta ronda)

`includes/core/class-plugin.php` (guard de depósito=comisión), `includes/emails/class-email-dispatcher.php` (2 bugs de resolución + `amir_provider_reveal_client_at` + `ProviderClientDetailsEmail`), `includes/core/class-installer.php` (option nueva), `includes/admin/class-providers-page.php` (UI de configuración del marketplace), `includes/panel/class-tours-section.php` + `assets/css/panel.css` (nav del editor). `AMIR_VERSION` 5.13.7 → 5.13.8 → 5.13.9.

### Verificación de esta ronda

`php -l` limpio en los 13 archivos tocados, **161 tests en verde (315 aserciones)** corridos con Docker (PHP 8.1.34 real, no el fallback de MAMP de § 6), plugin sincronizado y activado en el sandbox local (MariaDB) sin fatales a la versión 5.13.9, opción `amir_provider_reveal_client_at` confirmada con su default correcto (`request`). 3 ZIPs de edición armados (`dist-zips/tourflow-v5.13.9-{lite,pro,pro_max}.zip`), verificados por versión/edición/inclusión de archivos. **Sin probar en vivo todavía**: ningún email de esta ronda (cancelación de habitación, saldo cobrado de habitación, `ProviderClientDetailsEmail`) pasó por un envío real; el guard de depósito=comisión no se probó contra una aprobación real de proveedor.
