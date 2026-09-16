# Split Payments de MercadoPago para TourFlow — spec y plan de fases

Pedido del cliente (2026-09-09): evaluar si el split payment 1:1 de MercadoPago
(https://www.mercadopago.com.ar/developers/es/docs/split-payments/split-1-1/overview)
sirve para automatizar comisiones de **proveedores externos** (marketplace de
tours de terceros) y de **partners/RRPP** (afiliados que refieren clientes),
pensando en la operación real en Argentina/LatAm. Coherente con
`feedback_stabilization_priority` (memoria): esto es una feature nueva grande
que toca el circuito de pago real, así que se construye como **plugin
satélite**, opcional, sin forzar nada sobre el flujo manual que ya funciona.

**Decisión del cliente (2026-09-10), delegando el resto del diseño**: el
split de MercadoPago es **una opción de liquidación por proveedor, no un
reemplazo obligatorio**. Un proveedor sin cuenta de MercadoPago conectada
sigue exactamente el flujo manual actual (ledger `amir_provider_payouts`,
liquidación fuera de sistema) — el split solo se activa para el proveedor
que decida conectar su cuenta. Esto resuelve la pregunta abierta de la § 5
original (¿los proveedores actuales ya tienen cuenta de MP?) sin necesitar
la respuesta: da igual, conviven los dos modelos.

## 0. Plan de fases (orden de construcción)

1. **Ledger de comisiones de partners** (`amir_partner_payouts`) — hueco real
   encontrado en el análisis (§ 2): `amir_partners` calcula la comisión pero
   no la registra en ningún lado. Mismo patrón que `amir_provider_payouts`,
   cero integración de pago, cero riesgo, resuelve algo que falta HOY
   independientemente de si el split de MP se construye o no. **Primera
   pieza a construir**, ver § 6.
2. **Plugin satélite `mercadopago-split-tourflow/`** — conexión OAuth por
   proveedor + lógica condicional de checkout (usa split si el proveedor
   tiene cuenta conectada, si no cae al flujo 100%+manual de siempre). Ver
   § 3 para la arquitectura. Depende de una aprobación externa de
   MercadoPago que no está confirmada — ver § 4, es la pieza de mayor riesgo
   de cronograma, conviene iniciarla en paralelo al desarrollo.
3. **Split de comisión de partners** (fase separada, más incierta) — una vez
   validado el flujo de proveedores, evaluar si conviene extenderlo a
   partners vía el mismo mecanismo de payout de MP (transferencia
   programática después de confirmada la venta) en vez de vía split de
   checkout — ver § 2, son mecanismos técnicos distintos.

## 1. Qué es el split payment 1:1 de MercadoPago (verificado 2026-09-10)

Investigado contra la documentación real de MP (no asumido de memoria):

- **Modelo**: el pago del cliente final se cobra **con las credenciales del
  vendedor secundario** (no las del operador/marketplace) — el dinero entra
  directo a la cuenta de MP de ese vendedor. En el mismo movimiento, se
  descuenta una **comisión del marketplace** (el parámetro exacto —
  `marketplace_fee` vs `application_fee` — no quedó confirmado en la
  documentación pública fetcheada; hay que confirmarlo contra la referencia
  de API real antes de codear) y se transfiere automáticamente a la cuenta
  del operador. Es lo contrario del modelo actual de TourFlow (el operador
  cobra el 100% y liquida después manualmente).
- **Requisitos confirmados**:
  - El vendedor secundario necesita **cuenta de MercadoPago propia con nivel
    de identificación KYC 6** — no una cuenta genérica del operador. Esto es
    el bloqueo real de onboarding: cada proveedor/partner tiene que abrir o
    ya tener una cuenta de MP verificada a ese nivel.
  - **OAuth obligatorio** — el vendedor autoriza a la app de TourFlow a
    operar en su nombre (flujo estándar: redirect → autorización → se
    obtiene `access_token`/`user_id` del vendedor, se guarda del lado de
    TourFlow).
  - Solo disponible con **Checkout Pro o Checkout API** (no otros métodos).
  - El modelo **1:N** (varios vendedores en un mismo pago — relevante si un
    carrito mezcla ítems de más de un proveedor) **no es self-service**:
    requiere gestión comercial directa con MercadoPago, no solo integración
    técnica.
  - **Países soportados**: Argentina, Brasil, Chile, Colombia, México, Perú,
    Uruguay — no cubre España/Europa (irrelevante para el trabajo de
    Redsys/GDPR, que es un frente aparte).
  - No quedó claro en la documentación pública si la cuenta de **TourFlow
    como marketplace** necesita aprobación previa de MercadoPago (solicitud
    formal) antes de poder usar el modelo 1:1 — hay que confirmarlo
    directamente con soporte/ventas de MP antes de comprometerse a un
    cronograma. Esto puede ser una dependencia externa fuera de control del
    desarrollo.

## 2. Encaje real con lo que ya existe en TourFlow

### Marketplace de proveedores externos (`amir_providers`) — encaje fuerte

Hoy (`CONTRIBUTING.md § 11`): un tour con `provider_id` cobra el 100% a la
cuenta del operador vía `MercadoPagoGateway`/`StripeGateway`, la reserva
queda `pending_provider_approval` hasta que el proveedor aprueba/rechaza por
link de email, y la liquidación es un **ledger manual** (
`amir_provider_payouts`: `status` `pending`/`paid`, sin ninguna integración
de pago real — el operador transfiere aparte y marca "pagado" a mano).

El proveedor **es el vendedor real** del tour — es exactamente el rol que el
modelo 1:1 de MP espera del "vendedor secundario". Split payment reemplazaría
el ledger manual por una transferencia automática en el momento del pago:
el proveedor cobra directo, TourFlow se queda con la comisión configurada.
Reduce fricción operativa Y riesgo de compliance (el operador deja de
"poseer" dinero ajeno en tránsito).

### Partners/RRPP (`amir_partners`) — encaje más débil, hay que ser honesto

`amir_partners` ya tiene `commission_type`/`commission_value` (porcentaje o
monto fijo) — la comisión **ya se calcula** en algún punto del código, pero
no hay ningún ledger de liquidación (`amir_partner_payouts` no existe, a
diferencia de proveedores) ni mecanismo de pago: se liquida 100% fuera del
sistema hoy.

Acá el split 1:1 **no encaja tan directo** como con proveedores, y es
importante no prometerlo como si fuera lo mismo: el partner/RRPP **no es
dueño del tour** — el operador sigue siendo el vendedor real. El modelo 1:1
de MP paga primero al vendedor (con `marketplace_fee` yendo al marketplace),
no tiene un tercer beneficiario dentro del mismo split. Para pagarle una
comisión a un partner automáticamente, lo que hace falta no es un split del
checkout sino un **payout posterior** (transferencia programática del
operador al partner después de confirmada la venta) — una pieza técnica
distinta, que probablemente use la API de dinero/transferencias de MP (no
investigada en esta ronda) en vez del modelo de split.

**Conclusión de esta sección**: son dos problemas relacionados pero no
idénticos. Proveedores → split 1:1 encaja bien. Partners → conviene evaluar
por separado si el camino es un payout automático o simplemente completar
el ledger manual que ya falta (`amir_partner_payouts`, mismo patrón que
`amir_provider_payouts`, sin ninguna integración de pago — más barato, más
rápido, resuelve "no se está registrando la deuda con el partner" sin
depender de que MercadoPago apruebe nada).

## 3. Arquitectura si se construye — plugin satélite

Mismo patrón que Redsys (`redsys-for-tourflow/`) y coherente con la pausa de
features (`GUIA-PLUGINS-SATELITE-TOURFLOW.md`): no tocar el núcleo.

- Los filtros de extensión ya existen desde v5.6.19: `amir_payment_gateway_resolve`
  (`PaymentGatewayFactory::make()`) y `amir_payment_gateway_options`
  (dropdown de Configuración) — pero un split payment **no es una pasarela
  nueva**, es una variante de CÓMO se ejecuta un cobro ya existente con
  MercadoPago (usando credenciales de un tercero + fee). La interfaz actual
  `PaymentGatewayInterface` asume que hay una única cuenta configurada por
  instalación — habría que revisar si alcanza con que el satélite decida en
  runtime qué credenciales usar (las del proveedor si el tour las tiene
  conectadas, las del operador si no) o si hace falta un método nuevo en la
  interfaz. Esto es lo primero a resolver en diseño, antes de tocar código.
- Piezas nuevas necesarias: (a) flujo de conexión OAuth por proveedor
  (pantalla nueva, guardar `access_token`/`refresh_token`/`collector_id` —
  probablemente una tabla nueva o columnas nuevas en `amir_providers`,
  nunca en texto plano sin cifrar), (b) lógica de refresh de token OAuth
  (expiran), (c) manejo de reembolsos/contracargos considerando que el
  dinero fue directo al proveedor — de quién sale el reembolso y cómo se
  autoriza, (d) UI para que el operador configure el % de comisión por
  proveedor (probablemente ya lo tiene definido en algún lado — confirmar).

## 4. Por qué esto no rompe la pausa de features como una excepción grande

El riesgo real que motivó tratar esto con cautela (§ 4 original) sigue
vigente para la FASE 2 (el split de MP en sí): toca dinero de terceros y
depende de una aprobación externa fuera de control del desarrollo. Pero la
decisión del cliente de que sea **opcional, por proveedor** (§ arriba) baja
el riesgo de forma real: no hay fecha límite ni proveedor obligado a migrar,
así que construir la arquitectura no compromete nada que ya funciona — el
flujo manual sigue intacto como fallback permanente, no como parche
temporal. Eso, sumado a que la fase 1 (ledger de partners) no toca pagos en
absoluto, es lo que justifica avanzar en fases en vez de esperar una
confirmación explícita para cada pieza.

- Toca el circuito de pago real de terceros — un error acá significa plata
  mal dirigida, no solo un bug visual. Por eso el satélite, no el núcleo, y
  por eso conectar la cuenta es una acción explícita del proveedor (vía
  OAuth), nunca activada por default.
- Depende de una aprobación externa de MercadoPago fuera de control del
  desarrollo (tiempo indefinido) — ver § 5, es lo único que sigue bloqueando
  la fase 2 en la práctica.
- Requiere que cada proveedor tenga o abra una cuenta de MP con KYC nivel 6
  — ya no es un bloqueo: el proveedor que no la tenga simplemente no conecta
  nada y sigue como hoy.

## 5. Lo que sigue abierto (no depende de decisión del cliente, depende de MercadoPago o de construir)

1. **Aprobación de TourFlow como marketplace ante MercadoPago** — no
   confirmado si es self-service o requiere gestión comercial. Recomendado
   iniciar esta conversación con MP en paralelo al desarrollo de la fase 1,
   ya que puede ser el cuello de botella real de tiempo antes de poder
   probar la fase 2 en vivo con cualquier proveedor real.
2. **Parámetro exacto de la API** (`marketplace_fee` vs `application_fee`,
   objeto donde va — preference/payment/order) — confirmar contra la
   referencia de API real de MercadoPago (no la documentación conceptual
   fetcheada en esta ronda) antes de escribir el primer `POST` de la fase 2.
3. **Partners/RRPP — payout automático** (fase 3): sigue siendo la pieza más
   incierta, evaluar recién después de tener el split de proveedores
   funcionando en vivo con al menos un caso real.

**Fase 1 (ledger de partners) — diseño concreto en § 6, lista para
construir ahora.** Fases 2 y 3 quedan documentadas acá como la puerta de
entrada, mismo criterio que `PROMPT-REDSYS-EUROPA.md`/`PROMPT-ZAPIER-TOURFLOW.md`
— se retoman cuando haya claridad sobre el punto 1.

**Actualización 2026-09-10 — fase 1 ✅ hecha (v5.10.6), fase 2 con scaffolding real construido.** Ver § 7 para el diseño técnico confirmado contra la documentación real de MP y el detalle de lo que ya existe en `mercadopago-split-tourflow/`.

## 7. Fase 2 — diseño técnico confirmado y scaffolding construido (2026-09-10)

### 7.1 Decisiones cerradas con el cliente antes de codear

- **Reembolsos con split activo**: se intenta automático con el token del
  proveedor; si Mercado Pago lo rechaza (típicamente por saldo insuficiente
  del proveedor — ver § 7.2), la reserva NO finge éxito ni falla en
  silencio — queda logueada con el motivo real en el Log de pagos del
  núcleo, para que el operador coordine el reembolso con el proveedor por
  fuera del sistema.
- **Conexión OAuth del proveedor**: link generado por el operador desde una
  pantalla propia del satélite (TourFlow no tiene portal de autogestión
  para proveedores, mismo criterio que `ProvidersPage` del núcleo) y
  mandado por WhatsApp/email — el proveedor lo abre, entra a SU sesión de
  Mercado Pago, autoriza, y vuelve. Sin login a ningún sistema de TourFlow.

### 7.2 Lo que confirma la documentación real de MP (fetcheada 2026-09-10, no la conceptual de la ronda anterior)

- **Checkout Pro (lo que ya usa `MercadoPagoGateway` del núcleo)**: el
  campo es `marketplace_fee`, va en el body de `POST /checkout/preferences`
  junto a `items` — **no** `application_fee` (ese es el de la API de Pagos
  directa, que TourFlow no usa).
- **Autenticación de la creación del cobro**: el header `Authorization` va
  con el `access_token` del **vendedor** (el proveedor, obtenido por
  OAuth) — nunca el del operador/marketplace. Fuente:
  mercadopago.com.br/developers "Integrate checkout in Split Payments 1:1
  (marketplace)".
- **OAuth**: flujo `authorization_code` estándar —
  `https://auth.mercadopago.com/authorization?client_id=...&response_type=code&platform_id=mp&state=...&redirect_uri=...`,
  intercambio en `POST https://api.mercadopago.com/oauth/token`. El token
  devuelto (`access_token`/`refresh_token`/`user_id`) dura **180 días** —
  confirma que hace falta refresh real, no es un detalle menor.
- **Reembolsos — el hallazgo más importante para el diseño**: "en el
  modelo 1:1, el Marketplace no puede emitir un reembolso completo si el
  vendedor no tiene fondos suficientes en su cuenta" (documentación oficial
  de MP, sección de reembolsos de Split Payments). Esto es una limitación
  real del modelo, no un bug a evitar — el diseño tiene que asumir que un
  reembolso puede fallar por esto y decírselo al operador con claridad (ver
  decisión § 7.1).

### 7.3 Lo que sigue sin confirmar — genuinamente, ni con esta ronda de investigación

- **Con el token de quién se puede leer `GET /v1/payments/{id}` de un pago
  que se acreditó en la cuenta del proveedor** (para el webhook y para
  `fetch_payment_status`) — la documentación pública fetcheada no lo aclaró
  ni para el caso de éxito ni el de fallo. El gateway construido
  (`Gateway::fetch_payment_with_best_token()`) intenta primero con el
  token de la cuenta del operador (asumiendo que la app "colaboradora"
  puede leerlo, patrón reportado en integraciones públicas de terceros) y
  si no devuelve nada, prueba con el token de cada proveedor conectado uno
  por uno hasta encontrar el dueño — funciona pero no está verificado
  contra un pago real todavía. **Primer punto a confirmar en cuanto haya
  un proveedor de prueba conectado.**
- **Punto 1 de § 5 (aprobación de TourFlow como marketplace ante MP)**
  sigue sin confirmarse — sigue siendo el bloqueo real para poder probar
  cualquiera de esto en vivo, incluso con la app/OAuth ya funcionando.

### 7.4 Arquitectura construida — `mercadopago-split-tourflow/`

Mismo patrón exacto que `redsys-for-tourflow/` (ver `GUIA-PLUGINS-SATELITE-TOURFLOW.md`), namespace `MercadoPagoSplitForTourFlow\`, prefijo `mpstf_`. `php -l` limpio en los 8 archivos, sin probar contra WordPress real todavía (no hay credenciales de test reales para split — ver § 7.3).

- **Tablas propias** (`class-mpstf-installer.php`): `mpstf_provider_accounts`
  (una fila por proveedor que intentó conectar — `status`
  `pending`/`connected`/`disconnected`, `access_token`/`refresh_token`/
  `expires_at`) y `mpstf_split_payments` (mapea cada reserva cobrada CON
  split a su proveedor + `preference_id`/`payment_id` de MP — existe porque
  `PaymentGatewayInterface::refund()`/`fetch_payment_status()` solo reciben
  strings sueltos, nunca el booking completo, así que sin esta tabla no hay
  forma de saber el token de QUÉ proveedor usar).
- **`Gateway` (`class-mpstf-gateway.php`)** — implementa
  `PaymentGatewayInterface` completa, `id()` = `'mercadopago_split'`.
  Regla central: split solo aplica a una reserva de UN tour de UN proveedor
  conectado — un carrito (`booking_ref` empieza con `CART-`, hallazgo real
  del código: `CartController` arma un `BookingResult` sintético que puede
  representar tours de proveedores distintos bajo un solo cobro, y el
  modelo 1:N de MP no es self-service) o cualquier otro caso cae DIRECTO a
  `new \AmirBooking\Payments\MercadoPagoGateway()` (el gateway del núcleo)
  como fallback — nunca se bloquea un pago por esto, exactamente el
  comportamiento de hoy si este satélite no estuviera instalado.
- **`OAuth`/`ProviderAccounts`** — flujo de conexión y CRUD de las tablas
  propias, con refresh automático de token cuando vence (`Gateway::valid_access_token()`).
- **`ProvidersPage`/`Settings`** — pantallas propias de admin (menú "MP
  Split"): configuración de la app (client_id/secret/webhook secret/%
  comisión) y, por proveedor, botón "Generar link de conexión" +
  "Desconectar". Lee `amir_providers` del núcleo por SQL directo (sin punto
  de extensión más limpio disponible, permitido por § 6 de la guía) —
  nunca escribe ahí.
- **`Rest`** — dos rutas propias (`/mpstf/v1/oauth-callback`,
  `/mpstf/v1/webhook`) — deliberadamente NO se reusa el webhook de MP del
  núcleo: `BookingController::mercadopago_webhook()` instancia
  `MercadoPagoGateway` del núcleo directo, sin pasar por
  `PaymentGatewayFactory`, así que nunca resolvería a este gateway.

### 7.5 Qué falta para poder probar esto en vivo (no es código)

1. Confirmar con MercadoPago si TourFlow necesita aprobación comercial
   como marketplace, y conseguirla — bloqueo externo, ver § 5.1.
2. Crear la app de MP (Tus integraciones → crear aplicación) para conseguir
   `client_id`/`client_secret` reales y cargar el `redirect_uri` exacto
   (`Settings::redirect_uri()`) en su configuración.
3. Un proveedor de prueba con cuenta de MP propia para conectar de punta a
   punta y resolver § 7.3 contra un pago real.

## 6. Fase 1 — `amir_partner_payouts`, diseño

Mismo patrón exacto que `amir_provider_payouts` (`Installer::create_tables()`
línea ~527), sin ninguna integración de pago:

```sql
CREATE TABLE {$wpdb->prefix}amir_partner_payouts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id   INT UNSIGNED NOT NULL,
    booking_id   INT UNSIGNED DEFAULT NULL,
    amount_mxn   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status       ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    note         TEXT,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at      DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY partner_id (partner_id),
    KEY booking_id (booking_id),
    KEY status (status)
) $charset;
```

- **Cuándo se crea la fila**: al confirmarse una reserva con `partner_id`
  seteado (`amir_bookings.partner_id`, ya existe) — calcular el monto con
  `commission_type`/`commission_value` de `amir_partners` (ya existen,
  nunca usados para nada más que mostrarse en pantalla) sobre el
  `total_mxn` de la reserva. Enganchar en el mismo punto donde
  `BookingManager::confirm()` ya disparauna reserva a `confirmed` — un
  `do_action` nuevo o reuso de uno existente, a definir mirando el código
  real antes de tocarlo.
- **Pantalla admin**: nueva pestaña o sección en **TourFlow → 🤝 Partners**
  (ya existe la pantalla, agrega el ledger igual que Proveedores lo tiene
  en su propia pantalla **💸 Liquidación**) — listado filtrable por
  partner/estado, botón "Marcar pagado" (mismo patrón que
  `amir_provider_payouts`).
- **Fuera de alcance de esta fase**: cualquier pago automático — es
  puramente contable, igual que el de proveedores hoy.
- **Riesgo**: ninguno nuevo — mismo patrón ya probado y en producción con
  proveedores, solo aplicado a la tabla paralela que faltaba.
