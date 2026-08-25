# Continuación: Redsys + normativa europea (prioridad adelantada) — después de v5.2.1

**Pedido explícito del cliente (2026-08-06): adelantar Redsys + adaptación a normativa europea, para poder comercializar el plugin en Europa.** Esto invierte el orden que el cliente había cerrado antes ("Redsys/GDPR al final", `CLAUDE.md` § roadmap ítem 21, "por decisión explícita del cliente") — la decisión nueva pisa a la vieja, documentado acá para que quede claro que no es un olvido.

Leer primero `CLAUDE.md` completo (especialmente "Reglas críticas" y la regla de pasarelas de pago), después este archivo. `CONTRIBUTING.md §§ 16.23-16.36` tiene el detalle de sesión por sesión si hace falta contexto de algo puntual.

## 0. Estado al cierre de esta sesión (v5.2.1)

- **Plugin en v5.2.1**, `AMIR_DB_VERSION` en `1.25.0`.
- **Tours de fecha fija** (v5.2.0) — construido y probado, solo widget clásico. Ver `CONTRIBUTING.md § 16.35`.
- **Bug crítico corregido en vivo** (v5.2.1) — el panel de detalle del flujo combinado se desbordaba en sidebars angostos (`@media` → `@container`). Encontrado navegando al sitio real de Caliafarm y midiendo el layout con JS, no adivinando desde una captura. Ver `CONTRIBUTING.md § 16.36`. **Sin confirmar todavía que el cliente lo vio andar bien en producción** — es lo primero a preguntar al retomar, por si hay que iterar antes de seguir con cualquier otra cosa.

### "Solicitar fecha" — ✅ terminado en v5.3.0 (2026-08-08), ya no es pendiente

Lo que acá abajo describía como "a medio construir" se terminó antes de arrancar Redsys — el cliente pidió explícitamente cerrar esto primero. Ver `CONTRIBUTING.md § 16.37` para el detalle completo: `AMIR_DB_VERSION` → 1.26.0 (`maybe_update()` con el `ALTER TABLE` que faltaba), `BookingManager::create_date_request()`, `POST /amir/v1/tours/{id}/request-date`, `RequestDateBanner` en el widget clásico, y aprobar/rechazar desde `BookingsPage` (filtro por estado, sin pantalla nueva). Diseño confirmado sin timers automáticos — no es el patrón de proveedores externos.

## 1. Redsys — integración de pasarela de pago

### Por qué

El cliente quiere vender en Europa. Hoy las pasarelas activas son Stripe (global) y Mercado Pago (LatAm) — ninguna es la opción natural para comercios españoles/europeos. Redsys es la red de procesamiento interbancaria española (el "TPV Virtual") — la integración de pago que usa la mayoría de bancos españoles (BBVA, Santander, CaixaBank, etc. como entidades adquirentes).

### La arquitectura ya está lista para esto — no es un proyecto desde cero

`includes/payments/class-payment-gateway-interface.php` ya define el contrato (`id()`, `is_configured()`, `create_payment()`, `verify_webhook_signature()`, `parse_webhook_event()`, `fetch_payment_status()`, `refund()`) que implementan `StripeGateway` y `MercadoPagoGateway` — agregar Redsys es implementar la misma interfaz + registrarla en `PaymentGatewayFactory::make()` (`CLAUDE.md` regla 6, `BookingController` no se toca). Mirar `MercadoPagoGateway` como referencia más cercana (ambos son gateways de flujo redirect/notificación, a diferencia de Stripe que es más JS-first con `PaymentIntent`).

### Lo que ya se investigó esta sesión (con fuentes reales, no de memoria)

- Redsys firma con **HMAC_SHA256_V1**: el comercio arma los parámetros de la operación, los cifra con 3DES usando la clave del comercio, y genera un HMAC-SHA256 sobre eso — la firma resultante (`Ds_Signature`) va en el form que redirige al TPV.
- El **flujo es por redirección**: el comercio arma un formulario con los parámetros firmados y el navegador del cliente redirige al TPV de Redsys; Redsys vuelve a pegarle a una **URL de notificación** (`Ds_Merchant_MerchantURL`) del lado servidor con el resultado (independiente del navegador — como un webhook), y por separado redirige al navegador a una URL de "ok"/"ko".
- Manual oficial (conexión por redirección): https://canales.redsys.es/canales/ayuda/documentacion/Manual%20integracion%20para%20conexion%20por%20Redireccion.pdf — **hay que confirmar que este link siga vivo y bajar la versión más actual** antes de implementar nada, los manuales de Redsys se actualizan.
- Implementaciones de referencia (para entender el formato de parámetros/firma en código real, no reinventar): https://github.com/eusonlito/redsys-TPV (PHP) y https://github.com/santiperez/node-redsys-api (Node, pero el algoritmo de firma es el mismo, útil para entender el HMAC-SHA256).

### Antes de escribir código, cerrar con el cliente (no asumir)

1. **¿El cliente ya tiene un contrato con un banco adquirente español/europeo?** Esto es un tema de negocio, no técnico — Redsys no es una cuenta que se abre online como Stripe; hace falta un contrato de comercio con un banco (BBVA, Santander, CaixaBank, etc.) que a su vez da de alta el TPV Virtual y entrega las credenciales (código de comercio, clave secreta, terminal). Sin esto no hay nada para integrar todavía — preguntar primero.
2. **¿Sandbox/entorno de pruebas?** Redsys tiene un entorno de test (`sis-t.redsys.es:25443` históricamente) con credenciales de prueba propias del banco adquirente — confirmar que el cliente puede conseguirlas antes de empezar a codear a ciegas.
3. **Conexión por redirección vs. Web Service (SOAP/REST directo)** — el manual de Redsys documenta ambos modos. Redirección es más simple y es el patrón que ya usa Mercado Pago acá (`init_point`) — evaluar si conviene ese mismo criterio por consistencia, o si el cliente/banco pide específicamente Web Service.
4. **Refunds y consulta de estado** — a diferencia de Stripe/MP, no está confirmado que Redsys tenga una API de consulta de estado simétrica a `fetch_payment_status()` fácil de llamar bajo demanda (es más un modelo push/notificación). Investigar esto específicamente antes de implementar esa parte de la interfaz — puede necesitar un ajuste al contrato (`?PaymentStatusResult` ya es nullable, así que "no se pudo verificar" ya está contemplado, pero confirmar el mecanismo real).

## 2. Normativa europea — lo que ya está bien y lo que falta investigar

### Ya construido, confirmado sin código nuevo (sesión de `Multi-idioma` y `GDPR`, ver `CLAUDE.md`)

- Checkbox de aceptación de términos + registro de consentimiento (`consent_recorded_at`/`consent_text_hash`, § 15.3 en `CONTRIBUTING.md`).
- Banner de cookies.
- "Derecho al olvido" manual desde el admin (anonimización, § 15.3 pieza 4).
- Multi-moneda (EUR ya soportado) y multi-idioma (más allá de es/en).

### Confirmado esta sesión — buena noticia para el modelo de negocio

**Los tours/experiencias de fecha específica están exentos del derecho de desistimiento de 14 días** que aplica por default a las ventas a distancia en la UE. Es el **Artículo 16(l) de la Directiva 2011/83/UE** (Consumer Rights Directive): servicios de alojamiento (no residencial), transporte, alquiler de autos, catering, y **servicios de actividades de ocio, cuando el contrato prevé una fecha o período específico de ejecución**, quedan exentos. Como TourFlow reserva siempre para una fecha concreta, la mayoría de las reservas ya califican para la exención — hay que **verificarlo con un abogado antes de confiar en esto para producción** (esto es investigación, no asesoría legal), pero es el punto de partida correcto en vez de asumir que hace falta construir un flujo de devolución de 14 días desde cero.

Hay un matiz encontrado en la búsqueda que vale la pena investigar más si el marketplace de proveedores externos (§ 11, `CONTRIBUTING.md`) entra en juego para clientes europeos: el caso **CJEU Eventim (C-96/21)** trata sobre el derecho de desistimiento en reventa de entradas/intermediación — relevante si TourFlow actúa como intermediario revendiendo tours de terceros, no como el operador directo. Puede que la exención se aplique distinto según si TourFlow es el prestador directo o el intermediario — a chequear antes de asumir que el marketplace queda cubierto igual que un tour propio.

### Sin investigar todavía — próxima sesión antes de tocar código

- **IVA/facturación europea** — requisitos de qué debe mostrar un checkout/factura en la UE (precio con IVA incluido visible antes de pagar, datos fiscales del comercio, numeración de factura, etc.). Hoy `Currency` maneja moneda pero no hay lógica de IVA en ningún lado — confirmar qué necesita el cliente exactamente (¿un solo país, o varios con tasas de IVA distintas?).
- **SCA / 3D Secure 2** — pagos con tarjeta en la UE requieren autenticación fuerte del cliente (PSD2). Confirmar que Redsys lo maneja nativo en su flujo (probablemente sí, es parte estándar del TPV Virtual) y que Stripe ya lo tiene cubierto para clientes europeos que paguen con Stripe (Stripe ya soporta 3DS2, pero confirmar que el flujo actual del widget no lo esté bloqueando de alguna forma).
- **Información legal obligatoria pre-contrato** — la Directiva de Derechos del Consumidor exige mostrar cierta información antes de que el cliente pague (identidad y dirección del comercio, características principales del servicio, precio total, condiciones de cancelación). Auditar el checkout actual (`StepSummary`/`CheckoutForm`) contra esta lista — probablemente falta poco, pero no asumir.

## 3. Pendientes de sesiones anteriores, sin resolver — no perder de vista

- **Dos bugs reportados sin confirmar, esperando `debug.log` del cliente hace varias sesiones** — pantalla en blanco al agregar una habitación, y `503` en `/tours/featured`. Seguir preguntando por el log antes de adivinar. Ver `PROMPT-UX-CONTINUACION.md § 1` para el detalle completo.
- **Backlog de UX/UI** (Dashboard, voucher combinado si queda algo, etc.) — ver `PROMPT-UX-CONTINUACION.md`, pausado a favor de esta nueva prioridad, no descartado.

## 4. Recordatorios rápidos (el detalle completo ya está en `CLAUDE.md`)

- `react-src/` es la fuente del widget — nunca editar `assets/js/*`/`assets/css/*` a mano.
- Cambios de esquema: `create_tables()` **y** `maybe_update()` con `ALTER TABLE` explícito, subir `AMIR_DB_VERSION` — no repetir el olvido a medias de "Solicitar fecha" de esta sesión.
- Pasarelas de pago: implementar `PaymentGatewayInterface` + registrar en `PaymentGatewayFactory::make()`, el controlador no se toca.
- Sin WordPress local — usar Docker para lint/tests (`php -l`, `vendor/bin/phpunit`), y el método de preview local con bundle real + `fetch` mockeado para verificar UI (documentado en `PROMPT-UX-CONTINUACION.md § 4`) — o, si hace falta verificar contra el sitio real, navegar directo y medir con `javascript_tool`/`getBoundingClientRect()` en vez de adivinar desde una captura (así se encontró el bug de v5.2.1).
- Subir `AMIR_VERSION` en cualquier ZIP nuevo, aunque el cambio sea solo PHP.
