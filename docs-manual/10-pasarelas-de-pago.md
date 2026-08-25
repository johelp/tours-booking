[← Volver al índice](../MANUAL.md)

# 10. Pasarelas de pago

TourFlow puede cobrar con **Stripe** (tarjeta, Apple Pay, Google Pay) o con **Mercado Pago** (Checkout Pro). Las dos se configuran en **TourFlow → Configuración**, y podés tener credenciales cargadas para ambas al mismo tiempo — pero solo **una** está "activa" para cobrar las reservas nuevas.

## Elegir cuál usar: Stripe o Mercado Pago

En Configuración → **🔀 Pasarela de pago**, un selector define cuál de las dos cobra las reservas nuevas. La pantalla te muestra el estado de cada una (si tiene credenciales cargadas para el modo en que está) y te avisa si elegiste como activa una pasarela que **todavía no tiene credenciales** — en ese caso, las reservas nuevas fallarían al intentar cobrar, así que conviene resolverlo antes de que un cliente real lo sufra.

**Recomendación según país/moneda:** si tu negocio cobra en **pesos argentinos (ARS)**, usá **Mercado Pago** como pasarela activa — Stripe no liquida bien en esa moneda. Para el resto de monedas soportadas (MXN, USD, EUR), cualquiera de las dos funciona; la elección depende de con cuál tengas cuenta y prefieras operar.

## Configurar Stripe

Sección **💳 Stripe** en Configuración:

| Campo | Qué es |
|---|---|
| **Modo** | Test (pruebas, no mueve dinero real) o Live (producción). |
| **Publishable Key TEST / LIVE** | Clave pública de Stripe para cada modo — la usa el navegador del cliente. |
| **Secret Key TEST / LIVE** | Clave privada — nunca se muestra en el sitio público, autoriza los cobros reales. |
| **Webhook Secret** | Clave que valida que los avisos automáticos de Stripe son legítimos. La pantalla te muestra la URL exacta que tenés que cargar en tu cuenta de Stripe para dar de alta el webhook. |

## Configurar Mercado Pago

Sección **💙 Mercado Pago** en Configuración:

| Campo | Qué es |
|---|---|
| **Modo** | Test o Live, igual que Stripe. |
| **Access Token TEST / LIVE** | Credencial de tu cuenta de Mercado Pago para cada modo. |
| **Webhook Secret** | Se genera desde tu cuenta de Mercado Pago, en *Tus integraciones → Webhooks*, usando la URL que la pantalla de Configuración te indica. |

## Qué pasa si el cliente no termina de pagar

Cuando alguien empieza una reserva, esta queda en estado **"pendiente"** de inmediato y **le reserva el cupo** — nadie más puede tomar ese lugar mientras tanto. Si no completa el pago dentro de un plazo (configurable en **Configuración → ⚙ General → "Minutos para expirar reserva pending"**, entre 5 y 60 minutos, **15 por defecto**), el sistema la cancela sola y libera el cupo automáticamente.

En la práctica, la revisión de reservas vencidas corre **una vez por hora** en segundo plano, así que una reserva que venció a los 15 minutos puede tardar hasta la próxima corrida horaria en liberarse del todo — no es instantáneo al segundo exacto.

Las reservas de **lista de interés** (ver capítulo [8](08-lista-de-interes.md)) son distintas: no bloquean cupo ni tienen este plazo, porque corresponden a tours que ni siquiera están abiertos todavía.

## Log de pagos: diagnosticar problemas de un cliente

**TourFlow → Log de pagos** registra cada evento relevante de cobro: intento de cobro iniciado, error al iniciar, pago confirmado, resultado del webhook (exitoso/rechazado/reembolsado), verificación fallida, reembolso procesado/fallido. Por cada evento ves: fecha, la reserva asociada (con link directo), cliente, qué pasarela fue, tipo de evento, y el detalle/motivo (por ejemplo, el motivo real del rechazo de una tarjeta).

Te sirve para responder rápido "¿por qué no se cobró esta reserva?" sin tener que entrar al panel de Stripe o Mercado Pago. Podés buscar por número de referencia de reserva. Es una pantalla **de solo lectura** (no reintenta ni modifica ningún pago desde acá) y muestra como máximo los últimos 100 eventos — para historiales más largos hay que ir directo al panel de tu pasarela. Requiere permisos de administrador o de Tour Manager.

**Importante:** el estado real de un pago siempre se confirma consultando directamente a Stripe o Mercado Pago — el sistema nunca da por confirmada una reserva solo porque la pantalla del cliente dijo "listo". Si la pasarela no responde o faltan credenciales, la reserva no se confirma.

## Reembolsos y cancelaciones

Cuando aprobás una cancelación, cancelás por clima/mínimo de pasajeros, o reprogramás una reserva desde **TourFlow → Reservas**, el sistema **calcula** el reembolso que corresponde según la política de cancelación (7+ días antes: 100%; 3–6 días: 50%; menos de 3 días: sin reembolso) y **notifica al cliente por email** — pero **no ejecuta el reembolso automáticamente en la pasarela**. El reembolso real de la plata hay que procesarlo manualmente desde el panel de Stripe o Mercado Pago.

**Tours con depósito parcial** (ver capítulo [18](18-tipos-de-tour.md)): la misma política de cancelación se aplica sobre lo que **realmente cobraste** (el depósito), no sobre el precio total del tour — si cobraste 20% y corresponde reembolso total, se reembolsa ese 20%, no el tour completo.
