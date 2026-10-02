# Prompt de arranque — Flujo "Explorar" (Pro Max), segundo flujo de reserva

Copiar y pegar esto como primer mensaje de la sesión dedicada a esto. Pedido explícito del cliente (2026-08-09), tras probar en vivo el flujo combinado actual y encontrarlo lento después de cada click: construir un **segundo flujo, aparte**, más ágil, con la dinámica de búsqueda/reserva de sitios tipo Booking.com — sin tocar el flujo combinado existente, que sigue vivo y se perfecciona por separado.

## Qué NO es esta sesión

No es un rediseño del flujo combinado (`DiscoveryFlow.jsx`, `[flow_discovery]`). Ese componente **no se toca** — el cliente fue explícito: "dejemos el flujo combinado mientras lo perfeccionamos". Esta sesión construye un flujo **nuevo y aparte**, un shortcode nuevo, que convive con el existente.

El bug concreto de lentitud que sí se corrigió antes de escribir este prompt (v5.5.1, no hace falta repetirlo): el paso de personas del flujo combinado y del widget clásico disparaban un request de cotización **por cada click** de +/- sin debounce, a veces resolviendo fuera de orden. Corregido con `useDebouncedValue` (`react-src/src/hooks.js`, nuevo) + un ref de request-id en `DiscoveryFlow.jsx::TourDetailStep` y `BookingWidget.jsx::BookingFlow`. Reusá `hooks.js` en el flujo nuevo en vez de reinventar el debounce — cualquier input de cantidad/fecha que dispare un fetch tiene que pasar por ahí.

## El problema real a resolver (más allá del bug puntual)

El cliente no solo pidió arreglar el bug — pidió repensar la dinámica completa: "óptimo vía mobile, óptimo vía PC, enfocado en conversión y usabilidad, evitar que el usuario tenga que colocar los mismos datos varias veces, tamaños/acceso de imágenes, agilidad." La causa de fondo del flujo combinado actual no es solo el bug de debounce — es la arquitectura de pasos (`step` gate uno detrás del otro, cada avance es una transición de pantalla completa). El flujo nuevo tiene que evitar eso desde el diseño, no parchearlo después.

## Qué ya existe y hay que reusar — no reconstruir nada de esto

**Backend, sin cambios necesarios salvo el ítem marcado abajo** (`flow/v1`, namespace `TourFlow\`):

- `GET /tours/catalog-window?from=&until=&lang=&context=list|suggestion` (`DiscoveryController`) — tours propios con `available_dates[]` dentro de un rango de fechas (tope 60 días de ventana). Es la base de la grilla de experiencias.
- `GET /rooms` (`RoomBookingController`) — catálogo completo de habitaciones activas, sin filtro server-side (capacidad/precio/amenities se filtran en el cliente, ver más abajo).
- `POST /rooms/availability-batch` — `{room_ids[], check_in, check_out}` → `{availability: {room_id: bool}}`. Construido esta sesión para bajar de N requests (uno por habitación) a 1 solo — el flujo nuevo tiene que usar este endpoint desde el día uno, nunca volver al patrón viejo de un request por ítem.
- `GET /addons/global?lang=` (`DiscoveryController`) — catálogo de extras sin tour/habitación dueño (transfers, seguros, productos digitales).
- `POST /cart/checkout` (`CartController`) — **esta es la pieza clave para "no pedir los mismos datos dos veces"**: recibe UN bloque de datos del cliente (`customer_name/email/phone`, `lang`, `policy_accepted`, `terms_accepted`) más un array `items[]` mezclando tipos:
  - `{type:'tour', tour_id, schedule_id, date, adults, children, babies, addons, coupon_code, special_requests}`
  - `{type:'room', room_id, check_in, check_out, guests, coupon_code, special_requests}`
  - `{type:'addon', addon_id, qty}`
  Crea una fila en `amir_bookings` por cada ítem `tour`/`room` (todas comparten un `cart_group_id`), adjunta los `addon` a la primera reserva cobrable, arma UN pago combinado. `POST /cart/{cart_group_id}/confirm-payment` y `GET /cart/{cart_group_id}/pdf` (voucher general) ya están construidos y probados. **No inventar un checkout nuevo — el flujo nuevo alimenta este mismo endpoint.**

**Único gap real de backend**: `ToursController::format_tour_summary()` (`includes/api/class-tours-controller.php`, usado por `catalog-window`/`featured`) no trae ningún campo de precio — el motor de precios (`PricingEngine`) hoy solo se consulta con fecha+personas ya elegidas (`bookings/quote`), no da un "desde $X" barato para una tarjeta. **Decisión ya cerrada con el cliente: sí queremos "desde $X" en las tarjetas** (es parte de lo que hace sentir la grilla como Booking.com) — hay que sumar un campo tipo `from_price_mxn` a `format_tour_summary()`, calculado como el mínimo `price_mxn` vigente con `person_type='adult'` (mismo criterio que ya usa `PricingEngine::find_price()`, no reinventar esa lógica de vigencia por fecha — mirarla antes de escribir la query nueva).

**Frontend, patrones a reusar (todos ya corregidos esta sesión — no los dupliques con una versión vieja)**:
- `TourCard`/`RoomCard` en `DiscoveryFlow.jsx` — ya usan `<img loading="lazy" decoding="async">` en vez de `background-image` (fix real de rendimiento, antes cargaban todas las fotos de la grilla de entrada). `CardSkeleton` — placeholder con pulso mientras carga, reemplaza la grilla vacía de antes.
- `Stepper` (contador ± circular, 40px de touch target) y `GalleryLightbox` (overlay liviano sobre la grilla, no un paso del flujo).
- El patrón de **container queries, no media queries**, para cualquier layout que cambie de 1 a 2+ columnas — `.df-wrap { container-type: inline-size }` + `@container (min-width:900px)`. Motivo real, no estético: este widget puede vivir embebido en un sidebar angosto de un tema en pantallas de escritorio anchas — un `@media` mide la pantalla, no el contenedor, y ya causó un bug real en producción (`CONTRIBUTING.md § 16.36`). El flujo nuevo tiene que nacer con esto bien desde el principio.
- Sin web fonts en todo el plugin — familia tipográfica de sistema (`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`) en todos lados. Mantenerlo así (cero costo de carga de fuentes es parte de la agilidad pedida).

**Ninguno de estos componentes está exportado hoy** — viven como funciones privadas dentro de `DiscoveryFlow.jsx`. Antes de escribir el flujo nuevo, extraerlos a `react-src/src/components/shared.jsx` (archivo nuevo) y que ambos flujos importen de ahí — si no, cualquier fix futuro a estos componentes hay que hacerlo dos veces.

## Decisiones ya cerradas con el cliente — no volver a preguntar

1. **Precio "desde $X" en las tarjetas de tour**: sí, ver el campo de backend arriba.
2. **Cómo se abre el detalle de un tour/habitación**: **panel deslizable** (bottom-sheet en mobile, panel lateral en desktop), la grilla y la barra de búsqueda siguen montadas detrás — nunca un reemplazo de pantalla completa como hace el flujo combinado hoy. Volver de un detalle tiene que ser instantáneo (sin re-fetch, sin perder posición de scroll).
3. **Dónde viven los extras/servicios adicionales**: siempre visibles dentro del panel de carrito/checkout — no hay un "paso de extras" separado, porque este flujo no tiene pasos fijos.
4. **Alcance de esta sesión**: el flujo completo de punta a punta — tours, habitaciones, extras, carrito, checkout — no una versión parcial. Fue una decisión explícita del cliente priorizar tener el diseño bien pensado antes de arrancar, no repartir esto en slices entre sesiones.

## Principio de diseño central — de esto depende que se sienta rápido de verdad

**Buscar una vez, filtrar en el cliente.** La causa real de que el flujo combinado se sienta lento no es solo el bug de debounce — es que cada interacción (cambiar personas, aplicar un filtro, tocar una tarjeta) dispara una ida y vuelta al servidor. El flujo nuevo tiene que invertir eso:

| Qué dispara | Endpoint | ¿Con qué frecuencia? |
|---|---|---|
| Cambiar fechas/huéspedes en la barra de búsqueda (persistente, nunca un paso que se abandona) | `catalog-window` + `rooms` (cachear tras el primer fetch, no depende de fechas) + `availability-batch` | Debounced (~350ms), una sola vez por cambio real |
| Tocar un filtro (precio, capacidad, categoría) | Ninguno | Nunca — `.filter()`/`.sort()` client-side sobre lo ya traído |
| Cambiar personas dentro del panel de detalle | `bookings/quote` | Debounced + guard de request-id (mismo hook que el fix de esta sesión) |
| Agregar al carrito | Ninguno | Estado local, instantáneo |
| Confirmar y pagar | `cart/checkout` | Una vez, acción explícita |

Un catálogo de decenas de tours/habitaciones (no miles) hace totalmente viable filtrar en el cliente después de un solo fetch — es más simple que construir un endpoint de búsqueda nuevo, y es la diferencia real entre "cada click espera al servidor" y "cada click es instantáneo".

## Arquitectura propuesta

- **`react-src/src/ExploreFlow.jsx`** (nuevo) — componente único, shortcode `[flow_explore]`, Pro Max only (`AMIR_EDITION === 'pro_max'`).
- **`react-src/src/components/shared.jsx`** (nuevo) — `TourCard`, `RoomCard`, `CardSkeleton`, `Stepper`, `GalleryLightbox` extraídos de `DiscoveryFlow.jsx` sin cambios de comportamiento, re-exportados desde ambos archivos.
- **`react-src/src/hooks.js`** (ya existe desde el fix de debounce de esta sesión) — extender con lo que haga falta, no recrearlo.
- **Barra de búsqueda persistente**: `{ checkIn, checkOut, guests }` en estado, siempre editable, nunca un gate que se abandona. En mobile colapsa a un chip resumen ("12 ago → 15 ago · 2") que se expande al tocar; en desktop siempre expandida, sticky arriba.
- **Carrito**: mismo shape que ya usa `DiscoveryFlow.jsx` (`{uiId, type:'tour'|'room'|'addon', ...}[]`) — portar `addTourToCart`/`addRoomToCart`/`removeFromCart`/`handleCheckout` tal cual, no reinventar el mapeo a `items[]` de `cart/checkout`.
- **Panel de detalle**: bottom-sheet en mobile (`position:fixed; bottom:0; height:88vh`, footer con precio+CTA siempre visible, mismo patrón `.df-detail-cta` de anclar el CTA abajo para no tener que scrollear a buscarlo), panel lateral en desktop (`position:fixed; right:0; width:min(480px,100%)`), la grilla nunca se desmonta detrás.
- **Carrito/checkout**: barra inferior persistente (mobile y desktop — es un patrón de e-commerce bien entendido en ambos, no hace falta inventar un layout de escritorio distinto para v1), con los extras siempre visibles adentro al expandirla.
- **Prefijo CSS**: los componentes movidos a `shared.jsx` no deberían seguir llamándose `df-*` (ya no es específico de Discovery) — considerar `fc-*` ("flow card") para las clases compartidas, y `ex-*` para lo propio de `ExploreFlow.jsx` (mismo criterio que ya separa `ab-*` de `df-*` hoy). Confirmar que los dos flujos pueden convivir en la misma página sin colisión de clases antes de dar por cerrado.

## Cambios del lado PHP

1. `includes/discovery/class-explore-shortcodes.php` (nuevo, namespace `TourFlow\Discovery`) — calcado de `class-discovery-shortcodes.php`: `shortcode_atts()` → `Shortcodes::enqueue_widget_assets()` → `<div data-flow-explore="1" data-lang="...">`.
2. `includes/core/class-plugin.php` (~línea 156-159, donde se registra `flow_discovery`) — mismo patrón gateado a `pro_max`:
   ```php
   if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Discovery\ExploreShortcodes::class ) ) {
       add_shortcode( 'flow_explore', [ \TourFlow\Discovery\ExploreShortcodes::class, 'explore' ] );
   }
   ```
3. `react-src/src/booking-widget.jsx` — un import + un bloque de montaje nuevo (`querySelectorAll('[data-flow-explore]')`), mismo patrón que el bloque de `data-flow-discovery` ya existente.
4. `includes/api/class-tours-controller.php` — el campo `from_price_mxn` en `format_tour_summary()`.
5. `react-src/vite.config.js` — **sin cambios**, el bundle único ya recoge todo lo importado desde `booking-widget.jsx`.
6. **Sin registro REST nuevo** — todo lo que este flujo necesita ya está registrado en `class-plugin.php`.

## Antes de escribir código, confirmar (quedaron abiertas)

1. **¿Necesita un punto de entrada con `tour_id`/`room_id` preseleccionado?** — `DiscoveryFlow.jsx` lo tiene (`tourId`/`roomId` props, usado por `single-amir_tour.php`/`single-flow_room.php` para continuar el upsell desde la ficha de un tour). Supuesto de partida: **no**, este flujo es standalone/búsqueda-primero — confirmar antes de asumirlo si el cliente lo quiere embebido también en fichas individuales.
2. **Convivencia a largo plazo** — ¿ambos flujos quedan en paralelo indefinidamente (el operador elige cuál shortcode usar en qué página), o la intención es eventualmente reemplazar `[flow_discovery]` en algunos lugares (ej. home) y mantenerlo en otros (ej. como continuación desde la ficha de un tour)? No bloquea construir esto, pero conviene saber el destino final.

## Al terminar

- 90 tests en verde, `php -l` limpio, `npm run build` limpio.
- Verificar que `DiscoveryFlow.jsx` no cambió en absoluto (`git diff` vacío ahí) — es la condición explícita del cliente para esta ronda.
- Probar ambos shortcodes en la misma página de prueba, confirmar que no hay colisión visual/CSS.
- Actualizar `CONTRIBUTING.md § 16` (nueva subsección, mismo formato que el resto) y `CLAUDE.md` con el estado de cierre.
- Armar ZIP nuevo (Pro Max — sigue siendo la única edición en uso activo) solo si el cliente lo pide explícitamente para esta ronda, mismo criterio que el resto de las sesiones.
