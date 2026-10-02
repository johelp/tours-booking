# Instructivo — WordPress headless en `book.caliafarm.com` + frontend en Cloudflare (`caliafarm.com`)

**Objetivo**: dejar un WordPress nuevo, instalado, configurado y operativo en `book.caliafarm.com` como backend de TourFlow, para que el frontend `caliafarm-web` (Astro, Cloudflare Pages) pase a consumirlo y, recién cuando todo esté verificado, mover el dominio `caliafarm.com` a Cloudflare Pages.

**Estado de partida** (2026-10-01):
- `caliafarm.com` hoy es un WordPress en Banahosting, con el sandbox en `caliafarm.com/stag/` — de ahí sale hoy la API que consume el frontend (`PUBLIC_API_BASE=https://caliafarm.com/stag/wp-json`).
- El frontend `caliafarm-web` ya existe y consume `amir/v1` + `flow/v1` (edición **Pro Max** obligatoria).
- Plugin: TourFlow **v5.13.4** (rama `fase1/seguridad-base-codigo2`).

**Regla de oro del proceso**: nada de lo que hay hoy en `caliafarm.com` se toca hasta el día del corte (Fase 8, § 9). Todo se arma en paralelo y se prueba con el dominio de prueba de Cloudflare Pages (`*.pages.dev`).

---

## 0. Arquitectura final

```
                 Navegador del cliente
                   │                 │
   páginas (HTML)  │                 │  API (fetch desde el navegador: cotizar, reservar, pagar)
                   ▼                 ▼
   ┌───────────────────────┐   ┌──────────────────────────────────────┐
   │ caliafarm.com          │   │ book.caliafarm.com  (Banahosting)     │
   │ Cloudflare Pages       │──▶│ WordPress + TourFlow Pro Max          │
   │ Astro estático         │   │ - /wp-json/amir/v1, /flow/v1  (API)   │
   │ + Pages Functions      │   │ - /wp-admin y /gestor/   (operación)  │
   │   (/api/contact, ...)  │   │ - cron, emails (SMTP), PDFs, webhooks │
   └───────────────────────┘   └──────────────────────────────────────┘
          ▲ build: el HTML del catálogo           ▲
          │ se genera leyendo la API              │ webhooks servidor-a-servidor
          │ en el momento del deploy              │ (Stripe)
```

Dos consecuencias que conviene tener claras desde el principio:

1. **El frontend es estático.** Las fichas `/tour/{slug}` y `/stay/{slug}` se generan en el *build* (`getStaticPaths`). Un tour nuevo, una foto nueva o un cambio de precio "desde" en WordPress **no aparece en `caliafarm.com` hasta que se vuelve a desplegar el frontend** (ver § 7.4, deploy hook). La disponibilidad, la cotización y el checkout sí son en vivo (se piden desde el navegador).
2. **Los links que reciben los clientes por email/voucher** (verificar reserva, pagar saldo, actualizar tarjeta tras un pago rechazado) se arman con *URL pública del sitio* (`amir_public_site_url`) → tienen que apuntar a `caliafarm.com`, no a `book.` (ver § 4.2).

---

## 1. Decisiones previas (cerrar antes de arrancar)

| Decisión | Recomendación | Por qué |
|---|---|---|
| ¿WordPress nuevo vacío o clon de `/stag`? | **Instalación limpia + replicar solo lo necesario** (§ 5) | `book.` arranca sin reservas de prueba, usuarios viejos, log de pagos ni restos del sandbox. El costo es recargar catálogo y configuración — acotado, y sirve de revisión de contenido antes de salir a producción. |
| ¿Single site o Multisite? | **Single site** | `book.` es un backend dedicado a un solo operador. Multisite suma complejidad (prefijos `wp_N_`, export por subsitio) sin beneficio. |
| ¿`book.caliafarm.com` con proxy de Cloudflare (nube naranja) o solo DNS (nube gris)? | **Solo DNS (gris)** al menos hasta estabilizar | Con proxy: (a) el rate limiter del plugin vería todas las IPs como de Cloudflare y frenaría a clientes legítimos en conjunto (§ 6.3); (b) Bot Fight Mode/WAF pueden bloquear los webhooks de Stripe. Con nube gris no hay que resolver nada de eso. |
| ¿Quién maneja el DNS de `caliafarm.com`? | **Cloudflare** (cambiar nameservers) | Para servir el dominio raíz (`caliafarm.com`, sin `www`) desde Cloudflare Pages, la zona tiene que estar en Cloudflare. Ver § 2 — ojo con el email. |
| Pasarela | **Stripe** | Es la que usa `caliafarm-web`. Mercado Pago/Redsys tienen un hueco conocido en headless (§ 10). |

---

## Ruta rápida — paso a paso en WordPress

Orden exacto para dejar `book.caliafarm.com` operativo. Cada paso remite a la sección con el detalle. No avanzar al siguiente sin la verificación del anterior.

**A. Antes de tocar WordPress**
1. DNS en Cloudflare (con cuidado del email) + registro `book` en nube gris → § 2.
2. Exportar los tours de `/stag` con el script (desde tu Mac) → § 5.3, paso 1. Se hace temprano para tener el reporte a mano.

**B. Servidor (cPanel)**

3. Crear el dominio `book.caliafarm.com`, con document root propio → § 3.1.
4. AutoSSL → **verificar**: `https://book.caliafarm.com` abre con candado.
5. PHP 8.2 + `max_input_vars=5000`, `memory_limit=256M`, límites de subida → § 3.1.
6. Base de datos y usuario nuevos → § 3.1.

**C. WordPress base**

7. Instalar WordPress en español, con un admin con email real → § 3.2.
8. Agregar las constantes a `wp-config.php` → § 3.3.
9. Cron real cada 5 min en cPanel → § 3.4.
10. Ajustes: enlaces permanentes "Nombre de la entrada", **disuadir motores de búsqueda**, zona horaria del negocio → § 3.5.
11. Instalar y configurar el plugin SMTP → **verificar** con un email de prueba de WordPress → § 4.3.

**D. TourFlow**

12. Subir el ZIP **Pro Max** v5.13.4 y activarlo → **verificar**: `/wp-json/amir/v1/config` devuelve `"edition":"pro_max"` → § 4.1.
13. Configuración → 🌐 Frontend externo: URL pública = `pages.dev`; CORS = `pages.dev` + `localhost` → § 4.2.
14. Configuración general, marca, personalización y Marketing, copiando de `/stag` lado a lado → § 4.6.
15. Stripe en modo **test** + webhook test a `book.` con 3 eventos → § 4.5.
16. Verificar que existen las páginas `/verificar-reserva/` y `/proveedor-reserva/` → § 4.4.

**E. Contenido (desde `/stag`, solo lo necesario)**

17. Proveedores (el catamarán tiene proveedor en origen).
18. Categorías de tours (Tours → Categorías: "Caliafarm Experiences").
19. Extras globales.
20. Tours: importar **de a uno** → completar según el reporte → publicar → 🔄 Sincronizar → anotar IDs nuevos → § 5.3.
21. Disponibilidad de cada tour, comparando la vista previa de calendario con `/stag`.
22. Habitaciones y su disponibilidad (a mano, mismo slug).
23. Cupones vigentes y partners activos (mismo código).
24. Textos de email editados, por idioma.
25. Usuarios reales: Tour Manager y Panel rápido.

**F. Modo headless**
26. mu-plugin de redirección del front (opcional) → **verificar** que `/gestor/`, `/wp-admin/` y `/wp-json/` siguen respondiendo → § 6.1.
27. Sin caché de página; activar backups automáticos → § 6.2 y § 6.4.

**G. Conectar el frontend y probar**
28. En `caliafarm-web`: `PUBLIC_API_BASE` → `book.`, IDs nuevos de Sicilia Mia, imágenes fijas a `public/` → § 7 y § 5.4.
29. Checklist completo de verificación con tarjetas de test → § 8.
30. Borrar las reservas de prueba. `book.` queda listo para el corte → § 9.

---

## 2. Fase 1 — DNS en Cloudflare (sin cortar nada todavía)

> Si la zona `caliafarm.com` ya está en Cloudflare, saltar a 2.4.

1. **Inventario del DNS actual** en Banahosting (cPanel → Zone Editor): anotar/exportar **todos** los registros, sobre todo `MX`, `TXT` (SPF, DKIM, DMARC, verificaciones de Google/Meta), `CNAME` de correo (`mail.`, `autodiscover.`) y cualquier subdominio en uso.
2. Cloudflare → **Add a site** → `caliafarm.com` → plan Free. Cloudflare escanea e importa los registros: **compararlos uno por uno contra el inventario del paso 1**. Un `MX` o `TXT` faltante = email del dominio caído.
3. En el import, dejar los registros de correo (`mail`, `MX` targets, `autodiscover`) en **DNS only (gris)** — el proxy de Cloudflare no pasa tráfico SMTP/IMAP. El registro `A` de `caliafarm.com`/`www` sigue apuntando a Banahosting por ahora (puede ir con nube naranja o gris; el sitio actual sigue funcionando igual).
4. Cambiar los **nameservers** en el registrador del dominio a los dos que indica Cloudflare. Esperar a que Cloudflare marque la zona como *Active* (minutos a 24 h).
5. Crear el subdominio del backend:
   - Tipo `A`, nombre `book`, valor = IP del hosting de Banahosting (la misma que hoy usa `caliafarm.com`, cPanel → *Shared IP Address*), **Proxy: DNS only (gris)**.
6. Bajar el TTL del registro `A`/`CNAME` de `caliafarm.com` y `www` a **Auto/5 min** con anticipación al corte (§ 9), para que un rollback sea rápido.

**Verificación**: `dig +short book.caliafarm.com` devuelve la IP de Banahosting; el email del dominio sigue entrando y saliendo (mandarse un correo de prueba en ambos sentidos).

---

## 3. Fase 2 — WordPress en `book.caliafarm.com`

### 3.1 Hosting (cPanel de Banahosting)

1. **Domains → Create a New Domain** (o *Subdomains*): `book.caliafarm.com`, document root propio (ej. `public_html/book` o `~/book.caliafarm.com`). **Nunca** dentro de la carpeta del sitio actual.
2. **SSL/TLS Status → Run AutoSSL** para `book.caliafarm.com` (con nube gris, Let's Encrypt/Sectigo valida directo). Confirmar que `https://book.caliafarm.com` responde con candado antes de seguir.
3. **MultiPHP Manager**: PHP **8.1 o superior** (8.2 recomendado) para ese dominio. 8.1 es mínimo real (`endroid/qr-code`).
4. **MultiPHP INI Editor** para ese dominio:
   - `max_input_vars = 5000` (el editor de tours manda cientos de campos)
   - `memory_limit = 256M`, `upload_max_filesize = 64M`, `post_max_size = 64M`, `max_execution_time = 120`
5. **MySQL Databases**: base nueva + usuario nuevo con todos los privilegios sobre esa base. Anotar nombre/usuario/clave. **No** reutilizar la base del sitio actual.

### 3.2 Instalar WordPress

- Vía Softaculous (WordPress → instalar en `https://book.caliafarm.com`, sin plugins extra), o subida manual de WordPress + `wp-config.php`.
- Idioma del sitio: **Español** si el operador administra en español (el Panel de gestión `/gestor/` usa el idioma del sitio, no el del perfil).
- Usuario admin con email real del operador y clave fuerte (no `admin`).

### 3.3 `wp-config.php` — agregar antes de `/* That's all, stop editing! */`

```php
define( 'WP_HOME',    'https://book.caliafarm.com' );
define( 'WP_SITEURL', 'https://book.caliafarm.com' );
define( 'FORCE_SSL_ADMIN', true );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', true );      // errores a wp-content/debug.log, nunca a pantalla
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );   // se reemplaza por cron real, ver 3.4
```

### 3.4 Cron real

TourFlow depende del cron para vencer reservas impagas, recordatorios, emails de reseña, aviso a proveedores, etc. (el vencimiento de pagos también corre "perezoso" en cada request desde v5.13.4, pero los emails no).

cPanel → **Cron Jobs** → cada 5 minutos:

```
*/5 * * * * wget -q -O /dev/null "https://book.caliafarm.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

(o `php /ruta/a/book/wp-cron.php` si el hosting lo permite — evita pasar por HTTP).

### 3.5 Ajustes básicos de WordPress

- **Ajustes → Enlaces permanentes**: "Nombre de la entrada" → Guardar. Necesario para que `/wp-json/...` y `/gestor/` resuelvan.
- **Ajustes → Lectura**: marcar **"Disuadir a los motores de búsqueda de indexar este sitio"**. `book.` nunca debe competir en Google con `caliafarm.com`.
- **Ajustes → Generales → Zona horaria**: la del negocio (ej. `Europe/Rome` si Caliafarm opera en Sicilia — confirmar con el operador). Afecta disponibilidad del día, vencimientos y recordatorios.
- Tema: dejar uno liviano por defecto (Twenty Twenty-Four o similar). Solo se ve en `wp-admin` y en las 2 páginas de fallback del plugin (§ 4.4).
- Plugins mínimos: un plugin **SMTP** (WP Mail SMTP o FluentSMTP) — ver § 4.3. Nada de builders, caché de página ni plugins de seguridad agresivos hasta que todo esté verificado (un plugin de caché de página puede cachear respuestas de `/wp-json` y romper la disponibilidad en vivo).

---

## 4. Fase 3 — Plugin TourFlow y configuración

### 4.1 Instalar / actualizar el plugin

1. Armar el ZIP **Pro Max** v5.13.4 (o la última) con `vendor/` incluido (`composer install --no-dev --optimize-autoloader`, ver `INSTALL.md`). Correr los tests antes de empaquetar.
2. Plugins → Añadir nuevo → Subir → activar. Al activarse crea sus tablas, el rol Tour Manager, los cron y las páginas `/verificar-reserva/` y `/proveedor-reserva/`.
3. Verificar la edición y la versión:

   ```bash
   curl -s https://book.caliafarm.com/wp-json/amir/v1/config | python3 -m json.tool | grep -E '"edition"|"version"|"currency"|"siteUrl"'
   ```

   Esperado: `"edition": "pro_max"`, `"siteUrl": "https://book.caliafarm.com"`, moneda correcta (`EUR` si corresponde).

4. Si alguna pantalla de Tours/Reservas tira error de columna desconocida: entrar a cualquier página de `wp-admin` una vez (la red de seguridad `ensure_*` del Installer corrige el esquema sola), o desactivar/reactivar el plugin.

### 4.2 TourFlow → Configuración → 🌐 Frontend externo (el paso que más se olvida)

| Campo | Valor durante pruebas (Fases 4-7) | Valor el día del corte (Fase 8) |
|---|---|---|
| URL pública del sitio | `https://<proyecto>.pages.dev` | `https://caliafarm.com` |
| Habilitar CORS | ✅ | ✅ |
| Dominios permitidos | `https://<proyecto>.pages.dev`<br>`http://localhost:4321` | `https://caliafarm.com`<br>`https://www.caliafarm.com`<br>`https://<proyecto>.pages.dev` |

- Un origen por línea, con `https://`, **sin barra final ni ruta**. `www.` y sin `www` son orígenes distintos.
- Los previews de rama de Cloudflare (`https://<hash>.<proyecto>.pages.dev`) son orígenes distintos: si se quieren probar, agregarlos uno por uno.
- `localhost` solo mientras se desarrolla; sacarlo al pasar a producción.

### 4.3 Email (SMTP)

TourFlow manda todo por `wp_mail()`: confirmaciones, vouchers, pago rechazado, recordatorios, y también los formularios de Contacto/Grupos del frontend (`/api/contact`, `/api/groups` → `POST /amir/v1/contact`, `/groups-inquiry`).

1. Plugin SMTP configurado con la casilla real del negocio (ej. `reservas@caliafarm.com`) — mismo servidor de correo que hoy.
2. **Remitente** `@caliafarm.com` (no `@book.caliafarm.com`): así aplica el SPF/DKIM ya existente del dominio. Si el SMTP es el de Banahosting, confirmar que el SPF de `caliafarm.com` lo incluye.
3. TourFlow → Configuración → **Email del administrador** (`amir_admin_email`): a dónde llegan avisos de reservas y consultas de Contacto/Grupos.
4. Probar con TourFlow → ✉️ Emails → **Probar envío** (confirmación, recordatorio y "pago rechazado") a una casilla Gmail y una Outlook. Revisar que no caigan en spam.

### 4.4 Páginas de fallback del plugin

El plugin crea dos páginas en WordPress (shortcodes): `/verificar-reserva/` y `/proveedor-reserva/`. Comprobar que existen y están publicadas (Páginas). En headless:

- `/verificar-reserva/`: el frontend ya tiene su propia página (`src/pages/verificar-reserva.astro`, pago de saldo y reintento de tarjeta incluidos). La de WordPress queda solo como respaldo.
- `/proveedor-reserva/`: **el frontend NO tiene esta página**. Los links de aprobación que reciben los proveedores externos van a `<URL pública>/proveedor-reserva/?...` → hay que redirigirlos a `book.` (§ 7.3). Solo aplica si Caliafarm usa el Marketplace de proveedores.

### 4.5 Stripe

1. TourFlow → Configuración → Stripe: cargar claves **test** primero (`pk_test_…`, `sk_test_…`), modo **test**, pasarela activa = Stripe.
2. Stripe Dashboard (modo test) → Developers → **Webhooks → Add endpoint**:
   - URL: `https://book.caliafarm.com/wp-json/amir/v1/bookings/stripe-webhook`
   - Eventos: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`
   - Copiar el *Signing secret* (`whsec_…`) a Configuración → Webhook secret.
3. **No** borrar todavía el webhook viejo que apunta a `/stag` hasta el corte: si hay pruebas en curso ahí, siguen funcionando. Sí conviene **deshabilitarlo** apenas el frontend apunte a `book.`, para no confundir el Log de pagos.
4. El día del corte se repite con claves y webhook **live** (§ 9).

### 4.6 Resto de la configuración

- Copiar los valores de `/stag` pantalla por pantalla (abrir las dos instalaciones lado a lado). No hay export/import de configuración.
- General: moneda (`EUR`), prefijo de referencia de reserva, idiomas activos, **plazo de gracia tras pago rechazado** (default 6 h), minutos de vencimiento de `pending`.
- Marca: logo, color, nombre, tagline (se usan en emails y voucher PDF).
- Marketing: Meta Pixel / GA4 (el frontend los lee de `/config` → `metaPixelId`, `ga4Id`).
- Personalización: términos y política de cancelación (el frontend muestra el checkbox de términos).
- URLs de reseña (Google / TripAdvisor) — que sean las de Caliafarm, no de otro negocio.
- Panel de gestión: probar `https://book.caliafarm.com/gestor/` con un usuario Tour Manager.

---

## 5. Fase 4 — Replicar desde `/stag` solo lo necesario

`book.` arranca limpio: **no** se traen reservas, clientes, log de pagos, notificaciones ni usuarios de prueba. Se reconstruye solo el catálogo y la configuración que el sitio nuevo necesita. `/stag` sigue en pie hasta el corte, así que sirve de referencia (y de origen de las fotos) durante toda esta fase.

### 5.1 Inventario en `/stag`

Antes de cargar nada, armar la lista de lo que realmente va a estar a la venta (para los tours, el reporte del script de § 5.3 ya trae la lista):

```bash
# Tours (id, slug, nombre, proveedor, duración)
curl -s "https://caliafarm.com/stag/wp-json/amir/v1/tours" \
  | python3 -c 'import sys,json; d=json.load(sys.stdin); d=d.get("tours",d) if isinstance(d,dict) else d; [print(t["id"],t["slug"],t.get("provider_id"),t.get("duration_minutes"),t["name"],sep=" | ") for t in d]'

# Habitaciones
curl -s "https://caliafarm.com/stag/wp-json/flow/v1/rooms" | python3 -m json.tool | grep -E '"(id|slug|name)"'

# Extras globales / productos digitales
curl -s "https://caliafarm.com/stag/wp-json/flow/v1/addons/global" | python3 -m json.tool | grep -E '"(id|name)"'
```

Con el operador: marcar cuáles se replican, cuáles se descartan (pruebas, borradores viejos) y si alguno cambia de nombre/precio aprovechando la recarga.

### 5.2 Qué se replica y cómo

| Elemento | ¿Replicar? | Cómo |
|---|---|---|
| Configuración general, marca, personalización, Marketing | ✅ | A mano, § 4.6. El logo se vuelve a subir a Medios de `book.`. |
| Textos de email editados | ✅ si se editaron en `/stag` | TourFlow → ✉️ Emails → Editar textos, **por idioma**. Incluye el bloque HTML personalizado (confirmación y recordatorio) si se usó. |
| Proveedores (Marketplace) | ✅ **antes que los tours** | TourFlow → 🤝 Proveedores. Ojo: el frontend clasifica como *Day tours & excursions* a todo tour que tenga proveedor (§ 5.4). |
| Extras globales / productos digitales | ✅ | TourFlow → 🎁 Extras globales. Productos digitales: volver a subir el archivo. |
| Tours | ✅ solo los que van a la venta | Script de exportación + importador + completar en el editor (§ 5.3). |
| Disponibilidad (temporadas, bloqueos, semanas puntuales) | ✅ | TourFlow → Disponibilidad, regla por regla. Usar la vista previa de calendario para comparar contra `/stag`. |
| Habitaciones + su disponibilidad | ✅ | A mano (no hay importador de habitaciones). Mismo slug que en `/stag`. |
| Cupones | Solo los vigentes | A mano. Los usos ya consumidos en `/stag` no se trasladan: un cupón de uso único arranca de cero. |
| Partners | Solo los activos | A mano, **con el mismo código**: los links `?coupon=CODE` ya repartidos dependen de él. |
| Usuarios | Solo los reales | Crear de nuevo: Tour Manager y, si aplica, Panel rápido (`amir_quick_staff`/`amir_quick_cashier`). |
| Reservas, clientes, log de pagos, notificaciones | ❌ | — |

> **Confirmar con el operador**: si hay reservas **reales y futuras** en el WordPress actual de `caliafarm.com` (no `/stag` — por ejemplo, de cuando se vendía con WooCommerce), hay que recargarlas en `book.` como **reserva manual** + **💵 Cargar pago manual**, para que ocupen cupo y aparezcan en Modo Campo.

### 5.3 Tours: exportar de `/stag` con el script e importar en `book.`

El plugin no tiene una función de "exportar tours", así que se usa el script [`tools/export-tours-rest.py`](tools/export-tours-rest.py). Lee la **API pública** de `/stag` (solo GET, no toca nada allá) y genera el JSON en el formato exacto del importador (**TourFlow → Configuración → 📥 Importar**), más un reporte con lo que hay que completar a mano. Probado contra `/stag` el 2026-10-01: exporta los 6 tours activos.

**1. Exportar** (en tu Mac, con `/stag` todavía en pie):

```bash
python3 tools/export-tours-rest.py --base https://caliafarm.com/stag/wp-json --ids 11,12 --per-tour --out ~/Desktop/caliafarm-export/tours.json
```

- `--ids 11,12`: tours con "Ocultar de listados" activo, que el listado público no devuelve (las dos semanas de Sicilia Mia, por si acaso). Si hay otros ocultos, sumar sus IDs.
- `--per-tour`: además del JSON completo, uno por tour (`tours-<slug>.json`).
- Resultado (fuera del repo, a propósito): `tours.json`, `tours-<slug>.json` y `tours-reporte.md` (checklist por tour + tabla "ID origen → ID nuevo" para completar).

Qué hace el script con los datos:
- Junta las versiones ES y EN de cada tour (textos, itinerario, datos destacados, FAQ, horarios).
- Usa la **foto original** de cada imagen (no la versión de 1024 px) y saca duplicados. El importador las descarga a la Media Library de `book.`, y después del import ya no dependen de `/stag`.
- Precios genéricos por persona, o bandas por grupo alineadas con las que crea el importador. Si no coinciden, avisa en el reporte.
- Si un tour tiene `description_en` vacío en origen (pasa en `/stag`: el texto en inglés quedó cargado en el campo ES), copia el texto ES al EN y lo marca en el reporte. Si no, la ficha en inglés del frontend saldría sin descripción.

**2. Importar** en `book.` → TourFlow → Configuración → 📥 Importar → pegar el contenido de **un** `tours-<slug>.json` → Importar. Repetir con cada tour.
- **De a un tour**: el importador descarga todas las fotos dentro de la misma petición. Con un lote grande puede pasarse del tiempo máximo de PHP y cortarse a mitad.
- Si un import se corta o sale mal: borrar el tour (y sus fotos en Medios) y volver a importarlo. Reimportar el mismo slug actualiza el tour, pero **vuelve a subir las fotos** (quedan duplicadas en Medios).

**3. Completar en el editor**, siguiendo `tours-reporte.md`. Lo que el importador **nunca** carga, revisar en todos los tours:
- Días operativos y reglas de disponibilidad, lista de interés, depósito parcial, reserva directa, ocultar de listados, nota extra del email de confirmación, costo/margen. La API pública no los expone, así que hay que mirarlos en el editor de `/stag`.

Lo que el reporte marca por tour cuando aplica:
- Proveedor, extras del tour, video, categorías, "Armá tu tour", "Requiere nombre de cada integrante", precios por horario, fotos de paradas del itinerario.

**4. Publicar** cada tour. Al terminar, ir a TourFlow → Configuración → **🔄 Sincronizar todos los tours ahora**.

**5. Anotar el ID nuevo** de cada tour en la tabla del reporte. Sale de la columna "ID" de TourFlow → Tours, no del `post=` de la URL del editor. Hace falta para § 5.4.

Reglas: **el slug se mantiene idéntico** (el script lo exporta tal cual; no cambiarlo en el editor). Las URLs públicas son `/tour/{slug}`, y el frontend enlaza slugs concretos.

**Si `/stag` ya no estuviera disponible**: armar el JSON a mano con `PROMPT-IMPORTAR-TOUR.md`.

### 5.4 Lo que el frontend espera encontrar en los datos

`caliafarm-web` no es neutro respecto del contenido. Después de replicar, revisar:

| Dependencia | Dónde | Qué hacer |
|---|---|---|
| **IDs fijos de la página Sicilia Mia** — `TOUR_ID_JUNE = 11`, `TOUR_ID_JULY = 12` | `src/pages/sicilia-mia.astro:15-16` | En la instalación limpia los IDs van a ser otros. Si el 11 no existe en `book.`, **el build del frontend falla**. Actualizar a los IDs nuevos (o, mejor, cambiar la página para buscar por slug). |
| Slug `sicilia-mia-retreat` | `src/pages/stay/index.astro:82` | Mantener ese slug exacto. |
| Agrupación del catálogo | `src/pages/tours-and-experiences/index.astro:31-33` | *Day tours & excursions* = tours **con proveedor**; *Stays & retreats* = sin proveedor y duración ≥ 1200 min (20 h); *Farm experiences* = el resto. Cargar proveedor y duración igual que en `/stag` o los tours caen en otro grupo. |
| Imágenes/video fijos de `/stag` | ver § 7.2 | Mover al repo antes del corte. |

### 5.5 Orden de carga recomendado

1. Configuración completa (§ 4)
2. Proveedores
3. Extras globales
4. Tours: import → completar → publicar → sincronizar
5. Disponibilidad de tours
6. Habitaciones y su disponibilidad
7. Cupones y Partners
8. Textos de email
9. Usuarios
10. Actualizar IDs en `caliafarm-web` (§ 5.4) y seguir con § 6-7

---

## 6. Fase 5 — Ajustes de WordPress para el modo headless

### 6.1 Que nadie aterrice en el WordPress público (opcional, recomendado)

Las fichas `/tour/...` del WordPress siguen existiendo en `book.`. Para evitar que alguien navegue ahí, un *mu-plugin* que redirige el front de WordPress a `caliafarm.com`, dejando pasar admin, API, panel, cron y las páginas de fallback:

`wp-content/mu-plugins/caliafarm-headless-redirect.php`

```php
<?php
/**
 * Plugin Name: Caliafarm — redirigir el front de WordPress al sitio headless
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	// Páginas que SÍ tienen que seguir sirviéndose desde book.
	foreach ( [ 'gestor', 'proveedor-reserva', 'verificar-reserva', 'wp-login.php' ] as $keep ) {
		if ( $path === $keep || 0 === strpos( $path, $keep . '/' ) ) {
			return;
		}
	}
	$target = get_option( 'amir_public_site_url', '' );
	if ( $target ) {
		wp_redirect( rtrim( $target, '/' ) . '/', 302 );
		exit;
	}
} );
```

Usar `302` mientras se prueba; pasar a `301` después del corte si todo está bien. Verificar después de instalarlo que `/gestor/`, `/wp-admin/` y un `GET /wp-json/amir/v1/tours` siguen respondiendo.

### 6.2 Caché

- Sin caché de página sobre `book.` (ni plugin ni LiteSpeed Cache del hosting). Si el hosting la fuerza, excluir `/wp-json/*`, `/gestor/*`, `/verificar-reserva/*`, `/proveedor-reserva/*`.
- Object cache (Redis/Memcached) sí está bien si el hosting lo ofrece.

### 6.3 Rate limiter e IPs reales

El rate limiter del plugin usa `REMOTE_ADDR` (fix de seguridad v5.13.3). Con `book.` en **nube gris**, `REMOTE_ADDR` es la IP real del cliente para todo lo que va del navegador a la API (cotizar, reservar, pagar). Dos matices:

- Las Pages Functions (`/api/contact`, `/api/groups`) llaman a WordPress **desde Cloudflare**, no desde el navegador: todos los envíos de Contacto/Grupos comparten la IP de salida de Cloudflare. Con el volumen normal de un formulario de contacto no debería tocar el límite; si alguna vez aparece un `429` en esos formularios, esa es la causa.
- Si en el futuro se activa la **nube naranja** en `book.`: hay que (1) agregar `add_filter( 'amir_trust_proxy_headers', '__return_true' );` en un mu-plugin, y (2) **solo** si el servidor acepta tráfico exclusivamente de IPs de Cloudflare — si no, cualquiera falsifica `CF-Connecting-IP` y evade el límite. Además, crear una regla WAF *Skip* para `/wp-json/amir/v1/bookings/stripe-webhook` (Bot Fight Mode bloquea webhooks).

### 6.4 Backups

Backup diario automático (cPanel/JetBackup del hosting o UpdraftPlus a almacenamiento externo) de **base + `wp-content/uploads`**. `uploads/amir-booking/` tiene vouchers y QRs generados.

---

## 7. Fase 6 — Frontend `caliafarm-web` apuntando a `book.`

### 7.1 Variables de entorno en Cloudflare Pages

Cloudflare → Workers & Pages → proyecto → Settings → **Variables and Secrets** (Production **y** Preview):

| Variable | Valor |
|---|---|
| `PUBLIC_API_BASE` | `https://book.caliafarm.com/wp-json` |
| `PUBLIC_SITE_URL` | `https://caliafarm.com` |
| `MAILERLITE_API_KEY`, `MAILERLITE_GROUP_ID` | sin cambios (Secret) |

Cambiar el fallback en código también (hoy cae a `/stag` si falta la variable): `src/lib/api.ts:1`, `functions/api/contact.ts:33`, `functions/api/groups.ts:35` → `https://book.caliafarm.com/wp-json`. Y `.env.example`.

Después: **Deployments → Retry deployment** (el build lee la API en ese momento — si `book.` no responde, el build falla, lo cual es una buena verificación).

### 7.2 Imágenes y video hardcodeados a `caliafarm.com/stag` — **bloqueante para el corte**

Hay 13 URLs fijas a `https://caliafarm.com/stag/wp-content/uploads/...` en el frontend. El día que `caliafarm.com` pase a Cloudflare Pages, **todas dejan de existir** (el WordPress viejo ya no responde en ese dominio):

- `src/components/Hero.astro:5`
- `src/components/AboutTeaser.astro:9`
- `src/layouts/BaseLayout.astro:30` (imagen OG por defecto) y `src/lib/schema.ts:84`
- `src/pages/index.astro:40-41` (video del home + poster)
- `src/pages/sobre-nosotros.astro:9,14,19,24,29,68`

Solución recomendada: descargar esos archivos a `public/` del repo (o a `src/assets/` para que Astro los optimice) y referenciarlos con ruta relativa. El video `.mp4` puede ir a `public/video/` (ya existe esa carpeta) o a Cloudflare R2/Stream si pesa mucho. No sirve reemplazar el dominio por `book.`: en la instalación limpia esos archivos no existen ahí (salvo que se suban a mano a Medios, lo que además ataría el sitio público al hosting de WordPress).

Verificación: `grep -rn "caliafarm.com/stag" src functions public` no devuelve nada.

### 7.3 Redirecciones en el dominio público

Cloudflare (zona `caliafarm.com`) → Rules → **Redirect Rules** (estas sí tienen la opción *Preserve query string*, que hace falta porque los links llevan `?ref=…&token=…`):

| Si la URL coincide con | Redirigir a | Código | Preserve query string |
|---|---|---|---|
| `caliafarm.com/proveedor-reserva*` | `https://book.caliafarm.com/proveedor-reserva/` | 302 | ✅ |
| `caliafarm.com/tours/` | `https://caliafarm.com/tours-and-experiences` | 301 | — |
| `www.caliafarm.com/*` | `https://caliafarm.com/${1}` | 301 | ✅ |

- `/tours/` es el link "Ver otros tours" que el plugin pone en el email de solicitud rechazada.
- Más el mapa de **URLs viejas del WordPress actual → rutas nuevas** para no perder SEO (§ 9, paso 1). Las fichas `/tour/{slug}` ya coinciden en ambos sitios si los slugs no cambiaron.

### 7.4 Rebuild automático cuando cambia el contenido

Como el sitio es estático:

1. Cloudflare Pages → Settings → Builds → **Deploy hooks** → crear uno (rama de producción). Es una URL secreta.
2. Opciones para dispararlo:
   - **Manual**: guardar la URL en un marcador del operador ("Publicar cambios en la web") — un `POST` a esa URL redespliega en ~1-2 min.
   - **Programado**: un Cron Trigger / tarea externa que lo llame cada X horas.
   - **Automático al guardar un tour**: requiere código nuevo (un satélite que haga `wp_remote_post()` al hook en `save_post_amir_tour`). No existe hoy — evaluarlo aparte, respetando la pausa de features.

Documentarle al operador cuál quedó elegido: "cambié la foto del tour y no se ve" va a ser la primera consulta.

---

## 8. Fase 7 — Verificación completa antes del corte

Todo contra `https://<proyecto>.pages.dev` + `https://book.caliafarm.com`, Stripe en **test**. Marcar cada ítem:

**API y CORS**

```bash
# Config y edición
curl -s https://book.caliafarm.com/wp-json/amir/v1/config | grep -o '"edition":"[^"]*"'

# Preflight CORS desde el origen permitido → tiene que devolver Access-Control-Allow-Origin
curl -si -X OPTIONS https://book.caliafarm.com/wp-json/amir/v1/bookings/quote \
  -H "Origin: https://<proyecto>.pages.dev" \
  -H "Access-Control-Request-Method: POST" | grep -i access-control

# Desde un origen NO permitido → NO tiene que aparecer Access-Control-Allow-Origin
curl -si -X OPTIONS https://book.caliafarm.com/wp-json/amir/v1/bookings/quote \
  -H "Origin: https://example.com" \
  -H "Access-Control-Request-Method: POST" | grep -i access-control

# Catálogo
curl -s https://book.caliafarm.com/wp-json/amir/v1/tours | head -c 400
curl -s https://book.caliafarm.com/wp-json/flow/v1/rooms | head -c 400
```

**Flujo de compra (en el navegador, sobre `pages.dev`)**

- [ ] Home, catálogo, `/tour/{slug}`, `/stay/{slug}` cargan con fotos (ninguna imagen rota; revisar la consola).
- [ ] Reserva de tour con tarjeta `4242 4242 4242 4242` → confirmación + email + voucher PDF descargable.
- [ ] Reserva con habitación + extra en el mismo carrito → un solo cobro, un solo voucher general.
- [ ] Tarjeta de rechazo `4000 0000 0000 0002` → la reserva queda `pending` (no cancelada), llega el email de "pago rechazado", el botón del email abre `<pages.dev>/verificar-reserva/?…` y reintentar con `4242…` confirma. *(Pendiente de v5.13.4, se valida acá.)*
- [ ] Stripe Dashboard → Webhooks → el endpoint de `book.` muestra entregas `200`. TourFlow → Log de pagos registra los eventos.
- [ ] Cupón (si se usan) aplica en la cotización y en el cobro.
- [ ] Todos los links de los emails y del voucher apuntan al dominio del frontend, **ninguno** a `book.` (salvo el de proveedor).
- [ ] Formulario de Contacto y de Grupos → llega el email al operador y aparece la notificación en el Dashboard.
- [ ] Newsletter (MailerLite) sigue funcionando.
- [ ] Mobile real (iPhone + Android): checkout completo.

**Operación**

- [ ] `wp-admin` → Reservas muestra las reservas de prueba; cancelar una con reembolso → `charge.refunded` llega y queda reflejado.
- [ ] `/gestor/` con un Tour Manager: Reservas, Calendario, Modo Campo (escaneo de QR del voucher).
- [ ] Panel rápido con un usuario `amir_quick_staff` (solo ve su sección, sin montos).
- [ ] El cron corre: TourFlow → Dashboard, o `wp cron event list` muestra `amir_hourly_tasks`/`amir_daily_tasks` con próxima ejecución en el futuro cercano.
- [ ] Proveedor externo (si aplica): el link de aprobación abre la página en `book.`.

**Limpieza antes del corte**

- [ ] Borrar las reservas hechas durante estas pruebas (TourFlow Cleaner), para que `book.` llegue al corte sin datos falsos.
- [ ] Respaldo completo de `book.` (base + uploads).

---

## 9. Fase 8 — Día del corte (migración del dominio)

Elegir un horario de bajo tráfico. Duración estimada: 1-2 h + monitoreo.

1. **Mapa de redirecciones SEO**: listar las URLs indexadas del WordPress actual (`caliafarm.com/sitemap.xml` o Search Console → Páginas) y cargar en Redirect Rules (o en `public/_redirects` de `caliafarm-web`) las que no coinciden con una ruta nueva (ej. páginas de contacto/nosotros con otro slug, categorías, entradas de blog).
2. **Backup** del WordPress actual de `caliafarm.com` (por si hay que volver).
3. **Stripe a live** en `book.`: claves `pk_live_`/`sk_live_`, modo live, nuevo webhook **live** a `https://book.caliafarm.com/wp-json/amir/v1/bookings/stripe-webhook` con los 3 eventos y su `whsec_` live. Deshabilitar el webhook live viejo (el que apuntaba al WordPress actual), si existe.
4. **TourFlow → Frontend externo**: URL pública = `https://caliafarm.com`; CORS = `https://caliafarm.com`, `https://www.caliafarm.com` (+ `pages.dev` si se quieren seguir probando previews). Sacar `localhost`.
5. **Cloudflare Pages → Custom domains → Set up a custom domain**: `caliafarm.com` y `www.caliafarm.com`. Cloudflare reemplaza los registros `A` que apuntaban a Banahosting por los de Pages. **No tocar** `book`, `mail`, `MX`, ni ningún `TXT`.
6. Redesplegar el frontend (para que el build tome la config final).
7. Activar la Redirect Rule de `www` → apex (§ 7.3) si no estaba activa.
8. **Prueba de humo inmediata** (ventana de incógnito):
   - `https://caliafarm.com` sirve el sitio nuevo (ver en DevTools que lo sirve Cloudflare Pages).
   - Reserva real de monto bajo con tarjeta real → confirmar y **reembolsar** desde wp-admin. Revisar el webhook live en Stripe (`200`).
   - Un email de confirmación real → todos los links a `caliafarm.com`.
   - Contacto → llega.
9. **Search Console**: agregar/verificar la propiedad de dominio, enviar `https://caliafarm.com/sitemap-index.xml`. Confirmar que `book.` está con "Disuadir motores de búsqueda".
10. Pasar el redirect del mu-plugin (§ 6.1) a `301` cuando todo esté estable.
11. **Monitoreo 48-72 h**: Log de pagos, `wp-content/debug.log` de `book.`, Stripe → Webhooks (entregas fallidas), Cloudflare Pages → Functions logs, bandeja del operador.

### Rollback

Si algo crítico falla en el corte: Cloudflare Pages → Custom domains → quitar `caliafarm.com`/`www` y volver a crear los registros `A` hacia la IP de Banahosting (por eso el TTL bajo del § 2, paso 6). El WordPress viejo vuelve a responder en minutos. Las reservas hechas en `book.` durante la ventana quedan en `book.` — no se pierden.

### Después del corte (sin apuro)

- Decidir qué hacer con el WordPress viejo y `/stag` (quedan inaccesibles por dominio, pero siguen ocupando el hosting). Archivar un backup y desinstalar.
- Reemplazar el `pages.dev` en CORS si ya no se usa.

---

## 10. Huecos conocidos (no bloquean el corte con Stripe)

- **Mercado Pago / Redsys en headless**: las URLs de retorno (`back_urls` en `class-mercado-pago-gateway.php:53-55`, `URLOK/URLKO` en `redsys-for-tourflow`) usan `home_url('/')` → el cliente volvería a `book.caliafarm.com` (redirigido luego a `caliafarm.com` por el mu-plugin, pero sin pasar por una pantalla de confirmación). Con Stripe no aplica. Si algún día se activa MP/Redsys para Caliafarm, cambiarlas a `FrontendUrl::base()` + una ruta de retorno del frontend.
- **Contenido estático**: cambios de catálogo requieren redeploy (§ 7.4).
- **Rate limit compartido** de Contacto/Grupos detrás de las Pages Functions (§ 6.3).
- **`/proveedor-reserva/`** vive solo en `book.` — resuelto con redirect (§ 7.3), sin página propia en el frontend.

---

## Resumen de responsables (completar)

| Fase | Quién | Fecha |
|---|---|---|
| 1. DNS en Cloudflare | | |
| 2. WordPress en `book.` | | |
| 3. Plugin + configuración | | |
| 4. Replicar contenido desde `/stag` (incl. IDs en el frontend) | | |
| 5. Ajustes headless | | |
| 6. Frontend apuntando a `book.` (incl. imágenes) | | |
| 7. Verificación completa | | |
| 8. Corte del dominio | | |
