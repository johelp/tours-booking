# Prompt de arranque — desarrollo de tema/child theme para TourFlow

Copiar y pegar esto como primer mensaje al agente/desarrollador que va a construir o adaptar un tema (o child theme) de WordPress para convivir con TourFlow.

---

Vas a construir/adaptar un tema de WordPress pensado para usarse junto al plugin **TourFlow** (reservas de tours y experiencias). TourFlow ya está instalado y funcionando — tu trabajo es de diseño/frontend del tema, no tocás el plugin. Estas son las herramientas reales que tenés disponibles, verificadas contra el código actual del plugin:

## 1. Leé primero

**`GUIA-THEMES-TOURFLOW.md`** (raíz de este repo) es la referencia técnica completa — cada sección está verificada contra el código real, no es una guía genérica de WordPress. Este prompt es solo el resumen ejecutivo; ese documento tiene el detalle (qué archivo hace qué, qué evitar, checklist final).

## 2. Las piezas que podés usar

- **Shortcodes** — la lista completa y siempre actualizada, con sus atributos, está en **TourFlow → Dashboard → 🧩 Shortcodes disponibles** dentro del wp-admin (pedile acceso a quien te dé el sitio si no lo tenés). No adivines atributos, esa pantalla es la fuente de verdad.
- **`[flow_booking tour_id="X"]`** — el formulario de reserva completo (7 pasos). Tiene **dos estilos visuales** seleccionables por el operador en Personalización → Widget de reserva (misma lógica en los dos, solo cambia el layout):
  - **Clásica** — boxeada, angosta (`max-width:520px`), para convivir en una columna/sidebar junto a otro contenido.
  - **Fullwidth** — ancha (`max-width:880px`), stepper más grande y centrado, para una página propia dedicada a reservar, sin sidebar.
  Si tu tema incluye una plantilla de página "ancha, sin sidebar" para alojar el formulario, decile al operador que Fullwidth es la pensada para ese caso — vos no elegís el estilo desde el tema, es config del operador.
- **Piezas chicas para patrones con contenido dinámico** (no formularios completos, solo datos reales para insertar en tu propio diseño): `[flow_tour_list]` (grilla de tours), `[flow_spots_left tour_id="X"]` (cupo real, "Solo 2 lugares"), `[flow_tour_dates tour_id="X"]` (próximas fechas de un tour, para landings de campaña), `[flow_search_bar redirect_url="..."]` (Pro Max, barra de búsqueda para un hero de home). Armá tus patrones de Gutenberg (`register_block_pattern()`) combinando estos shortcodes con tu propio layout/tipografía — ver § 3.1 de la guía.
- **Plantillas de página sobreescribibles** — `single-amir_tour.php`, `archive-amir_tour.php` (y sus equivalentes `flow_room` en Pro Max) se pueden copiar sueltos a la raíz de tu tema/child theme para reemplazarlos por completo (`locate_template()`, mismo mecanismo nativo de WordPress). Ver § 2 de la guía — hay un detalle importante ahí sobre cómo nombrar el archivo si querés ofrecer la plantilla "Inmersiva" en vez de la "Clásica".
- **Variables CSS del widget** (`--ab-teal`, `--ab-text`, `--ab-radius`, etc.) — si querés que el resto del sitio comparta el color/tipografía que el operador configuró en Personalización, referenciá estas variables en tu CSS en vez de hardcodear valores. Ver § 4 de la guía para la lista completa y qué evitar (pisar estas variables por accidente rompe la Personalización del operador).

## 3. Reglas rápidas para no romper nada

- No envuelvas el `<div>` de un shortcode en `display:none` ni en algo que se muestre recién por JS del tema (acordeón cerrado, tab inactivo) — React necesita medir el contenedor al montar.
- No uses optimizadores de "carga diferida" que reescriban el `innerHTML` de contenedores `data-flow-*`/`data-amir-*` — destruyen el nodo antes de que React lo hidrate.
- El flujo combinado y Explorar (Pro Max) ya se adaptan solos al ancho de su contenedor (`@container`, no `@media`) — no necesitás JS/CSS especial para que se vean bien en un sidebar angosto.

Antes de dar por terminado el tema, repasá el checklist completo (§ 7 de la guía).
