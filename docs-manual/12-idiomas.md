[← Volver al índice](../MANUAL.md)

# 12. Idiomas

## Activar un idioma nuevo

En **TourFlow → Configuración → 🌐 Idiomas**, hay un campo con los **códigos activos separados por coma** (por ejemplo `es, en, fr`). Para sumar un idioma nuevo, agregá su código de 2 letras (formato ISO 639-1: `de` alemán, `fr` francés, `it` italiano, `pt` portugués, etc.) a esa lista y guardá.

**Español (`es`) siempre está activo** y no se puede quitar — es el idioma de respaldo de todo el sistema: si algo no está traducido en un idioma nuevo, se muestra en español en su lugar antes que mostrar un hueco vacío.

En cuanto activás un idioma, aparece automáticamente **una pestaña/sección nueva en el editor de cada tour** (dentro del cuadro de Contenido) para cargar el texto en ese idioma — no hace falta tocar nada más.

## ¿Se traduce solo el contenido de los tours?

**No.** Activar un idioma en Configuración **no traduce automáticamente** el nombre, descripción, "qué incluye", punto de encuentro o itinerario de tus tours. Vos (u otra persona de tu equipo) tenés que completar manualmente el contenido en el idioma nuevo, tour por tour, en la sección que aparece en el editor. Si dejás un tour sin traducir en un idioma activo, ese tour se muestra en español para los visitantes que naveguen en ese idioma.

Español e inglés tienen sus propios campos "de siempre" en el editor (son los dos idiomas históricos del plugin); cualquier otro idioma que actives usa un bloque de campos equivalente que aparece más abajo, bajo el título "🌐 Otros idiomas".

## Idiomas con textos del sistema ya traducidos

Además del contenido de los tours (que siempre cargás vos), TourFlow tiene textos fijos propios del sistema (botones, mensajes de error, textos del widget de reserva, de los emails, del voucher). Esos textos **ya vienen traducidos de fábrica** para: **español, inglés, italiano, francés y portugués**.

Si activás un idioma distinto a esos cinco (por ejemplo alemán), el **contenido de tus tours** sí se muestra en ese idioma si lo cargaste, pero los **textos fijos del sistema** (botones, mensajes) van a seguir viéndose en español hasta que exista una traducción lista para ese idioma — no es un error, es la falta de un archivo de traducción para ese idioma puntual.

## En qué idioma le llegan los emails al cliente

Los emails automáticos (confirmación, recordatorio, voucher, etc. — ver capítulo [15](15-emails-automaticos.md)) se mandan **en el idioma con el que el cliente hizo la reserva**, no en el idioma general del sitio. Es decir: si alguien reservó navegando tu sitio en inglés, va a recibir todos sus emails y el voucher en inglés, sin importar en qué idioma esté configurado el sitio para el resto de los visitantes.
