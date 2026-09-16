# Panel de gestión sin wp-admin — estado

Puerta de entrada para retomar esto en otra sesión. **8 de 8 secciones del
alcance acordado con el cliente ya están construidas** (2026-09-12), más
**Habitaciones** (Pro Max) sumada tras feedback post-entrega del mismo día
— ver `CLAUDE.md` "Estado al cierre" y el código en `includes/panel/`.
Emails y Log de pagos quedan **explícitamente fuera de alcance** ("los
logs dejamos fuera", pedido directo del cliente) — no son un pendiente, es
una decisión cerrada. Este documento queda como referencia de arquitectura
y de los hallazgos técnicos reales que aparecieron en el camino.

**Línea de tiempo de esta misma sesión** (todo 2026-09-12): v5.11.0
(infraestructura + 8 secciones) → feedback del cliente ("falta gestión de
habitaciones", "botón de nuevo partner", "100% adaptable a móviles",
"estilos consistentes") → v5.11.1 (sección Habitaciones + 2 bugs reales +
reskin de botones nativos + primer intento de mobile + consistencia de
color de marca) → v5.11.2 (pasada de UX/UI real: drawer mobile de verdad,
accesos rápidos en el Dashboard, estados de foco, transiciones). Ver el
detalle de cada ronda más abajo.

## Por qué existe esto

El cliente pidió (2026-09-12) que TourFlow, en sus 3 ediciones, tenga un
panel de gestión propio donde el rol "gestor de tours" (`amir_tour_manager`)
pueda operar el día a día sin entrar nunca a wp-admin — mejora la gestión y
hace más transparente el uso de TourFlow en sitios headless (Caliafarm ya
tiene un frontend público separado de WordPress; este panel es el
equivalente del lado operativo/interno). Referencia de diseño: el cliente
ya construyó exactamente este patrón en su otro producto, **EventFlow**
(`Organizer_Panel`/`Organizer_Auth`, `/Users/johelpastorino/Proyectos/Events Flow/ticketing-core`),
en producción — se investigó ese código a fondo antes de diseñar esto.

Alcance confirmado con el cliente: **todo lo operativo** entra al panel —
Tours, Reservas, Calendario, Disponibilidad, Modo Campo (+ estado/botón de
Google Calendar), Emails, Log de pagos, Partners y Liquidación de
partners. **Proveedores externos (marketplace) quedan excluidos** — siguen
siendo exclusivos del administrador en wp-admin. **Aclaración explícita
del cliente sobre Partners**: esta sección es de cara al OPERADOR (ver/dar
de alta partners, generar sus links/QR, liquidar comisiones) — nunca un
login para que un partner externo entre por su cuenta a gestionar nada,
eso no se pidió ni se construyó.

Es la excepción de "diferenciador enorme" a la pausa de features
(`feedback_stabilization_priority`) — mismo criterio que EventFlow ya
aplicó: es lógica propia del negocio, va en el núcleo, no en un plugin
satélite.

## Arquitectura ya construida (fase 1) — resumen para no releer todo el código

- `includes/panel/class-manager-auth.php` (`TourFlow\Panel\ManagerAuth`) —
  login propio: `wp_authenticate()` contra usuarios reales de WP (reusa
  hashing/recuperación de contraseña nativos) + gate
  `manage_options || manage_amir_booking`, token HMAC propio en una cookie
  separada de wp-admin (`tourflow_manager_session`, TTL 24h). 6 tests en
  `tests/unit/ManagerAuthTest.php`.
- `includes/panel/class-manager-panel.php` (`TourFlow\Panel\ManagerPanel`)
  — rewrite rule `/gestor/` + query var `tourflow_manager_page` (URL
  canónica interna por query string, no depende de permalinks "pretty"),
  `template_redirect` despacha por sección. El truco central: al validar
  la cookie se hace `wp_set_current_user()` SOLO para esa request (nunca
  se emite cookie de sesión real de WP) y desde ahí se invoca DIRECTO
  `BookingsPage::render()`/`CalendarPage::render()` — cero HTML duplicado,
  reusa el 100% de esas pantallas.
- **Refactor mínimo que hizo esto posible** en `class-bookings-page.php` y
  `class-calendar-page.php`: ambas ya posteaban a la URL actual (sin
  `action=""` explícito en sus `<form>`, no hacía falta el truco de
  admin-post.php de EventFlow) — solo tenían ~14 `admin_url(...)`
  hardcodeados para links/redirects internos. Se agregó una property
  `$base_url`/método `base_url()` a cada clase (default = el
  `admin_url()` de siempre, cero regresión en wp-admin) y se reemplazaron
  esos call sites. `DashboardPage::render_day_tours()`/`render_day_rooms()`
  (reusados por Calendario) recibieron el mismo tratamiento vía
  `set_bookings_base_url()`. **2 links de descarga de voucher PDF quedan
  apuntando a wp-admin a propósito** (la ruta de streaming corre por
  `AdminMenu::maybe_stream_pdf()`, gateada a `admin_init` — no se duplicó
  esa ruta) — el gestor los abre en pestaña nueva, cae en wp-admin solo
  para ESE link puntual (permitido, `amir-bookings-list` está en
  `$allowed_pages` de `TourManagerRole`).
- `assets/css/panel.css` — reskin sobre los mismos componentes de
  wp-admin (`.button`/`.notice`/`.wrap` se cargan de verdad vía
  `wp_print_styles()` en `render_shell()`, exactamente el mismo truco que
  ya usa `Organizer_Panel::render_shell()` de EventFlow en producción) +
  layout de sidebar propio (verificado que 6 ítems entran bien) + color de
  marca inyectado vía `WidgetTheme::render_inline_css()` (el mismo que usa
  el widget público). Verificado visualmente con un harness estático
  servido por `python3 -m http.server` (sin WordPress real disponible en
  este entorno) — sidebar, dashboard y login se ven correctos en desktop y
  en mobile (breakpoint 900px).
- Secciones construidas (las 8 del alcance): `dashboard` (stats de
  hoy/mañana vía `DashboardPage::get_day_summary()`, tarjeta de estado de
  Google Calendar si `AMIR_EDITION === 'pro_max'`), `tours`, `reservas`,
  `calendario`, `modo-campo`, `disponibilidad`, `partners`,
  `liquidacion-partners`. Partners es de cara al OPERADOR (alta, comisión,
  links/QR, liquidación) — nunca un login para que un partner externo
  entre por su cuenta, aclarado explícitamente por el cliente.
  `PartnerPayoutsPage` no necesitó ningún cambio para ser reusable — ya
  posteaba a sí misma sin `admin_url()` hardcodeado en absoluto.

### Modo Campo — el hallazgo real: `determine_current_user`

`FieldPage`/`class-field-page.php` recibió el mismo tratamiento de
`$base_url`/`set_lang()` que las demás (~10 `admin_url()` reemplazados,
incluida la redirección post-escaneo). El problema real y no obvio: su
escaneo de QR usa `admin-ajax.php` con `wp_ajax_amir_field_scan_lookup` —
WordPress solo dispara ese hook si `is_user_logged_in()` es `true`, y esa
función mira la cookie REAL de wp-admin. `wp_set_current_user()` (el
truco central del panel) solo dura la request que lo llamó — la llamada
`fetch()` del escaneo es una request HTTP nueva y separada, donde el
gestor vuelve a verse anónimo, así que el AJAX fallaría en silencio.

**Solución**: `ManagerPanel::register()` engancha
`add_filter('determine_current_user', ...)` — el filtro que WordPress usa
para resolver "quién sos" en CUALQUIER request (page load, admin-ajax.php,
REST), no solo en `template_redirect`. Si nadie más resolvió un usuario
real (prioridad 20, corre después del chequeo normal de cookie de
wp-admin — nunca pisa una sesión real) y la cookie del panel es válida, se
usa ese `user_id`. Con esto, `is_user_logged_in()`/`current_user_can()`/
nonces funcionan igual en cualquier request que lleve la cookie del panel
— **cero cambios en `FieldPage::ajax_scan_lookup()`**, su
`current_user_can()` de siempre ya alcanza. El único ajuste real en esa
clase fue la URL de redirect que el AJAX devuelve: como el handler corre
aparte de cualquier `render()` (no tiene `$this->base_url` cargado), la
vista de escaneo manda su propia base por POST (`panel_base`), validada
contra `home_url()` antes de usarla (nunca confiar en una URL que llega
del cliente sin chequear).

### Tours — mucho más simple de lo que el diseño original suponía

Investigando `TourPostType` (~2500 líneas) antes de tocar nada: cada
metabox (`meta_box_main`, `meta_box_pricing`, `meta_box_gallery`, etc.) es
un método **público** auto-contenido, sin un solo `admin_url()` ni AJAX
propio — son formularios puros, algunos con `wp_enqueue_media()`/`wp.media`
para fotos (galería, itinerario), sin TinyMCE/`wp_editor()` en ningún
lado. Y el guardado ENTERO (precio, horarios, addons, galería, itinerario,
FAQ) ya cuelga de un único hook nativo, `save_post_amir_tour`
(`save_meta()` en prioridad 10, `sync_to_db()` en prioridad 20) — el mismo
que `wp_insert_post()`/`wp_update_post()` disparan solos, sin importar
quién los llame.

**Conclusión: el refactor de guardado que este documento daba por
necesario (§ tabla vieja, "el único refactor real de fondo") no hacía
falta.** `TourFlow\Panel\ToursSection` (`includes/panel/class-tours-section.php`)
arma un `<form>` propio que invoca cada `meta_box_X($post)` en el mismo
orden que `TourPostType::add_meta_boxes()` (mismo gate de edición vía
`AMIR_EDITION`), y al enviarse llama `wp_insert_post()`/`wp_update_post()`
con el título — WordPress dispara `save_post_amir_tour` solo, que lee el
mismo `$_POST` que mandó el formulario (los nombres de campo son
idénticos porque son literalmente los mismos metabox callbacks). "Nuevo
tour" crea un auto-draft antes de mostrar el formulario, mismo truco que
usa `post-new.php` de WordPress (los metabox callbacks esperan un
`\WP_Post` real con ID válido).

**Bug real de fondo encontrado y corregido en el camino, pre-existente,
no introducido por este trabajo**: `save_meta()` exigía
`current_user_can('edit_amir_tour', $post_id)` — esa capability NUNCA
existió de verdad. El CPT se registra con `capability_type='post'` (no un
array `['amir_tour','amir_tours']`), así que WordPress nunca generó ese
nombre de meta-capability; `map_meta_cap()` caía a su catch-all y la
condición quedaba en `false` para cualquiera que no fuera Administrador.
Un Tour Manager — el rol pensado justamente para gestionar tours — podía
abrir un tour en wp-admin, tocar "Actualizar", y ver que el título se
guardaba (usa `edit_post`, una capability real, compartida con posts de
blog) mientras precio/horarios/galería/todo lo demás quedaba sin
persistir, **sin ningún error visible**. Corregido a
`current_user_can('manage_amir_booking')`, el mismo criterio que ya usa
cada pantalla de este panel — arregla el bug tanto en wp-admin como en el
panel nuevo.

`ToursSection` también suma, arriba del formulario, una guía colapsable
"¿Qué tipo de tour necesito?" — resumen corto de
`docs-manual/18-tipos-de-tour.md` (ejes Disponibilidad y Cobro, los que
más generan dudas) — pedido explícito del cliente ("la carga y edición de
tours debe ser óptima, práctica, entendible — saber cómo configurar cada
tipo, si tiene fechas, si se repite por días").

`ManagerPanel::render_shell()` ganó un parámetro `$with_media` — cuando es
`true` (solo la sección Tours), hace `wp_enqueue_media()` antes de
imprimir estilos e imprime `wp_print_scripts()`/`wp_print_media_templates()`
después del body — mismo truco que ya usa
`Organizer_Panel::render_shell()` de EventFlow para lo mismo.
- **Idioma del panel = idioma del sitio, no el del perfil del usuario**
  (decisión explícita del cliente 2026-09-12, acotada solo al panel para
  no tocar wp-admin). `ManagerPanel::lang()` usa `get_locale()` en vez de
  `get_user_locale()`; las 5 pantallas reusadas (`BookingsPage`,
  `CalendarPage`, `AvailabilityPage`, `PartnersPage`,
  `PartnerPayoutsPage`) ganaron un `set_lang(string $lang)` que pisa su
  `get_user_locale()` de siempre SOLO cuando se les llama — en wp-admin,
  sin llamar a `set_lang()`, siguen exactamente igual que antes (el perfil
  del usuario). **Limitación conocida, no resuelta**: `DashboardPage` no
  tiene este mecanismo (usa gettext `__()`/`_e()` directo, que sigue el
  locale que WordPress determinó para la request, típicamente atado al
  usuario logueado en contexto admin) — el Dashboard del panel puede
  quedar con textos en el idioma del perfil del gestor en vez del sitio
  en los fragmentos que vienen de `render_day_tours()`/`render_day_rooms()`.
  Si el cliente lo nota en el sandbox, la solución sería envolver esas
  llamadas en `switch_to_locale()`/`restore_current_locale()`.
- Registrado en `includes/core/class-plugin.php` (`ManagerPanel::register()`),
  universal a las 3 ediciones pese al namespace `TourFlow\` (primera pieza
  de ese namespace que corre fuera de Pro Max).

**Sin probar en vivo todavía** — no hay forma de levantar un WordPress real
en este entorno. Antes de dar esto por cerrado con el cliente, probar en
el sandbox, en este orden (de menor a mayor riesgo):

1. Login → Dashboard → confirmar que el idioma coincide con el del sitio
   (salvo, posiblemente, los fragmentos de `render_day_tours()`/
   `render_day_rooms()` — ver limitación de `DashboardPage` arriba).
2. `/gestor/reservas` → aprobar/cargar pago de una reserva.
3. `/gestor/calendario` → ver el día, confirmar que "ver reserva" cae
   dentro del panel.
4. `/gestor/disponibilidad` → cargar una regla.
5. `/gestor/partners` → dar de alta un partner, ver sus links/QR.
6. `/gestor/liquidacion-partners` → marcar una liquidación pagada.
7. `/gestor/modo-campo` → escanear un QR real de un voucher (el caso más
   sensible de todo el panel: depende de `determine_current_user`
   funcionando bien contra `admin-ajax.php`, ver arriba) — confirmar que
   un escaneo válido navega DENTRO del panel, no a wp-admin.
8. `/gestor/tours` → crear un tour nuevo de punta a punta (galería con
   `wp.media`, horarios, precio) y editar uno existente — la sección con
   más superficie nueva (`.postbox`/wp.media renderizando fuera de
   wp-admin) y la única que no se pudo verificar ni siquiera visualmente
   en este entorno (el harness estático no tiene el CSS real de wp-admin
   disponible localmente).
9. `/gestor/habitaciones` (solo Pro Max) → crear/editar una habitación de
   punta a punta, confirmar que un Tour Manager (no Administrador) puede
   guardarla — es justo el caso que estaba roto por el bug de
   `RoomPostType::save_meta()` documentado arriba, la prueba más
   importante de esta sección.
10. En mobile real (no el emulador del navegador): abrir el drawer con el
    botón ☰, confirmar que tocar el overlay o un link lo cierra, y que
    ninguna pantalla reusada (Reservas/Partners/Disponibilidad) fuerza
    scroll horizontal de página.

Confirmar en cada paso que ningún link devuelve a wp-admin salvo los 2 de
PDF de voucher en Reservas (documentados arriba, a propósito).

### Habitaciones — sumada tras feedback post-entrega (v5.11.1)

El cliente probó la entrega inicial (8 secciones) y pidió agregar
Habitaciones a la versión Pro Max ("le falta la gestión de habitaciones al
dashboard de la versión pro max"). `TourFlow\Panel\RoomsSection`
(`includes/panel/class-rooms-section.php`) es el mismo patrón exacto que
`ToursSection`, pero sobre `RoomPostType`/CPT `flow_room`: 3 metaboxes
(`meta_box_main`, `meta_box_gallery`, `meta_box_amenities`), auto-draft al
crear, guardado vía `wp_insert_post()`/`wp_update_post()` disparando
`save_post_flow_room` solo. `render()` bloquea con `wp_die()` si
`AMIR_EDITION !== 'pro_max'` — el CPT ni se registra en otras ediciones,
pero el gate explícito documenta la intención igual. Desde la edición de
una habitación hay un link directo a su disponibilidad puntual (via
`_flow_room_db_id` → `ManagerPanel::url('disponibilidad-habitaciones', ['room_id' => ...])`).

**Dos bugs reales de fondo encontrados en el camino, iguales en espíritu al
de Tours (`edit_amir_tour`)**:

1. `RoomPostType::save_meta()` exigía `current_user_can('manage_options')`
   **a secas, sin ningún fallback** — peor que el bug de Tours (que al
   menos tenía un fallback roto). Un Tour Manager nunca pudo guardar una
   habitación, **ni siquiera en wp-admin** — no era un problema del panel
   nuevo, ya estaba roto de antes. Corregido a
   `manage_options || manage_amir_booking`, mismo criterio que el resto.
2. `RoomAvailabilityPage::render()` **no tenía ningún `current_user_can()`
   propio** — dependía solo del gate del menú de wp-admin (`manage_options`
   en el registro del submenú), que el panel nuevo no atraviesa. Sin este
   fix, cualquiera que adivinara la URL de esta sección dentro del panel
   entraba sin chequeo de capability. Se agregó el mismo gate que usa el
   resto de las secciones — un hueco de seguridad real, no solo un
   prerequisito para reusar la pantalla.

### Consistencia visual — reskin de botones nativos + color de marca (v5.11.1)

Feedback del cliente: "algunos estilos como el botón de new partner" (el
`.button-primary` nativo de WordPress se veía con el azul `#2271b1` de
WP-core, pegado al lado de todo lo demás en color de marca). Corregido en
`panel.css` con `.tfp-body .button-primary`/`.button:not(.button-primary)`
reskineados a `var(--ab-teal, ...)` — una sola vez en el CSS del panel, sin
tocar cada pantalla admin reusada individualmente.

Bug de fondo más grande, encontrado auditando el resto de los estilos:
**~120 colores hardcodeados** (`#1D9E75`/`#0F6E56`/`#f0faf6`) repartidos en
10 archivos admin reusados por el panel (`class-bookings-page.php`,
`class-partners-page.php`, `class-availability-page.php`,
`class-calendar-page.php`, `class-partner-payouts-page.php`,
`class-field-page.php`, `class-dashboard-page.php`,
`class-tour-post-type.php`, `class-room-post-type.php`,
`class-room-availability-page.php`) — esas pantallas siempre se veían
"verde TourFlow" sin importar el color de marca real configurado por el
operador. Corregido con una sustitución mecánica y verificada (`sed`) a
`var(--ab-teal, #1D9E75)`/`var(--ab-teal-dark, #0F6E56)`/
`var(--ab-teal-light, #f0faf6)` — cero cambio visual en wp-admin (el mismo
fallback hex es el valor que `admin.css` ya define para esas variables ahí)
pero en el panel heredan el color real del operador vía
`WidgetTheme::render_inline_css()`. Verificado: sin doble-envoltura, lint
limpio en los 10 archivos, 111 tests siguen en verde.

### Mobile real — grid/flex de pantallas reusadas (v5.11.1) y drawer de verdad (v5.11.2)

Pedido explícito del cliente: "100% adaptable a móviles". El primer intento
(v5.11.1) resolvió el síntoma más grave — las pantallas admin reusadas
(Reservas, Partners, Disponibilidad, Disponibilidad de habitaciones) arman
su layout de 2 columnas/tablas anchas con **estilos inline en su propio
PHP** (`style="display:grid;grid-template-columns:1fr 320px"`), sin una
clase común que tocar sin reescribir esas pantallas — resuelto con
selectores de atributo (`[style*="grid-template-columns"]`) bajo un
breakpoint de 640px, más `min-width:0` sistemático (la causa real más común
de "se corta y scrollea de costado": un hijo de grid/flex no se encoge más
chico que su contenido por default).

Lo que **no** se resolvió en v5.11.1: la navegación del panel en mobile
era una barra horizontal con scroll lateral de iconos — funcional, pero no
se sentía a la altura de un panel de gestión real. Corregido de fondo en
**v5.11.2** con un **drawer off-canvas real** (topbar + botón hamburguesa +
sidebar deslizante + overlay), implementado con el truco clásico de
checkbox oculto + `<label for="...">` — **cero líneas de JavaScript**,
mismo espíritu de "sin build step nuevo" del resto del panel. En desktop
(>900px) el checkbox/topbar quedan ocultos por CSS y el sidebar sigue fijo
como siempre.

**Bug propio, encontrado y corregido durante la verificación visual** (no
llegó a entregarse roto): la regla base `.tfp-nav-overlay { display: none;
}` (pensada para ocultar el overlay en desktop) nunca se revertía dentro
del `@media (max-width: 900px)` — el overlay cambiaba de `opacity:0` a
`opacity:1` al abrir el drawer, pero seguía con `display:none` heredado,
así que nunca se veía. Detectado con `getComputedStyle()` contra un
harness local (`python3 -m http.server` sirviendo `panel.css` real) antes
de darlo por cerrado — corregido agregando `display: block` explícito
dentro del media query.

De paso, v5.11.2 sumó una tarjeta de **"Accesos rápidos"** al Dashboard
(Nueva reserva / Nuevo tour / Escanear voucher / Nuevo partner) — reusa
URLs que ya existen (`?action=new` en Tours/Reservas, confirmado en el
código antes de asumirlo) en vez de duplicar formularios — y **estados de
foco visibles** (`:focus-visible`) en links/botones/inputs de todo el
panel, que antes dependían del anillo de foco nativo de wp-admin y
quedaron sin reemplazo al reskinear ese chrome (regresión de accesibilidad
real para navegación a teclado, no solo estética).

## Fuera de alcance, por decisión del cliente

- **Emails** (`class-email-test-page.php`) y **Log de pagos**
  (`class-payment-log-page.php`) — el cliente pidió explícitamente
  dejarlos fuera ("los logs dejamos fuera", 2026-09-12). Si se retoman
  algún día: `EmailTexts::save()`/`PaymentEventLogger::recent()` ya son
  públicos/estáticos, mismo patrón `$base_url` que el resto — trivial.
- **Proveedores externos** (marketplace) — decisión de negocio ya cerrada
  (ver arriba), exclusivo del administrador en wp-admin.
