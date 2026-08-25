[← Volver al índice](../MANUAL.md)

# 18. Tipos de tour y experiencia

Un tour no "es" un solo tipo — se arma combinando una opción de cada uno de estos cuatro ejes. La mayoría de las decisiones importantes están en el primer eje, **Disponibilidad**: ahí es donde se define si el cliente reserva al instante o si vos aprobás cada solicitud antes de cobrar.

> Este capítulo también existe como documento aparte, pensado para imprimir o compartir con tu equipo sin entrar al panel de administración.

## I. Disponibilidad — elegí una

Ordenado de más automático a más manual:

| Modo | Cómo se activa | ¿Cobra al instante? |
|---|---|---|
| **Calendario normal** | Es el modo por defecto de cualquier tour nuevo. | Sí |
| **Días determinados** | Mismo Calendario normal, con menos días tildados. | Sí |
| **Fecha fija** | Campo "📌 Fecha fija" en el editor del tour. | Sí |
| **Fecha fija + solicitar otra** | Viene incluido automáticamente en todo tour de Fecha fija. | No — recién al aprobar |
| **Solo a pedido** | Casilla "🙋 Todas las reservas de este tour requieren mi aprobación". | No — recién al aprobar |
| **Armá tu tour** | Casilla "Este tour no tiene precio fijo…", dentro de Solo a pedido (requiere tildar esa primero). | No — vos cargás el precio al aprobar |
| **Lista de interés** | Sección "📋 Lista de interés" del editor, tour todavía en borrador. | No — recién al aprobar/abrir |

### Calendario normal

El cliente ve un calendario y elige entre los días que dejaste abiertos. Vos definís esos días de dos formas combinables: los **días de la semana por defecto** (cuadro 📅 Disponibilidad del propio tour) y **reglas puntuales** — bloqueos de temporada, feriados, excepciones — en **TourFlow → Disponibilidad** (ver capítulo [5](05-disponibilidad.md)).

**Usalo cuando** el tour sale de forma regular y vos ya sabés de antemano qué días operás.

### Días determinados

No es un modo aparte — es el mismo Calendario normal con menos días tildados (por ejemplo, "solo martes y jueves"). Se menciona como categoría propia porque es el pedido más común: un tour que no sale todos los días.

**Usalo cuando** el tour tiene una frecuencia fija pero acotada.

### Fecha fija

Para un evento que pasa **una sola vez** en una fecha puntual. El widget de reserva no muestra calendario — va directo a esa fecha (y al horario, si hay más de uno configurado).

**Usalo cuando** es un evento único, no una experiencia recurrente — una salida especial, una fecha conmemorativa.

### Fecha fija + solicitar otra

Viene incluido automáticamente en todo tour de Fecha fija — no es una casilla aparte. Si a un cliente esa única fecha no le sirve, puede pedir una distinta desde el mismo widget, **sin pagar nada**. Vos ves la solicitud en **TourFlow → Reservas** (filtro "📅 Fecha solicitada", en la lista general, no una pantalla nueva) y decidís: si aprobás, se le manda el link de pago por email; si no, se descarta.

**Usalo cuando** ya tenés un tour de Fecha fija y querés poder aceptar pedidos fuera de esa fecha sin comprometerte de antemano.

### Solo a pedido

El cliente ve el calendario normal y elige fecha/horario como en cualquier tour — pero **ninguna reserva se cobra al instante**. Cada una nace como solicitud y esperás tu aprobación (mismo mecanismo que arriba: filtro "📅 Fecha solicitada" en Reservas) antes de que se le cobre nada al cliente.

**Usalo cuando** necesitás confirmar algo de tu lado (proveedor, clima, cupo real) antes de comprometer el cobro, pero el tour sí tiene un calendario normal de fondo.

### Armá tu tour

Es una variante de **Solo a pedido**, no un modo aparte — la casilla solo se puede tildar si "Solo a pedido" ya está activo. Para un tour **sin precio ni horario fijo**, porque depende de lo que pida cada cliente (un itinerario a medida). El widget no muestra calendario ni precio: el cliente solo indica cuántas personas son y describe qué quiere armar (el campo de solicitudes especiales pasa a obligatorio, es el único lugar donde cuenta su pedido). La reserva nace en $0 — vos cargás el precio real al aprobarla, antes de mandar el link de pago.

**Usalo cuando** el tour literalmente no tiene un precio de lista porque cada salida se arma distinta (excursiones a medida, paquetes privados con extras variables).

### Lista de interés

Para un tour que **todavía no publicaste**. Aparece en la sección "Próximamente" del sitio con una fecha tentativa que vos cargás; el cliente se anota sin pagar. Cuando el tour se abre (o alcanza el umbral de interesados que definiste), cada anotado recibe el link de pago real. Ver capítulo [8. Lista de interés](08-lista-de-interes.md).

**Usalo cuando** querés medir demanda antes de comprometerte a operar un tour nuevo.

## II. Horario dentro del día — elegí uno

Independiente del eje anterior — cualquier modo de Disponibilidad de arriba puede tener cualquiera de estos tres:

- **Sin horario** — se reserva sin hora asignada.
- **Horario único** — el widget lo asigna solo, sin pedirle nada al cliente.
- **Múltiples horarios** — el cliente elige entre las salidas que configuraste.

## III. Origen — elegí uno

- **Tour propio** — vos lo gestionás de punta a punta, es el caso por defecto.
- **Tour de proveedor externo** — lo revendés con margen propio. Se cobra de entrada; la reserva queda pendiente de que el proveedor confirme por email (con recordatorio y cancelación automática si no responde a tiempo). A diferencia de "Solo a pedido", acá el cliente **sí paga antes** de que se apruebe.

## IV. Modelo de precio — elegí uno

Ver capítulo [3. Precios y horarios](03-precios-y-horarios.md) para el detalle completo de cada uno.

- **Por persona** — tarifas separadas para adulto, niño y bebé.
- **Por grupo** — precio fijo según el tamaño total del grupo (privados).

## V. Cobro — elegí uno

- **Pago completo** — es el default de cualquier tour. El cliente paga el 100% online al reservar.
- **Depósito parcial** *(edición Pro Max, solo en el flujo Explorar/combinado por ahora — el widget clásico todavía no)* — casilla "💰 Depósito parcial" en el editor del tour, con un % configurable (ej. 20%). El cliente paga solo ese % online al reservar; el resto lo cobrás vos después, ya sea **en efectivo** el día de la experiencia (botón "💰 Marcar saldo cobrado" en Modo campo o en el detalle de la reserva) o **mandando un link de pago** para el saldo (botón "📧 Enviar link de pago del saldo", mismo mecanismo que el resto de los links de pago del plugin). La política de cancelación (100/50/0% según antelación) se aplica sobre lo que realmente cobraste, no sobre el precio total del tour. No aplica a habitaciones — esas siempre se cobran 100% online.

**Usalo cuando** el precio del tour es alto y pedir el 100% de entrada frena reservas, pero tampoco querés operar 100% a pedido/sin cobrar nada.

## VI. Cómo se reserva desde la ficha (edición Pro Max)

- **Flujo combinado** — es el default de cualquier tour en Pro Max. Reservar desde la ficha entra a un flujo con sugerencia de habitaciones y extras antes de pagar ("upsell siempre").
- **Reserva directa** — casilla "🎯 Reserva directa (Pro Max)" en el editor del tour. La ficha usa el widget clásico directo (calendario visible de entrada, sin pasos de upsell), igual que en Lite/Pro.

**Usalo cuando** el tour claramente no tiene sentido combinar con nada más — un traslado puntual, por ejemplo. Es una elección tuya por tour, no algo que el sistema detecte solo: si el tour sí podría combinarse con una habitación aunque sea poco frecuente, mejor dejarlo con el flujo combinado por defecto.

## Otros ejes

- **Solo para adultos** — destildando "Admite niños"/"Admite bebés" en el editor. Ver capítulo [4. Restricciones de edad](04-restricciones-de-edad.md).
- **Mínimo de personas por reserva** — campo numérico en el editor del tour (junto a "Capacidad máxima"). Si lo cargás (ej. 2), nadie puede reservar sola/individualmente por debajo de ese número — el widget avisa y bloquea el paso hasta sumar suficiente gente entre adultos+niños+bebés. No confundir con el aviso de cupo mínimo AGREGADO por salida (ese suma TODAS las reservas de una fecha para avisarte si conviene cancelarla completa, es otra cosa). No aplica a reservas manuales que cargues vos mismo desde el admin, ni a Lista de interés.
- **Habitaciones** (edición Pro Max) — no es un "tipo de tour", es un tipo de reserva paralelo con su propio motor de check-in/check-out.

## Tres tours reales

**Snorkel en Bacalar al amanecer** — Calendario normal · Múltiples horarios · Tour propio · Por persona. El caso típico: sale casi todos los días, el cliente elige entre dos salidas, y paga adulto/niño/bebé al confirmar.

**Cena de aniversario en el muelle** — Fecha fija + solicitar otra · Horario único · Tour propio · Por grupo. Un evento puntual con una sola fecha cargada — si a alguien no le sirve esa noche, puede pedir otra y vos decidís si te conviene armarla.

**Excursión a cenote privado en temporada alta** — Solo a pedido · Múltiples horarios · Tour propio · Por persona. El calendario se ve normal, pero en temporada alta preferís confirmar cupo real con el operador del cenote antes de cobrarle a nadie — cada reserva te llega como solicitud.

**Ruta a medida por la selva** — Armá tu tour · Sin horario · Tour propio · Mínimo 2 personas. No hay un itinerario fijo que mostrar: el cliente cuenta cuántos son y qué le interesa ver, vos armás el recorrido y le cotizás antes de mandarle el link de pago.
