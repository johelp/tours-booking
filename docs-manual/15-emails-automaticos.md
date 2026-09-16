[← Volver al índice](../MANUAL.md)

# 15. Emails automáticos

Todos los emails que manda TourFlow usan tu identidad de marca (logo, color, nombre de empresa — configurables en **Configuración → 🎨 Identidad de marca**) y salen **en el idioma con el que el cliente hizo la reserva**, no en el idioma general del sitio (ver capítulo [12. Idiomas](12-idiomas.md)).

## Qué emails se mandan y cuándo

| Email | Cuándo se dispara |
|---|---|
| **Confirmación de reserva** | Apenas se confirma el pago. En simultáneo, te llega a vos (al email de alertas configurado) un aviso interno con los datos de la reserva nueva. |
| **Recordatorio** | Automáticamente, un día antes de la fecha del tour, a todas las reservas confirmadas de ese día. Se manda una sola vez por reserva. |
| **Solicitud de reseña** | Después del tour, invitando a dejar reseña en Google/TripAdvisor. El plazo es configurable (1 día por defecto, hasta 7). No se manda a reservas que vinieron de TripAdvisor o GetYourGuide, solo a las hechas directo en tu sitio. |
| **Cancelación** | Cuando se cancela una reserva — el texto cambia según el motivo (pedido del cliente, clima, mínimo de pasajeros no alcanzado). |
| **Reprogramación** | Cuando cambiás la fecha/horario de una reserva confirmada, para avisarle al cliente los datos nuevos. |
| **"Tu tour ya está disponible"** | Al publicar y notificar un tour que estaba en lista de interés (ver capítulo [8](08-lista-de-interes.md)) — le llega a cada anotado con el botón para pagar. |
| **Link de pago (reserva manual)** | Cuando cargás una reserva a mano y elegís "el cliente todavía no pagó" — le llega el mismo tipo de email con el botón de pago. |
| **Aviso interno de mínimo de pasajeros** | Te llega a vos si un tour de mañana o pasado mañana no alcanza el mínimo de pasajeros configurado — para que decidas si lo operás igual o lo cancelás. |

## Qué podés editar vos y qué es fijo

**Configurable** desde **Configuración**:
- Logo, color de marca, nombre de empresa (aparecen en el encabezado/pie de todos los emails).
- **Recomendaciones del email de confirmación**: una lista (una por línea) en español e inglés por separado, de cosas para tener en cuenta antes del tour (ej. "Llegá 10 minutos antes"). Si la dejás vacía, se usa una lista genérica de ejemplo.
- **Recomendaciones del voucher PDF**: es un contenido independiente del anterior — podés redactarlo distinto para el PDF que para el email.
- Los links de reseña de Google y TripAdvisor.
- El plazo de días para el email de solicitud de reseña.
- Una **nota personalizada** que podés agregar a mano en una reserva puntual (desde el detalle de la reserva o al cargar una manual), que aparece resaltada dentro de ese email de confirmación o de link de pago específico.

**Fijo** (no editable desde el panel hoy):
- El texto de la política de cancelación (7+ días: reembolso completo; 3–6 días: 50%; menos de 3 días: sin reembolso), que aparece en el email de confirmación y en el voucher, y que además es la que se usa para calcular el reembolso real cuando se cancela una reserva.
- La redacción general y el asunto de cada tipo de email.

> **Próximamente**: edición completa del cuerpo de cada plantilla de email desde el panel, y contenido extra personalizado por tour (por ejemplo "este tour requiere pasaporte") sumado automáticamente al email de confirmación de ese tour puntual. Todavía no está construido.

## El voucher / comprobante

El voucher es el comprobante de la reserva confirmada, en **PDF**: incluye número de reserva, datos del tour, punto de encuentro (con link a Google Maps), cantidad de personas, total pagado, nombre del pasajero, recomendaciones de qué llevar, la política de cancelación y un **código QR** — al escanearlo, lleva a la página pública donde se puede verificar el estado de esa reserva.

El cliente lo descarga con el botón **"Descargar mi voucher PDF"** dentro del email de confirmación, o desde la página de verificación de su reserva. Vos también podés descargarlo desde **TourFlow → Reservas → detalle de la reserva → Ver voucher PDF** (disponible para reservas confirmadas o completadas).

Si por algún motivo el servidor no puede generar el PDF, el sistema entrega automáticamente el mismo contenido como una página para imprimir/guardar — el cliente igual se lleva su comprobante, aunque en ese caso puntual no sea un archivo PDF real.
