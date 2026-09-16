[← Volver al índice](../MANUAL.md)

# 7. Cupones de descuento

Se gestionan en **TourFlow → Cupones** (esta pantalla requiere permisos de administrador completo — un usuario Tour Manager no puede entrar acá).

## Tipos de cupón

TourFlow soporta dos tipos de descuento:

- **Porcentaje** — por ejemplo, 15% de descuento sobre el total.
- **Monto fijo** — por ejemplo, $500 de descuento fijo, sin importar el total de la reserva.

No existe un tipo "cupón por fecha del tour": lo que sí podés definir por fecha es la **ventana en la que el código puede usarse** (ver abajo) — es decir, restringís cuándo el cliente puede aplicar el cupón, no para qué fecha de tour sirve.

## Crear un cupón

Botón **+ Nuevo cupón**, con estos campos:

| Campo | Qué es |
|---|---|
| **Código** | Lo que el cliente escribe al reservar (ej. `VERANO2026`). Se guarda en mayúsculas y debe ser único — si repetís un código existente, el sistema te avisa con un error. |
| **Tipo de descuento** | Porcentaje o monto fijo. |
| **Valor** | El número del descuento (ej. `15` para 15%, o `500` para $500). |
| **Válido desde / hasta** | Ventana de fechas en que el código funciona (para promos de temporada). Si las dejás vacías, no hay límite de fechas. |
| **Límite de usos** | Cuántas veces en total se puede usar el código antes de dejar de funcionar. Vacío = ilimitado. El sistema lleva la cuenta sola. |
| **Aplica a** | Todos los tours, o uno específico. |

## Pausar, reactivar o eliminar

- **Pausar/Activar**: alterna el cupón sin borrarlo — útil para "congelar" una promo temporalmente y reactivarla después con el mismo código e historial de usos.
- **Eliminar**: borrado definitivo, con confirmación.

## Cómo interactúa el descuento con el resto del precio

- El descuento se calcula sobre el **precio total del tour ya sumado** (adultos + niños + bebés, o el precio de grupo, según el modelo del tour).
- **Nunca** se aplica sobre servicios extra/add-ons — esos siempre se cobran a precio completo (ver capítulo [6](06-servicios-extra.md)).
- Si el tour no admite niños o bebés (ver capítulo [4](04-restricciones-de-edad.md)) y el cliente igual intenta reservar con ellos, la reserva se rechaza directamente por esa razón, antes de siquiera llegar a calcular el cupón.

## Compartir un link con descuento automático (sin que el cliente escriba el código)

Podés armar un link al tour agregando `?coupon=CODIGO` al final de la URL, por ejemplo:

```
https://tusitio.com/tour/snorkel-bacalar/?coupon=VERANO10
```

Cuando alguien entra por ese link, el widget de reserva **precarga solo el campo de cupón** con `VERANO10` en mayúsculas — el cliente no tiene que escribir nada, solo confirma la reserva con el descuento ya aplicado.

**Importante**: el link por sí solo no da ningún descuento — vos tenés que haber creado ese cupón de antemano en esta misma pantalla (con su tipo, valor y vigencia). Si el código de la URL no corresponde a ningún cupón real o activo, se ignora igual que si alguien hubiera escrito un código inválido a mano: la reserva sigue el proceso normal, simplemente sin descuento.

Este mecanismo es ideal para compartir un descuento personalizado con un partner, una campaña de redes o un newsletter, sin depender de que la persona copie bien un código.
