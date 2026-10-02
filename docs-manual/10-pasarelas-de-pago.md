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

La misma pantalla de Configuración tiene una guía colapsable **"💡 Guía rápida: cómo activar Mercado Pago"** con los pasos exactos para sacar cada credencial desde tu cuenta de Mercado Pago — no hace falta salir del plugin para encontrarla.

## Qué pasa si el cliente no termina de pagar

Cuando alguien empieza una reserva, esta queda en estado **"pendiente"** de inmediato y **le reserva el cupo** — nadie más puede tomar ese lugar mientras tanto. Si no completa el pago dentro de un plazo (configurable en **Configuración → ⚙ General → "Minutos para expirar reserva pending"**, entre 5 y 60 minutos, **15 por defecto**), la reserva pasa a **"Vencida (sin pago)"** y el cupo se libera solo.

Ese estado es distinto de "Cancelada": una reserva vencida por falta de pago **nunca tuvo un pago exitoso**, así que no hay nada que reembolsar. Al vencer, además, se **devuelve el uso del cupón** que la reserva había consumido (un cupón de un solo uso no queda "gastado" por una compra que nunca se pagó). Las habitaciones también se liberan — antes las fechas de una habitación con reserva vencida o cancelada podían quedar bloqueadas.

El cupo se libera **apenas vence el plazo** (el sistema lo revisa cada vez que alguien consulta disponibilidad o reserva, como máximo una vez por minuto), sin esperar a la revisión horaria de fondo.

Las reservas de **lista de interés** (ver capítulo [8](08-lista-de-interes.md)) son distintas: no bloquean cupo ni tienen este plazo, porque corresponden a tours que ni siquiera están abiertos todavía.

## Qué pasa si el pago es rechazado (tarjeta mal cargada, sin fondos, rechazo del banco)

Un pago rechazado **no cancela la reserva**. Es el mismo criterio de plataformas como Vrbo o Booking cuando una tarjeta es inválida: no se asume ninguna obligación de pago, pero se **congela el lugar durante un plazo de gracia** para que el cliente corrija los datos.

1. El cliente ve el mensaje de error de la pasarela en pantalla y puede **reintentar con otra tarjeta en el mismo formulario** — si el reintento sale bien, la reserva se confirma normalmente.
2. Si se va sin pagar, le llega un **email "No pudimos procesar tu pago"** con el plazo hasta el que se le guarda el lugar y un botón **"Actualizar mi tarjeta y pagar"** que lo lleva a completar el pago. El texto se puede editar en **TourFlow → ✉️ Emails → Editar textos** ("Pago rechazado: actualizar tarjeta").
3. El plazo de gracia se configura en **Configuración → ⚙ General → "Plazo de gracia tras un pago rechazado"**: **6 horas por defecto**, con opciones de 1, 2, 4, 6, 12 y 24 horas, o "Sin plazo" para usar solo el vencimiento normal de arriba.
4. Pasado el plazo, la reserva pasa a **"Vencida (sin pago)"** y el cupo vuelve a estar disponible.

Reglas para no congelar cupo de más: el plazo de gracia **solo se abre con el primer intento fallido** (los siguientes no lo estiran), **no aplica a salidas del mismo día**, y **nunca se extiende hasta el día del tour** — como máximo se guarda el lugar hasta las 00:00 de ese día. Cada intento fallido queda anotado en las notas internas de la reserva y en el **Log de pagos** con su motivo.

### Si el pago llega tarde

Puede pasar que el pago se complete **después** de que la reserva ya venció (el cliente tardó, o un medio offline como efectivo/transferencia de Mercado Pago se acredita horas después). El sistema nunca deja plata cobrada sin reserva:

- Si el **cupo sigue libre**, la reserva se **recupera y se confirma** normalmente.
- Si el cupo **ya lo tomó otra persona**, el pago se **reembolsa automáticamente** (por la misma pasarela con la que se cobró), la reserva queda cancelada con el monto reembolsado, al cliente se le explica en pantalla lo que pasó y a vos te llega una **notificación en el Dashboard** ("Pago tardío reembolsado") para que verifiques el reembolso en el Log de pagos.

## Log de pagos: diagnosticar problemas de un cliente

**TourFlow → Log de pagos** registra cada evento relevante de cobro: intento de cobro iniciado, error al iniciar, pago confirmado, resultado del webhook (exitoso/rechazado/reembolsado), verificación fallida, reembolso procesado/fallido. Por cada evento ves: fecha, la reserva asociada (con link directo), cliente, qué pasarela fue, tipo de evento, y el detalle/motivo (por ejemplo, el motivo real del rechazo de una tarjeta).

Te sirve para responder rápido "¿por qué no se cobró esta reserva?" sin tener que entrar al panel de Stripe o Mercado Pago. Podés buscar por número de referencia de reserva. Es una pantalla **de solo lectura** (no reintenta ni modifica ningún pago desde acá) y muestra como máximo los últimos 100 eventos — para historiales más largos hay que ir directo al panel de tu pasarela. Requiere permisos de administrador o de Tour Manager.

**Importante:** el estado real de un pago siempre se confirma consultando directamente a Stripe o Mercado Pago — el sistema nunca da por confirmada una reserva solo porque la pantalla del cliente dijo "listo". Si la pasarela no responde o faltan credenciales, la reserva no se confirma.

## Reembolsos y cancelaciones

Cuando aprobás una cancelación, cancelás por clima/mínimo de pasajeros, o reprogramás una reserva desde **TourFlow → Reservas**, el sistema **calcula** el reembolso que corresponde según la política de cancelación (7+ días antes: 100%; 3–6 días: 50%; menos de 3 días: sin reembolso) y **notifica al cliente por email** — pero **no ejecuta el reembolso automáticamente en la pasarela**. El reembolso real de la plata hay que procesarlo manualmente desde el panel de Stripe o Mercado Pago.

**Tours con depósito parcial** (ver capítulo [18](18-tipos-de-tour.md)): la misma política de cancelación se aplica sobre lo que **realmente cobraste** (el depósito), no sobre el precio total del tour — si cobraste 20% y corresponde reembolso total, se reembolsa ese 20%, no el tour completo.
