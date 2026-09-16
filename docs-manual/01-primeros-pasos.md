[← Volver al índice](../MANUAL.md)

# 1. Primeros pasos

## Qué es TourFlow

TourFlow es un plugin de WordPress pensado específicamente para operadores de tours y experiencias turísticas. Reemplaza la necesidad de usar una tienda online genérica (tipo WooCommerce): cada tour tiene sus propios horarios, precios por tipo de pasajero (adulto/niño/bebé) o por grupo, reglas de disponibilidad, punto de encuentro con mapa, y un widget de reserva paso a paso que el cliente completa directamente en tu sitio.

Con TourFlow instalado, tu sitio de WordPress puede:

- Publicar una ficha por cada tour, con fotos, descripción, precio, duración, itinerario, qué incluye/no incluye.
- Mostrar un catálogo (grilla) con todos tus tours.
- Recibir reservas online con pago con tarjeta (Stripe) o Mercado Pago.
- Mandar automáticamente el email de confirmación, el recordatorio, el voucher en PDF con código QR, y el pedido de reseña después del tour.
- Llevar un calendario y un dashboard operativo para el día a día (quién viene hoy, cuántos cupos quedan).
- Gestionar partners/referidos (hoteles, agencias) con comisión automática.
- Aplicar cupones de descuento.
- Mostrarse en varios idiomas.

## Antes de reservar: instalación

Un desarrollador ya se encarga de instalar y activar el plugin en tu sitio de WordPress (**Plugins → TourFlow → Activar**). Al activarlo, TourFlow crea automáticamente, sin que tengas que hacer nada:

- Las tablas internas donde vive toda la información (tours, reservas, precios, horarios, partners, cupones, etc.) — son independientes del resto de WordPress, así que actualizar el plugin no borra tus datos.
- El rol de usuario **Tour Manager**: un tipo de usuario para tu staff que solo puede entrar al panel de TourFlow (Dashboard, Reservas, Tours, Disponibilidad, Partners, Reportes) — no puede tocar plugins, usuarios, ni configuración general de WordPress. Al iniciar sesión, un Tour Manager entra directo al Dashboard operativo, sin ver el escritorio genérico de WordPress. Para dar de alta a alguien de tu equipo con este acceso limitado: **Usuarios → Añadir nuevo → Rol: Tour Manager**.
- Tareas automáticas programadas (recordatorios, liberación de reservas no pagadas, pedidos de reseña) que corren solas en segundo plano.
- Una página pública llamada `/verificar-reserva/`, que usa el sistema para que un cliente pueda consultar el estado de su reserva o completar un pago pendiente — no hace falta crearla ni tocarla vos.

## El menú de TourFlow

Una vez activado, en el menú lateral izquierdo de WordPress aparece **TourFlow** (con un ícono de calendario), con estos accesos:

Dashboard · 📅 Calendario · Reservas · Disponibilidad · Partners · Lista de interés · Reportes · Log de pagos · Cupones · Configuración · 🏄 Tours · + Nuevo tour

Cada uno se explica en detalle en su propio capítulo de este manual.

## Publicar tu primer tour

1. **TourFlow → + Nuevo tour**.
2. Completá los campos del editor (ver capítulo [2. Cargar un tour](02-cargar-un-tour.md)) — como mínimo: título, imagen destacada, al menos un horario y un precio.
3. Hacé clic en **Publicar**.

Al publicar, el tour queda disponible automáticamente en dos direcciones de tu sitio:

- `/tour/nombre-del-tour/` — la ficha individual del tour, con toda su información y el widget de reserva ya integrado (no hace falta hacer nada más para que aparezca ahí).
- `/nuestros-tours/` — un listado general con todos los tours publicados.

## Mostrar el catálogo o el widget en cualquier página

Si querés incrustar el catálogo de tours, el widget de reserva, o piezas más específicas (urgencia de cupo, fechas para una promoción) en una página propia (por ejemplo tu home), usá estos códigos cortos ("shortcodes") pegándolos en el editor de esa página. La lista completa, siempre actualizada, también está en **TourFlow → Dashboard → 🧩 Shortcodes disponibles** dentro del panel — esta tabla es la versión para consulta rápida sin entrar al admin.

> Los nombres actuales empiezan con `flow_`. Si en tu sitio ves ejemplos con el prefijo viejo `amir_` (`[amir_tour_list]`, `[amir_booking]`, etc.) van a seguir funcionando igual — son alias que el plugin mantiene por compatibilidad — pero para contenido nuevo usá siempre `flow_`.

| Shortcode | Qué muestra |
|---|---|
| `[flow_tour_list]` | Una grilla con todos los tours activos. Admite parámetros opcionales: `columns` (1 a 4 columnas), `layout="grid"` o `"list"`, `lang="en"` para forzar idioma, `accent="#1D9E75"` para el color de acento, `limit` para topear la cantidad de tours mostrados (`0` = todos). |
| `[flow_booking tour_id="3"]` | El widget de reserva paso a paso para el tour con ID 3. Agregá `lang="en"` para forzarlo en inglés. |
| `[flow_wishlist]` | La grilla de tours "Próximamente" (lista de interés) — ver capítulo [8](08-lista-de-interes.md). |
| `[flow_verify_booking]` | La página de verificación/pago de reservas — ya está creada automáticamente, normalmente no hace falta usarla a mano. |
| `[flow_spots_left tour_id="3"]` | Un chip de urgencia real, tipo "⚡ Solo 2 lugares para el 20 de agosto" — usa el cupo real de la fecha (o de la próxima fecha disponible si no le pasás `date="AAAA-MM-DD"`). Útil junto a la descripción de un tour para generar urgencia sin inventar un número. `threshold` define a partir de qué cupo se considera "bajo" (por defecto `5`); `only_if_low="yes"` hace que no muestre nada si el cupo no es bajo. |
| `[flow_tour_dates tour_id="3" title="Últimas fechas de agosto"]` | Una tira de próximas fechas disponibles de ese tour, cada una con un botón "Reservar" que lleva directo a la ficha del tour con esa fecha ya elegida. Pensado para una landing de promoción puntual (ej. enlazada desde una campaña de redes o un newsletter), no para el catálogo general. Admite `month="AAAA-MM"` para acotar el mes, `limit` (por defecto `6`), y `accent` para el color. |

Estos dos últimos, junto con el catálogo y el widget, funcionan en **cualquier edición** de TourFlow.

### Solo en la edición Pro Max — flujo de reserva combinado

Si tu instalación es Pro Max (habitaciones + carrito combinado), sumás estos:

| Shortcode | Qué muestra |
|---|---|
| `[flow_discovery mode="experience"]` | El flujo continuo con upsell: elegís un tour (o `mode="room"` para una habitación) y el sistema sugiere el resto (habitación, extras) antes de un único pago. El plugin ya publica la página **"Book Your Stay"** con este shortcode al activarse. |
| `[flow_explore]` | Segundo flujo, "búsqueda primero" (estilo Booking.com) — barra de fechas/huéspedes persistente, sin pasos fijos, para un visitante que todavía no eligió qué reservar. Convive con `[flow_discovery]`, no lo reemplaza. |
| `[flow_search_bar redirect_url="/explorar/"]` | Una barra de búsqueda standalone para el hero de tu home — al buscar, redirige a la página que tenga `[flow_explore]` con la fecha y huéspedes ya cargados. |
| `[flow_room_list]` | Grilla simple de habitaciones, sin buscador — mismo rol que `[flow_tour_list]` pero para habitaciones. |
| `[flow_room_search]` | Buscador de habitaciones por fecha, sin upsell de tours — solo habitaciones. |

Para saber el **ID** de un tour (necesario para `[flow_booking tour_id="X"]`, `[flow_spots_left tour_id="X"]` y `[flow_tour_dates tour_id="X"]`), fijate en **TourFlow → Tours**: cada fila tiene una columna **ID** (clic para copiarlo). Ojo: **no es el mismo número que aparece en la URL del navegador al editar el tour** — esa URL trae el ID interno de WordPress, que puede ser distinto del ID que usa TourFlow para los shortcodes. Usá siempre el que aparece en la columna ID.

## Roles de usuario

- **Administrador de WordPress** (`manage_options`): acceso completo a todo el plugin, incluida Configuración y Cupones.
- **Tour Manager** (`manage_amir_booking`): acceso a Dashboard, Calendario, Reservas, Disponibilidad, Partners, Lista de interés, Reportes, Log de pagos y a la edición de Tours — **sin** acceso a Cupones ni a Configuración (esas dos pantallas exigen permisos de administrador completo).

Esto te permite delegar la operación diaria (cargar tours, ver reservas, atender clientes) en tu staff, sin darles acceso a las claves de Stripe/Mercado Pago ni a la configuración general del sitio.
