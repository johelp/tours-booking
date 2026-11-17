[← Volver al índice](../MANUAL.md)

# 19. Panel de gestión — operar sin entrar a WordPress

Desde la v5.11.0, TourFlow tiene un **panel propio**, separado de `wp-admin`, pensado para que quien gestiona el día a día (reservas, tours, calendario, partners) no necesite nunca entrar a WordPress ni saber que es un plugin de WordPress por debajo. Es especialmente útil en sitios **headless** (un frontend público separado de WordPress) — de cara al operador, el panel es "el sistema", punto.

Este capítulo es independiente del [16. Panel de administración](16-panel-administracion.md) — ese capítulo explica las pantallas dentro de `wp-admin`; este explica el panel nuevo. Cubren en gran parte las mismas tareas (Reservas, Calendario, Disponibilidad, Partners), pero desde una URL y una pinta completamente distintas.

## Cómo entrar

El panel vive en **`tu-sitio.com/gestor/`** — es una página pública del sitio (no un plugin/app aparte), pero con su propio login, independiente del de WordPress. Cualquiera con ese link ve solo la pantalla de ingreso; sin sesión iniciada, no hay forma de ver ni un dato.

Usá el **mismo usuario y contraseña** que ya tenés en WordPress — el panel no crea cuentas nuevas, reusa las que ya existen. Pueden entrar:

- El **Administrador** del sitio — entra normalmente a `wp-admin`; si quiere ver este panel además, lo hace desde `tu-sitio.com/gestor/login` con la misma contraseña (un login propio, a propósito: así no se lo fuerza siempre acá).
- Cualquier usuario con el rol **"Gestor de tours"** (`Tour Manager`) — el rol que TourFlow ya crea para operar el plugin sin darle acceso completo a WordPress. Al iniciar sesión por el login normal de WordPress, **entra directo a este panel**, sin pasar por `wp-admin`.
- Cualquier usuario con uno de los dos roles del **Panel rápido** (ver más abajo) — al iniciar sesión por el login normal de WordPress, entra directo a su propia vista restringida de este panel.

La sesión dura **24 horas** y es completamente separada de la sesión de `wp-admin` — cerrar sesión acá no cierra `wp-admin`, y viceversa.

## Qué se puede hacer desde acá

El panel reemplaza, para el día a día, las pantallas de administración más usadas:

| Sección | Qué hace |
|---|---|
| **🏠 Dashboard** | Reservas y personas de hoy/mañana, estado de Google Calendar, y una tarjeta de **accesos rápidos** (Nueva reserva, Nuevo tour, Escanear voucher, Nuevo partner) para no tener que navegar para lo más común. |
| **🏄 Tours** | Alta y edición completa de tours — mismo editor que en `wp-admin` (fotos, precios, horarios, disponibilidad, itinerario, FAQ, etc.), con una guía rápida integrada de "¿qué tipo de tour necesito?" para no adivinar entre calendario normal / fecha fija / solo a pedido / armá tu tour. |
| **🛏 Habitaciones** *(solo Pro Max)* | Igual que Tours, pero para el CPT de habitaciones — fotos, amenities, precio, y acceso directo a la disponibilidad de esa habitación puntual. |
| **📋 Reservas** | Listado, filtros, carga manual, y el detalle completo de cada reserva (aprobar/rechazar, reprogramar, cargar pago manual, cancelar, reenviar voucher) — igual que en `wp-admin`. |
| **📅 Calendario** | Vista mensual con el detalle de cada día, igual que en `wp-admin`. |
| **📱 Modo campo** | Escaneo de QR/voucher en el punto de encuentro, pensado para celular — sin el sidebar del resto del panel, para aprovechar toda la pantalla. |
| **🗓 Disponibilidad** | Reglas de días operativos y excepciones puntuales por tour. |
| **🤝 Partners** | Alta de partners/RRPP, generación de sus links/QR de referido, ver sus reservas — de cara al **operador**, no un login para que el partner entre por su cuenta (eso no existe ni está pensado). |
| **💸 Liquidación** | Ledger de comisiones a pagarle a cada partner, con el botón para marcar una liquidación como pagada. |

**Qué queda afuera a propósito** (decisión explícita del cliente, 2026-09-12): **Emails** (plantillas/pruebas de envío) y **Log de pagos** — son pantallas de configuración/diagnóstico técnico, no de operación diaria, y siguen viviendo solo en `wp-admin`. **Proveedores externos (marketplace)** tampoco están acá — es una decisión de negocio más sensible, reservada al Administrador en `wp-admin`.

## 📋 Panel rápido — para personal de campo/anfitriones

Dos roles más chicos, pensados para quien necesita ver quién llega y cuándo **sin** darle acceso al resto del panel ni a montos/pagos completos: `TourFlow — Panel rápido` y `TourFlow — Panel rápido + cobros`. A diferencia del resto de las secciones, quien tiene alguno de estos dos roles queda **encerrado en esta única pantalla** — nunca ve Tours/Reservas/Calendario/Disponibilidad/Partners, ni tecleando la URL a mano.

Qué muestra, para ambos roles:

- **Próximos días** — cuántas personas llegan y cuántas se van, hoy/mañana/pasado mañana.
- El listado de reservas de un rango de fechas — nombre del cliente (o los nombres de cada integrante, si el tour los pidió), **teléfono y email con un toque** (`tel:`/`mailto:`, para contactar al cliente directo desde el celular), y cualquier **requerimiento especial** que haya dejado al reservar.
- **Reservas próximas o pasadas** — dos pestañas (Próximas/Pasadas) más los botones de 7/14/30/60 días; si hace falta un rango más amplio o puntual, un selector de fechas libre (Desde/Hasta) reemplaza a los botones.
- Al mirar un rango de **reservas pasadas**, una tarjeta de **Estadísticas del período**: cuántas personas ya hicieron un tour y cuántas ya se alojaron en ese rango.
- Si la reserva tiene depósito, un indicador de si el saldo está pendiente o al día — **sin mostrar ningún monto**.

El rol `+ cobros` ve, además, cuánto pagó y cuánto le falta pagar a cada cliente (en $, pero **nunca** el historial completo de transacciones — eso sigue siendo solo de `wp-admin` → Log de pagos) y el botón "Marcar cobrado" para saldar un depósito en efectivo.

## Idioma

El panel se muestra en el **idioma general del sitio** (`Configuración → Sitio`), no en el idioma de perfil de quien inició sesión — a diferencia de las mismas pantallas dentro de `wp-admin`, que sí siguen el idioma del perfil del usuario. Es intencional: si tu sitio es en inglés, el panel se ve en inglés sin importar en qué idioma tenga configurado su perfil cada gestor.

## Uso en celular

El panel es 100% usable desde el celular — pensado para consultarlo o cargar una reserva en el momento, no solo desde una computadora de escritorio. En pantallas angostas, el menú lateral pasa a un botón ☰ arriba a la izquierda que abre un panel deslizante; tocar fuera de él (o cualquier link) lo cierra solo.

## Cosas a tener en cuenta

- **No reemplaza a `wp-admin` del todo** — Emails, Log de pagos, Proveedores externos, y cualquier configuración general (pasarela de pago, moneda, marca) siguen gestionándose desde ahí. El panel es para la **operación diaria**, no para la puesta a punto inicial del sitio.
- Si tu sitio usa **permalinks "simples"** (sin URLs amigables activadas), `tu-sitio.com/gestor/` puede no funcionar — en ese caso pedile a quien administra el hosting que revise **Ajustes → Enlaces permanentes** en WordPress.
- El panel funciona en las **3 ediciones** de TourFlow (Lite, Pro, Pro Max) — la sección Habitaciones solo aparece si tu edición es Pro Max, ya que es la única que tiene ese módulo.
