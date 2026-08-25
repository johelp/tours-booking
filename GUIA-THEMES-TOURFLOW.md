# Guía de temas y child themes compatibles con TourFlow

Documento técnico para quien desarrolla (o adapta) un tema o child theme de WordPress pensado para convivir con TourFlow — ya sea un desarrollador externo, o el propio equipo de Nomade Contenidos construyendo el child theme propio que está en proceso. Todo lo que describe acá está verificado contra el código real del plugin en esta rama (`fase1/seguridad-base-codigo2`), no es una guía genérica de WordPress — cada sección referencia el archivo real que la respalda.

> **Pensado a futuro**: la § 6 documenta puntos de extensión que **todavía no existen** en el plugin — son una propuesta para hacer el desarrollo de temas más fácil en una próxima versión. El resto del documento (§ 1-5) describe lo que **ya funciona hoy**.

## 1. Qué necesita un tema, en el nivel más básico

TourFlow no exige un tema especial para funcionar — cualquier tema estándar de WordPress que llame a `wp_head()`/`wp_footer()` y no interfiera con el layout ya sirve. Los tres puntos de contacto reales son:

1. **Los shortcodes** (`[flow_booking]`, `[flow_tour_list]`, etc.) se pegan en el contenido de cualquier página — no requieren nada del tema.
2. **Las páginas de detalle/archivo de tours y habitaciones** (`/tour/{slug}/`, `/tours/`, `/room/{slug}/`, `/rooms/`) usan el sistema de plantillas de WordPress — acá es donde un tema puede intervenir, ver § 2.
3. **El widget de reserva** (React) lee su paleta de color/tipografía de variables CSS que el plugin inyecta — un tema puede convivir con ellas sin tocarlas, ver § 4.

Nada de esto requiere que el tema "sepa" de TourFlow — un tema completamente genérico ya es compatible. Las secciones siguientes son para el que quiere ir más allá de "compatible" a "hecho a medida".

## 2. Cómo hacerse cargo de una plantilla — `TemplateLoader`

El plugin resuelve qué archivo de plantilla usar en `includes/core/class-template-loader.php`, enganchado al filtro `template_include` de WordPress. La lógica, archivo por archivo:

| URL | Busca en el tema (`locate_template`) | Si no está, usa del plugin |
|---|---|---|
| `/tour/{slug}/` | `single-amir_tour.php` | `templates/single-amir_tour.php` (Clásica) o `templates/single-amir_tour-immersive.php` (Inmersiva), según lo elegido en Personalización → Plantilla de detalle |
| `/tours/` (archivo) | `archive-amir_tour.php` | `templates/archive-amir_tour.php` |
| `/room/{slug}/` (Pro Max) | `single-flow_room.php` | `templates/single-flow_room.php` |
| `/rooms/` (Pro Max) | `archive-flow_room.php` | `templates/archive-flow_room.php` |

**Detalle importante para la ficha de tour**: `locate_template(['single-amir_tour.php'])` es lo primero que se evalúa — si el tema (o el child theme) tiene ese archivo, **gana siempre**, sin importar qué plantilla esté elegida en Personalización. Es decir: para ofrecer la plantilla "Inmersiva" desde un tema propio, hay que copiar `templates/single-amir_tour-immersive.php` con el nombre `single-amir_tour.php` en el tema — copiar el archivo "Clásica" no alcanza si lo que se quiere es la Inmersiva.

`locate_template()` es la función nativa de WordPress — ya busca primero en el **child theme** y después en el tema padre. Un child theme puede sobreescribir cualquiera de estos cuatro archivos sin tocar el tema padre, siguiendo la convención estándar de WordPress (el archivo va suelto en la raíz del child theme, no en una subcarpeta).

### 2.1 Qué trae cada plantilla por dentro

Las cuatro plantillas llaman a `get_header()`/`get_footer()` al principio/final — heredan el header/footer del tema normalmente. La carga de datos del tour está factorizada en un partial compartido, **`templates/parts/tour-data.php`**, que arma las variables (`$title`, `$cover`, `$duration_fmt`, etc.) que ambas plantillas de tour consumen — si se copia una plantilla al tema, ese include sigue viniendo del plugin (`AMIR_PLUGIN_DIR . 'templates/parts/tour-data.php'`), no hace falta copiarlo aparte.

### 2.2 Alternativa sin copiar archivos: Elementor

Si el tema/child theme se arma con Elementor (o un builder similar), `includes/elementor/elementor-widgets.php` expone Dynamic Tags (`amir-price-from`, `amir-duration`, etc.) y un widget de tarjeta de tour — se puede armar una plantilla de Loop Builder 100% visual sin tocar PHP, en vez de copiar `single-amir_tour.php`. Ver `includes/elementor/class-elementor-integration.php` para el registro completo.

## 3. Los shortcodes — cómo montan en el DOM

Cada shortcode de PHP no imprime HTML de contenido — imprime un `<div>`/`<span>` marcador con atributos `data-*` (ej. `<div data-flow-discovery="1" data-mode="experience">`), y el bundle de React (`assets/js/booking-widget.js`) hidrata ese nodo con `createRoot(el).render(...)` al cargar (`react-src/src/booking-widget.jsx`).

Implicaciones prácticas para un tema/child theme:

- **No envolver el marcador en `display:none` ni en un contenedor que se muestre recién por JS propio del tema** (ej. un acordeón/tab que no está abierto al cargar) — React necesita poder medir el layout para el widget de reserva; si el contenedor no tiene dimensiones reales en el momento del montaje, algunos componentes (el calendario, el flujo combinado) pueden calcular mal su tamaño inicial.
- **No usar plugins/temas que reescriban `innerHTML` de contenedores de shortcode** (algunos optimizadores de "lazy render" de constructores de páginas lo hacen) — destruye el nodo antes de que React lo hidrate.
- El ancho responsive del **flujo combinado** (`[flow_discovery]`) y **Explorar** (`[flow_explore]`) ya se resuelve solo: ambos usan `container-type:inline-size` en su propio wrapper (`.df-wrap`/`.ex-wrap`, ver `react-src/src/DiscoveryFlow.jsx`/`ExploreFlow.jsx`) y `@container` en vez de `@media` — **un tema no necesita hacer nada especial para que se vea bien en un sidebar angosto**, el widget ya se adapta al ancho real de su contenedor, no al de la pantalla. (Este fue un bug real en producción antes de ese cambio — ver `CONTRIBUTING.md § 16.36` — así que si un child theme ve overflow o texto cortado dentro de un sidebar, casi seguro el bundle de assets está desactualizado, no un problema del tema.)

### 3.1 Patrones (block patterns) con contenido dinámico

TourFlow no registra patrones de Gutenberg propios — es responsabilidad del tema/child theme armarlos con `register_block_pattern()`, combinando bloques nativos de WordPress con shortcodes de TourFlow insertados en un bloque Shortcode/HTML. La lista completa y siempre actualizada de shortcodes disponibles (con sus atributos) está en **TourFlow → Dashboard → 🧩 Shortcodes disponibles** dentro del admin — no hace falta leer el código para armar un patrón, esa pantalla ya lo documenta.

Los shortcodes más útiles para patrones de contenido dinámico (a diferencia de `[flow_booking]`, que es el formulario completo):

- `[flow_tour_list columns="3" limit="6"]` — grilla de tours, para un patrón tipo "Nuestros tours" en el home.
- `[flow_spots_left tour_id="X"]` — chip de urgencia real ("Solo 2 lugares"), útil dentro de un patrón de landing de campaña junto a texto/imagen del tema.
- `[flow_tour_dates tour_id="X" title="..."]` — tira de próximas fechas de un tour puntual, pensado exactamente para un patrón de landing de promoción (ej. "Últimas fechas de agosto" con el resto del diseño a cargo del tema).
- `[flow_search_bar redirect_url="..."]` (Pro Max) — barra de búsqueda standalone para un patrón de hero de home, que redirige a una página con `[flow_explore]`.

Ninguno de estos shortcodes trae su propio contenedor visual pesado — son piezas chicas pensadas para insertarse dentro del diseño del tema, no widgets de página completa como `[flow_booking]`/`[flow_discovery]`. Es la combinación natural para patrones: el tema aporta layout/tipografía/imágenes, el shortcode aporta el dato real (cupo, fecha, precio) sin que el patrón tenga que hardcodear nada que se desactualice.

## 4. Paleta y tipografía del widget — variables CSS

El CSS del widget (`react-src/src/styles/widget.css`, compilado a `assets/css/booking-widget.css`) está construido casi enteramente sobre variables CSS con el prefijo `--ab-`:

```css
--ab-teal / --ab-teal-dark / --ab-teal-light / --ab-teal-mid   /* color de marca + variantes */
--ab-text / --ab-muted                                          /* texto principal / secundario */
--ab-border / --ab-bg / --ab-bg2                                 /* bordes y fondos */
--ab-red / --ab-amber                                            /* estados de error/aviso */
--ab-radius / --ab-radius-sm                                     /* radio de esquinas */
--ab-shadow                                                       /* sombra de tarjetas */
--ab-font / --ab-font-scale                                      /* tipografía y escala de texto */
```

**El operador ya las controla sin tocar código**, desde TourFlow → Personalización → Widget de reserva — `includes/core/class-widget-theme.php` arma un bloque `:root{...}` con los valores elegidos y lo inyecta con `wp_add_inline_style()` **después** del CSS del build, así que gana por orden de cascada sin `!important`.

Para un child theme hecho a medida, dos formas de trabajar con esto:

1. **No tocar nada** — el widget hereda lo que el operador configuró en Personalización, el tema no necesita saber estos nombres.
2. **Leer las mismas variables en el resto del sitio** — si el child theme quiere que, por ejemplo, los botones del tema (fuera del widget) usen el mismo verde que el widget, puede referenciar `var(--ab-teal)` en su propio CSS en vez de hardcodear un color — así un cambio de color en Personalización se propaga también al resto del sitio, sin duplicar la fuente de verdad. Esto es exactamente el tipo de coherencia visual que se busca con un child theme "propio adaptado".

**Qué evitar**: un `:root{}` del tema que redefina `--ab-*` con valores fijos DESPUÉS de que se encola `amir-booking-widget` (por ejemplo, un reset global cargado en el footer) — pisaría silenciosamente la Personalización del operador. Si el tema necesita su propia paleta base, usar nombres de variable propios y, si quiere sincronizarlos, leer `GET /wp-json/amir/v1/config` (`ConfigController`, expone los mismos valores resueltos como JSON — pensado originalmente para clientes que no pueden consumir CSS directo como una app móvil, pero sirve igual para JS de build-time de un tema).

### 4.1 Estilo del widget de reserva: Clásica vs. Fullwidth (v5.6.16+)

`[flow_booking]` tiene dos "skins" seleccionables en Personalización → Widget de reserva → Estilo del widget (`includes/core/class-widget-theme.php::STYLES`, opción `amir_widget_style`) — **misma lógica de los 7 pasos en las dos**, solo cambia layout/tipografía vía CSS (`.ab-style-fullwidth` en `widget.css`):

- **Clásica** (`classic`, default): boxeada, `max-width:520px` en desktop — pensada para convivir embebida en una columna angosta o un sidebar, junto a otro contenido.
- **Fullwidth** (`fullwidth`): `max-width:880px`, barra de progreso con círculos más grandes y centrados, tipografía/padding más generosos — pensada para una página propia sin sidebar (ej. una página "Reservar" dedicada).

Es una decisión del **operador**, no del tema — un child theme no necesita elegir por él. Lo único relevante para quien arma el layout de la página: si el tema va a ofrecer una plantilla de página "ancha, sin sidebar" pensada para alojar `[flow_booking]`, avisarle al cliente que Fullwidth es la opción pensada para ese caso (a diferencia de la Clásica, que se ve angosta y con mucho espacio vacío en un contenedor ancho). El tema no necesita CSS propio para esto — es 100% config del operador.

## 5. Assets encolados — para casos avanzados

`includes/core/class-shortcodes.php::enqueue_widget_assets()` encola, solo en páginas que efectivamente usan un shortcode:

- `amir-booking-widget` (estilo) → `assets/css/booking-widget.css`
- `amir-booking-widget` (script, en el footer) → `assets/js/booking-widget.js`
- `amir-widget-google-font` → si el operador eligió una Google Font en Personalización

`includes/core/class-assets.php` encola además, solo en el archive o la ficha de un tour:

- `amir-tour-cards` → `assets/css/tour-cards.css` (usado también por el widget de Elementor y `[flow_tour_list]`)

Un tema/child theme casi nunca necesita tocar esto — está acá por si hace falta depurar un conflicto de CSS/JS puntual (ej. confirmar que un estilo del tema no está cargando después y pisando al del widget) o, en un caso avanzado, hacer `wp_dequeue_style('amir-tour-cards')` si el child theme reemplaza por completo el markup de las tarjetas vía un hook propio.

## 6. Pensado a futuro — puntos de extensión que todavía no existen

Lo de acá abajo **no está construido**. Es la lista de qué le faltaría al plugin para que un tema/child theme pueda personalizar sin copiar el archivo de plantilla entero — útil como specs de una futura versión, tanto para el child theme propio de Nomade Contenidos como para cualquier desarrollador externo.

- **Hooks de acción alrededor de cada bloque de la ficha de tour** — hoy `single-amir_tour.php`/`single-amir_tour-immersive.php` no disparan ningún `do_action()`. Agregar puntos como `tourflow_before_gallery`, `tourflow_after_price_block`, `tourflow_before_footer_cta` dejaría insertar contenido (badges de confianza, upsells, contenido de marketing) sin duplicar la plantilla completa por un cambio chico.
- **Un filtro de clases del wrapper principal** — algo como `apply_filters('tourflow_single_tour_wrapper_class', 'amir-single-tour')` para que un child theme sume una clase propia (y así pueda tener su propio bloque de CSS específico) sin sobreescribir el archivo.
- **Registrar `single-amir_tour-immersive.php` en `locate_template()` con su propio nombre** — hoy, como describe § 2, un tema que quiere ofrecer la Inmersiva tiene que nombrar el archivo `single-amir_tour.php` (mismo nombre que la Clásica), lo cual es confuso. Sería más claro que `TemplateLoader::load()` buscara `single-amir_tour-immersive.php` en el tema cuando esa es la plantilla activa, y solo cayera a `single-amir_tour.php` para la Clásica.
- **Variables `--ab-*` documentadas en un lugar público** (hoy solo están en el código fuente) — un pequeño archivo `THEME-VARS.md` o una pestaña en Personalización con la lista completa, para que un desarrollador de temas no tenga que leer `widget.css` para saber qué existe.
- **Un evento de JS al hidratar cada shortcode** (`document.dispatchEvent(new CustomEvent('tourflow:mounted', {detail:{type, el}}))`) — hoy no hay forma de que el JS de un tema sepa cuándo un widget terminó de montarse, útil para animaciones de entrada del tema o analítica propia sin pelearse con el timing de React.

Ninguno de estos cuatro es un cambio grande — son extensiones puntuales, no una reescritura, y quedarían disponibles para cualquier tema, no solo el de Nomade Contenidos.

## 7. Checklist — ¿tu tema está listo para TourFlow?

- [ ] El contenedor donde vive cada shortcode no tiene `max-width` fijo agresivo ni `overflow:hidden` que recorte contenido dinámico (el widget ya se adapta al ancho real vía `@container`, pero necesita que el contenedor le deje espacio).
- [ ] Ningún CSS global del tema define `--ab-*` con valores fijos después del stylesheet del widget (rompería la Personalización del operador).
- [ ] Si el tema copia `single-amir_tour.php`/`archive-amir_tour.php`/etc., los archivos están sueltos en la raíz del tema (o child theme), no en una subcarpeta — así los encuentra `locate_template()`.
- [ ] Ningún plugin/optimización de "carga diferida" reescribe el `innerHTML` de los contenedores `data-flow-*`/`data-amir-*` antes de que React los hidrate.
- [ ] Los botones/enlaces propios del tema pasan un contraste de color aceptable contra `var(--ab-teal)` si el tema decide heredar el color de marca (ver § 4).

## 8. Estado del child theme propio de Nomade Contenidos

Referencia rápida — el detalle real vive donde se lo esté construyendo, no en este documento (que es la guía técnica general, no la bitácora de ese proyecto puntual). Al momento de escribir esto, el child theme está **en desarrollo**, sin una versión demostrable todavía. Cuando eso avance, documentar ahí (no acá) qué plantillas sobreescribe de la tabla de § 2 y qué variables de § 4 sincroniza — este archivo debería quedar sin cambios salvo que cambie algo de la arquitectura real del plugin.
