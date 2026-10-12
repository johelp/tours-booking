# Pendientes de verificación en vivo

Lista de todo lo que está construido y documentado pero **todavía no pasó por un WordPress real en un sitio público** (o, cuando se indica, falta probarlo en un dispositivo o cuenta externa). Armada el 2026-10-04 a partir de `CLAUDE.md` y `CONTRIBUTING.md`.

Lo que sí se verificó en el sandbox **local** (Docker, MariaDB y MySQL 8) está marcado como "local". Eso no reemplaza una prueba en un sitio real.

Marcá cada ítem cuando lo pruebes y anotá la fecha y el sitio.

---

## A. Versiones recientes (núcleo v5.13.x) — prioridad

- [ ] **Empaquetado v5.13.9** — instalar `tourflow-v5.13.9-*.zip` en un sitio real y confirmar que actualiza sin duplicar carpeta. *(local: los 3 ZIPs armados y verificados por versión/edición/archivos; plugin activo en el sandbox local sin fatales — falta un sitio real)*
- [ ] **MySQL 8 — instalación nueva** — confirmar en un hosting con MySQL 8 que TourFlow crea todas las tablas. *(local: OK)*
- [ ] **Regla base de días activos** — tour con los 7 días activos muestra disponibilidad; tour con días bloqueados los respeta. *(local: OK)*
- [ ] **Reimportar demo de tours** — falla con `Duplicate entry` en el tour borrador (§ 16.105, pendiente de corregir). Confirmar y decidir.
- [ ] **Pago rechazado con Stripe** — tarjeta `4000 0000 0000 0002`: la reserva queda `pending`, llega el email `PaymentFailedEmail`, y reintentar con `4242 4242 4242 4242` confirma la reserva.
- [ ] **Webhook de Stripe** — verificar que está suscripto `payment_intent.payment_failed`.
- [ ] **Plazo de gracia** — configurar 1 h, forzar un fallo, esperar, y confirmar que el cupo se libera.
- [ ] **Pago tardío** — pagar una reserva ya vencida con cupo libre (se recupera) y con cupo ocupado (se reembolsa sola).
- [ ] **Tours "grupo" (`price_model='group'`)** — con más de 1 persona se pueden reservar; con carrera, no se sobreventa. Probar con un tour real tipo Sicilia Mia.
- [ ] **Migración de la UNIQUE KEY** de `amir_provider_payouts` / `amir_partner_payouts` — probada solo en instalación nueva. Falta una instalación existente (1.39 → 1.40+).
- [ ] **Cupón de un solo uso con 100%** — no permite reservas gratis repetidas.
- [ ] **Cancelar reserva** — exige `access_token`; el email solo sirve para lectura.
- [ ] **Asunto de emails de contacto** (`/amir/v1/contact`, `/groups-inquiry`) — usa el nombre de la empresa, no `[Caliafarm]`.

## B. Bot connector (`tourflow-bot-connector` 0.1.0)

- [ ] Los 4 endpoints (`/catalog`, `/items/{tipo}/{id}`, `/availability`, `/booking-link`) con un WordPress real. *(local: OK)*
- [ ] Generar la API key, usarla desde un bot real, y revocarla. Confirmar que sin key devuelve 401.
- [ ] Habitaciones: `/booking-link` devuelve el archive general (no hay permalink propio). Decidir si alcanza.

## C. Automatizaciones (`tourflow-automations` 0.1.0) — nuevo

- [ ] **Zapier** con cuenta real: Catch Hook recibe el evento de prueba y se mapean los campos.
- [ ] **Make** con cuenta real: Custom Webhook recibe el evento de prueba.
- [ ] **n8n** con instancia real: nodo Webhook recibe el evento de prueba.
- [ ] **Verificación de firma** en n8n con el nodo de HMAC (Zapier no es confiable para esto, ver guía § 5).
- [ ] **Cron real del servidor** (`wp cron event run --due-now` cada minuto) y que los reintentos salen a su hora.
- [ ] **Evento real de punta a punta** — una reserva real confirmada llega a la planilla.
- [ ] **Cancelación, pago rechazado, saldo cobrado, pendiente de proveedor** — ninguno probado con un receptor real todavía (solo `confirmed`).

## D. Panel de gestión sin wp-admin (núcleo v5.11.x)

- [ ] Login en `/gestor/` con un usuario con rol de gestor de tours.
- [ ] Tours y Habitaciones: guardado completo (precio, horarios, galería). Es la única superficie con `wp.media` fuera de wp-admin.
- [ ] Drawer mobile en un celular real (no solo en el harness estático).
- [ ] Reservas, Calendario, Disponibilidad, Partners, Liquidación.
- [ ] Modo Campo: escaneo de QR con la cookie del panel.
- [ ] Idioma del panel = idioma del sitio (no el del perfil), incluido el Dashboard, que todavía puede quedar en el idioma del perfil (limitación conocida).
- [ ] **Nav del editor de tours** (v5.13.8) — barra sticky con un link por metabox + botón "Guardar" duplicado arriba; confirmar que funciona bien en un celular real.

## E. Panel rápido — roles nuevos (v5.13.0 / v5.13.1)

- [ ] Crear un usuario `amir_quick_staff` y uno `amir_quick_cashier`.
- [ ] Confirmar que el menú queda recortado a una sola sección y que tecleando la URL a mano no se accede a otras.
- [ ] Confirmar que el staff no ve montos ni precios, y que el cashier puede marcar el saldo cobrado.
- [ ] Listado en tarjetas en un celular real (375 px y 320 px).

## F. Emails

- [ ] **Bloque HTML personalizado** (v5.12.0) — banner real de Caliafarm en Gmail y Outlook (esos clientes son más estrictos que un navegador).
- [ ] **Plantillas en inglés** — con el idioma del admin en inglés, todos los textos se ven en inglés.
- [ ] **Link de pago** (v5.6.21) — el mensaje personalizado llega en el email.
- [ ] **Solicitud de fecha** (v5.6.9/v5.6.10) — el cliente recibe el aviso y el rechazo con mensaje.

## G. Pagos y proveedores

- [ ] **MercadoPago Checkout Pro** — con credenciales de prueba reales de una cuenta de MP.
- [ ] **Redsys** (`redsys-for-tourflow` 0.1.1) — pasarela de prueba de Redsys; firma, notificación async y reembolso. Ninguno pasó por un sitio real.
- [ ] **MercadoPago Split** (`mercadopago-split-tourflow` 0.1.1) — depende de que MP apruebe a TourFlow como marketplace (dependencia externa sin confirmar) y de un proveedor de prueba con cuenta propia.
- [ ] **Marketplace de proveedores** (v2.2.x) — aprobación por email sin login, recordatorio a 24 h y auto-cancelación a 48 h.
- [ ] **Habitaciones de proveedor externo** (v5.13.7) — reservar una habitación con proveedor asignado + costo por noche, confirmar que el email le llega al proveedor, aprobar desde el link, y que aparece la fila en Liquidación de proveedores. *(local: plugin activo sin fatales, circuito no ejercitado todavía)*
- [ ] **Carrito mixto propio + proveedor** (v5.13.7/v5.13.8) — un solo cobro, el voucher general distingue "confirmado" de "esperando al proveedor" por ítem (checklist completo de 6 pasos en `GUIA-CIRCUITO-PROVEEDORES-EXTERNOS.md § 7`).
- [ ] **Cancelación/reembolso y saldo cobrado de una habitación de proveedor** (v5.13.8) — confirmar que el email le llega al cliente (bug recién corregido, nunca probado en un envío real).
- [ ] **Timing de datos del cliente al proveedor** (`amir_provider_reveal_client_at`, v5.13.9) — probar los dos modos ("al solicitar"/"al aprobar") y confirmar que `ProviderClientDetailsEmail` sale recién al aprobar en el segundo modo.
- [ ] **Depósito = comisión del operador** (v5.13.9) — aprobar una reserva de proveedor pagada con depósito y confirmar que NO se genera fila en el ledger de liquidación.
- [ ] **Liquidación de partners** (v5.10.6) — la comisión se registra al confirmar una reserva con `partner_id`.
- [ ] **Depósito parcial** (v5.7.0) — cobro del porcentaje, saldo en efectivo y link de pago del saldo.
- [ ] **Venta suelta de producto digital** (`[flow_product]`, v5.10.0) — checkout, pago y descarga tokenizada.
- [ ] **Voucher del carrito** (v5.10.1) — confirmar en el sitio real de Caliafarm que el link ya no da "Acceso no autorizado".
- [ ] **Voucher PDF** (v5.7.10, v5.6.25) — el logo SVG usa el texto de respaldo; confirmar en un sitio real que el PDF se genera.

## H. Reservas y flujo de compra

- [ ] **Reserva directa por tour** (`skip_upsell`, v5.7.2).
- [ ] **Mínimo de personas por reserva individual** (v5.7.7) — confirmar que no bloquea tours que ya tenían el campo cargado para el aviso del cron (matiz documentado en § 16.81).
- [ ] **Requiere nombre de cada integrante** — en widget clásico, Discovery y Explore (v5.8.0).
- [ ] **Auto-scroll en mobile** (v5.8.3) — en iPhone y Android reales.
- [ ] **Tarjeta "¿Cuándo querés ir?"** (v5.8.3) — la estética sigue pendiente de un feedback concreto del cliente.
- [ ] **Explorar** (`[flow_explore]`, v5.6.0) — búsqueda, detalle, carrito, extras y checkout de punta a punta.
- [ ] **Sugerencia escalonada en Explorar** (v5.6.22) — habitaciones y extras después del primer ítem.
- [ ] **Armá tu tour** (`custom_quote`, v5.6.18) — la aprobación en wp-admin con precio editable.
- [ ] **Lista de interés / "avísame cuando abra"** — link de pago al abrir el tour.
- [ ] **Tours solo para adultos** — el widget oculta niños y bebés.
- [ ] **Tours de fecha fija** — solicitud de otra fecha desde el widget clásico.
- [ ] **Solicitar fecha / solo a pedido** — flujo completo desde el email hasta el link de pago.
- [ ] **Reactivar una solicitud rechazada** (v5.6.20).
- [ ] **Cargar pago manual y cambiar estado manualmente** desde el detalle de la reserva (v5.6.19).
- [ ] **Confirmación de pago** — pantalla honesta y reintento si `confirm-payment` falla (v5.4.3).
- [ ] **Cupón del 100%** — confirma directo sin pasarela (v5.7.14).
- [ ] **Habitaciones: fechas de reservas vencidas o canceladas se liberan** (v5.13.4).

## I. Shortcodes y frontend público

- [ ] `[flow_booking_variants]` (v5.8.4) — selector de variantes de Sicilia Mia.
- [ ] `[flow_tour_list]` — `columns="4"` y `limit` (v5.6.6), y color de marca (v5.8.3).
- [ ] `[flow_tour_dates]`, `[flow_spots_left]`, `[flow_search_bar]` (v5.6.7).
- [ ] `[flow_discovery]` con `show_catalog="yes"` (v5.6.26).
- [ ] `[flow_product]` en modo catálogo sin `addon_id` (v5.10.3).
- [ ] Estilo **Fullwidth** del widget (v5.6.16).
- [ ] **Video** YouTube y Vimeo en la ficha (v5.6.1).
- [ ] **FAQ por tour** en las dos plantillas de ficha (v5.9.0).
- [ ] **Plantilla inmersiva** de la ficha (v3.0.0).
- [ ] Botones y calendario frente a un **theme agresivo** (v5.7.4 a v5.7.6) — confirmar en el sitio real del cliente.
- [ ] **Fotos de tours** — la foto principal aparece tras cambiarla (v5.6.3), y las tarjetas muestran foto con URLs sin extensión (v5.6.2).
- [ ] **Sincronizar tours** no borra galerías (v5.6.3).
- [ ] **Caracteres especiales** (emoji, tildes) se guardan bien (v5.6.4).
- [ ] **Imágenes de galería** en tamaño `large`, no el original (v5.10.4).
- [ ] **Pixeles** de Meta y GA4 — los eventos llegan (v2.0.1). `Purchase` sigue sin dispararse en `PayBooking.jsx`.
- [ ] **Frontend headless de Caliafarm** — URL pública (`FrontendUrl`) en los emails y pixeles del frontend (v5.10.5).

## J. Administración

- [ ] **Traducción del editor de tours y del admin** al inglés, con el idioma de perfil en inglés (v5.7.9 a v5.7.14). Falta confirmar en un WordPress real.
- [ ] **Menú de administración en inglés** — los 4 encabezados de sección siguen en español (pendiente, § 16.90).
- [ ] **Disponibilidad** — vista previa de calendario y plantilla "Bloquear todo el año" (v5.7.14, v5.8.2).
- [ ] **Editor de tours**: secciones colapsables (v5.6.15), itinerario con varias paradas (v5.7.0), precios y margen en vivo (v5.3.1).
- [ ] **Extras globales** con foto (v5.10.2) y con ID visible (v5.10.3).
- [ ] **Reportes**: export CSV descarga el archivo (v5.8.0), manifiesto de pasajeros en PDF.
- [ ] **TourFlow Cleaner** (`tourflow-cleaner` 0.1.2) — limpiar datos de prueba, con la confirmación de dos pasos.
- [ ] **Notificaciones** — las del Cleaner no quedan huérfanas (v5.8.0).
- [ ] **Colapsar todo del itinerario** (v5.6.13).
- [ ] **Links del menú de Tours y Habitaciones** cargan bien (v5.1.2).
- [ ] **Separadores del menú** no son clicables (v5.0.2).
- [ ] **Permisos de Tour Manager** — el rol puede guardar tours y habitaciones (v5.11.1).
- [ ] **Datos de demo** — "Cargar tours de ejemplo" publica 6 tours (v5.5.0), con la limitación de que la regla de disponibilidad se corrigió en v5.13.6 y falta confirmar en un sitio real.

## K. Integraciones y configuración

- [ ] **Google Calendar** — un sentido, al confirmar o reprogramar una reserva.
- [ ] **GDPR** — checkbox de consentimiento con hash, banner de cookies, anonimización (v5.7.14: se auditó, no se probó).
- [ ] **Multi-idioma** (it, fr, pt) — textos de emails, voucher y widget.
- [ ] **Personalización del widget** — color, tipografía y radio (v2.x).
- [ ] **Descuento vía URL** para partners (`?coupon=CODE`).
- [ ] **Moneda configurable** (ARS, USD, EUR) — los importes se muestran bien en el widget y en los emails.

## L. Sin fecha de verificación todavía (ideas o decisiones abiertas)

- [ ] Microinteracciones: sin especificar qué puntos concretos.
- [ ] Generador de tours con IA: idea del cliente, sin construir.
- [ ] Widget embebible para partners (idea del 2026-08-25): sin diseñar.
- [ ] Plan de pago en 3 etapas (Sicilia Mia): cobro automático programado no existe, solo cobro manual del saldo.
- [ ] Ocultar tours de listados del archive nativo de WordPress: no filtra `archive-amir_tour.php`.
