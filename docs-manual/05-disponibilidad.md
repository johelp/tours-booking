[← Volver al índice](../MANUAL.md)

# 5. Disponibilidad

Todo lo de este capítulo asume el modo más común: un tour con **calendario normal**, donde el cliente elige la fecha y paga al instante. TourFlow tiene otros dos modos para casos distintos — antes de seguir, conviene saber que existen:

- **Fecha fija** — el tour se realiza una sola vez, en una fecha puntual (evento único). El widget no muestra calendario, va directo a esa fecha. Se carga en el propio editor del tour, sección "📌 Fecha fija".
- **Solo a pedido** — el tour conserva el calendario normal (todo lo de este capítulo sigue aplicando), pero **ninguna reserva se cobra al instante**: cada una te llega como solicitud y vos aprobás o rechazás antes de que se le cobre algo al cliente. Se activa con una casilla en el editor del tour.

La guía completa con los seis modos combinables y ejemplos armados está en el capítulo **[18. Tipos de tour y experiencia](18-tipos-de-tour.md)** — pensado para decidir qué modo usar en cada tour nuevo, antes de entrar al editor. Lo que sigue acá es específico del modo de **calendario normal**.

Hay dos lugares donde se controla cuándo se puede reservar un tour con calendario normal:

1. **Días operativos por defecto** — se cargan en el propio editor del tour (cuadro 📅 Disponibilidad), tildando los días de la semana en que el tour opera normalmente.
2. **Excepciones y reglas puntuales** — se gestionan en **TourFlow → Disponibilidad**, la pantalla dedicada de este capítulo.

## La pantalla Disponibilidad

Arriba de todo elegís **qué tour** querés configurar (aparece como pestañas/chips con tus tours activos). Debajo ves la tabla de reglas de ese tour: tipo (bloquear o permitir), días de la semana afectados, rango de fechas, prioridad y motivo.

### Crear una regla nueva

- **Tipo de regla**: 🚫 *Bloquear* (esas fechas dejan de estar disponibles) o ✅ *Permitir* (abre una excepción sobre una regla de bloqueo anterior).
- **Días de la semana**: si no marcás ninguno, la regla aplica a todos los días del rango.
- **Fecha desde / hasta** (opcional): si las dejás vacías, la regla no tiene límite de fechas — aplica siempre.
- **Prioridad** (1 a 100): decide qué regla gana cuando dos se superponen para la misma fecha. **A mayor número, más prioridad.**
- **Motivo** (opcional): texto libre solo para tu referencia interna (ej. "temporada alta", "mantenimiento de equipo", "feriado nacional") — el cliente nunca ve este texto.

### Plantillas rápidas

Para los casos más comunes hay botones de un clic que crean la regla por vos: "Bloquear todos los lunes", "Bloquear todos los miércoles", "Bloquear fin de semana", "Solo operar L-V".

### Eliminar una regla

Con el botón ✕ de cada fila (pide confirmación). Al agregar o quitar una regla, el calendario público del tour se actualiza casi al instante.

## Cómo se resuelve cuando hay reglas que se pisan (prioridad)

El sistema evalúa las reglas del tour de **mayor a menor prioridad**, y usa la **primera que efectivamente aplica** a la fecha que se está consultando (que la fecha caiga en su rango y que el día de la semana coincida, si la regla especifica días). Las reglas de menor prioridad ni se llegan a mirar si una de mayor prioridad ya resolvió esa fecha.

**Ejemplo concreto** (el mismo que usa la propia pantalla como guía):

1. Regla base: "Bloquear todos los miércoles" — prioridad 10.
2. Excepción: "Permitir miércoles" con fecha desde 1/6 hasta 30/6 — prioridad 20.

Resultado: los miércoles están bloqueados todo el año, **excepto** en junio, donde sí están disponibles — porque la regla de junio tiene más prioridad (20 > 10) y gana.

Si para una fecha dada **ninguna regla aplica**, el tour se considera disponible por defecto (sujeto igual a los días operativos generales que cargaste en el editor del tour, y a que la fecha no sea del pasado — una fecha ya pasada nunca se puede reservar, sin excepción).

## Cupo dentro de un día habilitado

Que un día esté "disponible" no significa que tenga cupo infinito:

- En tours **por persona**, cada pasajero reservado resta del cupo máximo configurado (**Capacidad máxima**, en el editor del tour) para ese horario.
- En tours de **precio por grupo** (privados), apenas se confirma una reserva en un horario, ese horario queda completo — no se combinan pasajeros de distintas reservas en el mismo horario privado.

## Consejo práctico

Pensá siempre las reglas como una lista ordenada por prioridad, donde **la regla más específica** (una fecha puntual, una excepción de temporada) debe tener el número de prioridad **más alto** — así es la que gana por sobre la regla general.
