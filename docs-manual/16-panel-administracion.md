[← Volver al índice](../MANUAL.md)

# 16. Panel de administración — recorrido de cada pantalla

Este capítulo recorre las pantallas de uso diario que no tienen ya su propio capítulo dedicado: Dashboard, Calendario, Reservas, Reportes y Log de pagos. (Disponibilidad, Partners, Lista de interés, Cupones y Configuración están explicadas en sus propios capítulos.)

## Dashboard (pantalla de inicio)

Pensada para consultarse rápido, incluso desde una tablet en el punto de encuentro el día de la operación. Muestra:

- Un desplegable con la lista de shortcodes disponibles y sus parámetros, por si necesitás recordarlos rápido.
- Una barra de notificaciones sin leer (por ejemplo, avisos de mínimo de pasajeros no alcanzado), con link directo a la reserva o pantalla correspondiente, y un botón para marcarlas todas como leídas.
- 4 indicadores rápidos: reservas de hoy, reservas de mañana, ingresos del mes, reservas pendientes de pago.
- Dos bloques, **Hoy** y **Mañana**: por cada tour que sale ese día, una fila con foto, horario, cupos libres (o "LLENO") que al desplegarse muestra cada reserva con cliente, pasajeros, idioma, origen de la reserva, botón directo de WhatsApp al cliente, y sus notas especiales.
- Si hay solicitudes de cancelación pendientes de tu revisión, aparecen en una sección aparte con acceso directo al detalle de cada una.

No tiene calendario completo ni filtros — es intencionalmente una foto de "hoy y mañana" para consulta rápida. Para ver cualquier otra fecha, usá el Calendario.

## 📅 Calendario

Una pantalla de **pantalla completa** (sin el menú lateral normal de WordPress) con su propia barra de navegación arriba (Dashboard, Calendario, Reservas, Disponibilidad, Partners, Lista de interés, Configuración) — usá esa barra para moverte a otra sección, no el botón "atrás" del navegador.

Es un calendario mensual: los días con reservas confirmadas muestran una marca con la cantidad. Hacé clic en cualquier día para ver, debajo, el mismo detalle de tours/reservas que en el Dashboard, pero para esa fecha puntual — sirve para revisar cualquier día pasado o futuro, no solo hoy/mañana.

## Reservas

### Listado

Filtros disponibles: texto libre (nombre, email o referencia), tour, estado, rango de fechas del tour, origen (Directo/Partner/TripAdvisor/GetYourGuide). La tabla muestra referencia, tour, fecha y horario, cliente, desglose de pasajeros, total cobrado, estado, origen y fecha en que se hizo la reserva — con paginación de 25 por página.

Botones: **+ Nueva reserva** (carga manual) y **⬇ Exportar CSV** (descarga exactamente lo que estás viendo filtrado en ese momento, en un archivo compatible con Excel).

### Cargar una reserva manual

Útil para reservas que te llegan por teléfono o WhatsApp. El formulario pide: tour y horario (al elegir el tour, carga los horarios disponibles), fecha (no permite fechas pasadas), idioma, cantidad de adultos/niños/bebés, datos del cliente, partner de origen (opcional), total (si lo dejás en 0, se calcula solo según el precio del tour), método de pago (efectivo, transferencia, tarjeta presencial, WhatsApp/coordinado, cortesía, agencia).

Tiene un checkbox clave: **"El cliente todavía no pagó — enviarle un link de pago"** — si lo marcás, la reserva queda sin confirmar y se le manda al cliente un email con botón de pago online (mismo mecanismo que la lista de interés), en vez del email de confirmación normal.

**Nota importante:** una reserva manual **no respeta** la restricción de "no admite niños/bebés" de un tour (ver capítulo [4](04-restricciones-de-edad.md)) — es a propósito, para que puedas hacer una excepción puntual con tu propio criterio cuando un cliente te lo pide directamente.

### Detalle de una reserva

Ves toda la información en tarjetas: tour reservado, datos del cliente (con botón de WhatsApp directo), pago (total, ID de pago, tipo de cambio si corresponde, monto reembolsado), servicios extra si los hay, notas internas (con formulario para agregar una nueva, quedan con fecha y quién la escribió), historial de emails enviados, y datos de auditoría (fecha de creación/actualización).

Las acciones disponibles cambian según el estado de la reserva:

- **Pendiente**: podés confirmarla manualmente (por si el aviso automático de la pasarela no llegó) o reenviar el email de confirmación.
- **Esperando pago**: reenviar el link de pago.
- **Solicitud de cancelación**: el sistema te muestra la política de reembolso calculada según los días de anticipación, y podés **aprobar** (con una nota opcional) o **rechazar** la solicitud.
- **Confirmada**: podés cancelarla por clima o por mínimo de pasajeros no alcanzado (con reembolso completo y aviso automático al cliente), reprogramarla a otra fecha/horario del mismo tour, o descargar su voucher en PDF.

Recordá siempre: aprobar una cancelación o reembolso acá **no mueve la plata en Stripe/Mercado Pago** — solo cambia el estado y avisa al cliente. El reembolso real lo procesás vos desde el panel de tu pasarela de pago (ver capítulo [10](10-pasarelas-de-pago.md)).

## Reportes

Filtrás por rango de fechas del tour (con accesos rápidos: este mes, mes anterior, últimos 3 meses, este año) y ves: ingresos del período, reservas confirmadas, personas totales, ticket promedio; un ranking de ingresos por tour; ingresos por origen (Directo/Partner/TripAdvisor/GetYourGuide); y una tabla de evolución mensual de los últimos 12 meses.

Solo cuenta reservas **confirmadas o completadas** — pendientes, canceladas y lista de interés no aparecen en ningún número de esta pantalla. El filtro de fechas es sobre la **fecha del tour** (cuándo sale), no sobre cuándo se hizo la reserva. Podés exportar el detalle completo del período a CSV.

## Log de pagos

Ver capítulo [10. Pasarelas de pago](10-pasarelas-de-pago.md#log-de-pagos-diagnosticar-problemas-de-un-cliente).
