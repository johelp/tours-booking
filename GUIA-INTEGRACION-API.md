# Guía de integración — construir una app móvil o web fuera de WordPress

Esto es para el caso concreto: tu desarrolladora va a construir un cliente nuevo (app móvil nativa, o un sitio web aparte) que muestra tours y permite reservar, **sin pasar por páginas de WordPress**. No necesita saber WordPress para esto — necesita conocer la REST API pública del plugin, que es la única superficie con la que va a hablar.

**Actualizado 2026-08-01** con la § 7 (habitaciones + carrito multi-ítem) — esa parte es **exclusiva de la edición Pro Max**, ver la nota sobre `edition` en la § 2. Todo lo demás (tours, reservas, pagos) es igual en cualquier edición.

Complementa a [GUIA-DESARROLLO.md](GUIA-DESARROLLO.md) (arquitectura completa, si además va a tocar código del plugin) y [SPEC-HEADLESS.md](SPEC-HEADLESS.md) (spec específico para reemplazar el sitio web público — esta guía es más general, cubre también app móvil, con ejemplos reales de payloads).

## 1. La idea central

El widget de reservas actual (`react-src/src/BookingWidget.jsx`) **ya es exactamente este caso de uso, resuelto**: es un cliente que habla solo por HTTP con `/wp-json/amir/v1/*`, sin nada de WordPress en el medio. Cualquier cliente nuevo (app móvil, web headless) hace lo mismo — es la misma API, el mismo flujo, otro lenguaje/framework. Si tu desarrolladora tiene dudas de cómo se ve un caso particular en la práctica, ese archivo es el ejemplo de referencia que ya funciona en producción.

Dato importante confirmado en el código: el flujo de reserva **no depende de sesión de WordPress ni de cookies** — los endpoints públicos no verifican nonce, solo `access_token`/`email` por reserva. Esto significa que una app móvil nativa (que no maneja cookies de navegador) puede consumir esta API exactamente igual que un navegador, sin nada especial de autenticación.

## 2. Config de arranque — ya resuelto

```
GET /wp-json/amir/v1/config
```
Ya existe (`includes/api/class-config-controller.php`, desde v2.8.0) — es lo primero que tu desarrolladora llama al arrancar el cliente. Devuelve todo lo que necesita para inicializar (mismos valores seguros que antes solo se inyectaban server-side en la página de WordPress vía `window.amirBooking`):

```json
{
  "edition": "pro_max",
  "stripePk": "pk_test_...",
  "stripeMode": "test",
  "mpMode": "test",
  "currency": "MXN",
  "activeLanguages": ["es", "en"],
  "siteUrl": "https://amiradventours.com",
  "companyName": "TourFlow",
  "waPhone": "5219831649541",
  "policyTextEs": "...",
  "policyTextEn": "...",
  "termsTextEs": "...",
  "termsTextEn": "...",
  "progressLabels": true,
  "theme": {
    "color": "#1D9E75", "colorDark": "#0f6e56", "colorLight": "#dcf3ea", "colorMid": "#a8ddc7",
    "fontKey": "system", "fontStack": "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    "fontScale": 1.0, "radius": 12, "radiusSm": 8
  },
  "marketing": { "gadsConversionId": "", "gadsConversionLabel": "" }
}
```
El bloque `theme` es opcional de usar — una app nativa probablemente tiene su propio sistema de diseño y no necesita heredar el color/tipografía configurados en Personalización → 🎨 Widget de reserva, pero está disponible por si quieren que la app siga la marca configurada ahí.

**`edition`** (nuevo, 2026-08-01): `"lite"`, `"pro"` o `"pro_max"`. Todo lo de este documento hasta la § 7 (tours, reservas, pagos) existe en **cualquier** edición — el namespace `amir/v1` es siempre el mismo. La § 7 (habitaciones + carrito) es **exclusiva de Pro Max**: el namespace `flow/v1` ni siquiera se registra si la edición no es esa, así que pegarle en una instalación Pro/Lite da `404 rest_no_route`, no un error más descriptivo. Revisar `edition` al arrancar el cliente es la forma de decidir si mostrar o no la parte de habitaciones, en vez de descubrirlo con un 404 en producción.

**CORS** (solo aplica a web, no a app móvil nativa) — ✅ resuelto (v3.4.0, extendido a `flow/v1` en v3.7.0): en **Configuración → 🌐 CORS (frontend externo)** se carga el dominio exacto del frontend (ej. `https://casacalia.vercel.app`), uno por línea. Solo esos orígenes reciben los headers `Access-Control-Allow-*` en las rutas `/wp-json/amir/v1/*` **y** `/wp-json/flow/v1/*` — cualquier otro origen sigue bloqueado por el navegador, no hay ningún `*` abierto. Si el frontend cambia de dominio (ej. pasa de preview de Vercel a dominio propio), hay que sumar la línea nueva ahí. Una app móvil nativa (iOS/Android) no tiene esta restricción — llama a la API directo sin problema de CORS.

## 3. El flujo completo de reserva, paso a paso

Este es el flujo real que ya usa el widget actual — tu desarrolladora reimplementa exactamente esto.

#### Dos puntos de entrada al flujo — cubiertos los dos

Hay dos maneras típicas de que alguien llegue a reservar. Desde el paso 3.1 en adelante el camino es idéntico para las dos — lo único que cambia es cómo se llega hasta ahí.

**Flujo A — desde la página de un tour específico** (el caso simple, ej. alguien entra directo a la ficha de "Snorkel en cenote" desde un link o desde la grilla):

`3.1 Listar tours (grilla)` → click en un tour → `3.2 Detalle` → `3.3 Disponibilidad` → `3.4 Cotizar` → `3.5 Crear reserva` → `3.6 Pagar` → `3.7 Confirmación`.

**Flujo B — desde un buscador general** (ej. "quiero reservar algo el 15 de agosto para 2 personas", sin saber todavía qué tour elegir):

Hoy **no existe un endpoint de búsqueda combinada** (fecha + personas + tour) en la API — hay que armarlo del lado del cliente combinando dos llamadas que ya existen:

1. `GET /tours` (3.1) — trae el catálogo completo activo.
2. Por cada tour candidato, `GET /availability/day?tour_id={id}&date=2026-08-15` (3.3) — mirá si algún horario tiene `available: true` y `slots_remaining > 0` ese día. Los que sí, son los resultados de la búsqueda.
3. Mostrar como grilla de resultados solo los tours que pasaron el filtro (podés reusar la misma tarjeta de la grilla normal — mismos campos, mismo endpoint de precio, ver 3.1.1).
4. Desde ahí, el usuario entra al mismo flujo que A — pero como la fecha ya la eligió en el buscador, podés saltar directo al paso `3.4 Cotizar` con esa fecha ya cargada, en vez de mostrarle el calendario de nuevo.

**Nota de escala**: para un catálogo de unas pocas decenas de tours esto anda perfecto — son N llamadas en paralelo (una por tour candidato), cada una cacheada 5 minutos del lado del servidor. Si el catálogo crece a cientos de tours, ese patrón deja de ser práctico y hace falta un endpoint de búsqueda dedicado en el backend (filtrar por fecha+disponibilidad en una sola consulta) — es trabajo de backend que no existe hoy, avisen cuando llegue ese punto y se evalúa.

### 3.1 Listar tours

```
GET /wp-json/amir/v1/tours?lang=es
```
```json
[
  {
    "id": 12, "slug": "snorkel-cenote-bacalar", "price_model": "percapita",
    "name": "Snorkel en cenote", "short_description": "Recorré el cenote más...",
    "duration_minutes": 180, "min_age": 0, "max_capacity": 10,
    "cover_image": "https://.../foto.jpg",
    "languages": ["Español", "Inglés"],
    "categories": [ { "slug": "buceo", "name_es": "Buceo", "name_en": "Diving" } ],
    "provider_id": null
  }
]
```
**Ojo con lo que no viene acá** (confirmado leyendo `ToursController::format_tour_summary()` — esta lista es deliberadamente liviana, no trae todo): **no hay precio** — se pide aparte por tour, ver 3.1.1 abajo (`price_model` sí viene: `"percapita"` o `"group"`, útil para saber qué mostrar). Tampoco viene la descripción larga (solo `short_description`, 120 caracteres) ni la galería completa (`cover_image` es una sola imagen, la primera) — para eso hay que pedir el detalle (3.2).

`provider_id` viene `null` en un tour propio, o el ID del proveedor si es un tour de terceros revendido (marketplace, § 11 CONTRIBUTING.md) — Pro-only, en Lite siempre es `null`.

#### 3.1.2 Filtrar el listado (categoría / origen)

`GET /tours` acepta parámetros opcionales de query, todos combinables entre sí:

```
GET /wp-json/amir/v1/tours?lang=es&category=buceo
GET /wp-json/amir/v1/tours?lang=es&source=own          # solo tours propios
GET /wp-json/amir/v1/tours?lang=es&source=provider      # solo tours de proveedores externos
GET /wp-json/amir/v1/tours?lang=es&provider_id=5         # un proveedor puntual (implica source=provider)
```

Para armar un filtro de categorías sin hardcodear nombres:

```
GET /wp-json/amir/v1/tour-categories
```
```json
[
  { "slug": "buceo", "name_es": "Buceo", "name_en": "Diving" },
  { "slug": "dia-completo", "name_es": "Día completo", "name_en": "Full day" }
]
```
Solo devuelve categorías con al menos un tour activo asignado.

#### 3.1.1 Precio "desde" por tour (para la grilla)

```
GET /wp-json/amir/v1/prices?tour_id=12
```
```json
{
  "prices": [
    { "person_type": "adult", "group_min": null, "group_max": null, "price_mxn": 800.00, "schedule_id": null },
    { "person_type": "child", "group_min": null, "group_max": null, "price_mxn": 500.00, "schedule_id": null }
  ],
  "exchange_rate": 20.1
}
```
No hay un endpoint que devuelva "precio desde" ya calculado — el cliente arma el mínimo él mismo: `Math.min(...prices.filter(p => p.price_mxn > 0).map(p => p.price_mxn))`. Es exactamente lo que hace `TourList.jsx` (componente `PriceTag`) hoy: un fetch por tarjeta. Para una grilla de N tours son N+1 requests (la lista + uno de precio por tour) — está cacheado 10 min del lado del servidor así que no es grave, pero tenlo en cuenta si la grilla es grande.

### 3.2 Detalle de un tour

```
GET /wp-json/amir/v1/tours/{id}?lang=es
```
```json
{
  "id": 12, "slug": "snorkel-cenote-bacalar", "price_model": "percapita",
  "name": "Snorkel en cenote", "description": "...", "what_to_expect": "...",
  "highlights": ["Snorkel en cenote de agua cristalina", "Guía certificado incluido"],
  "itinerary": "...",
  "itinerary_stops": [ { "title": "Punto de encuentro", "desc": "...", "image_url": "...", "is_start": true } ],
  "detail_facts": [ { "icon": "🗣️", "label": "Idioma", "value": "Español, Inglés" } ],
  "meeting_point": "...", "meeting_lat": 18.68, "meeting_lng": -88.39,
  "includes": ["Equipo de snorkel", "Guía certificado"],
  "excludes": ["Transporte", "Propinas"],
  "duration_minutes": 180, "min_age": 0,
  "allow_children": true, "allow_babies": true, "min_age_child": 4,
  "max_capacity": 10, "min_passengers": 1,
  "languages": ["Español", "Inglés"],
  "gallery_images": ["https://.../foto1.jpg", "https://.../foto2.jpg"],
  "categories": [ { "slug": "buceo", "name_es": "Buceo", "name_en": "Diving" } ],
  "provider_id": null,
  "schedules": [ { "id": 3, "time_start": "08:00:00", "time_end": "11:00:00", "label": "Salida mañana" } ],
  "prices": [ { "person_type": "adult", "group_min": null, "group_max": null, "price_mxn": 800.00, "schedule_id": null } ],
  "addons": [ { "id": 5, "name": "Equipo extra", "price_mxn": 150.00, "pricing_type": "per_unit" } ]
}
```
`itinerary_stops` y `detail_facts` son los campos nuevos de v2.7.0/v2.8.0 (itinerario tipo timeline y datos destacados configurables por tour) — si tu front va a mostrar la ficha completa, mostralos; si el tour no cargó ninguno, ambos vienen como array vacío, no rompe nada omitirlos. `itinerary` (texto libre) es el campo viejo, previo al timeline — puede venir vacío en tours cargados después de v2.7.0.

### 3.3 Disponibilidad (para el calendario/selector de fecha)

```
GET /wp-json/amir/v1/availability/month?tour_id=12&year=2026&month=8
```
Devuelve, para cada día del mes, si está disponible y con cuántos cupos (`{ "2026-08-15": { "available": true, "slots": 6, "reason": "available" }, "2026-08-16": { "available": false, "slots": 0, "reason": "blocked" }, ... }`) — pensado para pintar un calendario mes a mes sin pedir un endpoint por día.

```
GET /wp-json/amir/v1/availability/day?tour_id=12&date=2026-08-15&lang=es
```
Una vez elegida la fecha, esto da los horarios de ESE día con cupos restantes por horario — es lo que alimenta el selector de horario después del selector de fecha.

### 3.4 Cotizar (antes de crear la reserva — recotiza en cada cambio del formulario)

```
POST /wp-json/amir/v1/bookings/quote
Content-Type: application/json

{
  "tour_id": 12,
  "schedule_id": 3,
  "date": "2026-08-15",
  "adults": 2,
  "children": 1,
  "babies": 0,
  "coupon_code": "VERANO10",
  "addons": [ { "id": 5, "qty": 2 } ],
  "lang": "es"
}
```
Respuesta:
```json
{
  "valid": true,
  "total_mxn": 3200.00,
  "usd_reference": 160.00,
  "breakdown": { "...": "detalle por tipo de persona/addon" },
  "model": "percapita",
  "coupon_code": "VERANO10",
  "discount_mxn": 320.00,
  "coupon_error": null,
  "addons_mxn": 400.00
}
```
Nunca calcules el precio del lado del cliente — este endpoint es la única fuente de verdad (valida disponibilidad, cupón, política de niños/bebés, todo server-side).

### 3.5 Crear la reserva (arranca el cobro)

```
POST /wp-json/amir/v1/bookings
Content-Type: application/json

{
  "tour_id": 12, "schedule_id": 3, "date": "2026-08-15",
  "adults": 2, "children": 1, "babies": 0,
  "customer_name": "Juan Pérez", "customer_email": "juan@mail.com", "customer_phone": "+52 983...",
  "lang": "es", "coupon_code": "VERANO10", "addons": [{"id":5,"qty":2}],
  "policy_accepted": true, "terms_accepted": true
}
```
Respuesta (201) — **la forma cambia según la pasarela activa**:
```json
// Stripe
{ "success": true, "booking_ref": "BK-2026-00042", "booking_id": 42, "total_mxn": 2880.00, "gateway": "stripe", "client_secret": "pi_..._secret_..." }

// Mercado Pago
{ "success": true, "booking_ref": "BK-2026-00043", "booking_id": 43, "total_mxn": 2880.00, "gateway": "mercadopago", "preference_id": "...", "init_point": "https://www.mercadopago.com/checkout/...", "sandbox_init_point": "..." }
```
La reserva queda en estado `pending` — si no se completa el pago, un cron la libera sola (`amir_pending_expire_mins`, default 15 min).

### 3.6 Completar el pago — **acá es donde web y mobile se diferencian de verdad**

**Stripe**: `client_secret` es un `PaymentIntent` de Stripe estándar.
- Web: se completa con Stripe.js/`@stripe/react-stripe-js` (lo que ya hace `BookingWidget.jsx`).
- App móvil: se completa igual con el **Stripe SDK nativo** (`stripe-react-native`, o los SDKs de iOS/Android directo) — es el mismo `PaymentIntent`, Stripe no distingue si el cliente es web o app.

**Mercado Pago (Checkout Pro)**: `init_point`/`sandbox_init_point` es una URL de checkout **hospedado por Mercado Pago**, no un formulario propio.
- Web: se redirige el navegador a esa URL.
- App móvil: se abre esa URL en un browser in-app (`SFSafariViewController`/Chrome Custom Tabs) y se vuelve a la app por deep link cuando Mercado Pago redirige de vuelta.

Después de que el cliente paga, confirmá contra el backend (nunca confíes en que "volvió de la pasarela" = "pagó"):

```
POST /wp-json/amir/v1/bookings/{id}/confirm-payment
{ "payment_intent_id": "pi_..." }
```
Respuesta: `{ "confirmed": true, "booking_ref": "BK-2026-00042" }` — o un error con código HTTP explícito (`402` pago no completado, `403` el `payment_intent_id` no corresponde a esa reserva, `503` no se pudo verificar contra la pasarela). (Stripe — este endpoint verifica contra Stripe directo, server-side, que el `payment_intent_id` realmente pertenece a esa reserva y está pagado — fail-closed, no confía en el cliente.)

Para Mercado Pago existe además:
```
POST /wp-json/amir/v1/bookings/{id}/confirm-mp
```
pensado para hacer **polling** mientras el cliente está en el checkout de MP — primero mira si el webhook server-to-server ya confirmó, y si no, consulta la API de MP como respaldo. Útil en una app que necesita saber "¿ya pagó?" sin depender solo del deep link de vuelta.

### 3.7 Ver / confirmar una reserva ya existente

```
GET /wp-json/amir/v1/bookings/{ref}?token=…    (o ?email=…)
```
```json
{ "booking_ref": "BK-2026-00042", "status": "confirmed", "tour_date": "2026-08-15", "customer_name": "Juan Pérez", "adults": 2, "children": 1, "babies": 0, "total_mxn": 2880.00, "qr_code_path": "..." }
```
El `access_token` (32 bytes random, se genera al crear la reserva) es la credencial que hay que guardar del lado del cliente para volver a consultar esta reserva después — es lo más parecido a una "sesión" que tiene el sistema, pero por reserva, no por usuario. Guardalo en el cliente (SecureStorage en mobile, localStorage en web) si querés que el usuario pueda volver a ver "mis reservas" sin loguearse.

### 3.8 Voucher y cancelación

```
GET  /wp-json/amir/v1/bookings/{ref}/pdf?token=…      → descarga el voucher con QR
POST /wp-json/amir/v1/bookings/{ref}/cancel            → cancela (aplica política de reembolso automático)
POST /wp-json/amir/v1/bookings/{ref}/request-cancel     → solicita cancelación (revisión manual)
```
`cancel` y `request-cancel` piden `token` o `email` igual que 3.7 (autorización), en el body JSON o como query string — ambos funcionan, `WP_REST_Request` los combina. Respuesta de `cancel`: `{ "success": true, "message": "...", "refund_mxn": 2880.00 }` (`refund_mxn` según la política: 7+ días antes = reembolso completo, 3-6 días = 50%, menos de 3 días = sin reembolso — la calcula el backend, nunca el cliente).

### 3.9 Lista de interés (opcional — tours en borrador, "avisame cuando abra")

Fuera del alcance típico de "mostrar tours y reservar", pero si el front también va a ofrecer anotarse a tours que todavía no abrieron: `GET /wp-json/amir/v1/tours/upcoming` (tours en borrador con lista de interés activada) y `POST /wp-json/amir/v1/tours/{id}/wishlist` (anotarse, sin cobrar todavía — genera una reserva en estado `wishlist` que se convierte en link de pago real cuando el operador abre el tour). Si no es prioridad, se puede omitir sin que falte nada del flujo principal.

## 7. Habitaciones y carrito multi-ítem — **exclusivo de Pro Max**

Todo lo de acá vive bajo el namespace **`flow/v1`** (no `amir/v1`) y solo existe si `GET /config` devuelve `"edition": "pro_max"` — ver nota de arriba. Mismo criterio de siempre: público, sin nonce, sin sesión de WordPress.

### 7.1 Catálogo de habitaciones

```
GET /wp-json/flow/v1/rooms
```
```json
[
  {
    "id": 3, "slug": "ulivo-suite",
    "name_es": "Suite Ulivo", "name_en": "Ulivo Suite",
    "description_es": "...", "description_en": "...",
    "capacity_max": 4, "min_nights": 2, "price_per_night": 180.00,
    "default_checkin_time": "15:00:00", "default_checkout_time": "11:00:00",
    "gallery_images": ["https://.../foto1.jpg", "https://.../foto2.jpg"],
    "amenities": [ { "icon": "📶", "label_es": "Wifi gratis", "label_en": "Free wifi" } ],
    "video_url": "https://www.youtube.com/watch?v=..."
  }
]
```
`amenities`/`video_url` son nuevos (2026-08-01, § 16.11/16.13 CONTRIBUTING.md) — si una habitación no cargó ninguno, vienen `[]`/`""`, no rompe nada omitirlos.

### 7.2 Disponibilidad de una habitación para un rango de fechas

```
GET /wp-json/flow/v1/rooms/{id}/availability?check_in=2026-08-10&check_out=2026-08-15
```
```json
{ "available": true }
```
Chequea intervalo semi-abierto `[check_in, check_out)` (el día de checkout de una reserva ya libera la habitación para un check-in ese mismo día) **y** disponibilidad por temporada si el operador cargó reglas en TourFlow → 🌤 Disp. habitaciones (ej. una habitación que solo se ofrece jun-ago devuelve `available: false` fuera de esa ventana, aunque no haya ninguna reserva que choque).

### 7.3 Carrito multi-ítem — tour + habitación + extras en un solo checkout

El carrito se arma **del lado del cliente** (estado local, no hay que crear nada en el servidor hasta pagar). Cuando el usuario confirma, se manda TODO junto:

```
POST /wp-json/flow/v1/cart/checkout
Content-Type: application/json

{
  "items": [
    { "type": "tour", "tour_id": 12, "schedule_id": 3, "date": "2026-08-10", "adults": 2, "children": 0, "babies": 0 },
    { "type": "room", "room_id": 3, "check_in": "2026-08-10", "check_out": "2026-08-15", "guests": 2 }
  ],
  "customer_name": "Juan Pérez", "customer_email": "juan@mail.com", "customer_phone": "+52 983...",
  "lang": "es", "policy_accepted": true, "terms_accepted": true
}
```
Cada ítem del array `items` puede ser `"type": "tour"` (mismos campos que 3.5 de más arriba, incluido `addons`) o `"type": "room"`. Un tour de proveedor externo (marketplace) se manda exactamente igual que uno propio — no hay ningún campo que lo distinga desde el cliente.

Respuesta (201) — misma forma que 3.5 (Stripe/Mercado Pago), pero con un identificador de carrito en vez de un `booking_id` único:
```json
{ "success": true, "cart_group_id": "a1b2c3d4-...", "total_mxn": 1100.00, "gateway": "stripe", "client_secret": "pi_..._secret_..." }
```
Si el carrito incluye un tour de proveedor en modo "cobro diferido" (§ 11 CONTRIBUTING.md), ese ítem queda esperando aprobación **sin cobrarse** — el pago combinado solo cubre los ítems que sí se cobran ahora. Si el carrito entero era de ese tipo, `requires_payment: false` y no hay nada que pagar todavía.

Completar el pago: idéntico a 3.6 (Stripe.js/SDK nativo, o redirect de Mercado Pago) — el `client_secret`/`init_point` es el mismo tipo de objeto.

### 7.4 Confirmar el pago del carrito

```
POST /wp-json/flow/v1/cart/{cart_group_id}/confirm-payment
{ "payment_intent_id": "pi_..." }
```
```json
{ "confirmed": true, "booking_refs": ["BK-2026-00050", "BK-2026-00051"] }
```
Un solo pago confirma **todas** las reservas del carrito de una — cada ítem (tour u habitación) queda con su propio `booking_ref`, su propio email de confirmación, y aparece por separado en TourFlow → Reservas (agrupadas por `cart_group_id` si necesitás mostrarlas juntas en tu UI). Mismos códigos de error que 3.6 (`402`/`403`/`503`).

## 8. Seguridad — lo que hay que respetar sin excepción

- **Nunca** guardes la secret key de Stripe/Mercado Pago en el cliente (app o web) — solo la publishable key (Stripe `pk_...`) viaja al cliente, y es la única que necesitás para completar el pago del lado del cliente.
- Todos los endpoints públicos tienen rate limiting por IP (429 si te pasás) — el cliente tiene que manejar ese código con un mensaje razonable, no reintentar en loop.
- El `booking_ref` (`BK-2026-00042` — el prefijo `BK` es el default, configurable en Personalización → Identidad de marca) es secuencial y adivinable — nunca lo uses solo para identificar a un cliente. Siempre `access_token` o `email`.
- No hay login de usuario en este sistema — cada reserva es su propia unidad de acceso vía `access_token`. Si la app quiere un historial de reservas "de este usuario", eso es una feature nueva que no existe hoy (habría que decidir si vale la pena construir cuentas de usuario, o si alcanza con guardar los `access_token` de las reservas hechas desde ese dispositivo).

## 9. Qué NO necesita construirse de cero

- El **motor de precios, disponibilidad y cupones** ya vive en el backend (`quote`) — el cliente nuevo nunca calcula nada, solo muestra lo que la API devuelve.
- El **voucher con QR** ya se genera server-side — no hay que reimplementar generación de PDF ni códigos QR en el cliente.
- Los **emails de confirmación** los sigue mandando WordPress automáticamente al crear/confirmar la reserva — el cliente nuevo no necesita mandar ningún email.
- El **panel de operación** (WP Admin: Dashboard, Reservas, Modo campo, Calendario) sigue funcionando igual, viendo las reservas que entren por cualquier canal — no hay nada que sincronizar a mano, es la misma tabla `wp_amir_bookings` sin importar de dónde vino la reserva.

## 10. Diferencia clave si además arrancan con la app móvil primero

Todo lo de arriba es idéntico para web headless o app nativa — es la misma API, incluido `/config`. Lo único que cambia es el punto 3.6 (SDK nativo de pago en vez de JS) y que en mobile no hace falta resolver CORS (sección 2, y solo aplica cuando arranquen con la web). Si el plan es arrancar por la app y dejar la web headless para después, no hay ningún trabajo de backend hecho hoy que sea exclusivo de una u otra.
