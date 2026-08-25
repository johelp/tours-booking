# Continuación: UX/UI de clase mundial + pendientes reales (después de v5.0.4)

Sesión anterior cerrada en el límite de contexto con varios frentes abiertos. Este prompt es la puerta de entrada para retomarlos en orden. Leer primero `CLAUDE.md` completo, después `CONTRIBUTING.md § 16.23`-`§ 16.26` (esta última documenta el Stepper de v5.0.4), y correr `git log --oneline main..$(git branch --show-current)` para el historial completo.

## 1. Bugs reales sin confirmar — investigar ANTES de seguir con diseño

El cliente reportó estos tres problemas probando v5.0.2/v5.0.3 en vivo (`caliafarm.com/stag`). Uno ya se corrigió con causa raíz confirmada; los otros dos necesitan más información del cliente antes de poder arreglarlos con confianza — no adivinar sin esa información.

1. **✅ Corregido — "Sorry, you are not allowed to access this page" al entrar a Tours.** Causa raíz confirmada: `TourManagerRole` registraba una copia duplicada del submenú Tours/Nuevo tour (`add_tour_submenu()`, ya eliminado) apuntando al mismo slug que `AdminMenu::add_menus()`, pero con la capacidad `edit_amir_tours` — que nunca se le otorgaba a NINGÚN rol, ni siquiera Administrador. Ver `includes/core/class-tour-manager-role.php`.

2. **⚠️ Sin confirmar — "queda en blanco" al agregar una habitación.** Revisé `RoomPostType::meta_box_main()`/`meta_box_amenities()`/`meta_box_gallery()`/`save_meta()`/`sync_to_db()` completos, sin encontrar un fatal evidente en el código estático — a diferencia del bug de Tours (arriba), acá no hay una causa clara para señalar con confianza. **Antes de tocar código, pedirle al cliente**:
   - ¿La pantalla en blanco aparece al ABRIR "+ Nueva habitación", o al hacer clic en "Publicar"/"Guardar"?
   - Activar `WP_DEBUG`/`WP_DEBUG_LOG` en `wp-config.php` (o pedirle al hosting que lo active un momento) y compartir el contenido de `wp-content/debug.log` después de reproducir el error — un error PHP real ahí mismo va a decir la causa exacta, como pasó con el bug de `fmt_time()` de v5.0.1.
   - Si el hosting tiene acceso a logs de PHP-FPM/error_log del servidor, esos también sirven.

3. **⚠️ Sin confirmar — `503` en `GET /wp-json/flow/v1/tours/featured?date=...&lang=en`** (reportado al "querer buscar" en el flujo continuo). A diferencia de un error de WordPress ("Sorry, you are not allowed...", pantalla en blanco), un `503 Service Unavailable` normalmente se origina en el servidor/PHP-FPM (worker caído, timeout, límite de memoria) más que en la aplicación — no descartado que sea un fatal de PHP igual, pero no se puede confirmar sin el log. **Mismo pedido que arriba**: `debug.log` o el error log del servidor justo después de reproducirlo. Si el log muestra un error de PHP real dentro de `DiscoveryController::featured()` o algo que llama (`ToursController::format_tour_summary()`, `AvailabilityEngine::get_month_availability()`), corregirlo ahí. Si el log NO muestra nada de la app (o muestra un timeout/out-of-memory del servidor), es un problema de infraestructura del hosting, no del plugin — coordinarlo con el cliente/hosting aparte.

**Estado 2026-08-04, sesión de Stepper (v5.0.4)**: se preguntó de nuevo, el cliente todavía no tenía el `debug.log` a mano — se avanzó con la sección 2 mientras tanto (ver abajo). Sigue siendo el primer paso al retomar: pedirlo antes de tocar código de estos dos bugs.

## 2. UX/UI de clase mundial — flujo combinado (Pro Max)

Pedido explícito del cliente: "es el centro de nuestro sistema en términos de conversión, por lo que debe ser súper óptimo." Usar el skill **`ui-ux-pro-max`** (`Skill({ skill: 'ui-ux-pro-max' })`) al retomar esto — trae paletas/tipografía/guías de UX por dominio, ya se usó una vez esta sesión para el rediseño de los paneles de detalle (ver abajo qué se hizo y qué falta).

### Ya hecho esta sesión (v5.0.3), no repetir:

- **Modales → paneles de pantalla completa dentro del flujo.** `TourConfirmModal`/`RoomDetailModal` (bottom-sheet flotante sobre la grilla) se reemplazaron por `TourDetailStep`/`RoomDetailStep` — pantalla completa, sin overlay, "← Volver" arriba, precio+CTA anclados abajo (sticky), siempre visibles sin scrollear. Verificado con un preview local montando el bundle real con `fetch` mockeado (ver método abajo, § 4) — funciona de punta a punta: agregar → carrito → checkout.
- **Desktop usa más pantalla** (pedido explícito): `.df-detail` pasa a grid de 2 columnas (contenido | tarjeta de reserva sticky) desde 900px — sin restructurar el HTML, solo `grid-template-areas` sobre los mismos 3 hijos (botón volver, cuerpo, CTA).
- **Pulso visual en el CTA** cuando la selección queda completa, en vez de forzar un scroll (ya está siempre a la vista) — respeta `prefers-reduced-motion`.
- **Secciones vacías ya no se muestran** en el paso de extras (`ExtrasStep`, `DiscoveryFlow.jsx`) — si no hay addons por tour NI extras globales, no aparece el título "Servicios extra"; si no hay tours sugeridos, no aparece "Otros tours sugeridos" ni un texto de "no hay nada". Mismo criterio pendiente de auditar en el resto del flujo si aparecen más casos (ver § 3).
- **Bug real corregido en el camino**: en desktop, "← Volver" quedaba centrado en la pantalla en vez de alineado a la izquierda (`justify-self:stretch` por defecto de CSS grid ganándole a `width:auto`) — corregido con `justify-self:start`.

### Ya hecho en la sesión de continuación (v5.0.4-v5.0.5), no repetir:

- **Contadores de personas → `Stepper`.** Los 5 `<input type="number">` (Personas en el paso "start", Huéspedes en "rooms", Adultos/Niños/Bebés en `TourDetailStep`) pasaron al componente `Stepper` (−/valor/+, botones circulares de 40px, `--ab-teal`, disabled en min/max). Alcance confirmado con el cliente vía `AskUserQuestion`: **solo flujo combinado esta ronda** — el widget clásico (`BookingWidget.jsx`/`StepPeople`) queda para otra ronda, no asumir que ya está hecho ahí. Los inputs de cantidad de addons (`ExtrasStep`) quedaron fuera de alcance, no se tocaron. Ver `CONTRIBUTING.md § 16.26`.
- **Revisión de `TourCard`/`RoomCard`** (v5.0.5, `ui-ux-pro-max --domain style --domain ux`): `aria-label`/`role="img"` en las imágenes (antes sin texto alternativo), `TourCard` ahora es clickeable como imagen/título igual que `RoomCard` (inconsistencia real corregida), iconos de reemplazo 🎟/🛏 → SVG, `.df-pill` con touch target subido de ~26px a ~36px. Ver `CONTRIBUTING.md § 16.27`. **No se tocaron**: sombras/hover/tipografía en general (ya estaban razonables, no se encontró nada más que corregir sin inventar trabajo), ni el resto de los emojis del flujo (👥/✓/📅/títulos de paso — fuera de alcance, migrarlos todos es un trabajo mucho más grande no pedido).

**Recordatorio del cliente (2026-08-04, pensando en un demo)**: todo texto nuevo debe ser traducible (`t()`) o estar en inglés directamente — tenerlo presente en cualquiera de los puntos de abajo.

**Recordatorio del cliente (2026-08-05)**: demos de acá en adelante usan **Caliafarm** (azul moderno) como referencia, no Amir Adventours — no cambia código (color/logo/nombre ya son configurables por instalación), solo qué datos de ejemplo usar en previews/capturas.

### Ya hecho (v5.0.6-v5.0.7), no repetir:

- **Auditoría de seguridad pre-producción** (v5.0.6, pedido explícito "va a producción") — 5 hallazgos reales corregidos, verificados línea por línea antes de tocar código: vouchers/QR con nombre de archivo adivinable protegidos solo por `.htaccess` (inútil en nginx), "candado" cosmético del producto digital (redirigía a una URL pública), descarga digital posible sin pagar, inyección de fórmulas en export CSV, ruta de servidor filtrada en la API pública. Ver `CONTRIBUTING.md § 16.28`.
- **Voucher PDF combinado rediseñado** (v5.0.7, `CartVoucherGenerator`) — logo de marca configurable (antes ignoraba `amir_brand_logo_url`), footer con contacto (faltaba del todo), extras del carrito listados (se cobraban pero no se veían), hora/punto de encuentro en tours, título de sección por ítem. Bug real de fondo corregido: el voucher mentía "RESERVA CONFIRMADA" con un ítem de proveedor externo todavía `pending_provider_approval` — mismo patrón ya corregido en `CartConfirmationEmail` pero nunca heredado acá; ahora solo lista/cobra en el total lo ya confirmado, con aviso ámbar si algo queda pendiente. El QR de verificación se mantuvo sin tocar (pedido explícito del cliente al revisar). Ver `CONTRIBUTING.md § 16.29`.

- **Emails, pasada visual hecha** (v5.0.8) — `BaseEmail::wrap_template()` ya estaba sólido (header/footer/botones ya usaban el color de marca), el problema real estaba en el CONTENIDO de cada email: 7 lugares con teal hardcodeado (nuevo helper `BaseEmail::brand_color()`), y en `CartConfirmationEmail` una variable CSS (`var(--teal-light)`) y una clase (`ab-price-total`) copiadas del CSS del widget React que no existen en el contexto de un email — el divisor entre ítems y el total del carrito se veían sin ningún estilo. Verificado con un color azul de prueba para forzar que cualquier resto de teal se notara. Ver `CONTRIBUTING.md § 16.30`.

- **Menú de admin, consistencia arreglada** (v5.0.9) — pedido explícito, alcance confirmado vía `AskUserQuestion`: íconos + orden/agrupación. Íconos completados en los ~10 ítems que no tenían (antes mitad sí, mitad no). Bug real de agrupación corregido: "Disp. habitaciones" vivía en Contenido solo por compartir el gate de Pro Max con el CPT de habitaciones — movida junto a "Disponibilidad" (tours) en Operación diaria. **Sin verificar visualmente en un WordPress real todavía** — no hay entorno disponible, confirmar en el sandbox cuando se pueda. Ver `CONTRIBUTING.md § 16.31`.
- **"Ver detalle" + aclaración de ventana en Experiencias destacadas** (v5.1.0) — `TourCard` gana un botón explícito además del tap implícito en imagen/título (v5.0.5), y el título aclara "(±4 días)" — antes no decía nada. `window_days` ahora se pasa explícito desde el frontend en vez de depender en silencio del default del backend. Ver `CONTRIBUTING.md § 16.32`.
- **ZIP v5.1.0-promax armado y entregado** — 466 archivos, mismo proceso de siempre, `php -l` + smoke test de autoload real limpios. Solo Pro Max (única edición en uso). **Sin subir al sandbox todavía** — el cliente lo tiene para subirlo.

- **Foto/título/"Ver detalle" diferenciados en `TourCard`** (v5.1.1) — el cliente pidió avanzar directo en la misma sesión en vez de esperar a la próxima. Foto → `GalleryLightbox` (overlay sobre el flujo, no un paso más). Título → sigue abriendo `TourDetailStep` (sin cambios). "Ver detalle" → link real a la ficha del tour en pestaña nueva, con fallback al panel de reserva si el tour no tiene `permalink` todavía. Nuevo `ToursController::tour_permalink()` (reusa el patrón de `PartnerTracker`). Ver `CONTRIBUTING.md § 16.33`.

- **Tours de fecha fija — evento único, solo widget clásico** (v5.2.0) — pedido explícito del cliente ("abrir tours con fecha fija, sin que el cliente tenga que elegir"). Analizado con el cliente antes de construir (`AskUserQuestion`): alcance confirmado solo widget clásico (no el flujo combinado de Pro Max), horario se sigue eligiendo si hay más de uno. Nueva columna `amir_tours.fixed_date`, `AvailabilityEngine` la hace cumplir server-side (no solo confía en que el widget saltee el paso), `BookingWidget.jsx` saltea el paso "date" entero con el mismo patrón que ya usaba para saltear "schedule"/"extras". Verificado interactivamente. Ver `CONTRIBUTING.md § 16.35`.

### Sin empezar

1. **"Solicitar fecha" en tours de fecha fija — Pro y Pro Max** (pedido por el cliente en la misma sesión que v5.2.0, mientras se construía — no se llegó a diseñar ni construir). La idea: además de la fecha fija reservable, que el cliente pueda "solicitar" una fecha distinta, para que el operador la evalúe (aprobar/rechazar) en vez de una reserva instantánea. **Preguntas de diseño abiertas, cerrar con el cliente antes de tocar código** (mismo criterio que el resto de las features grandes de esta sesión — no asumir):
   - ¿El cliente paga al solicitar, o recién si el operador aprueba esa fecha puntual? Si paga al solicitar, hay que definir qué pasa con la plata si el operador rechaza (¿reembolso automático, como ya existe para el marketplace de proveedores — `pending_provider_approval` — o algo distinto?).
   - ¿Es un estado de reserva nuevo (`amir_bookings.status`), o conviene reusar/adaptar un patrón ya existente? Hay dos candidatos ya construidos y probados: **Lista de interés** (`wishlist`/`awaiting_payment`, para tours en borrador, sin pagar hasta que se abre una fecha real) y **aprobación de proveedor** (`pending_provider_approval`, con cobro ya hecho, aprobar/rechazar por link de email sin login, auto-cancelación a 48h). El segundo encaja más si la respuesta a la pregunta anterior es "paga al solicitar".
   - ¿El operador ve estas solicitudes en una pantalla nueva, o alcanza con una vista/filtro dentro de Reservas?
   - ¿Un tour de fecha fija admite UNA sola solicitud pendiente por vez, o varias en simultáneo (varios clientes proponiendo fechas distintas, el operador elige)?
2. **Dashboard** (contenido de la página, no el menú lateral — ya arreglado en v5.0.9) — el cliente pidió dejarlo para otra sesión, sin alcance definido todavía. Mismo criterio que las rondas anteriores: comparar contra lo que ya funciona, buscar bugs reales (branding, datos que no coinciden, estados que mienten) antes de solo maquillar.

## 3. Depósitos parciales por tour — diseño ya cerrado con el cliente, sin construir

Retomado por el cliente 2026-08-04 (`avanza con depósitos para la v5.0.3` — no se llegó a construir esta ronda, prioridad quedó en los bugs + UX). Decisiones YA cerradas vía `AskUserQuestion`, documentar y construir directo sin volver a preguntar:

- **Alcance**: todas las ediciones, ambos flujos (widget individual `BookingWidget.jsx` Y flujo combinado `DiscoveryFlow.jsx`) — es una propiedad del TOUR, no de Pro Max.
- **Saldo en efectivo**: necesita un botón "Marcar saldo cobrado" (Modo campo al hacer check-in, y detalle de la reserva) — no es solo informativo, queda un registro real con fecha.
- **Carrito mixto de Pro Max**: un tour con depósito combinado con otro ítem de cobro completo → un solo `PaymentIntent` cubre "100% de lo normal + % de depósito de lo especial", mismo criterio que ya se usa para el cobro diferido de proveedores (`is_deferred_charge` en `BookingManager::create_pending()`).
- **Reembolsos**: la política de cancelación (100%/50%/0% según antelación) se aplica sobre el MONTO REALMENTE COBRADO (el depósito), nunca sobre el total del tour.

**Diseño técnico esbozado (sin construir)**: nuevas columnas `amir_tours.deposit_enabled`/`amir_tours.deposit_pct`, snapshot `amir_bookings.deposit_pct` (0 = pago completo) + `amir_bookings.balance_paid_at`. `BookingResult` gana una propiedad `charge_mxn` (monto a cobrar AHORA vía la pasarela — default = `total_mxn` si no hay depósito, así que ningún call site existente cambia de comportamiento sin querer) — `StripeGateway`/`MercadoPagoGateway` pasan de leer `->total_mxn` a `->charge_mxn` para el monto del cobro. `amir_bookings.total_mxn` NUNCA cambia de significado (sigue siendo el precio total del tour, usado en reportes/voucher/emails) — separar claramente "precio total" de "monto cobrado ahora" es la parte más importante de no romper nada existente.

## 4. Cómo verificar visualmente sin un WordPress real (método usado esta sesión)

No hay entorno WP local (regla del proyecto, `CLAUDE.md`). Para verificar cambios de UI del flujo combinado sin subir un ZIP al sandbox cada vez:

1. `cd react-src && npm run build` (regenera `assets/js/booking-widget.js` + `assets/css/booking-widget.css`).
2. Crear un `.claude/launch.json` temporal con un server estático (`python3 -m http.server`) apuntando a la raíz del repo.
3. Crear un HTML temporal en la raíz del repo (ej. `_preview.html`, **borrar antes de terminar** — nunca commitear) que:
   - Defina `window.amirBooking` (currency, apiUrl, flowApiUrl, stripePk vacío).
   - Monte `<div data-flow-discovery data-mode="experience" data-lang="es"></div>`.
   - Sobreescriba `window.fetch` para devolver JSON mockeado según el path pedido (tours/featured, tours/{id}, availability/day, bookings/quote, addons/global, etc. — revisar `react-src/src/api.js`/`roomsApi.js` para los paths exactos, NO asumirlos).
   - Cargue `<link rel="stylesheet" href="/assets/css/booking-widget.css">` **antes** del `<script type="module" src="/assets/js/booking-widget.js">` — sin esto los botones se ven con estilos del navegador por defecto (bug real que pasó esta sesión, perdió tiempo).
4. `preview_start` con esa config, `navigate` a `http://localhost:PUERTO/_preview.html`, interactuar con `computer`/`form_input`/`read_page`.
5. Al terminar: `preview_stop`, borrar `_preview.html` y `.claude/launch.json` — no dejar rastros en el repo.

## 5. Al terminar cada ronda

Actualizar `CONTRIBUTING.md § 16` (nueva subsección) y `CLAUDE.md` con el estado de cierre — mismo patrón que las sesiones anteriores. Armar ZIP nuevo solo si el cliente lo pide explícitamente para esa ronda (proceso en `§ 16.16`/`§ 16.9`/`§ 16.23`).
