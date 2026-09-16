[← Volver al índice](../MANUAL.md)

# 11. Moneda

Se configura en **TourFlow → Configuración → 💰 Moneda**, con un único selector para toda la tienda: **MXN** (peso mexicano), **ARS** (peso argentino), **USD** (dólar), **EUR** (euro), o **"Otra"** para escribir cualquier código de 3 letras (formato ISO 4217, por ejemplo `GBP`, `BRL`, `COP`).

Esta es la moneda en la que se cobra realmente a través de Stripe o Mercado Pago — depende del país donde opera tu negocio y de qué moneda soporten tus cuentas de pago.

## ⚠️ Si elegís "Otra"

El plugin acepta cualquier código de 3 letras sin validarlo contra nada — pero **solo Stripe o Mercado Pago deciden si de verdad pueden cobrar en esa moneda**. Cargar un código que tu pasarela no soporta puede romper los cobros aunque el plugin no muestre ningún error al guardarlo. Confirmá primero con tu pasarela de pago que soporta la moneda que vas a elegir.

## ⚠️ Importante si ya tenés reservas cargadas

TourFlow **no convierte montos** al cambiar la moneda. Cada reserva guarda el número que se cobró en su momento, pero la pantalla siempre lo muestra con el símbolo/código de la **moneda configurada actualmente** — sin recalcular nada.

En la práctica, esto significa que si cambiás de MXN a USD teniendo reservas viejas cargadas en pesos, esas reservas antiguas van a **verse** etiquetadas como dólares con el mismo número, sin que el valor real haya cambiado. Por eso, **no se recomienda cambiar la moneda base una vez que ya hay historial de reservas** — hacelo, si es posible, antes de empezar a operar, o entendiendo que los montos históricos van a quedar mal etiquetados visualmente (no en la base de datos real de Stripe/Mercado Pago, que sí procesó cada cobro en la moneda correcta al momento de cobrarlo).

## Tipo de cambio de referencia (USD)

Si tu moneda configurada **no** es dólares, aparece una sección aparte, **💱 Tipo de cambio de referencia (USD)**, pensada para mostrarle a turistas extranjeros una referencia aproximada en dólares dentro del widget de reserva (por ejemplo "≈ USD 38"). No afecta el cobro real, que siempre se hace en tu moneda configurada.

- **Modo automático**: se actualiza solo cada 4 horas contra un servicio externo de tipo de cambio.
- **Modo manual**: cargás vos el valor fijo (ej. "17" significa 1 USD = 17 de tu moneda local).

Si tu moneda ya es USD, esta sección se oculta automáticamente porque no aplica.
