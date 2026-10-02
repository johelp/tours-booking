# Prompt — Diseñar en Figma el nuevo frontend de Caliafarm (headless sobre TourFlow)

**Estado**: spec/prompt para diseño, sin construir. Pensado para pegar en Figma (Figma Make / First Draft, o como brief para un diseñador) y, una vez aprobado el diseño, pasarlo a un desarrollador que lo implemente como frontend headless.

**Contexto de negocio**: reemplazar el frontend WordPress actual de `caliafarm.com` por un sitio nuevo, más rápido y ágil, **manteniendo WordPress + TourFlow (Pro Max) como backend** — el catálogo de tours, la disponibilidad, el motor de precios/cupones y el checkout siguen viviendo ahí, consumidos por REST API pública. No es un rediseño de marca: se conserva la información y los enlaces actuales del home (dos experiencias principales, tienda Shopify, contacto, formulario de grupos), solo cambia cómo se renderiza.

**Hosting decidido**: **Cloudflare Pages**. Esto condiciona el stack técnico que el diseño debe poder soportar (ver § 5).

> ✅ Nota de esta sesión: se consultó en vivo la API pública y las páginas de WordPress del sandbox (`https://caliafarm.com/stag/`) — la mayoría de los `[TODO]` de la primera versión de este documento ya se resolvieron con contenido real (ver cada sección). Sigue siendo un **sandbox de pruebas**, no necesariamente el dominio de producción final — confirmar con el cliente si `caliafarm.com/stag/` es el contenido a migrar tal cual, o si hay diferencias con lo que ve el público hoy en `caliafarm.com` (raíz).



---

## 1. Alcance — qué páginas diseñar

Un frontend headless que reemplaza **solo el sitio público** (igual alcance que `SPEC-HEADLESS.md`, ya evaluado para este plugin). `wp-admin` no se toca — Dashboard, Reservas, Modo campo, Configuración, etc. siguen siendo WordPress tal cual.

| # | Página | Contenido | Fuente de datos |
|---|--------|-----------|------------------|
| 1 | **Home** | Hero, las 2 experiencias principales, sección Tienda (Shopify), resumen "Sobre nosotros", Contacto, Formulario de grupos, footer con los mismos links que hoy | TourFlow API + Shopify (externo) + contenido estático |
| 2 | **Catálogo de experiencias** | Grid de todos los tours activos, con filtro por categoría | `GET /amir/v1/tours`, `GET /amir/v1/tour-categories` |
| 3 | **Detalle de experiencia** | Galería, descripción, itinerario, incluye/no incluye, mapa, FAQ, calendario de disponibilidad, y el flujo de reserva/checkout completo | `GET /amir/v1/tours/{id}`, `/schedules`, `/prices`, `/availability/month|day`, `POST /bookings/quote`, `POST /bookings` |
| 4 | **Sobre nosotros** | Versión completa de lo que hoy vive en la página "About" de WordPress | Contenido estático (copiar del sitio actual) |
| 5 | **Contacto** | WhatsApp, email, ubicación/mapa, redes sociales | `GET /amir/v1/config` (`waPhone`, `companyName`) + contenido estático |
| 6 | **Grupos** | El mismo formulario de reservas de grupo que existe hoy (ver § 4 — no es parte de la API de TourFlow, hay que decidir cómo se preserva) | Externo / a definir |
| 7 | **Confirmación de reserva** | Pantalla post-pago (éxito) y pantalla de reserva ya existente (`/mi-reserva?ref=...`) | `GET /amir/v1/bookings/{ref}?token=...`, `GET .../pdf` |

Diseñar cada página en **desktop (1440px) y mobile (390px)** como mínimo — el pedido explícito es que la experiencia sea "más rápida, ágil y óptima que con WordPress", así que mobile-first en la ejecución visual (jerarquía clara, CTAs grandes, sin bloqueos de layout tipo el que ya se corrigió una vez en el widget embebido por `@media` vs. `@container`, ver `CONTRIBUTING.md § 16.36`).

---

## 2. Home — estructura sección por sección

### 2.1 Header / navegación
Confirmado navegando el sandbox (`wp/v2/pages` + home real): **Home · Tours and Experiences · Accommodations (Suite Search) · Sicilia Mia Retreat · About Us · Private Group Experiences · Contact**, más un CTA de reserva (`Booking`). Selector de idioma ES/EN (`activeLanguages` en `/config`, aunque el copy real observado está mayormente en inglés — confirmar con el cliente si el sitio nuevo debe arrancar en inglés por default, dado el público). Sticky en scroll, colapsa a menú hamburguesa en mobile.

### 2.2 Hero
Título/tagline real de la página actual: **"Caliafarm — Sicily as We Live It. Sicily as You'll Remember It."** Usar como base del hero (titular + subtítulo), con foto/video de portada real de la granja (hay abundante material fotográfico ya cargado en las galerías de tours, ej. `caliafarm2026-*.jpeg`) + CTA principal ("Ver experiencias" / "Book Your Stay").

### 2.3 Las dos experiencias principales
Confirmado en vivo vía `GET /wp-json/flow/v1/tours/featured?limit=2&lang=es` contra el sandbox — hoy las dos experiencias destacadas son:

1. **"Full Experience: Cooking Dinner & Wine Tasting"** — 240 min (4h), desde €150, modelo per cápita. "Your Full Experience at Caliafarm begins with a warm welcome from Franco, who will personally take you on a journey through…"
2. **"Cooking and Dinner Experience"** — 320 min (~5h20), desde €150, modelo per cápita. "First, Franco will personally escort you through his garden, unveiling the origins of the vegetables that will later grace…"

(Existe una tercera experiencia en el catálogo, no destacada hoy: **"Fico d'India Romantic Experience for Two"** — modelo `group`, 24h de duración, solo +18, con contraparte también vendida como producto en la tienda Shopify — ver § 2.4. Vale la pena confirmar con el cliente si debería ser la tercera destacada o si se mantiene solo en el catálogo general.)

Diseñar las dos tarjetas grandes con foto, nombre, descripción corta, precio "desde €150", botón "Ver y reservar" — alimentadas en vivo por el endpoint de arriba, no hardcodeadas, para que si el operador cambia precio/foto/disponibilidad en TourFlow, el home se actualice solo. Diseñar también el estado de carga (skeleton) y el estado sin resultado.

### 2.4 Tienda — confirmado: Shopify externo en `casacalia.com`
Se confirmó en vivo (links reales en el home del sandbox) que la tienda **no vive en `caliafarm.com`** — es una tienda Shopify aparte, bajo la marca **"Casa Calia"**, dominio `casacalia.com` (ej. `casacalia.com/collections/sicily-guides-and-books`, y un producto que es la versión "para llevar" de la experiencia romántica Fico d'India de arriba). Hoy el sitio actual simplemente **linkea afuera**, sin ningún embed — confirma la opción 1 de las tres evaluadas originalmente en este documento (la más simple). Recomendación: mantenerlo así en el sitio nuevo (sección "Tienda" en el home con 3-4 productos destacados + botón "Ver tienda completa" → `casacalia.com`) salvo que el cliente pida explícitamente subir de nivel a un embed (Buy Button) o a la Storefront API — no hay evidencia de que hoy se necesite más que un link.

### 2.5 Sobre nosotros (resumen)
Copy real de la página `About Us` del sandbox: **"Calia Farm is an open door to Sicily — experienced naturally, as it comes."**, con una segunda línea: *"Calia Farm is about living Sicily, not just visiting it."* y una declaración de misión ("Our mission is to create real moments in Sicily…"). Es un negocio familiar — la página completa presenta al equipo con nombre y bio propia: **Nicolas Calia, Franco Calia, Giuseppe Calia, Franca Drago, Giulia Armata** (cada uno con su propia página `/about-us/{nombre}/`). Para el resumen del home, usar el primer párrafo + foto + link "Conocer más" a la página completa (§ mostrar el equipo ahí, no en el home).

### 2.6 Formulario de grupos
Ver § 4 — mantener tal cual funciona hoy. Página real de referencia: `Private Group Experiences at Caliafarm` (ver campos exactos confirmados en § 4).

### 2.7 Contacto
Datos reales confirmados: email `info@caliafarm.com`, Instagram `instagram.com/caliafarm`. **No hay WhatsApp configurado** en este sandbox (`GET /amir/v1/config` → `waPhone` viene vacío) — aunque el formulario de contacto real sí pide "Telephone / WhatsApp" como dato del cliente (no como canal de contacto saliente); confirmar con el cliente si quiere sumar un WhatsApp de contacto real o si prefiere seguir solo con el formulario + email. Ubicación/mapa: `[TODO: no se encontró dirección física exacta en las páginas revisadas, confirmar con el cliente]`.

### 2.8 Footer
Replicar la misma estructura observada: links a Tours and Experiences, Accommodations, About Us, Private Group Experiences, Contact, ícono de Instagram, link a Terms of Service & Privacy Policy (página real: `terms-of-service-privacy-policy`), y el link de la tienda (`casacalia.com`).

---

## 3. Página de experiencia (catálogo + detalle) — reusar el flujo ya construido

El flujo de reserva/checkout **no hay que rediseñarlo desde cero** — ya existe como componente React funcional y probado (`react-src/src/BookingWidget.jsx` y, si aplica Pro Max, `DiscoveryFlow.jsx`/`ExploreFlow.jsx`) que habla 100% por HTTP contra `/wp-json/amir/v1/*` sin sesión de WordPress (confirmado en `SPEC-HEADLESS.md` § 1). La recomendación es:

- **En Figma**: diseñar el detalle de experiencia (galería, descripción, itinerario, mapa, FAQ) con la misma identidad visual del resto del sitio nuevo, y dejar un contenedor claro para el "panel de reserva" (calendario + personas + resumen + pago) — no hace falta diseñar cada pantalla del checkout pixel a pixel, ya existe una versión funcional que se puede portar/reestilizar.
- **En desarrollo**: portar ese componente (cambiar cómo se monta — de `createRoot` sobre un `data-*` de WordPress a un componente normal de la app nueva — y resolver `/config` por `fetch()` en vez de `window.amirBooking`), tal como ya lo describe `SPEC-HEADLESS.md § 4`.

Endpoints relevantes (ver `GUIA-INTEGRACION-API.md § 3` para el detalle completo con payloads reales):
```
GET  /amir/v1/tours?lang=es                     Catálogo
GET  /amir/v1/tour-categories                   Filtro por categoría
GET  /amir/v1/tours/{id}?lang=es                Detalle completo
GET  /amir/v1/availability/month|day            Calendario
POST /amir/v1/bookings/quote                    Cotización en vivo
POST /amir/v1/bookings                          Crear reserva + iniciar pago
POST /amir/v1/bookings/{id}/confirm-payment     Confirmación verificada
GET  /amir/v1/bookings/{ref}?token=...          Pantalla de confirmación / "mi reserva"
```

### 3.1 Catálogo de experiencias — estructura

- Header con título de sección + filtro por categoría (chips o `<select>`, alimentado por `GET /amir/v1/tour-categories` — solo devuelve categorías con al menos un tour activo).
- Grid de tarjetas (2-3 columnas desktop, 1 columna mobile): foto de portada (`cover_image`), nombre, `short_description` (120 caracteres, ya viene recortada por la API), duración (`duration_minutes`), precio "desde" (`GET /prices?tour_id=`), botón "Ver detalle".
- Skeleton de carga con la misma forma de la tarjeta real (mismo criterio ya aplicado en `TourList.jsx`/`CardSkeleton` — evita el salto visual cuando llegan los datos).
- Estado vacío si el filtro no devuelve nada.

### 3.2 Detalle de experiencia — estructura real a replicar

Esta es la sección más rica de contenido de todo el sitio nuevo — la API (`GET /amir/v1/tours/{id}`) ya devuelve todos los campos necesarios; el detalle de cada uno abajo es literal de lo que hoy arma `templates/single-amir_tour.php`, para que el diseño en Figma no tenga que inventar la estructura de cero:

1. **Header/hero de la experiencia**: título, ubicación corta, precio "desde" destacado (`$X {moneda} — Desde`), botón "Reservar ahora" (ancla al panel de reserva).
2. **Galería con lightbox**: `gallery_images[]` (array de fotos) + `video_url` si tiene (YouTube/Vimeo, se integra como primer ítem de la galería con miniatura liviana — nada de YouTube/Vimeo carga hasta el click, ver `CONTRIBUTING.md § 16.47`).
3. **Datos destacados** (`detail_facts[]`): fila de íconos con label/valor configurables por el operador — ej. 🗣️ Idioma: Español/Inglés, ⏱️ Duración: 3h, 👥 Grupo máximo: 10. Viene vacío si el tour no cargó ninguno.
4. **Highlights** (`highlights[]`): lista corta con check ✓, 2 columnas en desktop — "lo más destacado" en formato bullet, no el itinerario completo.
5. **Descripción larga** (`description`) + **qué esperar** (`what_to_expect`).
6. **Itinerario tipo timeline** (`itinerary_stops[]`): cada parada con título, descripción, foto opcional, y si es el punto de inicio (📍). Se muestra como acordeón (`<details>`) — en el sitio actual todas abren por default con un botón "Colapsar todo". Si el tour no cargó itinerario nuevo, puede tener el campo viejo de texto libre (`itinerary`) como fallback.
7. **Incluye / No incluye** (`includes[]` / `excludes[]`): dos columnas lado a lado.
8. **Punto de encuentro** (`meeting_point` + `meeting_lat`/`meeting_lng`): texto + mapa embebido (o un link "Ver en Google Maps" si no se quiere embeber un mapa interactivo).
9. **FAQ por experiencia** (`faq_items[]`, v5.9.0): acordeón pregunta/respuesta, opcional — viene vacío si el operador no cargó ninguna.
10. **Panel de reserva**: el componente reusado de § 3 arriba (calendario → horario → personas → extras → resumen → pago). En desktop suele ir como columna fija al costado (sticky) en vez de al final de la página — mismo patrón que ya usa la plantilla actual.
11. **"También te puede interesar"**: 2-3 tours sugeridos al pie (`templates/parts/tour-suggested.php` hoy) — se puede armar en el cliente con `GET /amir/v1/tours` filtrado por la misma categoría, excluyendo el tour actual.
12. **Nota**: algunos tours son casos de borde con presentación distinta (fecha fija sin calendario, "a cotizar" sin precio fijo, solo bajo pedido) — si Caliafarm tiene alguno de estos (confirmar), el panel de reserva ya resuelve la lógica, solo hay que dejar en el diseño un estado alternativo para "Solicitar fecha" / "A cotizar" en vez de "Reservar ahora".

Existen **dos plantillas visuales** para esta página en el plugin actual — Clásica e Inmersiva (`templates/single-amir_tour-immersive.php`, más foto/hero a pantalla completa) — vale la pena mirar cuál usa Caliafarm hoy como punto de partida de la jerarquía visual, aunque el diseño nuevo no tiene por qué limitarse a ninguna de las dos.

---

## 4. Formulario de grupos — qué preservar

No existe ningún endpoint de "grupos" en la API de TourFlow (se confirmó revisando el código) — este formulario vive hoy en WordPress como una pieza aparte (probablemente un plugin de formularios tipo Contact Form 7/Gravity Forms — el HTML no expone cuál exactamente, pero el patrón de marcado es consistente con esos plugins, no un desarrollo custom).

**Página real confirmada**: `Private Group Experiences at Caliafarm` (`caliafarm.com/stag/private-group-experiences-at-caliafarm/`). Copy real: *"Looking for a unique group activity or planning a special occasion? Caliafarm now offers exclusive private bookings for groups of 6 or more."* + un párrafo mencionando explícitamente las 3 experiencias ofrecibles en grupo (Cooking & Dinner Experience, Sicilian Winetasting & Sunset Experience, Full Experience).

**Campos reales confirmados del formulario**:
- Name
- Email
- Telephone
- Group Size
- Preferred Date
- Alternative Date
- Additional Requests/Questions
- botón **Send**

(El formulario de Contacto, § 2.7, es más simple — Name, Email, Telephone/WhatsApp, Message — no confundir los dos, son páginas y formularios distintos.)

Al pasar a headless, dos caminos:
1. **Mantenerlo tal cual, embebido**: seguir usando vía `<iframe>` a la página de WordPress actual, o simplemente linkeando ahí — cero trabajo nuevo, pero rompe la cohesión visual del sitio nuevo.
2. **Rehacerlo en el frontend nuevo**: mismo formulario, mismos 7 campos de arriba, como componente propio del sitio nuevo, que manda los datos al mismo destino de siempre (`[TODO: confirmar con el cliente/administrador de WordPress a dónde llegan hoy estos envíos — email fijo, CRM, planilla]` — si es un email fijo, alcanza con una Cloudflare Pages Function/Worker que lo reenvíe, sin tocar WordPress para nada).

Diseñar el formulario asumiendo la opción 2 (mejor UX, consistente con el resto del sitio), con los 7 campos reales de arriba — el campo "experiencia de interés" no está en el formulario actual, así que no inventarlo salvo que el cliente lo pida explícitamente.

---

## 5. Restricciones técnicas a respetar en el diseño (por el hosting en Cloudflare Pages)

- **Stack recomendado para el desarrollo posterior**: Astro con islands de React (o Next.js con `@cloudflare/next-on-pages`) — Astro tiene soporte nativo de primera clase para Cloudflare Pages y renderiza la mayoría del sitio como HTML estático puro (rapidísimo, ideal para Home/Sobre nosotros/Contacto), hidratando como "isla" interactiva solo lo que necesita JS real (el panel de reserva/checkout, el formulario de grupos, el buscador de experiencias). Esto no cambia nada del diseño en Figma, pero sí implica: **evitar depender de que TODO el layout necesite JavaScript para verse** — el diseño debe funcionar bien como HTML+CSS puro primero, con interactividad como capa encima.
- **CORS**: antes de que el frontend nuevo pueda crear reservas o cotizar, hay que cargar su dominio en **TourFlow → Configuración → 🌐 CORS (frontend externo)** en el WordPress de `caliafarm.com` (`amir_cors_allowed_origins`). Cloudflare Pages da un dominio fijo de producción (`*.pages.dev` o el dominio propio) más un subdominio distinto por cada preview de rama — para no bloquear los previews, cargar el dominio de producción customizado (`caliafarm.com` o el que se use) y, si hace falta probar checkouts desde un preview, agregar también ese `*.pages.dev` puntual.
- **Sin Elementor**: el sitio público actual puede tener páginas armadas visualmente en Elementor (confirmar `[TODO]`) — al pasar a headless eso se pierde como editor sin código; cualquier cambio de layout futuro pasa a requerir un push de código al repo del frontend. Vale la pena que el cliente lo sepa antes de aprobar el diseño final (ya está documentado como el único trade-off real en `SPEC-HEADLESS.md § 3.5`).
- **SEO**: WordPress hoy da metadata/sitemap gratis vía el theme o un plugin de SEO. El frontend nuevo tiene que resolver esto por su cuenta (Astro/Next.js lo hacen bien con generación estática, pero es trabajo a construir, no viene solo) — el diseño debe contemplar título, meta description y al menos una imagen Open Graph por página de experiencia.
- **Idiomas**: `content_i18n` ya resuelve el contenido por idioma server-side (`?lang=es|en`) — el frontend nuevo solo decide su propio ruteo (ej. `/en/experiencias/...`) y pasa el parámetro, sin lógica de traducción propia que construir.

---

## 6. Identidad visual

Confirmado en vivo contra el sandbox (`GET /amir/v1/config` → `theme`) — estos son los valores **reales** configurados hoy, no un placeholder:

| Token | Valor |
|---|---|
| Color primario | `#01248e` (azul profundo, coherente con "azul moderno") |
| Color oscuro | `#001963` |
| Color claro | `#d8deee` |
| Color medio | `#99a7d1` |
| Tipografía | **Lato** (`fontKey: "lato"`) |
| Radio de esquina | `12px` (chico: `8px`) |
| Moneda activa | **EUR** |
| Nombre de marca | `Caliafarm` |

Usar esta paleta y tipografía **tal cual** como base en Figma (no la sugerencia genérica de § 10.4 — esa era una suposición antes de tener el dato real; con `#01248e`/Lato confirmados, reconciliar el acento de CTA de § 10.4 contra este azul más oscuro en vez del `#1E3A8A` propuesto ahí). Libertad de ampliar con escala de grises y estados hover/error/success, ya que un sitio completo necesita más que las variables del widget de reserva.

---

## 7. Entregable esperado de Figma

- Un archivo Figma con las 7 páginas de § 1, cada una en desktop (1440) y mobile (390), con un sistema de componentes reusable (botones, tarjetas de experiencia, inputs de formulario, header/footer) — no pantallas sueltas sin relación.
- Estados de carga (skeleton) y vacío/error para las secciones que dependen de la API (experiencias destacadas, catálogo, disponibilidad).
- Anotaciones de qué endpoint alimenta cada bloque dinámico (para que quien lo desarrolle no tenga que releer esta spec pieza por pieza).
- Los `[TODO]` de este documento resueltos con contenido/copy real del sitio actual antes de considerarse listo para pasar a desarrollo.

## 8. Preparar el WordPress actual para uso headless — checklist previo

Una vez que el diseño esté aprobado y arranque el desarrollo, el WordPress de `caliafarm.com` necesita estos ajustes en el backend **antes** de que el frontend nuevo pueda funcionar de punta a punta (nada de esto rompe el sitio WordPress actual — son adiciones, no reemplazos, mismo criterio que `SPEC-HEADLESS.md § 4`):

- [ ] **CORS**: cargar el dominio de producción del frontend nuevo (y el `*.pages.dev` de preview si hace falta probar checkouts ahí) en **TourFlow → Configuración → 🌐 CORS (frontend externo)**. Sin esto, cualquier `POST` (cotizar, crear reserva, confirmar pago) queda bloqueado por el navegador con error de preflight — es el ítem más fácil de subestimar y el más bloqueante.
- [ ] **Endpoint de schema.org por tour** (gap real, documentado en `SPEC-HEADLESS.md § 3.3`): hoy `Core\StructuredData::tour_schema()` arma el JSON-LD `TouristTrip` **solo dentro de `templates/single-amir_tour.php`**, en el momento en que WordPress renderiza la página — no sirve de nada para un frontend headless que arma su propio HTML. Hay que agregar `GET /wp-json/amir/v1/tours/{id}/schema` que devuelva ese mismo JSON-LD ya armado (reusa la función existente server-side, cero lógica nueva) para que el frontend lo inyecte en un `<script type="application/ld+json">`. Necesario para § 9 de abajo.
- [x] **Edición activa confirmada en vivo**: `GET /amir/v1/config` en el sandbox devuelve `"edition": "pro_max"` — el frontend puede usar sin problema `flow/v1/tours/featured`, habitaciones y carrito multi-ítem.
- [ ] **Verificar `GET /amir/v1/config`** trae los datos reales que el sitio nuevo necesita (`waPhone`, `companyName`, `theme.*`, `activeLanguages`) — hoy están pensados para el widget, pero sirven igual como fuente de verdad para el resto del sitio (footer, contacto, paleta).
- [ ] **Definir qué pasa con las URLs viejas**: si `caliafarm.com/tours/algun-tour` hoy es una URL de WordPress indexada en Google, el corte a headless necesita decidir redirects 301 (viejo slug → nueva URL equivalente) para no perder el posicionamiento ya ganado — esto se resuelve en Cloudflare Pages (`_redirects`) o en el propio WordPress si el dominio sigue apuntando ahí para otras rutas.
- [ ] **Decidir qué sigue sirviendo WordPress directo**: `/wp-json/*` (API) y `/wp-admin/*` (panel) siguen respondiendo desde WordPress sin cambios. Lo que hoy es el sitio público (home, tours, about, etc.) pasa a responder desde Cloudflare Pages — hay que decidir el reparto de DNS/subdominios (ej. `caliafarm.com` → Cloudflare Pages, `api.caliafarm.com` o el dominio actual con solo `/wp-json` → WordPress) antes del corte final.
- [ ] **Elementor** (§ 5): confirmar con el negocio si se usa hoy para algo más que la ficha de tour — si sí, avisar que esa capacidad de edición visual sin código se pierde al pasar a headless (`SPEC-HEADLESS.md § 3.5`).

## 9. SEO + preparado para IA (GEO — Generative Engine Optimization)

Pedido explícito: el sitio nuevo tiene que estar SEO-óptimo y preparado para que motores de búsqueda con IA (ChatGPT, Perplexity, Google AI Overview, Copilot, Claude) puedan citarlo — no solo rankear en Google clásico. Esto no es trabajo de Figma (no se diseña visualmente), pero **sí condiciona contenido y estructura** que el diseño debe dejar espacio para mostrar (ej. FAQ visible, texto real no solo imágenes, breadcrumbs). Documentado acá para que quede como parte de la spec de desarrollo.

### 9.1 Metaetiquetas por tipo de página

| Página | `<title>` | `meta description` | Open Graph / Twitter Card |
|---|---|---|---|
| Home | `Caliafarm — {propuesta de valor corta}` | 150-160 caracteres, con la propuesta de valor + ubicación | `og:type=website`, imagen 1200×630 del hero |
| Catálogo | `Experiencias en {ubicación} — Caliafarm` | Resumen del catálogo | `og:type=website` |
| Detalle de experiencia | `{nombre del tour} — Caliafarm` | Usar `short_description` de la API, recortada a 155 caracteres | `og:type=product` (o `website`), imagen = `cover_image`, precio en `product:price:amount`/`currency` si se usa `product` |
| Sobre nosotros | `Sobre Caliafarm — {gancho corto}` | Resumen de la propuesta/historia | `og:type=website` |
| Contacto | `Contacto — Caliafarm` | Cómo reservar/contactar | `og:type=website` |

Todas las páginas necesitan además: `<link rel="canonical">` (evita contenido duplicado, importante porque `?lang=` puede generar variantes de la misma URL), `hreflang="es"`/`hreflang="en"` recíprocos entre las versiones ES/EN de cada página (ya hay base para esto: `content_i18n` resuelve el contenido por idioma server-side), y viewport/charset estándar.

### 9.2 Schema.org (JSON-LD) por tipo de página — **schema de experiencias incluido**

- **Home**: `Organization` o `LocalBusiness`/`TouristAttraction` (nombre, logo, `sameAs` con las redes sociales, `address`/`geo` si aplica) — es la entidad raíz que Google y los motores de IA usan para saber "quién es Caliafarm".
- **Catálogo de experiencias**: `ItemList` con `TouristTrip` resumido por cada tour (o simplemente confiar en que cada detalle tiene su propio schema completo — un `ItemList` liviano en el catálogo ayuda igual a que se entienda la estructura del sitio).
- **Detalle de experiencia — el más importante**: `TouristTrip` (schema.org), **ya construido y probado en el backend** (`Core\StructuredData::tour_schema()`, con tests) — incluye nombre, descripción, imágenes, duración (ISO 8601), idiomas, ubicación (`Place`/`GeoCoordinates`), y `Offer` con precio/moneda/disponibilidad. Con el endpoint nuevo de § 8 (`GET /tours/{id}/schema`), el frontend solo lo pide y lo inyecta — cero trabajo de armar el JSON-LD de nuevo. Sumar además `FAQPage` (a partir de `faq_items[]`, si el tour tiene) — la investigación de GEO indica que `FAQPage` schema por sí solo mejora notablemente la tasa de citación en motores de IA tipo Perplexity, y `BreadcrumbList` (Home > Experiencias > {nombre del tour}).
- **Tienda (Shopify)**: si se integra vía Storefront API (§ 2.4, opción 3), cada producto debería tener su propio `Product` schema (nombre, precio, disponibilidad, imagen) — si es solo un embed de Buy Button, Shopify ya lo resuelve del lado de ellos.
- **Sobre nosotros**: puede reforzar el `Organization` del home con más detalle (`founder`, `foundingDate` si aplica, `award`/`review` si hay reseñas reales).

### 9.3 Contenido — qué ayuda realmente a que un motor de IA cite el sitio

No es solo metadata — el contenido en sí importa más para GEO que para SEO clásico:
- **Formato "respuesta primero"**: en el detalle de experiencia y en el FAQ, la primera oración de cada respuesta debe contestar directo, no divagar (los motores de IA extraen esa primera frase como cita).
- **Datos concretos, no solo adjetivos**: duración exacta, capacidad máxima, precio, distancia/ubicación — números y hechos citables, no solo "una experiencia inolvidable".
- **Jerarquía clara de encabezados** (H1 único por página → H2 por sección → H3 por sub-ítem) — el diseño en Figma debe respetar esa jerarquía semántica, no solo el tamaño de fuente visual.
- Evitar que contenido clave (precio, duración, qué incluye) viva **solo** dentro de imágenes o de contenido que carga tarde vía JavaScript sin fallback — si el HTML inicial (SSG en Astro/Next.js) ya trae ese texto, tanto Google como los crawlers de IA lo leen sin ejecutar JS.

### 9.4 robots.txt, sitemap.xml y acceso de bots de IA

- **`robots.txt`**: permitir explícitamente, además de `Googlebot`/`Bingbot`, a los crawlers de IA: `GPTBot` (OpenAI), `ChatGPT-User`, `ClaudeBot`/`anthropic-ai` (Claude), `PerplexityBot`, `Google-Extended` (para AI Overview). Bloquear solo rutas que no aportan (ej. si hubiera un `/checkout` interno, o parámetros de tracking duplicados).
- **`sitemap.xml`**: generado por el frontend nuevo (Astro/Next.js lo resuelven en build o con un endpoint dinámico), listando home, catálogo, cada experiencia, about, contacto — referenciado desde `robots.txt`. Si el catálogo cambia seguido (nuevos tours, tours que se dan de baja), conviene regenerarlo en cada deploy o con un endpoint dinámico en vez de un archivo estático fijo.
- **`llms.txt`** (convención emergente, no un estándar oficial todavía, pero cada vez más adoptada): un archivo de texto plano en la raíz (`/llms.txt`) con un resumen curado del sitio en Markdown simple — qué es Caliafarm, qué experiencias ofrece, links a las páginas más importantes — pensado para que un LLM lo lea directo sin tener que rastrear todo el sitio. Barato de mantener, vale la pena sumarlo.

### 9.5 Rendimiento (afecta tanto a SEO clásico como a GEO)

Google usa Core Web Vitals como factor de ranking, y un sitio lento reduce cuánto puede rastrear un crawler en el tiempo asignado. Con Astro/Next.js + Cloudflare Pages (§ 5) el objetivo realista es LCP < 2.5s y CLS < 0.1 en mobile — bien por debajo de lo que da hoy WordPress con este stack (motivo original del pedido del cliente).

---

## 10. UX ágil, rápida y responsiva — microinteracciones y animación

Pedido explícito del cliente: la experiencia tiene que sentirse "súper ágil, fácil, rápida y responsiva", con microinteracciones y animaciones sutiles — no solo performance bruta (§ 5), también percepción de velocidad y feedback constante. Recomendaciones concretas para que Figma las diseñe explícitamente (no dejarlas libradas al desarrollo):

### 10.1 Dónde poner microinteracciones (catálogo → detalle → checkout)

- **Tarjetas de experiencia** (catálogo y home): hover con cambio sutil (elevación/sombra o zoom leve de la imagen, 150-300ms, `ease-out`) + `cursor: pointer` en toda la tarjeta, no solo en el botón — hoy mismo el plugin corrigió este bug real en `TourCard` (`CONTRIBUTING.md § 16.27`), no repetirlo en el sitio nuevo.
- **Botones**: estado hover, estado "presionado" (`active`, leve escala hacia abajo tipo 97-98%), y estado de carga (spinner + texto "Reservando…", deshabilitado mientras dura la acción) — bloquear doble-submit es crítico en un botón de pago, no es solo estética (ya es un patrón que el plugin tuvo que corregir en Modo Campo por el mismo motivo, `CONTRIBUTING.md § 16.91`).
- **Transición entre pasos del checkout** (calendario → personas → extras → resumen → pago): slide/fade sutil entre pasos (200-300ms) para reforzar "avancé", no un salto seco de pantalla — y el foco/scroll debe moverse solo al inicio del paso nuevo, mismo criterio que el auto-scroll ya construido en los 4 flujos de reserva actuales (`CONTRIBUTING.md § 16.93`, no reinventarlo, extenderlo).
- **Feedback al agregar algo** (extra al carrito, habitación, producto): micro-confirmación inmediata — el ítem "vuela" visualmente hacia el resumen del carrito, o el contador del carrito hace un pulso breve — sin abrir un modal bloqueante que corte el flujo.
- **Skeletons de carga** en vez de spinners genéricos para listas/tarjetas (catálogo, experiencias destacadas, calendario de disponibilidad) — misma forma que el contenido real, para que no haya salto visual al llegar los datos (patrón ya usado en `CardSkeleton`/`TourList.jsx`).
- **Validación de formularios en vivo**: error inline junto al campo (no un banner genérico arriba de todo), apenas el usuario sale del campo — nunca solo al enviar todo el formulario.
- **Selector de fecha/calendario**: al elegir un día, resaltado inmediato + los horarios disponibles aparecen con una transición breve (no un re-render brusco de toda la sección).

### 10.2 Principios de animación (para que quede "sutil", no recargado)

- **Duración**: 150-300ms para microinteracciones (hover, click, aparición de un elemento chico). Nunca más de 500ms para algo que bloquea la siguiente acción del usuario — una animación larga en el camino crítico (checkout) se siente lenta aunque técnicamamente no lo sea.
- **Easing**: `ease-out` para elementos que entran (se sienten más responsivos), `ease-in` para los que salen — nunca `linear`, se percibe robótico.
- **Qué SÍ animar**: aparición de contenido nuevo (fade/slide corto), cambios de estado (seleccionado/no seleccionado, hover, error), transiciones entre pasos, skeletons.
- **Qué NO animar**: nada en loop infinito salvo un indicador de carga real (un ícono con "bounce" decorativo constante es puro ruido) — y nunca animar el layout completo de la página en cada interacción chica (ej. rebotar todo el contenedor porque se agregó un extra al carrito).
- **`prefers-reduced-motion`**: todas las animaciones deben poder desactivarse/reducirse si el sistema operativo del usuario lo pide — no es opcional, es un requisito de accesibilidad real, y hay que dejarlo anotado en el hand-off a desarrollo aunque no sea visible en Figma.
- **Nunca animar a costa de la usabilidad del checkout**: el hallazgo del propio motor de diseño consultado para esta spec marca explícitamente "complex booking" (un checkout complicado/sobrecargado de efectos) como anti-patrón a evitar en este tipo de producto — la prioridad siempre es que reservar sea rápido y claro, la animación es un refuerzo, no el protagonista.

### 10.3 Qué más hace que un sitio "se sienta" rápido (más allá de la performance bruta)

- **Feedback inmediato a cada acción** (§ 10.1) — la percepción de velocidad depende más de que algo responda al instante (aunque el resultado final tarde un poco) que del tiempo real de carga.
- **Mobile-first de verdad**: diseñar primero la versión de 390px y expandir a desktop, no al revés — los touch targets mínimos son 44×44px (calendario, botones +/- de personas, chips de filtro), y el texto base nunca debajo de 16px en mobile (evita el zoom automático de iOS al enfocar un input).
- **Contenido crítico visible sin esperar JS**: con Astro/islands (§ 5), la mayor parte del sitio ya es HTML estático servido al instante — reservar la hidratación (JS real) solo para el panel de reserva, el carrito y el formulario de grupos, nunca para el contenido de lectura (descripción, galería, FAQ).
- **Sin saltos de layout** (CLS): reservar el espacio de imágenes/skeletons antes de que carguen (`width`/`height` o `aspect-ratio` explícitos), y usar `font-display: swap` con una fuente de respaldo similar para que el texto no "salte" cuando carga la tipografía definitiva.

### 10.4 Paleta/tipografía — reconciliada contra el dato real (§ 6)

La § 6 ya trae el valor real configurado hoy (`#01248e` primario, `Lato`, radio 12px). Como referencia adicional, un sistema de diseño evaluado para este tipo de producto (reserva de experiencias, con foco en microinteracciones) recomienda sumar un **color de acento distinto del azul** específicamente para el CTA de reservar — hoy el sitio no parece tener uno diferenciado (todo cae sobre el mismo azul `#01248e`), lo cual puede hacer que el botón de reservar no resalte lo suficiente sobre el resto de la UI. Sugerencia concreta para validar con el cliente: un acento cálido (ej. un naranja/terracota, coherente con la estética mediterránea/rústica de las fotos reales de la granja) reservado **únicamente** para el CTA principal ("Reservar ahora", "Agregar al carrito") — nunca para links secundarios ni decorativo, para que mantenga su fuerza de llamado a la acción.

Paleta final a usar en Figma:

| Rol | Valor |
|---|---|
| Primario | `#01248e` |
| Oscuro | `#001963` |
| Claro | `#d8deee` |
| Medio | `#99a7d1` |
| Acento CTA (a validar con el cliente) | un cálido a definir — ej. terracota/naranja quemado |
| Tipografía | **Lato** (real, confirmada) |
| Radio | `12px` / `8px` (chico) |

---

## 11. Qué datos mostrar y cuál es el flujo de reserva a implementar (addendum)

Dos precisiones importantes que corrigen/completan lo dicho en § 3 más arriba — leer esto antes de diseñar el panel de reserva y las tarjetas de experiencia.

### 11.1 Qué campos de la API mostrar en UI y cuáles no

La API devuelve algunos campos que son señal para el frontend (deciden qué hacer) pero **no deben mostrarse tal cual al usuario final**:

**Mostrar siempre** (son el contenido real): `name`, `short_description`/`description`, `cover_image`/`gallery_images`, `duration_minutes` (formateado, ej. "3 horas", nunca el número crudo de minutos), precio "desde", `highlights`, `itinerary_stops`, `includes`/`excludes`, `detail_facts`, `faq_items`, `meeting_point`, `languages`, `video_url`.

**Usar para decidir, no mostrar como texto**: `id`/`slug` (van en la URL, no en el copy visible), `price_model` (`"percapita"` vs `"group"` — decide qué selector de personas mostrar, nunca un texto tipo "Modelo: percapita"), `provider_id` (si no es `null`, sí conviene mostrar un badge sutil "Operado por {proveedor}" — pero el ID numérico en sí no), `min_passengers`/`max_capacity` (se usan para validar el selector de personas y mostrarlos como "Mínimo 2 personas" solo si aplica, no como campo crudo), `allow_children`/`allow_babies`/`min_age_child` (deciden si se muestran los contadores de niños/bebés, no se listan como texto), `skip_upsell`/`hide_from_lists`/`custom_quote`/`request_only`/`fixed_date` (son flags de comportamiento — cambian qué pantalla se muestra, ej. "A cotizar" en vez de precio, "Solicitar fecha" en vez de calendario — nunca se muestran como tal).

**No mostrar nunca (son de operación interna, no de cara al cliente)**: cualquier ID crudo fuera de la URL, `booking_source`, `payment_gateway`, `access_token`/`client_secret` (se usan para completar el pago, jamás se imprimen en pantalla), y ningún campo que empiece con `internal_`/`admin_` si aparece en alguna respuesta.

**`hide_from_lists`**: si un tour lo tiene activo (caso típico: las habitaciones/variantes de un retiro semanal como Sicilia Mia, pensadas para reservarse solo desde una landing propia, no desde el catálogo general — ver `CONTRIBUTING.md`, "Dudas de producto"), **no debe aparecer** en el catálogo general (§ 3.1) ni en "experiencias destacadas" del home (§ 2.3), aunque sí puede tener su propia página de detalle enlazada directo. Confirmar con el cliente qué tours de Caliafarm tienen este flag activo antes de armar el catálogo.

### 11.2 El flujo de reserva a implementar es el combinado (Discovery), no el widget clásico aislado

Corrección importante a § 3: no diseñar el panel de reserva como el widget clásico simple (un tour suelto, sin upsell). **El flujo que se va a usar es el "flujo continuo"/combinado de Pro Max** (`DiscoveryFlow.jsx` en el código actual, `CONTRIBUTING.md § 16.15`), porque es el que da la experiencia más práctica y la que más conversión genera: reservar un tour ofrece de forma natural sumar una habitación y/o extras en el mismo checkout, en vez de flujos aislados por tipo de producto.

Esto implica diseñar en Figma, además del detalle de experiencia:

- **Paso de personas/fecha** → cotización en vivo → **paso de upsell**: después de elegir la experiencia principal, ofrecer habitaciones disponibles para esas fechas (si Caliafarm tiene, ej. Sicilia Mia) y extras/productos globales (`GET /flow/v1/addons/global` — servicios y productos digitales que no pertenecen a ningún tour puntual) — cada paso se puede saltear si no aplica o si el usuario no quiere sumar nada.
- **Carrito multi-ítem persistente**: un resumen tipo barra lateral o footer sticky que acumula tour + habitación + extras, visible en todo momento (no un carrito escondido) — se arma 100% en el cliente, no se crea nada en el backend hasta pagar.
- **Un solo checkout combinado**: nombre/email/teléfono, términos, y un único pago que cubre todo el carrito junto (`POST /flow/v1/cart/checkout`), no un pago por ítem.
- **Confirmación única**: una sola pantalla de éxito con los `booking_ref` de todos los ítems del carrito y un solo voucher/QR combinado para descargar.

Endpoints específicos de este flujo (namespace `flow/v1`, exclusivo Pro Max — confirmar `edition` primero, ver § 8):
```
GET  /flow/v1/tours/featured                    Paso 1: experiencias destacadas con disponibilidad
GET  /flow/v1/tours/catalog-window               Alternativa: catálogo completo en una ventana de fechas
GET  /flow/v1/rooms/availability-batch           Habitaciones disponibles para esas fechas (si aplica)
GET  /flow/v1/addons/global                      Extras/productos para el paso de upsell
POST /flow/v1/cart/checkout                      Un solo pago para todo el carrito
POST /flow/v1/cart/{cart_group_id}/confirm-payment
```

El widget clásico de un solo tour (§ 3, `BookingWidget.jsx`) queda como referencia de qué campos/validaciones lleva cada paso individual, pero **no es la experiencia a construir en el frontend nuevo** — la experiencia a diseñar es la combinada, de punta a punta, con upsell y carrito.

---

## 12. Referencias en este repo

- `SPEC-HEADLESS.md` — spec completa de por qué y cómo pasar el sitio público a headless (arquitectura, gaps, fases, trade-offs).
- `GUIA-INTEGRACION-API.md` — todos los payloads reales de la API pública (`amir/v1` y `flow/v1`), con ejemplos.
- `CONTRIBUTING.md § 16` — detalle de Pro Max (habitaciones, carrito, flujo continuo) por si Caliafarm necesita mostrar también habitaciones/retiros (ej. Sicilia Mia) en este frontend nuevo.
