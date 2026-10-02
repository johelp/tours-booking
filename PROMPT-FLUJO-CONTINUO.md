# Prompt de arranque — Flujo continuo de descubrimiento (Pro Max)

Copiar y pegar esto como primer mensaje de la sesión dedicada a esto. El pedido explícito del cliente fue tener **tiempo y tranquilidad** para hacer esto bien — no es una tarea más entre otras, es la pieza de mayor valor de producto que queda en el roadmap de Pro Max, y está marcada como **crítica para un cliente real**.

---

Vamos a construir el "flujo continuo de descubrimiento" para TourFlow Pro Max. El spec de producto ya está **cerrado y dialogado con el cliente** — no es un feature nuevo a diseñar desde cero, es momento de construir con foco en conversión/upselling.

## Leer antes de tocar código, en este orden

1. **`CONTRIBUTING.md § 16` completo** (16.1 a 16.17) — la sección más larga del documento. En particular:
   - § 16.1–16.6: motor de reservas de habitaciones, carrito multi-ítem, disponibilidad, ya construidos y probados con 90 tests unitarios.
   - **§ 16.15 — el spec exacto de los dos flujos**, la parte más importante de esta lectura.
   - § 16.16 — el backend compartido que YA está construido y listo para consumir (ver abajo).
   - § 16.17 — idea nueva del cliente (productos digitales en extras), sin diseñar todavía.
2. `CLAUDE.md § Reglas críticas` y `CONTRIBUTING.md § 6` (Convenciones) — patrones obligatorios del repo.

## Qué ya está construido y probado — no rehacer

- **Motor de reservas de habitaciones completo**: `TourFlow\Rooms\RoomBookingManager` (crear + reprogramar), `RoomAvailability` (intervalo semi-abierto, disponibilidad por temporada vía `flow_room_availability_rules`), CPT `flow_room` con amenities/video/galería con lightbox, plantilla `single-flow_room.php`.
- **Carrito multi-ítem**: `TourFlow\Cart\CartController` — `POST /flow/v1/cart/checkout` acepta `items: [{type:'tour',...}, {type:'room',...}]` mezclados, crea todas las reservas de una, inicia UN pago combinado (Stripe/MP), `POST /flow/v1/cart/{cart_group_id}/confirm-payment` confirma todo el grupo junto.
- **Voucher general**: `GET /flow/v1/cart/{cart_group_id}/pdf` — un PDF+QR único cubriendo todos los ítems del carrito, escaneable en Modo Campo.
- **Google Calendar, Reportes, GDPR, import de tours** — todo al día, ver § 16 y § 15.
- **Backend de descubrimiento, YA listo para consumir desde React**:
  - `GET /flow/v1/tours/featured?date=YYYY-MM-DD&limit=6&window_days=4` — tours **propios** destacados (`sort_order`) con sus fechas disponibles alrededor de la fecha pedida. Esto alimenta el **paso 1 del Flujo A**.
  - `GET /flow/v1/tours/catalog-window?from=YYYY-MM-DD&until=YYYY-MM-DD` — catálogo completo (propio + proveedor) con disponibilidad dentro del rango. Esto alimenta el **paso 2 del Flujo B**.
  - `GET /flow/v1/rooms`, `GET /flow/v1/rooms/{id}/availability` — ya construidos (§ 16.6), alimentan el paso de habitaciones de ambos flujos.
  - `amir_addons` ya tiene `applies_to` (tour/room/both) listo para el paso de extras.

**No vuelvas a preguntar sobre lo que ya está decidido** (todo esto en § 16.15, cerrado con el cliente 2026-08-01):

- Son **dos flujos**, no uno lineal: **Flujo A** (experiencia destacada → habitaciones en rango ±1 día alrededor de ESA experiencia → extras → checkout único → voucher general) y **Flujo B** (habitación → catálogo completo de experiencias ±5 días de la estadía, propias y de terceros → sumar a la reserva).
- Extras = un solo paso con dos tipos de ítem mezclados: addons de catálogo (sin fecha propia) + "otros tours" sugeridos (con fecha propia, dentro o fuera de la estadía).
- El modal de detalle de un tour/habitación tiene que estar disponible en cualquier punto de ambos flujos sin perder el estado del carrito.
- Mobile-first, igual de óptimo en PC — no es un ajuste posterior, es el criterio de diseño desde el primer boceto.
- Ágil y rápido — el criterio de UX es que sumar un ítem se sienta tan fluido como ya se siente en `RoomSearch.jsx` hoy.

## Antes de escribir código, cerrar estas preguntas (quedaron abiertas en § 16.15)

1. **¿Un componente único o dos separados?** — `DiscoveryFlow.jsx` con Flujo A/B como dos entradas del mismo componente (compartiendo el carrito, el modal de detalle, el paso de extras), o dos shortcodes/componentes independientes que comparten solo el backend. Recomendación propia a validar: componente único — los dos flujos comparten carrito, modal, extras y checkout, así que separarlos completamente duplicaría la mayor parte del árbol de componentes.
2. **¿Cómo se marca un tour como "destacado" en el editor?** — hoy `featured` = primeros N por `sort_order`, sin campo nuevo (ya cerrado en § 16.4). Confirmar que sigue siendo así o si el cliente quiere un checkbox explícito "Destacado" ahora que hay un caso de uso real y crítico.
3. **Ventana del Flujo B**: el spec dice "±5 días", pero `catalog-window` ya está parametrizado (`from`/`until` explícitos, no hardcodea 5) — confirmar si 5 es el número real a usar en el cliente o si el operador debería poder configurarlo.
4. **Productos digitales en extras** (§ 16.17, idea nueva sin diseñar) — ¿entra en el alcance de esta sesión, o se separa para después? Si entra: ¿reusa `amir_addons` con un `pricing_type` nuevo (`'digital'`) + un campo de archivo adjunto, o necesita su propia tabla?

## Al terminar

Actualizar `CONTRIBUTING.md § 16.15/16.16` (pasar de "construido el backend, falta el frontend" a documentar los dos flujos ya construidos) y `CLAUDE.md` con el estado de cierre de la sesión — mismo patrón que el resto de las secciones de § 16. Armar ZIP nuevo (Pro/Lite/Pro Max, ver § 16.16 para el proceso ya establecido) solo si el cliente lo pide explícitamente para esta ronda.
