# Casos prácticos — API de TourFlow para diseñar una web

Guía práctica para construir un sitio web nuevo (fuera de WordPress) que muestre el catálogo de tours de Visit Sicily Tours y permita reservar. Tres casos de uso concretos: mostrar la grilla de tours, mostrar el detalle de un tour, y el proceso completo de reserva. Cada uno con el endpoint real, la respuesta real, y un ejemplo de código.

**Base URL**: `https://visitsicilyexperiences.com/wp-json/amir/v1`

No hace falta ningún login ni manejo de cookies/sesión — todos los endpoints públicos son `GET`/`POST` directos por HTTP, con `access_token` por reserva como única "credencial" cuando aplica.

---

## 0. Arrancar: bootstrap de configuración

Antes de pintar cualquier pantalla, pedí la configuración del sitio — moneda, idiomas activos, clave pública de Stripe, textos legales.

```
GET /config
```

```json
{
  "stripePk": "pk_live_...",
  "stripeMode": "live",
  "mpMode": "live",
  "currency": "EUR",
  "activeLanguages": ["es", "en"],
  "siteUrl": "https://visitsicilyexperiences.com",
  "companyName": "Visit Sicily Tours",
  "waPhone": "",
  "policyTextEs": "...", "policyTextEn": "...",
  "termsTextEs": "...", "termsTextEn": "...",
  "progressLabels": true,
  "theme": {
    "color": "#1D9E75", "colorDark": "#0f6e56", "colorLight": "#dcf3ea", "colorMid": "#a8ddc7",
    "fontKey": "system", "fontStack": "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    "fontScale": 1.0, "radius": 12, "radiusSm": 8
  },
  "marketing": { "gadsConversionId": "", "gadsConversionLabel": "" }
}
```

```javascript
async function getConfig() {
  const res = await fetch('https://visitsicilyexperiences.com/wp-json/amir/v1/config');
  return res.json();
}
// Guardá currency y stripePk — los vas a necesitar en cada pantalla de precio y en el pago.
```

`theme` es opcional de usar — si el sitio nuevo tiene su propio diseño, ignoralo. Si querés heredar los mismos colores/tipografía que ya están configurados en el WordPress (Personalización → 🎨 Widget de reserva), aplicalos como tus variables CSS.

---

## Caso 1 — Mostrar la grilla de tours

```
GET /tours?lang=es
```

```json
[
  {
    "id": 12,
    "slug": "tour-etna-atardecer",
    "price_model": "percapita",
    "name": "Etna al atardecer",
    "short_description": "Subí al volcán activo más alto de Europa y mirá caer el sol desde 2900 metros...",
    "duration_minutes": 300,
    "min_age": 8,
    "max_capacity": 12,
    "cover_image": "https://visitsicilyexperiences.com/wp-content/uploads/2026/etna-01.jpg",
    "languages": ["Español", "Inglés"]
  }
]
```

Esta lista es liviana a propósito — **no trae precio ni galería completa**. `short_description` viene ya recortado a 120 caracteres, no hace falta truncarlo de nuevo.

### 1.1 Precio "desde" por tarjeta

```
GET /prices?tour_id=12
```

```json
{
  "prices": [
    { "person_type": "adult", "group_min": null, "group_max": null, "price_mxn": 85.00, "schedule_id": null },
    { "person_type": "child", "group_min": null, "group_max": null, "price_mxn": 45.00, "schedule_id": null }
  ],
  "exchange_rate": 1.0
}
```

(La clave se llama `price_mxn` por motivos históricos del proyecto — el valor real está en la moneda que devuelve `/config`, `EUR` en este caso, no en pesos mexicanos.)

```javascript
async function getGrid(lang = 'es') {
  const tours = await fetch(`https://visitsicilyexperiences.com/wp-json/amir/v1/tours?lang=${lang}`).then(r => r.json());

  // Un fetch de precio por tarjeta, en paralelo — cacheado 10 min server-side
  const withPrices = await Promise.all(tours.map(async (t) => {
    const { prices } = await fetch(`https://visitsicilyexperiences.com/wp-json/amir/v1/prices?tour_id=${t.id}`).then(r => r.json());
    const validPrices = prices.filter(p => p.price_mxn > 0).map(p => p.price_mxn);
    return { ...t, priceFrom: validPrices.length ? Math.min(...validPrices) : null };
  }));

  return withPrices;
}
```

**Diseño de la tarjeta** — con los campos de arriba alcanza para: imagen de portada (`cover_image`), nombre, resumen corto, precio "desde" (calculado como arriba), duración (`duration_minutes`, formatealo a "5 h" del lado del cliente), badge de idiomas disponibles (`languages`). Si el tour tiene edad mínima (`min_age > 0`), es un buen dato para mostrar como chip ("+8 años").

---

## Caso 2 — Mostrar el detalle de un tour

```
GET /tours/12?lang=es
```

```json
{
  "id": 12, "slug": "tour-etna-atardecer", "price_model": "percapita",
  "name": "Etna al atardecer",
  "description": "Descripción larga completa del tour...",
  "what_to_expect": "Qué esperar del día...",
  "highlights": ["Vista panorámica desde 2900m", "Guía volcanólogo certificado", "Transporte 4x4 incluido"],
  "itinerary": "",
  "itinerary_stops": [
    { "title": "Punto de encuentro — Catania", "desc": "Salida en vehículo 4x4 desde el centro", "image_url": "", "is_start": true },
    { "title": "Refugio Sapienza", "desc": "Ascenso guiado hasta el cráter", "image_url": "", "is_start": false }
  ],
  "detail_facts": [
    { "icon": "🗣️", "label": "Idioma", "value": "Español, Inglés" },
    { "icon": "👥", "label": "Grupo", "value": "Máx. 12 personas" },
    { "icon": "🥾", "label": "Nivel", "value": "Moderado" }
  ],
  "meeting_point": "Plaza del Duomo, Catania", "meeting_lat": 37.5024, "meeting_lng": 15.0873,
  "includes": ["Transporte 4x4", "Guía certificado", "Equipo de frío"],
  "excludes": ["Comidas", "Propinas"],
  "duration_minutes": 300, "min_age": 8,
  "allow_children": true, "allow_babies": false, "min_age_child": 8,
  "max_capacity": 12, "min_passengers": 1,
  "languages": ["Español", "Inglés"],
  "gallery_images": ["https://.../etna-01.jpg", "https://.../etna-02.jpg"],
  "schedules": [{ "id": 3, "time_start": "15:00:00", "time_end": "20:00:00", "label": "Salida tarde" }],
  "prices": [{ "person_type": "adult", "group_min": null, "group_max": null, "price_mxn": 85.00, "schedule_id": null }],
  "addons": [{ "id": 5, "name": "Fotos profesionales del tour", "price_mxn": 25.00, "pricing_type": "per_unit" }]
}
```

```javascript
async function getTourDetail(id, lang = 'es') {
  const res = await fetch(`https://visitsicilyexperiences.com/wp-json/amir/v1/tours/${id}?lang=${lang}`);
  if (!res.ok) throw new Error('Tour no encontrado');
  return res.json();
}
```

**Mapeo sugerido de secciones de la página** (mismo orden que usan las dos plantillas de WordPress del plugin — ya validado contra GetYourGuide/Viator):

1. **Hero**: `gallery_images[0]`, `name`, precio desde (mismo cálculo que en la grilla).
2. **Highlights**: `highlights` — bullets cortos arriba de la descripción larga.
3. **Descripción**: `description` (texto largo) + `what_to_expect`.
4. **Datos destacados**: `detail_facts` — grid de ícono+etiqueta+valor, si el array no viene vacío.
5. **Itinerario**: `itinerary_stops` — si viene vacío, el tour no tiene timeline cargado, omitir la sección entera (no mostrarla vacía). Si `itinerary` (texto libre, campo viejo) tiene contenido y `itinerary_stops` está vacío, usar ese como fallback de texto simple.
6. **Incluye / No incluye**: `includes` / `excludes`.
7. **Punto de encuentro**: `meeting_point` + mapa con `meeting_lat`/`meeting_lng` si no son `null`.
8. **Galería**: el resto de `gallery_images`.
9. **Widget de reserva**: acá arranca el Caso 3, usando `schedules`, `prices`, `addons` que ya vienen en esta misma respuesta — no hace falta pedirlos aparte si ya cargaste el detalle completo.

---

## Caso 3 — Proceso de reserva completo

Siete pasos, en orden. Los primeros dos (`schedules`/`prices`) ya los tenés si veniste del Caso 2 — se muestran acá aparte por si el flujo de tu web los pide en un momento distinto (ej. un buscador que no pasa por la ficha del tour primero).

### 3.1 Disponibilidad por mes (para pintar el calendario)

```
GET /availability/month?tour_id=12&year=2026&month=8
```

```json
{
  "2026-08-15": { "available": true, "slots": 6, "reason": "available" },
  "2026-08-16": { "available": false, "slots": 0, "reason": "blocked" }
}
```

### 3.2 Horarios disponibles ese día

```
GET /availability/day?tour_id=12&date=2026-08-15&lang=es
```

Devuelve los horarios de ese día con cupos restantes — alimenta el selector de horario después de elegir la fecha.

### 3.3 Cotizar (antes de crear la reserva)

Llamalo cada vez que cambie algo del formulario (personas, cupón, add-ons) — **nunca calcules el precio vos mismo del lado del cliente**, este endpoint es la única fuente de verdad.

```
POST /bookings/quote
Content-Type: application/json

{
  "tour_id": 12, "schedule_id": 3, "date": "2026-08-15",
  "adults": 2, "children": 1, "babies": 0,
  "coupon_code": "", "addons": [{ "id": 5, "qty": 1 }], "lang": "es"
}
```

```json
{
  "valid": true,
  "total_mxn": 220.00,
  "usd_reference": null,
  "breakdown": { "...": "detalle por tipo de persona/addon" },
  "model": "percapita",
  "coupon_code": "",
  "discount_mxn": 0,
  "coupon_error": null,
  "addons_mxn": 25.00
}
```

### 3.4 Crear la reserva (arranca el cobro)

```
POST /bookings
Content-Type: application/json

{
  "tour_id": 12, "schedule_id": 3, "date": "2026-08-15",
  "adults": 2, "children": 1, "babies": 0,
  "customer_name": "Anna Rossi", "customer_email": "anna@mail.com", "customer_phone": "+39 333...",
  "lang": "es", "coupon_code": "", "addons": [{ "id": 5, "qty": 1 }],
  "policy_accepted": true, "terms_accepted": true
}
```

Respuesta (201) — **la forma cambia según la pasarela activa en el sitio**:

```json
// Stripe (lo esperable para un sitio europeo)
{ "success": true, "booking_ref": "BK-2026-00042", "booking_id": 42, "total_mxn": 220.00, "gateway": "stripe", "client_secret": "pi_..._secret_..." }
```

`policy_accepted` y `terms_accepted` son dos checkboxes separados y obligatorios (política de cancelación y términos/GDPR) — el backend rechaza la reserva si falta cualquiera de los dos, aunque tu web los muestre como uno solo por error.

### 3.5 Completar el pago

Con `client_secret`, montá el `PaymentElement` de Stripe.js (`@stripe/react-stripe-js` si es React) — es un `PaymentIntent` estándar, cualquier integración normal de Stripe Elements sirve tal cual.

```javascript
// Ejemplo con @stripe/react-stripe-js
import { PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';

async function pay(stripe, elements) {
  const { error } = await stripe.confirmPayment({
    elements,
    confirmParams: { return_url: 'https://visitsicilyexperiences.com/confirmacion' },
  });
  if (error) throw error;
}
```

### 3.6 Confirmar contra el backend

Después de que Stripe confirma el pago, **verificá siempre contra el backend** — nunca asumas que "Stripe dijo que sí" es suficiente sin este paso.

```
POST /bookings/42/confirm-payment
{ "payment_intent_id": "pi_..." }
```

```json
{ "confirmed": true, "booking_ref": "BK-2026-00042" }
```

### 3.7 Pantalla de confirmación

```
GET /bookings/BK-2026-00042?token=...
```

```json
{
  "booking_ref": "BK-2026-00042", "status": "confirmed", "tour_date": "2026-08-15",
  "customer_name": "Anna Rossi", "adults": 2, "children": 1, "babies": 0,
  "total_mxn": 220.00, "qr_code_path": "..."
}
```

El `access_token` (te llega implícito en el flujo, no hace falta pedirlo aparte) es lo que hay que guardar en el cliente (`localStorage` en web) si el visitante quiere volver a ver esta reserva después — no hay login de usuario en el sistema, cada reserva es su propia credencial.

---

## Seguridad — no negociable

- Nunca guardes ninguna secret key (Stripe/Mercado Pago) en el frontend — solo la `stripePk` pública que devuelve `/config`.
- Todo endpoint público tiene rate limiting por IP (`429` si te pasás) — mostrá un mensaje razonable, no reintentes en loop.
- `booking_ref` es secuencial y adivinable (`BK-2026-00042`) — nunca lo uses solo para identificar a un cliente, siempre junto con `access_token` o `email`.

---

## Referencia completa

Esto cubre los tres casos pedidos (grilla, detalle, reserva) con lo mínimo necesario para diseñar la web. Para el resto de la superficie de la API (cancelaciones, voucher PDF, lista de interés, seguridad en detalle) ver [GUIA-INTEGRACION-API.md](GUIA-INTEGRACION-API.md).
