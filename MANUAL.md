# Manual de TourFlow

TourFlow es el plugin de WordPress que usa tu negocio para publicar tours, recibir reservas online, cobrar (con Stripe o Mercado Pago) y gestionar todo el día a día de la operación: calendario, disponibilidad, partners, cupones, reportes y comunicación automática con tus clientes por email.

Este manual está escrito para vos, el operador — el dueño o el staff que usa el panel de administración día a día. No asume conocimientos de programación.

> Versión del plugin al momento de escribir este manual: **v5.7.7**. Actualizado en esta ronda: capítulos [2](docs-manual/02-cargar-un-tour.md) y [18](docs-manual/18-tipos-de-tour.md) — sumaron "Armá tu tour" (variante de Solo a pedido sin precio fijo), "Mínimo de personas por reserva" (ahora bloquea reservas individuales chicas, no solo avisa por email), y las secciones Pro Max que faltaban del editor de tour (Depósito parcial, Reserva directa, Venta separada). Rondas anteriores actualizaron el capítulo [1](docs-manual/01-primeros-pasos.md) (tabla de shortcodes al día — nombres `flow_*`, `[flow_spots_left]` y `[flow_tour_dates]` nuevos, sección Pro Max con `[flow_discovery]`/`[flow_explore]`/`[flow_search_bar]`/`[flow_room_list]`/`[flow_room_search]`), y los capítulos [3](docs-manual/03-precios-y-horarios.md) y [5](docs-manual/05-disponibilidad.md). El resto del manual (Marketplace de proveedores, habitaciones/Pro Max, flujo continuo, GDPR, multi-idioma 3+, personalización visual v3+, wishlist con reserva real, video en la ficha, flujo Explorar) todavía no tiene su capítulo propio — son features reales y en uso, pendientes de documentar acá. Algunas funciones marcadas como "próximamente" ya están terminadas y no se actualizó la nota — verificá contra el panel si tenés dudas.

## Índice

1. [Primeros pasos](docs-manual/01-primeros-pasos.md) — qué es TourFlow, instalación, cómo publicar tu primera página de reservas
2. [Cargar un tour](docs-manual/02-cargar-un-tour.md) — cada campo del editor de tours, con ejemplos
3. [Precios y horarios](docs-manual/03-precios-y-horarios.md) — precio por persona vs. por grupo
4. [Restricciones de edad](docs-manual/04-restricciones-de-edad.md) — edad mínima, tours solo para adultos
5. [Disponibilidad](docs-manual/05-disponibilidad.md) — días operativos y excepciones
6. [Servicios extra (add-ons)](docs-manual/06-servicios-extra.md)
7. [Cupones de descuento](docs-manual/07-cupones.md) — tipos, cómo crearlos, links con descuento automático
8. [Lista de interés](docs-manual/08-lista-de-interes.md) — vender tours antes de publicarlos
9. [Partners y referidos](docs-manual/09-partners.md) — links de referido y comisiones
10. [Pasarelas de pago](docs-manual/10-pasarelas-de-pago.md) — Stripe vs. Mercado Pago
11. [Moneda](docs-manual/11-moneda.md)
12. [Idiomas](docs-manual/12-idiomas.md) — activar un idioma nuevo
13. [Personalización visual del widget](docs-manual/13-personalizacion-widget.md) — colores y tipografía
14. [Marketing](docs-manual/14-marketing.md) — Meta Pixel, Google Ads, GA4
15. [Emails automáticos](docs-manual/15-emails-automaticos.md) — cuáles se mandan y cuándo
16. [Panel de administración](docs-manual/16-panel-administracion.md) — recorrido de cada pantalla
17. [Preguntas frecuentes / solución de problemas](docs-manual/17-faq.md)
18. [Tipos de tour y experiencia](docs-manual/18-tipos-de-tour.md) — los siete modos de disponibilidad combinables, con ejemplos armados

---

### Cómo leer este manual

Cada capítulo es independiente — podés ir directo al tema que necesitás resolver hoy. Si es tu primera vez con TourFlow, seguí el orden: **Primeros pasos → Cargar un tour → Precios y horarios** te dejan con tu primer tour publicado y reservable.
