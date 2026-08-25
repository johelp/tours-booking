[← Volver al índice](../MANUAL.md)

# 9. Partners y referidos

Los partners son terceros (hoteles, agencias, influencers, otro negocio) que te traen clientes a cambio de una comisión. TourFlow les da a cada uno un link único de seguimiento y calcula automáticamente cuánto les corresponde por cada venta que generan.

## Crear un partner

**TourFlow → Partners → + Nuevo partner**:

| Campo | Qué es |
|---|---|
| **Nombre/Empresa** | Obligatorio. |
| **Email** | Obligatorio. |
| **Teléfono** | Opcional. |
| **Tipo de comisión** | *Porcentaje* (ej. 15% de cada venta) o *Monto fijo* (ej. $500 por cada reserva, sin importar el total). |
| **Valor** | El número de esa comisión. |
| **Notas internas** | Texto libre para vos (acuerdos, condiciones especiales, etc.) — el partner no lo ve. |

Al crear el partner, TourFlow genera automáticamente:

- Un **token único** que lo identifica.
- Un **link de tracking** al catálogo completo de tours con ese partner identificado.
- Un **código QR descargable** de ese link — ideal para que, por ejemplo, un hotel lo imprima y lo ponga en la recepción.

## Cómo funciona el link para el partner

Cuando alguien visita tu sitio a través de un link con `?ref=TOKEN-DEL-PARTNER` (al catálogo completo o a un tour puntual), el sistema guarda ese dato en una **cookie que dura 30 días** en el navegador de esa persona. Si en cualquier momento dentro de esos 30 días completa una reserva —no hace falta que sea en la misma visita—, esa reserva queda automáticamente asociada al partner.

Si pasan los 30 días sin que reserve, se pierde la asociación (a menos que vuelva a entrar por el mismo link, lo que renueva otros 30 días).

## Ver el detalle y las comisiones de un partner

Desde el listado de **TourFlow → Partners**, entrando al detalle de uno ves:

- 3 indicadores: total de ventas, reservas confirmadas, comisión ganada.
- El listado de las últimas reservas referidas (hasta 30), con el total y la comisión calculada de cada una.
- Los links y QR de tracking: el general del catálogo, y uno específico por cada tour si lo generaste.
- Botón **🔄 Regenerar QR**, por si necesitás un código QR nuevo (borra el archivo anterior y genera uno nuevo).

**Importante:** las comisiones que ves acá son un **cálculo automático de referencia**, no un pago real gestionado por el plugin — TourFlow no le transfiere plata al partner por vos. Es la información que necesitás para saber cuánto liquidarle vos mismo, por fuera del sistema (transferencia, efectivo, etc.).

Solo se cuentan para las estadísticas las reservas en estado **confirmada** o **completada** — una reserva pendiente de pago o cancelada no suma ni a las ventas ni a la comisión del partner.
