# Spec: conector de automatización (Zapier/Make/n8n) para TourFlow — sin construir, para otra sesión

**Estado: solo spec, cero código.** Pedido explícito del cliente (2026-08-24): documentar antes de construir, mismo criterio que `PROMPT-REDSYS-EUROPA.md`. Pensado para arrancarse en una sesión dedicada aparte, como plugin satélite — no toca el núcleo salvo un posible agregado menor opcional (§ 6).

Leer primero `GUIA-PLUGINS-SATELITE-TOURFLOW.md` completo — este documento asume ese contexto (qué hooks ya existen, las convenciones de namespace/prefijo, el checklist de § 8) y no lo repite.

## 0. Por qué esto y por qué ahora

Investigación de mercado pedida por el cliente (2026-08-24, ver conversación) sobre qué integraciones ofrecen 8 competidores directos (FareHarbor, Bokun, Rezdy, Checkfront, Xola, Peek Pro, TrekkSoft, Regiondo): **automatización tipo Zapier es la única categoría de integración presente en los 8 sin excepción** — más consistente incluso que canales OTA o contabilidad. Es también, con diferencia, la más barata de construir para TourFlow: los hooks de ciclo de vida de una reserva (`amir_booking_confirmed`, `amir_booking_cancelled`, etc.) ya existen desde v5.6.19 — no hace falta inventar el punto de extensión, solo exponerlo hacia afuera. La propia `GUIA-PLUGINS-SATELITE-TOURFLOW.md § 7` ya lo señala como hueco conocido: *"Hook genérico de creación de reserva... útil para un satélite tipo 'mandar todo a Zapier/Make' que no quiera enganchar 5 hooks distintos"*.

## 1. Decisión de arquitectura ya tomada (para no dar vueltas en la próxima sesión)

**No construir una "app" oficial publicada en el Zapier Developer Platform como primer paso.** Publicar una app en Zapier implica pasar su proceso de revisión, mantenerla versionada ahí, y solo sirve para Zapier — no para Make, n8n, Pabbly, o cualquier otra herramienta que el operador ya use. En cambio:

**Fase 1 = un despachador de webhooks salientes genérico**, configurable desde el admin: el operador pega la URL que le da SU herramienta (el "Catch Hook" de Zapier, el módulo "Custom Webhook" de Make, un nodo Webhook de n8n — todas exponen una URL para recibir POSTs, es el mecanismo más universal que existe), elige qué eventos le interesan, y el satélite le manda un POST con JSON cada vez que pasan. Cero proceso de aprobación de terceros, funciona con cualquier herramienta el día que se instala, y valida demanda real antes de invertir en publicar algo en el marketplace de Zapier — que sería la Fase 3, solo si esto despega.

## 2. Casos de uso concretos (lo que un operador arma con esto)

Ninguno de estos requiere código nuevo en TourFlow más allá del despachador — son combinaciones que el OPERADOR arma del otro lado, en su cuenta de Zapier/Make:

1. **Reserva confirmada → fila nueva en Google Sheets.** El caso de uso #1 en cualquier encuesta de por qué alguien usa Zapier con su software de reservas — un registro/backup fuera del plugin, sin pedirle al operador que aprenda a usar Reportes.
2. **Reserva confirmada → aviso a Slack/Telegram del equipo.** Reemplaza "mirar el Dashboard cada rato" para un equipo chico.
3. **Reserva de proveedor externo esperando aprobación (`amir_booking_pending_provider_approval`) → SMS/WhatsApp al operador.** Este es el caso de uso con más urgencia real: hay una ventana de 24h antes del recordatorio automático y 48h antes de la auto-cancelación+reembolso (§ 11 CONTRIBUTING.md) — un aviso instantáneo evita perder una venta por no haber visto el email a tiempo.
4. **Reserva cancelada (`amir_booking_cancelled`) → actualizar una hoja de cálculo de flujo de caja**, o avisar a contabilidad.
5. **Nueva reserva en lista de interés (wishlist) → agregar el contacto a un CRM/lista de leads** (HubSpot, Airtable, Notion) — hoy ese dato vive solo dentro de TourFlow.
6. **Cupón usado (`coupon_code` en el payload de `amir_booking_confirmed`) → registrar en una hoja de ROI de partners/campañas** — hoy no hay ningún reporte de "qué cupón trajo qué reserva" fuera de mirar la tabla a mano.
7. **Solicitud de cancelación (`cancellation_requested`, visible en el listado de Reservas) → alerta al equipo para revisión manual** antes de que se resuelva sola.
8. **Reserva de habitación confirmada (`flow_room_booking_confirmed`) → mismo tipo de flujo que el 1-2, pero para Pro Max.**

**Caso de uso en la dirección inversa** (Zapier/Make → TourFlow, "acción" en vez de "trigger"): una agencia recibe una reserva por teléfono, WhatsApp, o un formulario externo (Typeform, Google Forms) y quiere cargarla en TourFlow sin entrar a wp-admin. `BookingManager::create_manual()` ya existe y ya es lo que usa el formulario "Nueva reserva manual" de `class-bookings-page.php` — exponerlo como acción de Zapier ("cuando llega una respuesta de Typeform, creá una reserva en TourFlow") es funcionalidad real, no solo notificaciones. Ver § 5.2.

## 3. Qué eventos exponer — prioridad sugerida

De los ~13 hooks ya documentados en `GUIA-PLUGINS-SATELITE-TOURFLOW.md § 2`, no hace falta exponer los 13 desde el día uno. Orden sugerido por valor/frecuencia de uso real (casos de uso § 2):

| Prioridad | Hook del núcleo | Por qué primero |
|---|---|---|
| 1 | `amir_booking_confirmed` | El evento que más operadores van a querer (casos 1, 2, 6) |
| 1 | `flow_room_booking_confirmed` | Mismo que arriba, Pro Max |
| 2 | `amir_booking_pending_provider_approval` | Urgencia real de negocio (caso 3) |
| 2 | `amir_booking_cancelled` | Caso 4 |
| 3 | `amir_booking_date_requested` | Menos frecuente, pero fácil de sumar junto con los de arriba |
| 4 (opcional) | Resto de los hooks de § 2 de la guía (reprogramada, reembolso, recordatorios) | Sumar solo si un operador real lo pide — no construir de más antes de tener uso real |

## 4. Forma del payload — definir el contrato una sola vez

Cada evento manda un JSON con una forma **consistente entre todos los eventos** (mismo esqueleto, campos que no aplican van `null`), para que el operador arme su Zap/escenario una sola vez y lo reutilice:

```json
{
  "event": "amir_booking_confirmed",
  "site_url": "https://ejemplo.com",
  "timestamp": "2026-08-24T14:30:00Z",
  "booking": {
    "id": 123,
    "booking_ref": "TF-2026-00123",
    "item_type": "tour",
    "status": "confirmed",
    "tour_id": 5,
    "tour_name": "Snorkel en cenote",
    "room_id": null,
    "tour_date": "2026-09-10",
    "customer_name": "Ana García",
    "customer_email": "ana@ejemplo.com",
    "customer_phone": "+52...",
    "adults": 2, "children": 0, "babies": 0,
    "total_mxn": 1500.0,
    "currency": "MXN",
    "coupon_code": "VERANO20",
    "lang": "es"
  }
}
```

Este esqueleto se arma UNA vez (`WebhookPayloadBuilder` o similar, un solo método `from_booking_id(int $id, string $event): array`) y lo reusan todos los listeners de hooks — sin repetir el `SELECT`/formateo por cada evento.

**Seguridad del webhook saliente**: firmar el payload con HMAC-SHA256 usando un secreto generado por instalación (mismo criterio que ya usan los webhooks ENTRANTES de Stripe/MP, pero en la dirección contraria) — header `X-TourFlow-Signature`, para que quien reciba el webhook pueda verificar que vino de verdad de este sitio y no de alguien que adivinó la URL. Documentar cómo verificarlo (ejemplo en Zapier "Code by Zapier"/Make "Verify HMAC").

## 5. Funciones concretas del satélite

### 5.1 Admin — pantalla de configuración

Una pantalla nueva (`TourFlow → 🔌 Automatizaciones` o similar, dentro del NAMESPACE del satélite, no del núcleo):
- Lista de "destinos" (endpoints) configurados: URL + qué eventos de § 3 tiene tildados cada uno + activo/pausado.
- Botón "Enviar prueba" por destino — manda un payload de ejemplo (datos ficticios) para que el operador pueda armar su Zap/escenario sin esperar una reserva real.
- Log simple de los últimos N envíos por destino (éxito/fallo, código de respuesta) — mismo espíritu que el Log de pagos del núcleo, para que un webhook roto no falle en silencio.
- Reintentos: si el destino no responde 2xx, reintentar con backoff (ej. 3 intentos: inmediato, +1min, +10min) antes de marcarlo como fallido en el log — un Zap pausado por el operador no debería perder el evento para siempre.

### 5.2 Acción inversa — crear reserva manual (fase 2, no fase 1)

Endpoint REST propio del satélite (`POST /wp-json/zapier-tourflow/v1/bookings`, namespace propio, nunca `amir/v1`) que valida una API key propia (no reusar nonces de WP, esto lo llama un servicio externo sin sesión de usuario) y llama a `BookingManager::create_manual()` con los datos recibidos — mismo motor que ya usa el formulario de "Nueva reserva manual" del admin, cero lógica de negocio duplicada (regla de oro de la guía, § 6).

### 5.3 Fase 3 (opcional, solo si hay demanda real): app publicada en Zapier

Si el despachador genérico (Fase 1) demuestra que operadores lo usan de verdad, recién ahí vale la pena invertir en una app nativa de Zapier (mejor UX: el operador busca "TourFlow" en Zapier en vez de tener que copiar una URL) usando el Zapier Platform CLI, con triggers tipo "REST Hook" (Zapier llama a un endpoint de suscripción/desuscripción propio, en vez del polling). Esto es trabajo de mantenimiento continuo (Zapier audita apps públicas periódicamente) — no arrancar acá sin validar la Fase 1 primero.

## 6. Único cambio opcional al núcleo — no bloqueante

`GUIA-PLUGINS-SATELITE-TOURFLOW.md § 7` señala que no existe un hook `amir_booking_created` que dispare para **cualquier** reserva nueva sin importar el camino (pending, date_requested, pending_provider_approval, wishlist). El satélite puede arrancar perfectamente enganchándose a los hooks específicos que ya existen (§ 3 de este documento) — no es un bloqueante. Si más adelante un caso de uso real pide "avisame de CUALQUIER reserva nueva sin importar el estado", ahí sí vale la pena sumar ese hook genérico al núcleo (cambio chico, un `do_action()` más al final de cada método `create_*` de `BookingManager`) — no adelantarlo sin un caso de uso real que lo pida.

## 7. Decisiones a cerrar con el cliente antes de escribir código (no asumir)

1. **¿Este satélite es gratis/incluido con cualquier edición, o un producto aparte con su propio precio?** Decisión de negocio, no técnica — afecta si vive en un repo público/gratuito o en el mismo modelo de venta que el núcleo.
2. **¿Cuántos destinos (endpoints) por instalación?** ¿Uno solo, o varios con distintos eventos cada uno? (§ 5.1 asume "varios" — confirmar que no es sobre-ingeniería para el caso de uso real).
3. **¿Alcance inicial: todas las ediciones (Lite/Pro/Pro Max) o solo Pro+?** Los hooks de § 3 ya existen en el núcleo compartido — no hay razón técnica para restringirlo por edición, pero puede ser una decisión de diferenciación comercial (como el depósito parcial fue Pro Max-only por decisión de negocio, no técnica).
4. **Nombre del plugin/producto** — "Zapier for TourFlow" puede ser engañoso si Fase 1 no es una app de Zapier de verdad todavía (es un despachador de webhooks genérico). Considerar algo como "Automations for TourFlow" o "Flows for TourFlow" que no prometa una integración oficial que no existe hasta la Fase 3.

## 8. Recordatorios rápidos (detalle completo en `CLAUDE.md`/`GUIA-PLUGINS-SATELITE-TOURFLOW.md`)

- Namespace propio (nunca `AmirBooking\`/`TourFlow\`), prefijo de opciones/tablas propio, `Requires Plugins: amir-booking` en el docblock — checklist completo en `GUIA-PLUGINS-SATELITE-TOURFLOW.md § 8`.
- No duplicar lógica de negocio — `BookingManager::create_manual()` para la acción inversa (§ 5.2), nunca INSERT directo a `amir_bookings`.
- Mismo pipeline de siempre para probar sin WordPress real: Docker para `php -l`/`phpunit` si el satélite suma tests, harness aislado con un `$wpdb` fake para lógica de dominio.
