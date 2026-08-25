# Guía de plugins satélite para TourFlow

Documento técnico para quien construye un plugin **aparte** que extiende TourFlow — una pasarela de pago nueva, una integración con un CRM, un sync con un channel manager, notificaciones por WhatsApp/SMS, o cualquier otra cosa que hoy no viene de fábrica — sin tocar ni un archivo del núcleo. Todo lo que describe acá está verificado contra el código real del plugin en esta rama (`fase1/seguridad-base-codigo2`), no es una guía genérica de hooks de WordPress.

> **Por qué un plugin aparte y no un fork o un parche**: TourFlow se actualiza seguido (esta sesión sola pasó de v5.6.14 a v5.6.19). Un plugin satélite sobrevive esas actualizaciones sin conflictos porque nunca toca los archivos del núcleo — solo se engancha a los puntos de extensión documentados acá.

## 1. Dos tipos de punto de extensión — no son lo mismo

TourFlow usa dos mecanismos nativos de WordPress, y confundirlos es el error más común al planear un satélite:

| | `do_action()` — Hooks de notificación | `apply_filters()` — Puntos de estrategia |
|---|---|---|
| **Qué significa** | "Esto ya pasó, avisale a quien le interese." | "Necesito que alguien me devuelva algo para poder seguir." |
| **Cuántos pueden escuchar** | Cualquier cantidad, todos reaccionan en paralelo, ninguno bloquea a otro. | Normalmente uno activo a la vez (el que el operador eligió/configuró). |
| **Sirve para** | CRM, WhatsApp/SMS, sync con channel manager, analítica propia, auditoría/logging. | Pasarelas de pago — algo que necesita ser **elegible** y **llamado**, no solo enterarse después. |
| **Cobertura hoy** | Buena — 13 momentos distintos del ciclo de vida de una reserva ya disparan un hook (§ 2). | Recién agregada en v5.6.19 — antes `apply_filters()` no se usaba **nunca** en todo el plugin (§ 3). |

Si tu satélite necesita reaccionar a "algo pasó" (mandar un WhatsApp cuando se confirma una reserva, empujar el contacto a un CRM), lo que sigue en § 2 **ya te alcanza hoy, sin ningún cambio al núcleo**. Si necesita ser una opción activa y seleccionable (una pasarela de pago nueva), § 3 es tu punto de entrada.

## 2. Catálogo de hooks de notificación — ya disponibles hoy

Todos son `do_action()` reales del código actual (`grep -rn "do_action(" includes/` para confirmar). Enganchate con `add_action('nombre_del_hook', tu_callback, 10, N)`.

### Ciclo de vida de una reserva

| Hook | Firma | Cuándo dispara |
|---|---|---|
| `amir_booking_confirmed` | `( int $booking_id )` | Reserva confirmada — pago real, confirmación manual del admin, o pago manual cargado (§ 5). No dispara para reservas de habitación (Pro Max), ver `flow_room_booking_confirmed`. |
| `flow_room_booking_confirmed` | `( int $booking_id )` | Igual que el anterior, pero para reservas de habitación (Pro Max) — `amir_booking_confirmed` no dispara en ese caso porque el JOIN interno con `amir_tours` no aplica. |
| `amir_booking_cancelled` | `( int $booking_id, string $reason_type )` | Reserva cancelada — `$reason_type` es `'client'`, `'weather'`, `'min_pax'`, etc. |
| `amir_booking_rescheduled` | `( int $booking_id )` | Reserva reprogramada a otra fecha/horario. |
| `amir_booking_date_requested` | `( int $booking_id )` | Nace una solicitud de fecha (tour de fecha fija pidiendo otra fecha, tour "solo a pedido", o "armá tu tour" sin precio) — status `date_requested`, sin cobro todavía. |

### Marketplace de proveedores externos (§ 11 CONTRIBUTING.md)

| Hook | Firma | Cuándo dispara |
|---|---|---|
| `amir_booking_pending_provider_approval` | `( int $booking_id )` | Reserva de un tour con proveedor externo, esperando que el proveedor confirme disponibilidad. |
| `amir_provider_booking_approved` | `( int $booking_id )` | El proveedor aprobó. |
| `amir_provider_awaiting_payment` | `( int $booking_id )` | Proveedor aprobó en modo "cobro diferido" — recién ahora se manda el link de pago real al cliente. |

### Pagos y reembolsos

| Hook | Firma | Cuándo dispara |
|---|---|---|
| `amir_process_gateway_refund` | `( int $booking_id, float $refund_mxn )` | Se decidió que corresponde reembolso (política de cancelación) — quien escucha esto es responsable de llamar a la pasarela real. |
| `amir_gateway_refund_completed` | `( string $gateway_id, array $event_raw, int $booking_id )` | Un reembolso se confirmó del lado de la pasarela (via webhook) — `$gateway_id` es `'stripe'`/`'mercadopago'`/el id de tu satélite. Agregado en v5.6.19, gateway-agnóstico a propósito. |
| `amir_stripe_refund_completed` | `( array $event_raw )` | **Legacy** — mismo momento que el anterior, mal nombrado (dispara también para Mercado Pago). Se mantiene solo por compatibilidad hacia atrás, no lo uses en código nuevo. |

### Recordatorios y reseñas (cron)

| Hook | Firma | Cuándo dispara |
|---|---|---|
| `amir_send_reminder_email` | `( object $booking )` | Recordatorio previo al tour (cron diario). |
| `amir_send_review_email` | `( object $booking )` | Pedido de reseña posterior al tour (cron diario). |
| `amir_send_provider_reminder_email` | `( int $booking_id )` | Recordatorio al proveedor externo que no respondió a tiempo. |

### Ejemplo mínimo — satélite de notificación por WhatsApp

```php
<?php
/**
 * Plugin Name: WhatsApp Notify for TourFlow
 * Requires Plugins: amir-booking
 */
add_action( 'amir_booking_confirmed', function ( int $booking_id ) {
    $booking = ( new \AmirBooking\Core\BookingManager() )->get_booking( $booking_id );
    if ( ! $booking ) return;
    // ... armar y mandar el mensaje de WhatsApp con tu proveedor (Twilio, etc.)
}, 10, 1 );
```

Cero archivos del núcleo tocados, cero riesgo de conflicto en la próxima actualización.

## 3. Puntos de estrategia — pasarelas de pago

### 3.1 La interfaz a implementar

`includes/payments/class-payment-gateway-interface.php` define el contrato que ya cumplen `StripeGateway` y `MercadoPagoGateway`:

```php
interface PaymentGatewayInterface {
    public function id(): string;
    public function is_configured(): bool;
    public function create_payment( /* ... */ );
    public function verify_webhook_signature( string $payload, array $headers ): bool;
    public function parse_webhook_event( string $payload ): ?PaymentEvent;
    public function fetch_payment_status( string $reference ): ?PaymentStatusResult;
    public function refund( string $charge_reference, float $amount_mxn ): bool;
}
```

Mirá `includes/payments/class-mercado-pago-gateway.php` como referencia — es el ejemplo más cercano a un gateway nuevo (a diferencia de Stripe, que es más JS-first con `PaymentIntent`).

### 3.2 Los dos filtros para registrarte

Agregados en v5.6.19, específicamente para esto:

```php
<?php
/**
 * Plugin Name: Redsys for TourFlow
 * Requires Plugins: amir-booking
 */
namespace RedsysForTourFlow;

// 1. Resolver tu gateway cuando el núcleo pida 'redsys'
add_filter( 'amir_payment_gateway_resolve', function ( $gateway, string $id ) {
    if ( $id === 'redsys' ) {
        return new RedsysGateway(); // tu clase, implementa PaymentGatewayInterface
    }
    return $gateway; // importante: devolver $gateway sin tocar si no es la tuya
}, 10, 2 );

// 2. Sumar tu opción al dropdown de Configuración → Pasarela de pago activa
add_filter( 'amir_payment_gateway_options', function ( array $options ): array {
    $options['redsys'] = 'Redsys';
    return $options;
} );
```

Con esto, el operador puede elegir "Redsys" en Configuración igual que elegiría Stripe o Mercado Pago — el núcleo nunca necesitó saber que tu plugin existe.

### 3.3 El webhook es tuyo

`PaymentGatewayFactory`/`PaymentGatewayInterface` no incluyen el registro de la ruta REST que recibe la notificación de la pasarela — eso lo registra cada gateway. Tu satélite registra su propia ruta (`register_rest_route`) y, cuando confirma un pago, llama a `BookingManager::confirm( $booking_id, $charge_reference )` o dispara los mismos flujos que ya usan Stripe/MP (`class-booking-controller.php::handle_gateway_webhook()` es un buen modelo a seguir, aunque no es reusable directo porque asume `stripe-signature`/`x-signature` como headers — la lógica de negocio que llama después sí es 100% reusable).

## 4. Pagos manuales — el valor reservado `'manual'`

Desde v5.6.19, el admin puede marcar una reserva como pagada a mano (transferencia, efectivo, o cualquier cosa que tu satélite no automatice) — la reserva queda con `payment_gateway = 'manual'`. `PaymentGatewayFactory::make('manual')` resuelve a `null` **a propósito** — un reembolso futuro sobre esa reserva no va a intentar llamar a ninguna API (el listener de `amir_process_gateway_refund` ya sabe manejar un gateway `null`: loggea "reembolsar manualmente" en vez de fallar).

**Si tu satélite es una pasarela real, no uses `'manual'` como tu `id()`** — es un valor reservado del núcleo, no una pasarela.

## 5. Cambiar el estado de una reserva desde tu satélite

Si tu integración necesita cambiar el estado de una reserva por su cuenta (ej. un sync con un channel manager que cancela una reserva desde el otro lado), llamá directo a los métodos públicos de `BookingManager` (`confirm()`, `cancel()`, `reschedule()`) — todos disparan los hooks de § 2 automáticamente, así que el resto del sistema (emails, voucher, ledger de proveedores) sigue funcionando sin que tu satélite tenga que replicar esa lógica. No actualices la tabla `amir_bookings` directo con SQL propio salvo que sepas exactamente por qué — te salteás los hooks y probablemente el email al cliente.

## 6. Convenciones para no chocar con el núcleo ni con otros satélites

- **`Requires Plugins: amir-booking`** en el docblock de tu plugin (soportado nativo desde WordPress 6.5) — evita que se active sin el plugin principal.
- **Namespace propio**, nunca `AmirBooking\` ni `TourFlow\` — son del núcleo. Un prefijo con el nombre de tu integración (`RedsysForTourFlow\`, `WhatsappNotifyForTourFlow\`) alcanza.
- **Prefijo de opciones/tablas propio** si tu satélite guarda configuración — no reuses `amir_*` como prefijo de `wp_options` o de tablas nuevas.
- **No dupliques lógica de negocio** (cálculo de reembolso, disponibilidad, precio) — llamá a las clases del núcleo (`BookingManager`, `PricingEngine`, `AvailabilityEngine`) en vez de reimplementar la regla. Si esas clases no exponen algo que necesitás como público, es una señal de que falta un punto de extensión — pedilo antes de hackear un workaround.

## 7. Pensado a futuro — lo que todavía no existe

Igual que `GUIA-THEMES-TOURFLOW.md § 6`, esto es una lista honesta de huecos, no algo ya construido:

- **Filtro de contenido/catálogo de tours** — no hay ningún `apply_filters` sobre los datos de un tour antes de exponerse por la API o guardarse. Un satélite de sync con un channel manager (Booking.com/Airbnb, evaluado y no priorizado, ver roadmap) hoy tendría que leer `amir_tours` directo con SQL en vez de engancharse a algo limpio.
- **Hook genérico de creación de reserva** — hoy solo hay hooks por status específico (`date_requested`, `pending_provider_approval`, `confirmed`) pero ningún `amir_booking_created` que dispare para **cualquier** reserva nueva sin importar el camino que tomó. Útil para un satélite tipo "mandar todo a Zapier/Make" que no quiera enganchar 5 hooks distintos.
- **Filtro de precio final** — `PricingEngine::quote()` no tiene ningún punto donde un satélite pueda ajustar el total antes de mostrarlo (ej. un programa de fidelidad con descuento propio, por fuera del sistema de cupones ya existente).

Ninguno de los tres es un cambio grande si hace falta — son extensiones puntuales sobre el patrón que ya existe (§ 2/§ 3), no una reescritura.

## 8. Checklist — ¿tu plugin satélite está listo?

- [ ] `Requires Plugins: amir-booking` en el docblock.
- [ ] Namespace y prefijo de opciones/tablas propios, nada de `AmirBooking\`/`TourFlow\`/`amir_*`.
- [ ] Si es una pasarela: implementa `PaymentGatewayInterface` completa, engancha los dos filtros de § 3.2, y su `id()` no es `'stripe'`, `'mercadopago'` ni `'manual'`.
- [ ] Si reacciona a eventos: usa los hooks de § 2 en vez de sondear la base de datos por su cuenta.
- [ ] Si cambia el estado de una reserva: pasa por `BookingManager`, nunca por SQL directo.
- [ ] No duplica lógica de precio/disponibilidad/reembolso que ya vive en el núcleo.
