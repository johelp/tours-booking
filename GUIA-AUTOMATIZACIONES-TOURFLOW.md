# Automatizaciones con TourFlow (Zapier, Make, n8n)

Plugin satélite **Automations for TourFlow** (`tourflow-automations/`, v0.1.0). Cuando pasa algo en una reserva (se confirma, se cancela, espera aprobación de un proveedor…), TourFlow manda un `POST` con JSON a una URL que vos elegís. Esa URL es el "catch hook" de Zapier, el "custom webhook" de Make o el nodo Webhook de n8n: la herramienta recibe el dato y vos armás lo que sigue (una fila en Google Sheets, un aviso a Slack, un WhatsApp…).

Es un mecanismo abierto: cualquier herramienta que reciba un POST sirve. Por eso no hay una "app de Zapier" — el satélite no depende de ninguna plataforma en particular.

> **Estado:** Fase 1 (salida de eventos) construida y probada contra un receptor real en un WordPress local (firma verificada, reintentos, log). **No probada todavía en cuentas reales de Zapier, Make o n8n** — ver § 7. La Fase 2 (que una herramienta *cree* reservas en TourFlow) no está hecha.

---

## 1. Instalación y configuración (5 minutos)

1. Instalá `tourflow-automations-v0.1.0.zip` en el sitio, **con TourFlow (amir-booking) ya activo**.
2. Entrá a **Automatizaciones** en el menú del admin.
3. En el bloque **Agregar destino**:
   - **Nombre:** cualquiera, para reconocerlo (ej. "Planilla de reservas").
   - **URL del webhook:** la que te da la herramienta (ver § 3). Tiene que ser `https://`.
   - **Eventos:** marcá solo los que te interesan.
4. Tocá **Enviar prueba** en el destino: llega un evento de ejemplo con datos ficticios (`"test": true`) y la pantalla te dice si respondió 2xx.

Podés tener hasta **10 destinos**. Cada uno con sus eventos. Podés **pausar** un destino sin borrarlo.

## 2. Eventos disponibles

| Evento | Cuándo sale | Dato extra en `extra` |
|---|---|---|
| `amir_booking_confirmed` | Reserva de tour confirmada (pago recibido) | — |
| `flow_room_booking_confirmed` | Reserva de habitación confirmada (Pro Max) | — |
| `amir_booking_pending_provider_approval` | Reserva de un proveedor externo esperando su aprobación | — |
| `amir_booking_cancelled` | Reserva cancelada | `reason_type` (`client`, `admin`…) |
| `amir_booking_date_requested` | Cliente pidió otra fecha / reserva "a pedido" | — |
| `amir_booking_rescheduled` | Reserva de tour reprogramada | — |
| `flow_room_booking_rescheduled` | Reserva de habitación reprogramada (Pro Max) | — |
| `amir_booking_payment_failed` | Un pago fue rechazado (la reserva sigue en plazo de gracia) | `reason` |
| `amir_booking_balance_paid` | Se cobró el saldo de una reserva con depósito | — |

**Importante con el pago rechazado:** el evento sale en el **primer** intento fallido, pero la reserva **no se cancela**: queda reteniendo el cupo durante el plazo de gracia configurado en TourFlow. Si el cliente paga bien después, llega `amir_booking_confirmed`. Para un aviso de "cobro fallido" no uses `cancelled`.

## 3. Cómo conectar cada herramienta

### Zapier
1. Creá un Zap → trigger **Webhooks by Zapier** → evento **Catch Hook**.
2. Zapier te muestra una URL del tipo `https://hooks.zapier.com/hooks/catch/...`. Copiala.
3. En TourFlow, agregá un destino con esa URL.
4. En Zapier, **Test trigger**: como todavía no hay datos reales, usá **Enviar prueba** desde TourFlow. Zapier toma el JSON y te deja mapear los campos.
5. Conectá la acción (ej. **Google Sheets → Create Spreadsheet Row**) mapeando campos como `booking__customer_name`, `booking__tour_name`, `booking__tour_date`, `booking__total`.

Zapier aplana el JSON: `booking.customer_name` aparece como `booking__customer_name`.

### Make
1. Creá un escenario → módulo **Webhooks → Custom webhook**. Creá un webhook nuevo y copiá su URL.
2. En TourFlow, agregá el destino con esa URL.
3. En Make, **Redetermine data structure** después de la prueba de TourFlow.
4. Agregá la acción (ej. **Google Sheets → Add a Row**) mapeando `booking.customer_name`, `booking.tour_name`, etc.

### n8n
1. Creá un workflow → nodo **Webhook** (método `POST`). Copiá la **Production URL** (no la de test, salvo para probar).
2. En TourFlow, agregá el destino con esa URL.
3. Ejecutá el nodo en modo *Listen for test event* y tocá **Enviar prueba**.
4. Agregá el nodo que quieras (Google Sheets, Slack, Telegram…) leyendo `{{ $json.body.booking.customer_name }}`.

> En n8n el cuerpo llega dentro de `body` (`$json.body`). Es la herramienta que más fácil hace la verificación de firma (§ 5), porque el nodo Webhook puede exponer el cuerpo crudo.

## 4. Forma del JSON

Todos los eventos usan **la misma forma**. Lo que no aplica va en `null`:

```json
{
  "event": "amir_booking_confirmed",
  "site_url": "https://ejemplo.com",
  "site_name": "Mi operadora",
  "timestamp": "2026-10-03T14:30:00+00:00",
  "test": false,
  "extra": null,
  "booking": {
    "id": 123,
    "booking_ref": "TF-2026-00123",
    "item_type": "tour",
    "status": "confirmed",
    "tour_id": 5,
    "tour_name": "Snorkel en cenote",
    "room_id": null,
    "room_name": null,
    "tour_date": "2026-09-10",
    "check_out_date": null,
    "customer_name": "Ana García",
    "customer_email": "ana@ejemplo.com",
    "customer_phone": "+52 984 000 0000",
    "adults": 2,
    "children": 0,
    "babies": 0,
    "total": 1500,
    "currency": "MXN",
    "coupon_code": "VERANO20",
    "discount": 150,
    "deposit_pct": null,
    "lang": "es",
    "confirmed_at": "2026-09-03 10:12:00"
  }
}
```

- `booking.total` y `booking.discount` van en la moneda configurada del sitio, que viene indicada en `booking.currency` (ej. `MXN`, `ARS`, `USD`). Leé siempre `currency` junto con el monto.
- Las reservas **anonimizadas** (derecho al olvido) llegan con `customer_*` en `null`.
- Los tokens de acceso y los IDs de pasarela **nunca** se envían. La lista de campos es fija (`includes/class-tfa-payload.php`).

## 5. Firma y verificación

Cada envío trae dos cabeceras:

- `X-TourFlow-Timestamp`: segundos Unix del envío.
- `X-TourFlow-Signature`: `sha256=` + HMAC-SHA256 de `timestamp + "." + cuerpo`, con el **secreto del sitio**.

Para verificarla hay que hacer lo mismo, con el **cuerpo tal cual llegó** (el texto crudo, no el JSON re-serializado) y el mismo secreto. El secreto está en **Automatizaciones → Secreto de firma** (se muestra enmascarado; si lo regenerás, copialo en el momento porque no se vuelve a ver completo, y actualizalo en tus herramientas).

Además conviene rechazar envíos con timestamp viejo (más de 5 minutos) para evitar replays.

**Node.js** (Zapier "Code by Zapier", o un servidor propio):
```js
const crypto = require('crypto');
function verifyTourFlow(rawBody, timestamp, secret, signature) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret)
    .update(timestamp + '.' + rawBody).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature));
}
```

**PHP** (si recibís el webhook en tu propio servidor):
```php
$expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw_body, $secret);
$ok = hash_equals($expected, $signature);
```

**Limitación honesta:** en Zapier el *Catch Hook* entrega el JSON ya parseado y no siempre expone el cuerpo crudo ni las cabeceras, así que la verificación **no es confiable ahí**. Si la firma importa para tu caso (ej. un evento que dispara un pago o un reembolso), recibí el webhook en **n8n** o en tu propio endpoint. Para un registro en una planilla, verificar no es crítico, pero la URL del hook es un secreto: no la compartas.

## 6. Reintentos, log y cron

- Si el destino responde algo distinto de 2xx (o no responde), TourFlow reintenta: **1 min** después del primer fallo, y **10 min** después del segundo. Tras el tercer intento queda como `failed`.
- Todo queda en el log de la misma pantalla (últimos 500 envíos): evento, reserva, intento, resultado y código HTTP.
- El envío **no bloquea** el checkout del cliente: se encola en WP-Cron.

**Importante para hosting:** WP-Cron se dispara con visitas al sitio. Si el sitio tiene poco tráfico, o el hosting desactiva WP-Cron (`DISABLE_WP_CRON`), los envíos se demoran. Para que sean puntuales, configurá un cron real del servidor que corra cada minuto:

```bash
* * * * * cd /ruta/a/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

(requiere WP-CLI en el servidor; si tu hosting no lo permite, pedí al proveedor un cron de HTTP a `wp-cron.php`.)

## 7. Qué está probado y qué no

| Qué | Estado |
|---|---|
| Envío de punta a punta (`amir_booking_confirmed`) | Probado en WordPress local (MariaDB) |
| Forma del payload de `amir_booking_cancelled` (con `reason_type`) | Probado solo al construir el JSON; no se envió a un receptor |
| Firma HMAC verificada por un receptor independiente | Probado (receptor en Python, `firma_ok=True`) |
| Reintentos a +1 min y +10 min tras un 500 | Probado (log con intentos 1 y 2) |
| Ausencia de tokens/IDs de pasarela en el payload | Probado (búsqueda del token en el JSON, no aparece) |
| Botón "Enviar prueba" | Probado (HTTP 200, registrado en el log) |
| Pantalla de admin sin errores | Probado (renderizado como administrador) |
| **Zapier, Make, n8n con cuenta real** | **No probado todavía** — las instrucciones de § 3 están escritas a partir de la documentación de cada herramienta |
| Verificación de firma dentro de Zapier / Make | **No confiable / no probado** (ver § 5) |
| Cron real del servidor | **No probado** (en el sandbox se usó `wp cron event run`) |
| Envío de los eventos con el sitio real en producción | **No probado** — ver la checklist de `PENDIENTES-VERIFICACION-EN-VIVO.md` |

## 8. Privacidad

El payload incluye **datos personales de clientes** (nombre, email, teléfono). Al configurar un destino, el operador le está enviando esos datos a un tercero (Zapier, Make, n8n o el servicio que elija). El operador es responsable de tener la base legal y el acuerdo de tratamiento con esa herramienta. Si un cliente ejerce el derecho al olvido, la reserva se anonimiza en TourFlow, pero lo que ya se envió a una planilla o a un CRM externo hay que borrarlo allá.

## 9. Pendiente (Fase 2, no construida)

- **Acción inversa:** que Zapier/Make/n8n *creen* una reserva en TourFlow (ej. desde un formulario de Typeform o una respuesta de WhatsApp). Requiere un endpoint propio con API key, que llame a `BookingManager::create_manual()`. Queda pendiente de decisión del cliente.
- **Evento genérico de "cualquier reserva nueva"** (`amir_booking_created`): hoy hay que suscribirse a los eventos por estado. Un hook genérico en el núcleo sería un cambio chico si un caso real lo pide.
