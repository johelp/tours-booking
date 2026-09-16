# Prompt de arranque — Servicios extra globales + productos digitales (Pro Max)

Copiar y pegar esto como primer mensaje de la sesión dedicada a esto.

---

Vamos a construir dos piezas relacionadas del paso de extras del flujo continuo (Pro Max): un **catálogo de servicios extra globales** (no atados a un tour o habitación puntual) y **productos digitales** como un tipo de ítem nuevo dentro de ese mismo paso. El cliente pidió explícitamente seguir con esto en la próxima sesión.

## Antes que nada: hay dos bugs críticos sin confirmar en vivo

La sesión anterior corrigió dos bugs graves que el cliente reportó probando v4.0.2 (selección múltiple de habitaciones imposible, selector de fecha duplicado) — quedaron en v4.0.5, **sin confirmar en vivo todavía**. Si el cliente no llegó a probar v4.0.5 antes de esta sesión, preguntar si conviene confirmar eso primero antes de seguir agregando superficie nueva encima.

## Leer antes de tocar código, en este orden

1. **`CLAUDE.md`** completo (contexto general + reglas críticas).
2. **`CONTRIBUTING.md § 16.11`** — spec del paso de extras ya cerrado: "un solo paso, dos tipos de ítem mezclados" (addons de catálogo + otros tours sugeridos).
3. **`CONTRIBUTING.md § 16.17`** — la idea de productos digitales tal como la planteó el cliente originalmente (2026-08-01), sin diseñar.
4. **`CONTRIBUTING.md § 16.18`**, punto "Extras — addons de catálogo" — documenta la limitación actual: cada addon en `amir_addons` sigue perteneciendo a un `tour_id`/`room_id` puntual, no existe catálogo global. El paso de extras hoy solo muestra addons de los tours que YA están en el carrito.
5. **`CONTRIBUTING.md § 16.22`**, sobre todo los dos bugs críticos recién corregidos — para no reabrir código que se acaba de tocar sin necesidad.

## Qué ya existe y no hay que rehacer

- **`amir_addons`** (`includes/core/class-installer.php`) — tabla con `tour_id`, `room_id`, `applies_to ENUM('tour','room','both')`, `pricing_type ENUM('per_unit','flat')`, `name_es/en`, `price_mxn`. **Hoy toda fila requiere `tour_id` O `room_id`** — no hay forma de crear un addon sin dueño (ni la UI ni el modelo de datos lo esperan realmente, aunque `applies_to` sugiera lo contrario).
- **`amir_booking_addons`** — tabla de unión ya genérica (`booking_id`, `addon_id`, `qty`, `unit_price_mxn` "congelado" al momento de reservar) — NO depende de tour vs. habitación, ya sirve para cualquier `item_type` de `amir_bookings`.
- **UI de administración de addons**: **solo existe para tours** — `TourPostType::meta_box_addons()` (`includes/cpt/class-tour-post-type.php`), guarda contra `tour_id`. **Las habitaciones no tienen ninguna pantalla para cargar addons todavía**, pese a que el esquema ya tiene `room_id`/`applies_to`.
- **`ExtrasStep`** (`react-src/src/DiscoveryFlow.jsx`) — paso ya construido: itera los tours en el carrito, pide `getTour(tour_id)` (que ya trae `.addons`) por cada uno, y muestra sus addons con selector de cantidad/checkbox (mismo patrón que `StepExtras` de `BookingWidget.jsx`). Debajo, un grid de "otros tours sugeridos" vía `catalog-window?context=suggestion`.
- **`CartController::create_item()`** (`includes/cart/class-cart-controller.php`) — el ítem `type:'tour'` acepta `addons: [{id, qty}]` y lo reenvía a `BookingManager::create_pending()`, que ya sabe validarlos y cobrarlos (`PricingEngine::apply_addons()`). **El ítem `type:'room'` NO acepta `addons` en absoluto** — ni el payload lo lee, ni `RoomBookingManager::create_pending()` sabe hacer nada con eso.
- **Cupones ya generalizados** (sesión anterior, § 16.21/16.22) como referencia de patrón: `amir_coupons.room_id` se sumó a `tour_id` sin romper nada existente — mismo tipo de generalización que probablemente haga falta acá.

## Qué pidió el cliente, en sus palabras

> "Podemos evaluar tener tours que solo se venden separados... [y] el agregador de servicios extra globales y productos digitales con lo que eso conlleva."

Dos piezas:

1. **Servicios extra globales** — un addon que no pertenece a NINGÚN tour ni habitación puntual (ej. transfer aeropuerto, seguro de viaje, alquiler de equipo) y puede ofrecerse en el paso de extras **sin importar qué tours/habitaciones haya en el carrito** — hoy es literalmente imposible: si el carrito no tiene ningún tour (ej. Flujo B con solo una habitación y sin haber sumado experiencias todavía), el paso de extras no tiene ningún addon que mostrar, aunque el operador quiera vender un transfer igual.
2. **Productos digitales** — un tipo de ítem nuevo dentro de ese mismo paso (ej. una guía en PDF de bajo costo) — entrega por link de descarga después del pago, no una experiencia física.

## Preguntas a cerrar con el cliente antes de escribir código

1. **¿Los addons globales son una tabla/columna nueva, o `amir_addons` con `tour_id`/`room_id` ambos `NULL`?** Reusar la tabla existente es más simple (ya tiene `applies_to`, `pricing_type`, i18n) — pero hoy ningún código espera esa fila sin dueño; hay que auditar cada `WHERE tour_id = %d` que la toca (`meta_box_addons`, `PricingEngine::apply_addons()`, `fetch_addons()` de `ToursController`) para confirmar que un addon global no se pierde en el camino.
2. **¿Dónde los administra el operador?** — Hoy la única UI de addons vive DENTRO del editor de cada tour (`meta_box_addons`, ligado a `tour_id`). Un catálogo global necesita una pantalla propia (ej. **TourFlow → 🎁 Extras globales**), separada de "los addons de este tour puntual". Las habitaciones tampoco tienen ninguna UI de addons hoy — ¿se construye de una vez (paridad con tours), o se pospone y esta ronda es solo "extras globales + digitales"?
3. **¿Cómo entra un addon global al carrito?** Hoy el carrito solo tiene dos tipos de ítem (`type:'tour'`, `type:'room'`) y los addons viajan DENTRO del ítem tour (`addons: [...]`). Recomendación a validar: un addon global probablemente necesita ser su **propio tipo de ítem** (`type:'addon'`) en el payload de `POST /flow/v1/cart/checkout` — evita atarlo artificialmente a un tour o habitación que puede no estar en el carrito. Implica: `CartController::create_item()` gana una rama nueva; y como `amir_booking_addons` solo necesita un `booking_id` al que colgarse, probablemente el más simple es adjuntarlo a la PRIMERA reserva pagable del carrito en vez de crear una `amir_bookings` propia solo para el addon — a validar si alcanza o si hace falta algo más prolijo.
4. **Productos digitales — ¿mismo mecanismo que un addon (`pricing_type` nuevo `'digital'` + un campo de archivo) o tabla propia?** Si reusa `amir_addons`, hace falta un campo de archivo adjunto (Media Library) y un flujo de entrega: ¿el link de descarga va en el email de confirmación / voucher general, o hace falta una pantalla de descarga propia con token (mismo criterio que `access_token` de una reserva — no un link público adivinable)?
5. **¿Un addon global aplica siempre en los dos pasos donde hoy aparecen addons/tours** (Flujo A paso 4, y el equivalente en Flujo B), **o el operador necesita elegir dónde se ofrece** cada uno (mismo criterio que se usó para tours con `hide_from_lists`/`hide_from_suggestions`, § 16.22 — puede que un addon global necesite algo análogo)?

## Al terminar

Actualizar `CONTRIBUTING.md § 16` (nueva subsección numerada, siguiendo el patrón de las anteriores) y `CLAUDE.md` con el estado de cierre. Armar ZIP nuevo (Pro/Lite/Pro Max, proceso ya establecido en § 16.16/16.9 — incluye el smoke test de autoload real) solo si el cliente lo pide explícitamente para esa ronda.
