# Stripe Integration Skill

Cuando se invoca este skill (`/stripe`), actuás como experto en integraciones Stripe. Revisás el código existente contra las mejores prácticas documentadas aquí, identificás problemas, y los corregís o explicás según lo que pida el usuario.

---

## 1. DECISIÓN DE ARQUITECTURA

### ¿Cuándo usar Payment Intents vs Checkout Sessions?

| Escenario | Recomendación |
|-----------|--------------|
| UI embebida (SPA, app propia) | **Payment Intents + Payment Element** |
| Redirect a página de Stripe | **Checkout Sessions** (menos código, más features) |
| Suscripciones / facturación | **Checkout Sessions o Billing API** |
| Marketplace / Connect | **Payment Intents + Transfer** |
| Pagos futuros (sin presencia) | **Setup Intents** → luego PI off-session |

> Stripe recomienda Checkout Sessions para la mayoría de casos nuevos. Usar Payment Intents solo cuando se necesite UI totalmente embebida o flujo de checkout custom.

---

## 2. CICLO DE VIDA DEL PAYMENT INTENT

```
requires_payment_method
        ↓
requires_confirmation (si confirmation_method='manual')
        ↓
requires_action (3DS, bank redirect)
        ↓
processing
        ↓
succeeded  ──────────────────────────→ (charge.refunded → refunded/partially_refunded)
        ↓
canceled
```

**Regla crítica**: Nunca inferir el resultado del pago solo por el lado del cliente. Siempre confirmar vía webhook `payment_intent.succeeded`.

---

## 3. IMPLEMENTACIÓN SERVER-SIDE (PHP sin SDK)

### 3.1 Crear PaymentIntent — patrón correcto

```php
function stripe_create_payment_intent( $amount_cents, $currency, $opts = [] ) {
    $secret_key = get_stripe_secret_key(); // desde config/env, nunca hardcodeado

    // Idempotency key: basada en session/cart ID, no en timestamp
    $idempotency_key = 'pi_create_' . ($opts['session_id'] ?? uniqid('', true));

    $body = [
        'amount'                     => (int) $amount_cents, // siempre entero, en centavos
        'currency'                   => strtolower($currency), // 'usd', 'ars', 'eur'
        'automatic_payment_methods[enabled]' => 'true',
        'description'                => sanitize_text_field($opts['description'] ?? ''),
        'receipt_email'              => sanitize_email($opts['email'] ?? ''),
        'statement_descriptor_suffix'=> substr(sanitize_text_field($opts['descriptor'] ?? ''), 0, 22),
        'metadata[source]'           => sanitize_text_field($opts['source'] ?? 'app'),
        'metadata[customer_name]'    => sanitize_text_field($opts['customer_name'] ?? ''),
        'metadata[customer_email]'   => sanitize_email($opts['email'] ?? ''),
        'metadata[internal_ref]'     => sanitize_text_field($opts['internal_ref'] ?? ''),
    ];

    $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
        'headers' => [
            'Authorization'   => 'Bearer ' . $secret_key,
            'Content-Type'    => 'application/x-www-form-urlencoded',
            'Idempotency-Key' => $idempotency_key,            // ← SIEMPRE
            'Stripe-Version'  => '2024-06-20',                // ← SIEMPRE pinear versión
        ],
        'body'    => $body,
        'timeout' => 15,                                       // ← SIEMPRE setear timeout
    ]);

    return stripe_parse_response( $response );
}
```

### 3.2 Actualizar PaymentIntent (amount cambió, ej: cupón)

```php
function stripe_update_payment_intent( $pi_id, $new_amount_cents, $session_id ) {
    $response = wp_remote_post(
        'https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id),
        [
            'headers' => [
                'Authorization'   => 'Bearer ' . get_stripe_secret_key(),
                'Content-Type'    => 'application/x-www-form-urlencoded',
                'Idempotency-Key' => 'pi_update_' . $pi_id . '_' . $session_id,
                'Stripe-Version'  => '2024-06-20',
            ],
            'body'    => [ 'amount' => (int) $new_amount_cents ],
            'timeout' => 15,
        ]
    );
    return stripe_parse_response( $response );
}
```

### 3.3 Parser de respuesta centralizado

```php
function stripe_parse_response( $response ) {
    if ( is_wp_error( $response ) ) {
        // Error de red/conexión — loguear para diagnóstico
        error_log( '[Stripe] Connection error: ' . $response->get_error_message() );
        return [ 'error' => [ 'type' => 'connection_error', 'message' => $response->get_error_message() ] ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code >= 400 || isset( $body['error'] ) ) {
        error_log( '[Stripe] API error ' . $code . ': ' . ($body['error']['message'] ?? 'unknown') );
    }

    return $body;
}
```

### 3.4 Verificar monto server-side (CRÍTICO — evita fraude)

```php
// Al crear la orden, verificar que el PI amount == order total calculado server-side
function verify_pi_amount( $pi_id, $expected_amount_cents ) {
    $response = wp_remote_get(
        'https://api.stripe.com/v1/payment_intents/' . rawurlencode($pi_id),
        [
            'headers' => [
                'Authorization'  => 'Bearer ' . get_stripe_secret_key(),
                'Stripe-Version' => '2024-06-20',
            ],
            'timeout' => 10,
        ]
    );

    $data = stripe_parse_response( $response );
    if ( isset( $data['error'] ) ) return false;

    // Tolerancia de 0: el monto debe coincidir exactamente
    return (int) $data['amount'] === (int) $expected_amount_cents;
}

// Uso en creación de orden:
// if ( ! verify_pi_amount( $stripe_pi, round( $order_total * 100 ) ) ) {
//     return new WP_Error('amount_mismatch', 'Payment amount does not match order total.', ['status'=>400]);
// }
```

---

## 4. WEBHOOK — IMPLEMENTACIÓN CORRECTA

### 4.1 Verificación de firma (resistente a timing attacks)

```php
function stripe_verify_webhook_signature( $payload, $sig_header, $secret ) {
    if ( empty($secret) || empty($sig_header) ) return true; // sin secret configurado, aceptar todo

    $parts     = explode(',', $sig_header);
    $timestamp = '';
    $sigs      = [];

    foreach ( $parts as $part ) {
        [$k, $v] = explode('=', $part, 2);
        if ( $k === 't'  ) $timestamp = $v;
        if ( $k === 'v1' ) $sigs[]    = $v;
    }

    if ( empty($timestamp) ) return false;

    // Prevenir replay attacks: rechazar eventos con más de 5 minutos de diferencia
    if ( abs( time() - (int) $timestamp ) > 300 ) return false;

    $signed_payload = $timestamp . '.' . $payload;
    $expected       = hash_hmac( 'sha256', $signed_payload, $secret );

    foreach ( $sigs as $sig ) {
        if ( hash_equals( $expected, $sig ) ) return true; // ← hash_equals, no ==
    }

    return false;
}
```

### 4.2 Handler de webhook — estructura correcta

```php
function handle_stripe_webhook( $request ) {
    $payload    = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $secret     = get_stripe_webhook_secret();

    // 1. Verificar firma ANTES de cualquier procesamiento
    if ( ! stripe_verify_webhook_signature( $payload, $sig_header, $secret ) ) {
        return new WP_Error( 'invalid_signature', 'Webhook signature verification failed.', ['status' => 400] );
    }

    $event = json_decode( $payload, true );
    if ( ! $event || ! isset($event['id'], $event['type']) ) {
        return new WP_Error( 'invalid_payload', 'Invalid event payload.', ['status' => 400] );
    }

    // 2. Loguear TODOS los eventos (idempotente via REPLACE)
    stripe_log_event( $event );

    // 3. Verificar idempotencia: ¿ya procesamos este evento?
    if ( stripe_event_already_processed( $event['id'] ) ) {
        return rest_ensure_response( ['received' => true, 'skipped' => 'duplicate'] );
    }

    // 4. Responder 200 RÁPIDO — Stripe tiene timeout de ~30s
    // En WordPress puro: procesar sync está OK. En sistemas con cola: usar wp_schedule_single_event()

    // 5. Procesar evento
    switch ( $event['type'] ) {
        case 'payment_intent.succeeded':
            handle_pi_succeeded( $event['data']['object'] );
            break;
        case 'payment_intent.payment_failed':
            handle_pi_failed( $event['data']['object'] );
            break;
        case 'payment_intent.canceled':
            handle_pi_canceled( $event['data']['object'] );
            break;
        case 'charge.refunded':
            handle_charge_refunded( $event['data']['object'] );
            break;
        default:
            // Evento no manejado — no es error, solo ignorar
            break;
    }

    // 6. Marcar evento como procesado
    stripe_mark_event_processed( $event['id'] );

    return rest_ensure_response( ['received' => true] );
}
```

### 4.3 Idempotencia en handlers

```php
function handle_pi_succeeded( $pi ) {
    $order = find_order_by_pi( $pi['id'] );
    if ( ! $order ) {
        // Orden aún no creada (race condition): loguear para investigación manual
        error_log( '[Stripe] payment_intent.succeeded: no order found for PI ' . $pi['id'] );
        return;
    }

    // La función de update debe ser idempotente: retornar false si ya está pagada
    $changed = set_order_payment_status( $order->ID, 'paid' );
    if ( ! $changed ) return; // ya estaba pagada, no reenviar emails

    // Solo ejecutar side effects si el estado realmente cambió
    send_payment_confirmation_email( $order->ID );
    update_stripe_pi_metadata( $pi['id'], [ 'order_id' => $order->ID ] );
}
```

### 4.4 Guard de estado de pago (evitar regresiones)

```php
function set_order_payment_status( $order_id, $new_status ) {
    $final_states    = ['paid', 'refunded', 'partially_refunded'];
    $terminal_states = ['refunded'];

    $current = get_current_payment_status_from_db( $order_id ); // bypass object cache

    if ( $current === $new_status ) return false; // idempotente
    if ( in_array($current, $terminal_states, true) ) return false; // irreversible
    if ( in_array($current, $final_states, true) && ! in_array($new_status, $final_states, true) ) {
        return false; // no degradar: paid → pending NUNCA
    }

    update_payment_status_in_db( $order_id, $new_status );
    log_payment_event( $order_id, 'status_change', $current, $new_status );
    return true;
}
```

---

## 5. CLIENT-SIDE (JavaScript)

### 5.1 Patrón completo con manejo de redirect

```javascript
// Al cargar la página: verificar si Stripe redirigió al volver de 3DS/banco
async function checkStripeRedirectReturn() {
    const params = new URLSearchParams(window.location.search);
    const piId   = params.get('payment_intent');
    const status = params.get('redirect_status');

    if ( piId && status === 'succeeded' ) {
        // Limpiar la URL antes de procesar (evita re-submit en F5)
        window.history.replaceState({}, '', window.location.pathname);

        const savedPi   = loadPiFromSession();
        const savedForm = loadFormFromSession();

        if ( savedPi?.piId === piId && savedForm?.email ) {
            await submitOrder({ pi_id: piId, payment_status: 'processing', ...savedForm });
            return;
        }
        // Sin datos de sesión: mostrar formulario de recuperación
        showOrderRecoveryPrompt( piId );
    }
}

// Reusar PI en lugar de crear uno nuevo (Stripe recomienda esto)
async function getOrCreatePaymentIntent( amount, sessionId ) {
    const saved = loadPiFromSession();

    if ( saved?.piId && saved?.amount === amount ) {
        return { clientSecret: saved.clientSecret, piId: saved.piId };
    }

    // Cancelar PI viejo si el monto cambió
    if ( saved?.piId ) cancelPaymentIntent( saved.piId );

    const res = await fetch('/api/stripe/create-intent', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ amount, session_id: sessionId })
    });
    const data = await res.json();
    if ( data.error ) throw new Error( data.error.message );

    savePiToSession({ clientSecret: data.clientSecret, piId: data.paymentIntentId, amount });
    return data;
}

// Confirmar pago con manejo de error correcto
async function confirmPayment( elements, formData ) {
    const { error } = await stripe.confirmPayment({
        elements,
        confirmParams: {
            return_url: window.location.href, // Stripe añade ?payment_intent=... al volver
            payment_method_data: {
                billing_details: { name: formData.name, email: formData.email }
            }
        },
        redirect: 'if_required' // solo redirige si el método lo requiere (3DS, iDEAL, etc.)
    });

    if ( error ) {
        // Error de confirmación: NO crear nuevo PI, reusar el mismo para retry
        // Solo actualizar el estado del formulario
        throw new Error( error.message );
    }

    // Sin redirect: pago exitoso en página
    return 'processing';
}
```

### 5.2 Persistencia de PI en sessionStorage

```javascript
const PI_KEY   = 'stripe_pi';
const FORM_KEY = 'stripe_form';

function savePiToSession(data)   { try { sessionStorage.setItem(PI_KEY, JSON.stringify(data)); } catch(e){} }
function loadPiFromSession()     { try { return JSON.parse(sessionStorage.getItem(PI_KEY)); } catch(e){ return null; } }
function clearPiFromSession()    { try { sessionStorage.removeItem(PI_KEY); } catch(e){} }

function saveFormToSession(data) { try { sessionStorage.setItem(FORM_KEY, JSON.stringify(data)); } catch(e){} }
function loadFormFromSession()   { try { return JSON.parse(sessionStorage.getItem(FORM_KEY)); } catch(e){ return null; } }
```

---

## 6. TRAZABILIDAD — QUÉ LOGUEAR Y DÓNDE

### 6.1 Vinculación PI ↔ Orden interna

Siempre vincular en ambas direcciones:

```php
// En nuestra DB: orden tiene → _stripe_pi = 'pi_xxx'
// En Stripe metadata: PI tiene → order_id = '123', order_ref = 'REF-001'

// Buscar orden por PI (lookup local, no API call)
function find_order_by_pi( $pi_id ) {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_stripe_payment_intent' AND meta_value = %s LIMIT 1",
        $pi_id
    ));
    // NOTA: bypass object cache (get_var directo a DB) para evitar cache stale
}
```

### 6.2 Tabla de audit log de pagos

```sql
CREATE TABLE {prefix}_payment_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    BIGINT UNSIGNED NOT NULL,
    event_type  VARCHAR(50)  NOT NULL,  -- 'created','webhook','manual','refund','email_*'
    from_status VARCHAR(50)  NOT NULL DEFAULT '',
    to_status   VARCHAR(50)  NOT NULL DEFAULT '',
    stripe_pi   VARCHAR(100) NOT NULL DEFAULT '',
    notes       TEXT,
    created_at  DATETIME     NOT NULL,
    INDEX idx_order (order_id),
    INDEX idx_pi    (stripe_pi)
);
```

### 6.3 Tabla de Stripe events recibidos

```sql
CREATE TABLE {prefix}_stripe_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id    VARCHAR(100) NOT NULL,
    event_type  VARCHAR(100) NOT NULL,
    payload     LONGTEXT     NOT NULL,
    received_at DATETIME     NOT NULL,
    processed   TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY uk_event_id (event_id),
    INDEX idx_type (event_type)
);
```

### 6.4 Eventos mínimos a registrar

| Evento | Qué loguear |
|--------|------------|
| PI creado | order_id, amount_cents, currency, pi_id |
| Checkout iniciado | order_id, pi_id, timestamp |
| Confirmación cliente | pi_id, resultado (ok/error), mensaje |
| Webhook recibido | event_id, type, pi_id, order_id |
| Estado de pago cambiado | order_id, from_status, to_status, trigger (webhook/manual) |
| Email enviado | order_id, tipo, destinatario, resultado |
| Reembolso | order_id, amount, stripe_refund_id |

---

## 7. MANEJO DE ERRORES

### 7.1 Tipos de errores y respuestas

| Tipo | `error.type` | Acción |
|------|-------------|--------|
| Tarjeta rechazada | `card_error` | Mostrar `error.message` al usuario, NO crear nuevo PI |
| Fondos insuficientes | `card_error`, code `insufficient_funds` | Mostrar mensaje específico |
| Autenticación requerida | `card_error`, code `authentication_required` | Stripe maneja automáticamente con Payment Element |
| Error de conexión | `api_connection_error` | Retry con backoff exponencial |
| Rate limit | `rate_limit_error` | Retry con delay progresivo (1s, 2s, 4s) |
| Error de servidor Stripe | `api_error` | Retry hasta 3 veces, luego fallar |
| Request inválido | `invalid_request_error` | Bug en el código, no retry |

### 7.2 Retry con backoff exponencial (PHP)

```php
function stripe_request_with_retry( $url, $args, $max_retries = 3 ) {
    $retryable = ['api_connection_error', 'api_error', 'rate_limit_error'];
    $delay_ms  = 500;

    for ( $i = 0; $i <= $max_retries; $i++ ) {
        if ( $i > 0 ) usleep( $delay_ms * 1000 );

        $response = wp_remote_post( $url, $args );
        $body     = stripe_parse_response( $response );

        if ( ! isset($body['error']) ) return $body;

        $type = $body['error']['type'] ?? '';
        if ( ! in_array($type, $retryable, true) || $i === $max_retries ) return $body;

        $delay_ms *= 2; // backoff exponencial: 500ms, 1s, 2s
    }
}
```

### 7.3 Decline codes más comunes y mensajes para el usuario

| Código | Mensaje al usuario |
|--------|-------------------|
| `card_declined` | "Tu tarjeta fue rechazada. Contactá a tu banco o intentá con otra tarjeta." |
| `insufficient_funds` | "Fondos insuficientes. Intentá con otra tarjeta." |
| `expired_card` | "Tu tarjeta está vencida." |
| `incorrect_cvc` | "El código de seguridad es incorrecto." |
| `stolen_card` / `lost_card` | "No podemos procesar este pago. Por favor contactanos." |
| `do_not_honor` | "Tu banco rechazó el pago. Contactá a tu banco." |

---

## 8. SEGURIDAD — CHECKLIST OBLIGATORIO

### Por implementación
- [ ] **TLS 1.2+ en todos los endpoints** (Stripe requiere esto)
- [ ] **API keys en variables de entorno**, nunca en código ni repositorios
- [ ] **Separar keys live/test**: detectar por `sk_live_` vs `sk_test_`
- [ ] **Webhook secret rotado periódicamente** (o ante sospecha de compromiso)
- [ ] **Verificación de firma en TODOS los webhooks** (incluso en desarrollo)
- [ ] **Montos calculados server-side**: nunca confiar en el monto del cliente
- [ ] **Verificar que el PI pertenezca a una orden del sistema** antes de operar sobre él
- [ ] **Endpoints de gestión de PI autenticados** (no públicos si no es necesario)
- [ ] **No almacenar datos de tarjeta**: solo usar tokens de Stripe
- [ ] **No loguear client_secret, keys, ni datos de tarjeta**

### Por clave API
- [ ] Usar clave con **permisos mínimos** (restringir en Stripe Dashboard)
- [ ] **Rotar claves** si hay deploy de nuevo desarrollador o salida del equipo
- [ ] **Monitorear uso** en Stripe Dashboard → Developers → Logs

---

## 9. TESTING

### Tarjetas de prueba (modo test)

| Escenario | Número |
|-----------|--------|
| Pago exitoso | `4242 4242 4242 4242` |
| Requiere 3DS | `4000 0025 0000 3155` |
| 3DS rechazado | `4000 0000 0000 9235` |
| Fondos insuficientes | `4000 0000 0000 9995` |
| Tarjeta rechazada | `4000 0000 0000 0002` |
| Tarjeta vencida | `4000 0000 0000 0069` |

CVC: cualquier 3 dígitos. Fecha: cualquier futura.

### Webhooks locales con Stripe CLI

```bash
# Instalar Stripe CLI
stripe login

# Escuchar webhooks y redirigir a tu servidor local
stripe listen --forward-to localhost:8080/wp-json/vs-order/v1/stripe/webhook

# El CLI te da un webhook signing secret temporal para testing

# Disparar un evento manualmente
stripe trigger payment_intent.succeeded

# Reenviar un evento específico
stripe events resend evt_xxxxx --webhook-endpoint=we_xxxxx
```

---

## 10. ANTI-PATRONES COMUNES (NO HACER)

```php
// ❌ Sin idempotency key — puede crear duplicados en retry
wp_remote_post('https://api.stripe.com/v1/payment_intents', ['body' => $data]);

// ✅ Con idempotency key
wp_remote_post('https://api.stripe.com/v1/payment_intents', [
    'headers' => ['Idempotency-Key' => 'order_' . $session_id],
    'body' => $data
]);

// ❌ Comparación de firma vulnerable a timing attacks
if ( $expected === $received_sig ) { ... }

// ✅ Resistente a timing attacks
if ( hash_equals($expected, $received_sig) ) { ... }

// ❌ Confiar en el monto del cliente
$amount = $request->get_param('amount'); // NUNCA

// ✅ Calcular server-side
$amount = calculate_order_total($items); // SIEMPRE

// ❌ Sin API version — cambios de Stripe pueden romper la integración
wp_remote_post('https://api.stripe.com/v1/...', ['headers' => ['Authorization' => ...]]);

// ✅ Versión pineada
wp_remote_post('https://api.stripe.com/v1/...', ['headers' => [
    'Authorization'  => 'Bearer ' . $key,
    'Stripe-Version' => '2024-06-20',
]]);

// ❌ Reenviar email si el webhook llega duplicado
function handle_succeeded($pi) {
    update_status('paid');
    send_email(); // se enviará 2 veces si el webhook se entrega dos veces
}

// ✅ Solo side-effects si el estado cambió
function handle_succeeded($pi) {
    $changed = update_status_idempotent('paid'); // retorna false si ya estaba en 'paid'
    if ($changed) send_email();
}

// ❌ Sin timeout — puede colgar el proceso
wp_remote_post('https://api.stripe.com/v1/...', ['body' => $data]);

// ✅ Timeout explícito
wp_remote_post('https://api.stripe.com/v1/...', ['body' => $data, 'timeout' => 15]);
```

---

## 11. CHECKLIST DE PRODUCCIÓN

Antes de ir a producción con una integración Stripe:

**Keys y configuración:**
- [ ] Reemplazadas claves `sk_test_` / `pk_test_` por `sk_live_` / `pk_live_`
- [ ] Webhook endpoint apuntando a dominio de producción
- [ ] Webhook secret de producción configurado (distinto al de test)
- [ ] `STRIPE_VERSION` pineada en el código

**Código:**
- [ ] Todos los `wp_remote_post` tienen `timeout`, `Idempotency-Key` y `Stripe-Version`
- [ ] Montos calculados server-side y verificados antes de crear la orden
- [ ] Firma del webhook verificada con `hash_equals()`
- [ ] Handlers de webhook son idempotentes
- [ ] Manejo del redirect return de 3DS implementado
- [ ] Logs de audit trail activos

**Monitoreo:**
- [ ] Alertas en Stripe Dashboard para pagos fallidos
- [ ] Alertas para webhooks con errores repetidos
- [ ] Log de errores de API monitoreado
- [ ] Test de tarjeta exitoso con `4242 4242 4242 4242` en producción

**PCI / Seguridad:**
- [ ] Ningún número de tarjeta pasa por el servidor
- [ ] TLS en todos los endpoints
- [ ] API keys en variables de entorno (no en código)

---

## 12. ISSUES CONOCIDOS EN PROYECTOS — REFERENCIA RÁPIDA

Cuando revisés una integración existente, buscá estos patterns:

```bash
# Buscar llamadas sin idempotency key
grep -n "payment_intents" *.php | grep -v "Idempotency"

# Buscar comparaciones de firma inseguras
grep -n "=== \$sig\|== \$expected\|in_array.*sig" *.php

# Buscar amounts del frontend (sospechoso)
grep -n "body\[.amount.\]\|params\[.amount.\]" *.php

# Buscar wp_remote_post sin timeout
grep -A5 "wp_remote_post.*stripe" *.php | grep -v "timeout"
```
