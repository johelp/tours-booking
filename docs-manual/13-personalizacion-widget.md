[← Volver al índice](../MANUAL.md)

# 13. Personalización visual del widget de reserva

En **TourFlow → Configuración → 🎨 Widget de reserva** podés ajustar la apariencia del widget de reserva (`[amir_booking]`) sin tocar ningún código — los cambios se aplican al instante en todo el sitio.

| Control | Qué hace |
|---|---|
| **Color principal** | Un único selector de color (más su valor en hexadecimal, sincronizados entre sí). A partir de ese color, el sistema calcula automáticamente las variantes más oscuras, más claras y un tono intermedio para botones, acentos y fondos — así siempre queda una combinación legible, sin que tengas que elegir varios colores a mano ni arriesgarte a una combinación que no se lea bien. |
| **Tipografía** | Se elige de una lista cerrada ya probada: fuente del sistema (por defecto), Inter, Poppins, Nunito, Roboto o Lato. No se puede escribir el nombre de una fuente libre — es a propósito, para garantizar que siempre se vea bien en el ancho chico del widget. |
| **Tamaño de texto** | Compacto, Normal o Grande. Los campos de formulario y los botones táctiles (como los contadores +/- de personas) **nunca bajan de un tamaño mínimo**, aunque elijas "Compacto" — es el límite que evita que el celular haga zoom automático al tocar un campo, y que garantiza que los botones sigan siendo fáciles de tocar con el dedo. |
| **Radio de esquinas** | Cuadrado, Redondeado o Muy redondeado ("píldora") — el estilo de bordes de tarjetas, botones y campos. |
| **Texto de los pasos** | Mostrar el nombre de cada paso (ej. "Personas", "Pago") debajo de los íconos de la barra de progreso, u ocultarlo y dejar solo los íconos. |

## Qué todavía no se puede personalizar

- No hay un selector de **plantilla** o diseño alternativo — hoy existe **una sola plantilla** de ficha de tour y de widget de reserva; lo que personalizás acá son sus variables visuales (color, tipografía, tamaño, esquinas), no su estructura o el orden de los pasos. Una segunda plantilla de tour está en el plan de trabajo, pero todavía **no está disponible**.
- No hay un color secundario independiente (por ejemplo, un color distinto para botones vs. acentos) — es intencional, para evitar combinaciones ilegibles.
- No hay carga de tipografía personalizada/propia, solo las seis opciones curadas.
- Los ajustes son **globales**: se aplican al widget de todos los tours por igual, no hay personalización visual distinta por tour individual.
