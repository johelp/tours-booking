[← Volver al índice](../MANUAL.md)

# 8. Lista de interés

La lista de interés ("Próximamente") sirve para **medir demanda y empezar a vender un tour antes de tenerlo publicado del todo** — por ejemplo, una salida especial de temporada que todavía estás armando, pero de la que ya conocés la fecha y querés saber si hay suficiente gente interesada antes de confirmarla.

No hay que confundirla con una "lista de espera" para un tour ya publicado que se llenó de cupo — eso no existe en TourFlow hoy; la lista de interés es exclusivamente para tours que **todavía están en borrador** (sin publicar).

## Cómo activarla en un tour

En el editor del tour, cuadro **⚙ Configuración del tour**, sección **📋 Lista de interés**:

- **Activar lista de interés para este tour** (checkbox).
- **Umbral para avisar al admin**: número de anotados a partir del cual el sistema te manda una notificación (email + aviso en el Dashboard) para que decidas si abrís el tour. Dejalo en `0` si preferís solo acumular interesados sin un aviso automático.
- **Fecha del tour/retiro**: la fecha ya definida a la que la gente muestra interés. Hace falta cargarla a mano porque, mientras el tour sigue en borrador, no existe todavía un calendario de disponibilidad real (ese se activa recién al publicar).

Guardá el tour **sin publicarlo** — mientras esté en borrador es invisible en el catálogo normal, pero aparece en la sección "Próximamente" donde uses el shortcode `[amir_wishlist]`.

## Qué ve y hace el cliente

En `[amir_wishlist]`, el cliente ve el tour en modo "Próximamente" y puede anotarse completando un mini-formulario real: elige horario (si el tour tiene más de uno), cantidad de personas, ve el precio estimado ya calculado, y carga sus datos de contacto — **sin pagar nada todavía**. Cada anotación queda guardada como si fuera una reserva más, solo que en un estado especial de "interés" que no bloquea cupo ni tiene plazo de expiración.

## Cómo lo gestionás vos: TourFlow → Lista de interés

Esta pantalla te muestra, por cada tour en borrador con lista de interés activa: nombre, fecha planeada, cantidad de anotados y una barra de progreso hacia el umbral que definiste. Con **Ver anotados** ves el detalle completo: referencia, nombre, email, horario elegido, personas, precio y fecha en que se anotó cada uno.

### Publicar y notificar

Cuando decidís que el tour ya está listo para venderse de verdad, usá el botón **Publicar y notificar** (te va a pedir confirmación indicando cuántas personas se van a notificar). Esto hace dos cosas a la vez:

1. Publica el tour en WordPress (queda disponible normalmente, con calendario de disponibilidad real).
2. Le manda a **cada persona anotada** un email con un link para completar el pago de su reserva al precio ya calculado en su momento.

El botón queda deshabilitado si todavía no hay ningún anotado.

### Si publicás el tour "a mano" desde el editor, sin usar este botón

Esto es importante: si en algún momento publicás el tour directamente desde el editor normal de WordPress (por ejemplo, olvidándote de que tenía lista de interés activa), **las reservas de interés no se notifican solas** — quedan "esperando" sin que nadie les avise que ya pueden pagar. TourFlow lo detecta y te muestra un aviso especial arriba de todo en esta pantalla ("Tours publicados con reservas de interés sin avisar") con un botón **Notificar ahora** para mandar los emails pendientes en cualquier momento.

## Qué pasa cuando el cliente hace clic en el link de pago

El link lo lleva a la misma página de verificación de reserva de siempre. Ahí, si su reserva está en estado "esperando pago", se le muestra directamente el paso de pago (tarjeta o Mercado Pago, según la pasarela activa) para completar la compra. Recién en ese momento arranca el plazo real de expiración de la reserva (ver capítulo [10](10-pasarelas-de-pago.md)) — antes de que haga clic, su lugar no corre ningún riesgo de vencerse.

## Nota sobre nombres de campos heredados

Vas a ver que en algunos lugares internos del sistema los campos de pago se llaman con nombres relacionados a "Stripe" aunque hoy funcionan igual con Mercado Pago — es solo una cuestión de nomenclatura interna que no afecta el funcionamiento.
