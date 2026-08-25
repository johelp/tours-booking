[← Volver al índice](../MANUAL.md)

# 4. Restricciones de edad

TourFlow tiene dos niveles de control de edad, ambos en el editor del tour, en el cuadro **⚙ Configuración del tour**:

## Edad mínima general

El campo **Edad mínima** (ej. `12`) es informativo: se muestra como chip en la ficha del tour y en las tarjetas del listado, y también aparece como aviso dentro del propio widget de reserva, justo en el paso donde el cliente elige cuántas personas van — para que quede visible justo en el momento en que decide cuántos niños o bebés lleva.

## Admite niños / Admite bebés (sección "👶 Restricciones de edad")

Este es el control que decide si el widget de reserva **deja elegir niños y bebés como categoría de pasajero, o no**:

| Campo | Qué controla |
|---|---|
| **Admite niños** (checkbox, tildado por defecto) | Si se destilda, el contador de "Niños" directamente desaparece del paso de personas del widget — el cliente solo puede reservar adultos (y bebés, si están permitidos). |
| **Admite bebés** (checkbox, tildado por defecto) | Igual que el anterior, pero para el contador de "Bebés". |
| **Edad mínima "niño" (vs. bebé)** | El umbral de edad que separa "bebé" de "niño" en el widget (por defecto 4 años: por debajo de esa edad el sistema y el cliente lo consideran bebé, no niño). |

### Ejemplo: tour solo para adultos

Si tenés, por ejemplo, un tour de buceo certificado o una ruta de tirolesa que por seguridad **no admite menores**, destildá tanto "Admite niños" como "Admite bebés". El resultado, para el cliente:

- En el paso de "Personas" del widget de reserva, solo ve el contador de Adultos — ni siquiera aparecen los contadores de Niños/Bebés en pantalla.
- Si de todas formas alguien intentara forzar una reserva con niños o bebés (por ejemplo manipulando la petición directamente, sin pasar por el formulario visible), el sistema **rechaza la reserva en el servidor** con un mensaje de error — no depende únicamente de ocultar los contadores en pantalla, hay una verificación real del lado del servidor.

### Excepción: reservas manuales cargadas por vos

Si vos (el operador) cargás una reserva a mano desde **TourFlow → Reservas → + Nueva reserva** (por ejemplo porque un cliente te escribió por WhatsApp pidiendo traer a su hijo a un tour marcado como solo-adultos), esa restricción **no se aplica** a las reservas manuales — es una excepción intencional para que puedas hacer una excepción puntual con criterio propio. La restricción solo bloquea reservas que el cliente hace por su cuenta a través del widget público.
