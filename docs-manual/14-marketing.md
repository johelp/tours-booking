[← Volver al índice](../MANUAL.md)

# 14. Marketing

## Meta Pixel, Google Ads y GA4

En **TourFlow → Configuración → 📣 Marketing** podés cargar, todos opcionales e independientes entre sí:

| Campo | Para qué sirve |
|---|---|
| **Meta Pixel ID** | Conecta tu sitio con el Administrador de Anuncios de Meta (Facebook/Instagram) para medir campañas y armar públicos de remarketing. |
| **Google Ads — Conversion ID** y **Conversion Label** | Miden conversiones de tus campañas de Google Ads (cuánta gente que vio un anuncio terminó reservando). |
| **GA4 — Measurement ID** | Conecta tu sitio con Google Analytics 4, independiente de Google Ads — cargalo si además de medir campañas querés ver todo el tráfico del sitio en Analytics. |

**Si no cargás ningún ID, el plugin no agrega ningún script externo al sitio** — cero impacto en la velocidad de carga hasta que decidas usarlo. Apenas cargás uno solo, el script correspondiente se activa en **todas las páginas del sitio** (no solo en la ficha de un tour) — es necesario para que el remarketing y los públicos de estas plataformas funcionen bien.

### Qué eventos se miden automáticamente

| Momento | A Meta le llega | A Google le llega |
|---|---|---|
| Alguien abre la ficha de un tour | Vista de contenido | Vista de producto |
| El cliente arranca el proceso de reserva en el widget | Inicio de checkout | Inicio de compra |
| La reserva queda confirmada | Compra (con el monto real cobrado) | Compra en GA4 **+** conversión de Google Ads (si cargaste Conversion ID y Label) |

El evento de "reserva confirmada" está protegido para no contarse dos veces por la misma reserva, aunque la pantalla de confirmación se vuelva a dibujar en el navegador del cliente.

### Qué NO mide todavía (importante saberlo)

- **Las reservas que vienen de la lista de interés** (cuando alguien se anota para un tour en borrador y después completa el pago con el link que le llega por email) **no generan ningún evento de marketing** — ni de inicio de pago ni de compra confirmada. Lo mismo aplica a cualquier reserva que vos cargues manualmente desde **Reservas → + Nueva reserva** con la opción de "enviarle un link de pago": esas conversiones no van a quedar registradas en Meta Ads, Google Ads ni GA4.
- El evento de "vista de tour" solo se dispara en la ficha individual de cada tour, no en el catálogo general.
- No hay integración con TikTok, Pinterest u otras plataformas — solo Meta, Google Ads y GA4.

## Descuento automático por link (`?coupon=CODE`)

Ver capítulo [7. Cupones](07-cupones.md#compartir-un-link-con-descuento-automático-sin-que-el-cliente-escriba-el-código) — te permite compartir un link de un tour que precarga un cupón ya creado, sin que el cliente tenga que escribirlo.

## Datos estructurados para buscadores (SEO)

TourFlow genera **automáticamente**, sin que tengas que activar ni configurar nada, un bloque de datos técnicos (llamado "schema" o "datos estructurados") en cada ficha de tour, pensado para que Google y buscadores con inteligencia artificial (ChatGPT, Perplexity, Google AI Overview) entiendan mejor de qué se trata la página — y potencialmente muestren resultados más ricos (precio, duración, disponibilidad) directamente en los resultados de búsqueda.

No hay ningún botón de "activar SEO": el sistema arma este bloque a partir de los mismos datos que ya cargaste al crear el tour (nombre, descripción, fotos, duración, idiomas, edad mínima, punto de encuentro con coordenadas, precio desde, moneda y disponibilidad de stock). Si algún dato no está cargado, simplemente se omite esa parte del bloque — no rompe nada, solo queda menos completo.

Esto **no cubre** reseñas estructuradas, preguntas frecuentes (FAQ) ni migas de pan — el alcance de hoy es específicamente la ficha del tour como producto reservable.
