# Prompt de arranque — Marketplace de proveedores externos

Copiar y pegar esto como primer mensaje de la sesión que implemente la feature.

---

Vamos a implementar el marketplace de proveedores externos (tours de terceros) para TourFlow. El spec de producto ya está **cerrado y dialogado con el cliente** — no es un feature nuevo a diseñar desde cero.

Antes de tocar código, leé en este orden:
1. `CONTRIBUTING.md § 11` — el spec completo: decisiones cerradas, flujo de reserva paso a paso, y el modelo de datos sketcheado (`amir_providers`, `amir_tours.provider_id`, `provider_cost_mxn` en `amir_prices`, status `pending_provider_approval` en `amir_bookings`, `amir_provider_payouts`).
2. La memoria del proyecto `project_marketplace_providers.md` — mismo contenido resumido, con el razonamiento del *por qué* de cada decisión.
3. `CLAUDE.md § Reglas críticas` y `CONTRIBUTING.md § 6` (Convenciones) — patrones obligatorios del repo (nonce + `current_user_can()`, `Installer::create_tables()` + `maybe_update()` con `ALTER TABLE` explícito para cualquier columna nueva, subir `AMIR_DB_VERSION`, páginas de admin en PHP plano sin React, tests en `tests/unit/`).

**No vuelvas a preguntar sobre lo que ya está decidido** (carga manual de tours por el equipo de TourFlow, aprobación por link de email sin login, recordatorio a 24h / cancelación automática a 48h, costo de proveedor separado del precio de venta, liquidación manual v1, aviso discreto al cliente sin nombrar al proveedor). Esas son decisiones de negocio ya cerradas con el cliente.

## Antes de escribir código, hacé estas preguntas (decisiones de implementación que sí quedaron abiertas)

1. **Alta de proveedores**: ¿el v1 necesita una pantalla admin nueva "Proveedores" (crear/editar/listar, para poder elegir uno en el dropdown del editor de tour), o alcanza con cargarlos directo en la base por esta vez y agregar la pantalla en una iteración siguiente? Sin algún tipo de alta no hay forma de asignarle `provider_id` a un tour desde el editor.

2. **Visibilidad de lo que se le debe a cada proveedor**: ¿el v1 necesita una pantalla (aunque sea simple, tipo lista) para ver el total pendiente de liquidar por proveedor y marcar pagos, o alcanza con que quede en la tabla `amir_provider_payouts` sin UI todavía (se consulta a mano)?

3. **Texto exacto del aviso al cliente**: el spec dice "discreto, sin nombrar al proveedor" — ¿tenés una redacción puntual en mente (ej. "Este tour es operado por un partner local, sujeto a confirmación") o defino yo una propuesta y la revisamos antes de mergear? Además, ¿va como badge junto al precio, como nota debajo de la descripción, o en el paso de resumen del checkout?

4. **Rechazo del proveedor — ¿con motivo o genérico?**: cuando el proveedor rechaza una reserva (sin cupo, etc.), ¿el link de rechazo le pide un motivo breve (que se le muestre al cliente en el email de cancelación) o alcanza con un rechazo genérico sin detalle?

5. **Moneda del costo del proveedor**: ¿siempre es la misma moneda configurada en el sitio (`Core\Currency`), o hay que contemplar que el proveedor cotice en otra moneda (ej. EUR) distinta a la que se le cobra al cliente? Esto es relevante sobre todo si esto se usa en instalaciones fuera de México/Argentina (ver memoria `project_visit_sicily_experiences`).

6. **Reenvío manual de la notificación**: si el email al proveedor rebota o se pierde, ¿hace falta un botón "Reenviar notificación" en el detalle de la reserva (mismo patrón que ya existe "Reenviar link de pago"), o alcanza con que el cron de recordatorio a las 24h cubra ese caso?

7. **Aviso visible para el equipo de TourFlow**: ¿conviene un badge/contador (mismo patrón que el badge de notificaciones no leídas del menú) para reservas `pending_provider_approval` que se están por vencer, o alcanza con que aparezcan en el listado normal de Reservas con su estado?

Con las respuestas, seguí el modelo de datos ya sketcheado en `CONTRIBUTING.md § 11` sin necesidad de rediseñarlo — solo ajustá el detalle según lo que se responda arriba. Al terminar, actualizá `CONTRIBUTING.md § 11` (pasar de "spec, sin construir" a documentar lo implementado, mismo patrón que las secciones 5.1/5.2/5.4) y `CLAUDE.md` con el estado de cierre de la sesión.
