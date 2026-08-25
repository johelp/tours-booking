[← Volver al índice](../MANUAL.md)

# 17. Preguntas frecuentes / solución de problemas comunes

### Edité un tour, publiqué, pero los cambios no aparecen en el sitio (o no aparece en Disponibilidad/Reservas/Reportes)

TourFlow guarda cada tour en dos lugares: como publicación normal de WordPress, y en una tabla interna propia que usa el resto del sistema (precios, disponibilidad, reportes). Normalmente se sincronizan solos al publicar/actualizar. Si notás un desfasaje:

1. Fijate si al entrar al editor del tour aparece un aviso rojo arriba de "este tour no se pudo sincronizar con la base de datos interna" — si aparece, avisá a soporte técnico con ese mensaje exacto.
2. Si no aparece ese aviso pero igual el tour no aparece donde debería, andá a **TourFlow → Configuración**, al final de la página, y usá el botón **🔄 Sincronizar todos los tours ahora** — vuelve a copiar todos los tours publicados hacia la base interna del plugin.

### El widget de reserva le muestra un error o "no hay cupos" siendo que debería haber

Revisá, en este orden: (1) que la fecha elegida no esté bloqueada por una regla en **TourFlow → Disponibilidad**; (2) que el horario elegido no haya llegado a la **Capacidad máxima** configurada en el tour; (3) si el tour es de precio por grupo, que no haya ya una reserva confirmada en ese mismo horario (en tours de grupo, un horario se llena con una sola reserva).

### Un cliente me dice que pagó pero la reserva sigue "pendiente"

Andá a **TourFlow → Log de pagos** y buscá por la referencia de la reserva — ahí vas a ver qué pasó realmente con el cobro (si el aviso automático de la pasarela llegó o no, y por qué). Si confirmás con el panel de Stripe/Mercado Pago que el pago sí se hizo, podés confirmar la reserva manualmente desde su detalle en **TourFlow → Reservas** ("Confirmar reserva manualmente").

### Configuré Mercado Pago (o Stripe) pero las reservas nuevas no cobran nada

En **Configuración → 🔀 Pasarela de pago**, confirmá cuál está marcada como "activa" — solo esa es la que se usa para cobrar. Si la activa es, por ejemplo, Mercado Pago pero solo cargaste las claves de Stripe, las reservas van a fallar al cobrar. La misma pantalla te avisa si la pasarela activa no tiene credenciales cargadas para su modo actual (test/live).

### Cancelé o aprobé un reembolso desde el panel, ¿el cliente ya recibió su plata?

No automáticamente. TourFlow calcula el reembolso que corresponde según la política de cancelación y le avisa al cliente por email, pero **el movimiento real de dinero hay que hacerlo manualmente** desde el panel de Stripe o de Mercado Pago.

### Publiqué un tour que tenía lista de interés activa y nadie recibió el email para pagar

Pasa cuando publicás el tour directamente desde el editor normal de WordPress en vez de usar el botón **"Publicar y notificar"** de **TourFlow → Lista de interés**. Entrá a esa pantalla: debería aparecerte un aviso de "tours publicados con reservas de interés sin avisar" con un botón **Notificar ahora** para mandar los emails pendientes.

### Cambié la moneda del negocio y ahora las reservas viejas se ven raras

Es esperable: TourFlow no convierte montos históricos al cambiar de moneda, solo cambia el símbolo/código que se muestra. Ver el detalle en el capítulo [11. Moneda](11-moneda.md). Evitá cambiar la moneda base si ya tenés historial de reservas cargado.

### Activé un idioma nuevo pero el contenido de mis tours sigue en español

Es el comportamiento esperado: activar un idioma no traduce automáticamente el contenido de los tours, solo habilita la pestaña donde tenés que cargarlo vos a mano, tour por tour. Ver capítulo [12. Idiomas](12-idiomas.md).

### Un cliente me dice que su email de confirmación le llegó incompleto o mal traducido

Confirmá primero en qué idioma reservó (los emails salen en el idioma de la reserva, no en el del sitio). Si el idioma es español, inglés, italiano, francés o portugués, el contenido fijo del sistema ya está traducido — si el problema persiste, puede tratarse de una sección de "recomendaciones" que todavía no tenga texto cargado para ese idioma específico en Configuración (hoy ese cuadro solo tiene campos dedicados para español e inglés). Reportalo a soporte técnico con el idioma exacto y qué sección del email se ve mal.

### No encuentro cómo aplicar acciones en lote (por ejemplo, cancelar varias reservas a la vez)

Todavía no está disponible — la selección múltiple en el listado de Reservas es una función pendiente de terminar. Por ahora, las acciones (confirmar, cancelar, reprogramar) se hacen una reserva a la vez desde su detalle.

### Quiero una segunda plantilla de diseño para la ficha de tour

Todavía no está disponible — hoy existe una única plantilla de ficha de tour y de widget de reserva. Lo que sí podés personalizar ya son sus colores, tipografía, tamaño de texto y radio de esquinas (ver capítulo [13](13-personalizacion-widget.md)).

### Quiero editar el diseño/texto completo de los emails, no solo las recomendaciones

Todavía no está disponible como función del panel — hoy solo son editables las secciones de "recomendaciones" (una por línea, en Configuración) y una nota personalizada por reserva puntual. El resto del contenido y diseño de cada email es fijo.

### ¿Cómo doy de alta a alguien de mi equipo para que use el panel sin darle acceso a todo WordPress?

Creá un usuario nuevo con el rol **Tour Manager** (**Usuarios → Añadir nuevo → Rol: Tour Manager**). Va a poder entrar al Dashboard, Calendario, Reservas, Disponibilidad, Partners, Lista de interés, Reportes, Log de pagos y a editar Tours — pero no a Cupones ni a Configuración, y tampoco al resto del sitio de WordPress (plugins, usuarios, temas, etc.).
