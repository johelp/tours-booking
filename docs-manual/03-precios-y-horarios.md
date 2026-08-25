[← Volver al índice](../MANUAL.md)

# 3. Precios y horarios

Esta sección vive dentro del editor del tour, en el cuadro **💰 Precios y horarios**. Se guarda al publicar/actualizar el tour, igual que el resto de los campos.

## Horarios

Un tour puede tener uno o varios horarios de salida. Por cada horario cargás:

- **Hora de inicio** y **hora de fin**.
- **Etiqueta en español** y **etiqueta en inglés** (opcional pero recomendado), por ejemplo "Salida amanecer" / "Sunrise departure" — ayuda al cliente a elegir cuando hay más de un horario.

Con el botón **+ Agregar horario** sumás filas nuevas; con la ✕ de cada fila la eliminás. No hace falta guardar cada horario por separado: se guardan todos juntos con el resto del tour.

**Por qué importa cuántos horarios tenga un tour:** si el tour tiene **un solo horario**, el widget de reserva del cliente se lo salta directamente (no le pregunta nada, asume ese horario). Si tiene **dos o más**, aparece un paso extra en el proceso de reserva donde el cliente elige entre ellos.

## Modelo de precio

Se elige acá mismo, arriba de todo en el cuadro **💰 Precios y horarios** → *Modelo de precio* (antes vivía en un cuadro separado — se unificó para no tener que ir y volver entre dos secciones distintas). Cambia por completo qué sección de precios ves acá debajo:

### Por persona (adulto / niño / bebé)

El precio se cobra multiplicado por la cantidad de cada tipo de pasajero que reserve el cliente. Cargás tres valores:

| Tipo | Rango de edad mostrado al cliente | Notas |
|---|---|---|
| **Adulto** | 13+ años | El precio base. |
| **Niño** | 4–12 años (el "4" se puede ajustar por tour, ver [4. Restricciones de edad](04-restricciones-de-edad.md)) | Precio reducido habitual. |
| **Bebé** | 0–3 años | Poné **0** si los bebés no pagan — el widget lo muestra como "Gratis". |

El total de una reserva con este modelo es: `(adultos × precio adulto) + (niños × precio niño) + (bebés × precio bebé)`, más servicios extra si el cliente agrega alguno, menos el descuento de cupón si aplica.

### Precio fijo por grupo (privado)

Pensado para tours privados donde se cobra por el grupo completo, no por cabeza — por ejemplo un catamarán privado o un paseo en lancha exclusivo. Se cargan **tramos de precio según cantidad de personas**, que se ajustan solos según la **Capacidad máxima** que cargaste para el tour:

- 1–2 personas
- 3 personas
- 4 personas en adelante, hasta la Capacidad máxima (ej. si tu tour admite hasta 10, este último tramo pasa a ser "4–10 personas")

Si subís la Capacidad máxima más adelante, el último tramo se recalcula solo la próxima vez que entrés al editor — no hace falta tocar nada más.

El cliente paga el precio del tramo que le corresponde según cuántas personas sean en total, sin importar cómo se dividan entre adultos/niños/bebés.

**Importante sobre el modelo de grupo**: apenas una reserva confirma un horario de un tour de grupo, ese horario completo queda bloqueado para cualquier otro cliente — no se mezclan pasajeros de reservas distintas en un mismo horario privado.

## Costo del proveedor y margen (si el tour tiene un proveedor asignado)

Si el tour tiene un proveedor externo asignado (ver capítulo de Marketplace de proveedores), debajo de cada precio de venta aparece un campo de **costo del proveedor** — lo que vos le pagás a él, distinto del precio que le cobrás al cliente. El **margen** (venta − costo) se calcula solo y se muestra en vivo al lado de cada campo, en rojo si da negativo — no hace falta sacar la cuenta a mano.

## En qué moneda se cargan los precios

Los precios se cargan en la **moneda configurada para tu negocio** (ver capítulo [11. Moneda](11-moneda.md)) — el cuadro de precios muestra el código de moneda actual (ej. "Precios por persona (MXN)") para que no haya dudas al cargarlos.
