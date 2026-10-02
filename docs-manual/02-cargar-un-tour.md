[← Volver al índice](../MANUAL.md)

# 2. Cargar un tour

Para crear o editar un tour: **TourFlow → + Nuevo tour** (o **TourFlow → Tours (editar)** para modificar uno existente). El editor de un tour es la pantalla normal de WordPress para escribir contenido, pero con varios cuadros ("meta boxes") debajo donde cargás todos los datos específicos del tour. Se guardan todos juntos al **Publicar** o **Actualizar**.

> El editor de tours de TourFlow usa siempre el editor clásico de WordPress (no el editor por bloques), aunque el resto del sitio use bloques — es intencional, para que toda la información del tour se guarde de forma confiable en un único paso al publicar.

## Título y descripción principal (en español)

El **título** de la publicación es el nombre del tour en español, y el **cuerpo del editor** (el área de texto grande) es la descripción principal en español. Estos dos campos son la base de todo — no hay un campo aparte para "nombre en español", es el título de WordPress.

## ⚙ Configuración del tour

| Campo | Qué es |
|---|---|
| **Nombre en inglés (EN)** | El nombre del tour para cuando el sitio se muestra en inglés. Si lo dejás vacío, se usa el título en español como respaldo. |
| **Modelo de precio** | *Por persona* (adulto/niño/bebé) o *Precio fijo por grupo* (privado). Define qué sección de precios ves más abajo — ver capítulo [3](03-precios-y-horarios.md). |
| **Duración (minutos)** | Ej: `120` para un tour de 2 horas. Se muestra convertido a horas/minutos en la ficha pública. |
| **Edad mínima** | Edad mínima general para hacer el tour (ej. `12` para un tour de tirolesa). Se muestra en las tarjetas del listado y en la ficha. |
| **Capacidad máxima** | Cupo máximo de personas por salida/horario. |
| **Mín. pasajeros p/operar** | Cumple dos roles con el mismo número. (1) Si un tour próximo (mañana o pasado mañana) no llega a este mínimo SUMANDO todas sus reservas confirmadas, el sistema te avisa por email y con una notificación en el Dashboard, para que decidas si operarlo igual o cancelarlo. (2) Además bloquea, reserva por reserva, que alguien reserve solo/a por debajo de este número — si cargás `2`, nadie puede reservar individualmente para 1 sola persona, el widget avisa y no deja continuar hasta sumar suficiente gente. Dejalo en `1` (o vacío) si no querés exigir un mínimo por reserva. |
| **Idiomas disponibles** | Lista libre separada por coma, ej. `Español, English` — es informativa (se muestra como chip en la ficha), no cambia el idioma del sitio. |
| **Orden de listado** | Número para controlar el orden en que aparece este tour dentro de `[amir_tour_list]` (menor = aparece antes). |

### 📌 Fecha fija (evento único, opcional)

Para un tour que se realiza **una sola vez** en una fecha puntual — cargala acá y el widget de reserva salta el calendario, va directo a esa fecha. Dejalo vacío para el comportamiento normal (calendario con disponibilidad por reglas). Ver capítulo [18. Tipos de tour y experiencia](18-tipos-de-tour.md) para las combinaciones posibles y cuándo conviene cada una.

### 🙋 Solo a pedido (sin cobro inmediato)

Casilla independiente de la anterior — con esto tildado, el tour conserva su calendario normal, pero **ninguna reserva se cobra al instante**: cada una nace como solicitud que aprobás/rechazás manualmente desde Reservas antes de que se le cobre algo al cliente. Ver capítulo [18](18-tipos-de-tour.md).

Dentro de esta misma sección hay una segunda casilla, **"Este tour no tiene precio fijo — el cliente arma su pedido y yo cotizo"** ("Armá tu tour"), que solo se puede activar si "Solo a pedido" ya está tildado. Con eso el tour no muestra calendario ni precio: el cliente indica cuántas personas son y describe qué quiere armar, y vos cargás el precio real al aprobar la solicitud.

### 💰 Depósito parcial (Pro Max, opcional)

Solo visible en la edición Pro Max. Con esto tildado, el cliente paga online solo un % del total al reservar (lo definís vos, entre 1 y 99) — el resto lo cobrás después, en efectivo el día de la experiencia (botón "Marcar saldo cobrado") o mandando un link de pago aparte para el saldo. Por ahora solo funciona reservando desde el flujo Explorar/combinado — el widget clásico todavía cobra el 100%. No aplica a habitaciones.

### 🎯 Reserva directa (Pro Max, opcional)

Solo visible en la edición Pro Max. Por defecto, en Pro Max reservar un tour desde su ficha entra siempre al flujo combinado (sugiere habitaciones/extras antes de pagar). Tildá esto para los tours que no tiene sentido combinar con nada más (ej. un traslado puntual) — la ficha usa entonces el widget clásico directo, igual que en Lite/Pro.

### 🙈 Venta separada (opcional)

Dos casillas independientes para un tour que solo tiene sentido reservar por su cuenta (ej. un traslado): **ocultar de listas/grillas** (no aparece en `[flow_tour_list]` ni en destacados/catálogo) y **ocultar de sugerencias** (no aparece como "otro tour sugerido" en el paso de extras de otra reserva). El tour se sigue reservando normal desde su propia ficha — solo cambia dónde aparece listado.

### 👶 Restricciones de edad

Ver capítulo dedicado: [4. Restricciones de edad](04-restricciones-de-edad.md).

### 📋 Lista de interés ("Próximamente")

Ver capítulo dedicado: [8. Lista de interés](08-lista-de-interes.md).

## 📝 Contenido bilingüe (EN)

Acá cargás todo el contenido que tiene versión en inglés, más — si activaste idiomas adicionales en Configuración → Idiomas (ver capítulo [12](12-idiomas.md)) — una sección extra por cada idioma nuevo.

- **Qué esperar (ES / EN)**: un párrafo describiendo la experiencia (nivel de dificultad, qué se siente, recomendaciones generales).
- **Tour name (EN)**: el mismo campo de nombre en inglés que ya viste arriba, repetido acá como referencia.
- **Incluye / No incluye (ES / EN)**: una lista, **un ítem por línea** (por ejemplo: "Equipo de snorkel", "Guía certificado", "Bebidas"). Se muestran como listas con check/cruz en la ficha del tour.
- **Itinerario (ES / EN)**, opcional: texto libre para detallar el recorrido paso a paso.

Si activaste un idioma nuevo (por ejemplo alemán o francés), aparece un bloque **🌐 Otros idiomas** con los mismos campos (nombre, punto de encuentro, descripción, qué esperar, incluye/no incluye, itinerario) para cada idioma activo — se cargan a mano, el sistema no traduce automáticamente el contenido (ver [12. Idiomas](12-idiomas.md)).

## 🖼 Galería de fotos

La **imagen destacada** (panel derecho del editor, el mismo campo estándar de WordPress) es la foto principal del tour — la que aparece primero en todos lados.

Debajo, con el botón **+ Agregar fotos a la galería**, podés subir o elegir varias fotos adicionales desde la biblioteca de medios de WordPress. Se pueden quitar con la ✕ que aparece sobre cada miniatura. El orden en que las agregás es el orden en que se muestran.

## 💰 Precios y horarios

Ver capítulo dedicado: [3. Precios y horarios](03-precios-y-horarios.md).

## 🎁 Servicios extra

Ver capítulo dedicado: [6. Servicios extra (add-ons)](06-servicios-extra.md).

## 📅 Disponibilidad

Acá elegís los **días de la semana en que el tour opera por defecto** (domingo a sábado, tildando los que correspondan). Esta es la regla base; las excepciones puntuales (cerrar un feriado, abrir una fecha especial, etc.) se gestionan aparte en **TourFlow → Disponibilidad** — ver capítulo [5](05-disponibilidad.md).

## 📍 Punto de encuentro

- **Punto de encuentro (ES / EN)**: dirección o descripción del lugar de encuentro (ej. "Muelle municipal, frente a la farmacia").
- **Latitud / Longitud**: coordenadas GPS exactas, usadas para mostrar el mapa y el botón "Ver en mapa" en el widget de reserva y en el voucher. Para conseguirlas: abrí Google Maps, clic derecho sobre el punto exacto, copiá las coordenadas que aparecen (formato `18.6849, -87.9789`) y pegalas en los dos campos por separado.

## 🔗 Integraciones externas

- **TripAdvisor ID**: el identificador de tu experiencia en TripAdvisor (si lo usás para mostrar reseñas o enlazar).
- **GetYourGuide ID**: identificador de la actividad en GetYourGuide, si el tour también se vende ahí.

Estos dos campos son solo de referencia/integración — no conectan automáticamente reservas ni sincronizan cupos con esas plataformas.

## ❓ Preguntas frecuentes (FAQ) — opcional

Sección para dudas puntuales de ESE tour (ej. "¿Puedo ir solo?", "¿Qué pasa si llueve?") — no es un FAQ general del sitio, es propio de cada tour.

- Botón **+ Agregar pregunta** — agrega una fila con pregunta (ES/EN) y respuesta (ES/EN).
- Si no cargás ninguna pregunta, la sección no se muestra en la página del tour — es 100 % opcional.
- Se muestra como un acordeón (click para expandir cada pregunta) debajo de "Punto de encuentro", en las dos plantillas de detalle (Clásica e Inmersiva).

## Ejemplo completo

Un tour real cargado de punta a punta se vería así:

- **Título:** Snorkel en Bacalar al amanecer
- **Nombre EN:** Bacalar Sunrise Snorkeling
- **Modelo de precio:** Por persona
- **Duración:** 180 (3 horas) · **Edad mínima:** 6 · **Capacidad máxima:** 12 · **Mín. pasajeros:** 4
- **Restricciones de edad:** admite niños desde 6 años (por eso no hace falta destildar "Admite niños"), admite bebés
- **Horarios:** 06:00–09:00 "Salida amanecer" / 09:30–12:30 "Salida media mañana"
- **Precios:** Adulto $650, Niño $450, Bebé $0
- **Punto de encuentro:** Muelle municipal de Bacalar, con lat/lng cargadas
- **Incluye (ES):** Equipo de snorkel · Guía certificado · Agua embotellada
- **No incluye (ES):** Transporte al muelle · Fotos profesionales

Al publicarlo, ya queda disponible en `/tour/bacalar-sunrise-snorkeling/` (o el slug que corresponda al título) con el widget de reserva funcionando.
