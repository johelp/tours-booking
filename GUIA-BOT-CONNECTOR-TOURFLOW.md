# Guía de integración — TourFlow Bot Connector (chatbots y n8n)

Versión del conector: **0.2.0**. Estado: **sin probar en un WordPress real** (solo `php -l` y prueba local del servidor MCP). Antes de usarlo con clientes, correr el checklist del § 9.

## 1. Qué es y qué no es

Un plugin satélite de WordPress que expone una API propia (`/wp-json/tfbc/v1`) para que un chatbot, un agente de IA o un flujo de n8n puedan:

- **Consultar** el catálogo (tours; habitaciones y productos digitales en Pro Max) y el detalle de cada ítem.
- **Ver disponibilidad** de un tour por fecha o de una habitación por rango.
- **Cotizar** un tour con el motor de precios real.
- **Reservar (pendiente)** un tour: crea la reserva, retiene el cupo y devuelve un **link de pago**.
- **Consultar el estado** de una reserva.

**No** cobra, **no** recibe datos de tarjeta y **no** confirma reservas. El pago lo hace el cliente en la página del sitio con la pasarela activa (Stripe, Mercado Pago, Redsys). Reservar habitaciones o productos desde el bot **no está soportado** todavía (solo tours).

## 2. Instalación y credenciales

1. Instalar y activar `tourflow-bot-connector` (requiere TourFlow activo).
2. En wp-admin: **Bot Connector** → completar el *nombre del sitio* → **Generar API key**.
3. Copiar la key **en ese momento**: se muestra en texto plano una sola vez; después solo los últimos 4 caracteres. Si se pierde, se regenera (la anterior deja de valer).
4. Mantener el toggle de acceso activo. Se puede pausar sin rotar la key.

Todas las llamadas llevan el header:

```
X-TFBC-Api-Key: <tu key>
```

Base URL: `https://TU-SITIO/wp-json/tfbc/v1` (en el sitio headless, el dominio del **backend** de WordPress, no el del frontend).

Límite: 120 requests por 5 minutos por IP. Un chatbot que corre en un servidor propio comparte IP entre todos sus usuarios: agrupar llamadas.

> La key da acceso a crear reservas pendientes. Guardarla solo en el servidor (credencial de n8n, variable de entorno, secreto del Worker). Nunca en un frontend ni en el prompt del bot.

## 3. Endpoints

Todas las respuestas incluyen `"site": "<nombre del sitio>"`, útil si el bot atiende varias instalaciones.

| Método y ruta | Para qué | Parámetros |
|---|---|---|
| `GET /catalog` | Lista reservable | `lang` (es, en, it…) |
| `GET /items/{type}/{id}` | Detalle | `type`: tour, room, product; `lang` |
| `GET /availability` | Disponibilidad | `type`, `id`; tour: `date`; room: `check_in`, `check_out` |
| `POST /quote` | Cotización | `tour_id`, `date`, `adults` (obligatorios); `children`, `babies`, `schedule_id`, `coupon_code`, `lang` |
| `POST /book` | Reserva pendiente + link de pago | ver abajo |
| `GET /bookings/{ref}?email=` | Estado de reserva | `email` obligatorio |
| `GET /booking-link` | Link a la ficha con fecha precargada | `type`, `id`, `date`, `lang` |

Fechas siempre `YYYY-MM-DD`. Spec completa en [`tourflow-bot-connector/openapi.yaml`](tourflow-bot-connector/openapi.yaml) (reemplazar el host de `servers`).

### 3.1 `POST /book`

```json
{
  "tour_id": 12,
  "date": "2026-12-20",
  "adults": 2,
  "children": 1,
  "customer_name": "Ana Pérez",
  "customer_email": "ana@example.com",
  "customer_phone": "+52 983 000 0000",
  "lang": "es",
  "coupon_code": "",
  "special_requests": "",
  "participant_names": [],
  "policy_accepted": true,
  "terms_accepted": true
}
```

Respuesta 201:

```json
{
  "site": "Caliafarm",
  "success": true,
  "booking_ref": "AMIR-2026-00123",
  "status": "pending",
  "total": 1800,
  "currency": "MXN",
  "requires_payment": true,
  "payment_url": "https://…/verificar-reserva/?ref=…&token=…"
}
```

- Si `requires_payment` es `false` (tour de proveedor externo, "solo a pedido", solicitud de fecha, "armá tu tour"), **no hay** `payment_url`: el sitio le avisa al cliente por email cuando haya aprobación o precio. El bot debe decirlo así.
- Error 422: `{"success": false, "error": "…"}` con un mensaje legible (sin cupo, mínimo de personas, tour que no admite niños, faltan nombres de integrantes, cupón inválido). El bot debe mostrárselo al cliente y ofrecer alternativa, no reintentar a ciegas.
- La reserva pendiente **retiene cupo** hasta que vence (`amir_pending_expire_mins`, Configuración de TourFlow). Si el cliente no paga, se libera sola.

### 3.2 Consentimientos

`policy_accepted` y `terms_accepted` solo pueden ser `true` si el **cliente los aceptó explícitamente en la conversación** (mostrarle el texto o el link de la política y términos del sitio y pedir un "sí"). Marcarlos por él es incumplir el consentimiento y, en la UE, GDPR. El servidor rechaza la reserva si no llegan en `true`.

## 4. Flujo recomendado para el bot

1. **Descubrir**: `GET /catalog` (cachear unos minutos) → elegir ítem → `GET /items/tour/{id}` para el detalle.
2. **Fecha**: `GET /availability?type=tour&id=…&date=…`. Si no hay cupo, probar fechas cercanas.
3. **Cotizar**: `POST /quote` con personas y cupón.
4. **Confirmar con el cliente**: resumir tour, fecha, personas y total; pedir nombre, email, teléfono y aceptación de política y términos.
5. **Reservar**: `POST /book`. Entregar `payment_url` y aclarar que la reserva queda **pendiente hasta pagar**.
6. **Postventa**: `GET /bookings/{ref}?email=` para estado. Pagos pendientes devuelven de nuevo `payment_url`.

Si el cliente solo quiere mirar: `GET /booking-link` devuelve la ficha con la fecha precargada, sin crear nada.

### Prompt de sistema sugerido

```
Sos el asistente de reservas de {NOMBRE_DEL_NEGOCIO}. Respondé en el idioma del cliente.
Usá SIEMPRE las herramientas para precios, disponibilidad y reservas; nunca inventes datos.
Antes de crear una reserva, resumí tour, fecha, personas y total, y pedí confirmación explícita.
Pedí nombre, email y teléfono. Pedí que acepte la política de cancelación y los términos
(compartí los links) y SOLO marcá policy_accepted y terms_accepted en true si dijo que sí.
Nunca pidas ni aceptes datos de tarjeta: el pago se hace en el payment_url.
Aclará que la reserva queda pendiente hasta pagar y que el cupo se libera si no se paga a tiempo.
Si una herramienta devuelve un error, explicalo con sus palabras y ofrecé alternativas.
No hables de temas fuera de los tours/servicios del negocio.
```

## 5. n8n

### 5.1 Credencial

Crear una credencial **Header Auth**: nombre `X-TFBC-Api-Key`, valor la key. Reutilizarla en todos los nodos HTTP Request.

### 5.2 Opción A — nodos HTTP Request como herramientas de un AI Agent

1. Nodo **AI Agent** (con el modelo de tu preferencia y memoria de ventana por sesión).
2. Agregar un nodo **HTTP Request Tool** por herramienta, con la credencial de arriba:

| Herramienta | Método | URL | Parámetros |
|---|---|---|---|
| `list_catalog` | GET | `{{BASE}}/catalog` | query `lang` |
| `get_item` | GET | `{{BASE}}/items/{type}/{id}` | type, id, lang |
| `check_availability` | GET | `{{BASE}}/availability` | query: type, id, date / check_in / check_out |
| `quote_tour` | POST | `{{BASE}}/quote` | body JSON (ver § 3) |
| `create_pending_booking` | POST | `{{BASE}}/book` | body JSON (ver § 3.1) |
| `get_booking_status` | GET | `{{BASE}}/bookings/{ref}` | query `email` |

En cada herramienta, usar "Let the model define this parameter" y escribir una descripción corta de cuándo usarla (la misma que en `openapi.yaml`). Pegar el prompt del § 4 en el *System Message* del agente.

### 5.3 Opción B — un solo nodo MCP Client Tool

Si desplegás el servidor MCP (§ 6), el AI Agent usa un nodo **MCP Client Tool** apuntando a la URL del Worker con header `Authorization: Bearer <MCP_TOKEN>`. Expone las 7 herramientas de una vez y la API key de WordPress no queda en n8n.

### 5.4 Entrada del chat

- **WhatsApp / Telegram / web**: disparador correspondiente → AI Agent → nodo de respuesta. Usar el id de la conversación como clave de memoria.
- Con un disparador de Webhook, validar un secreto propio en el header antes de pasar el mensaje al agente.

### 5.5 Flujo sin IA (determinista)

Para un botón "Reservar" fijo: Webhook → HTTP `POST /quote` → IF `quote` válida → HTTP `POST /book` → enviar `payment_url` por WhatsApp/email. Útil si preferís formularios en vez de conversación.

## 6. Servidor MCP (Claude, ChatGPT, otros agentes)

Carpeta [`tourflow-bot-connector/mcp-server/`](tourflow-bot-connector/mcp-server/): un Cloudflare Worker sin dependencias que habla MCP por HTTP y llama a la API.

```bash
cd tourflow-bot-connector/mcp-server
npx wrangler secret put TFBC_BASE_URL   # https://TU-SITIO/wp-json/tfbc/v1
npx wrangler secret put TFBC_API_KEY    # la key del § 2
npx wrangler secret put MCP_TOKEN       # un token largo y aleatorio que inventás
npx wrangler deploy
```

En el cliente MCP, registrar la URL del Worker con el header `Authorization: Bearer <MCP_TOKEN>`. Herramientas: `list_catalog`, `get_item`, `check_availability`, `quote_tour`, `create_pending_booking`, `get_booking_status`, `get_booking_link`.

Un Worker por sitio (cada instalación tiene su propia key). Para atender Caliafarm y Visit Sicily, desplegar dos con nombres distintos.

## 7. Chatbot propio con function-calling

Cargar `openapi.yaml` (o las 7 herramientas del § 5.2) como *tools* del modelo, ejecutar las llamadas en tu backend (donde vive la key) y devolver el JSON al modelo. El frontend del chat nunca habla directo con WordPress.

## 8. Seguridad y buenas prácticas

- Key solo en servidor; rotarla si hay duda (Bot Connector → regenerar).
- No registrar en logs el `payment_url` completo (lleva el token de acceso a la reserva).
- `GET /bookings/{ref}` exige el email: el bot debe pedirlo al cliente, nunca deducirlo.
- Un bot abierto al público puede retener cupo creando reservas pendientes sin pagar. Mitigar: exigir nombre y email válidos, limitar reservas por conversación en tu lógica, y mantener corto el vencimiento de pendientes.
- Los mensajes de error del servidor pueden salir en el idioma del sitio; el bot puede reformularlos.

## 9. Checklist de verificación (pendiente)

- [ ] Generar key; sin header o con key mala → 401/403.
- [ ] `GET /catalog` y `/items/tour/{id}` devuelven datos y `site`.
- [ ] `/availability` con fecha con y sin cupo.
- [ ] `/quote` coincide con lo que muestra el widget.
- [ ] `/book` sin `terms_accepted` → 422; con todo → 201 y fila `pending` en Reservas.
- [ ] Abrir `payment_url` y pagar con tarjeta de prueba `4242…`: la reserva pasa a confirmada y llega el email.
- [ ] No pagar: la reserva vence y el cupo se libera.
- [ ] `/bookings/{ref}` con email correcto vs incorrecto (404 en ambos casos de error).
- [ ] Tour de proveedor externo: `requires_payment:false`, sin link.
- [ ] Flujo completo desde n8n y desde un cliente MCP.

## 10. Límites conocidos

Solo tours se reservan desde el bot; las habitaciones no tienen ficha pública propia (el link cae al archivo general); habitaciones y productos no resuelven idioma server-side igual que los tours; no hay cancelación ni reprogramación desde el bot (se hace en el sitio o por el operador).
