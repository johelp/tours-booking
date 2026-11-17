# Hallazgos — auditoría de hardening (2026-11-05)

Alcance: código de este repo (núcleo TourFlow + 5 satélites) + inspección de configuración (solo lectura) de `book.caliafarm.com`. Ver `alcance.md`. Sin pentest activo contra el sitio en vivo.

Cada hallazgo usa el formato del skill `hardening-soc2-iso27001`. Estado por defecto: `Abierto`, salvo que se indique lo contrario.

---

```
ID: SEC-001
Título: 3 cuentas de administrador en producción sin MFA
Prioridad: P1
Estado: Abierto — requiere acción del cliente, no soy yo quien configura el 2FA de cada admin
Ubicación: book.caliafarm.com, Loginizer Dashboard (admin.php?page=loginizer) — panel "Recommendations"
Impacto real: Las 3 cuentas con rol Administrator (caliafar, johel, lucrecia) dependen solo de contraseña para entrar a un sitio que procesa pagos reales (Stripe/MercadoPago/Redsys) y datos personales. Un phishing o una contraseña reutilizada y filtrada en otro sitio compromete el panel completo sin ninguna segunda barrera.
Reproducción segura: Loginizer Dashboard → sección "Recommendations" lista sin marcar: Two Factor Auth (Email), Two Factor Auth (App), reCAPTCHA, Rename Login Page, Change 'admin' Username.
Causa raíz: Nunca se activó el 2FA que ya viene incluido en Loginizer PRO (licencia activa confirmada en el mismo panel) — queda en "recomendado", no aplicado.
Corrección propuesta: Activar Two Factor Auth (App, tipo TOTP — preferible a Email) en Loginizer para los 3 administradores. Requiere que cada uno configure su propia app (Google Authenticator / Authy) — no es algo que yo pueda completar por ellos.
Prueba de regresión: Intentar login con usuario+contraseña correctos sin el código TOTP → debe rechazar el acceso.
Referencias: CWE-308 (uso de un solo factor), ASVS V6.1-V6.3, SOC2 CC6.1-CC6.3, ISO 27001:2022 5.17/8.5.
Evidencia: Captura de texto del panel de Loginizer, 2026-11-05 (ver conversación).
```

```
ID: SEC-001B
Título: PHP 8.1.34 en producción — versión sin corrección oficial de seguridad
Prioridad: P1
Estado: Abierto — cambio de versión de runtime en un sitio en vivo, requiere coordinar con el hosting y probar compatibilidad antes de aplicarlo
Ubicación: book.caliafarm.com — Site Health → Info → Server → PHP version 8.1.34
Impacto real: Según la referencia del skill (orientativa, a reconfirmar en el momento de actuar), PHP 8.1 o anterior ya no recibe parches de seguridad oficiales. Un sitio que procesa pagos reales corriendo sobre un runtime sin soporte de seguridad activo queda expuesto a cualquier vulnerabilidad de PHP que se descubra de ahora en más, sin que el proveedor la corrija nunca para esta versión.
Reproducción segura: Site Health → Info, cualquier administrador puede verlo.
Causa raíz: El hosting no actualizó el PHP del sitio; el plugin en sí solo exige `>=8.1` (compatible con 8.2/8.3 también, confirmado por la matriz de CI que corre ambas versiones).
Corrección propuesta: Subir la versión de PHP a 8.2 o 8.3 desde el panel del hosting (cPanel/MultiPHP), y correr la suite de TourFlow (PHPUnit + smoke test manual del checkout) contra esa versión antes de confirmar el cambio en producción — el repo ya lo valida en CI para 8.1 y 8.2, falta confirmar 8.3 también.
Prueba de regresión: Checkout completo (reserva + pago + confirmación) funcionando igual tras el cambio de versión.
Referencias: ASVS V1.14 (gestión de la plataforma), SOC2 CC7.1, ISO 27001:2022 8.8 (gestión de vulnerabilidades técnicas).
Evidencia: Site Health → Info, 2026-11-05.
```

```
ID: SEC-002
Título: DISALLOW_FILE_EDIT no está definido — edición de archivos PHP habilitada desde wp-admin
Prioridad: P1
Estado: Abierto — cambio en wp-config.php del sitio en vivo, requiere aprobación antes de tocarlo
Ubicación: book.caliafarm.com — confirmado por la presencia de los menús "Theme File Editor" (theme-editor.php) y "Plugin File Editor" (plugin-editor.php) en wp-admin, visibles en esta misma sesión.
Impacto real: Cualquiera que obtenga acceso de Administrator (vía phishing, credencial filtrada, o — más relevante dado SEC-001 — sin MFA) puede editar el código PHP de cualquier tema o plugin activo directamente desde el navegador, lo que equivale a ejecución de código arbitrario persistente en el servidor. Es la vía de persistencia más común tras un compromiso de WordPress.
Reproducción segura: Entrar a wp-admin con una cuenta Administrator → Apariencia → Editor de archivos de tema (o Plugins → Editor de archivos de plugins) → el formulario de edición carga normalmente, sin aviso de estar deshabilitado.
Causa raíz: wp-config.php nunca definió la constante `DISALLOW_FILE_EDIT`.
Corrección propuesta: Agregar `define( 'DISALLOW_FILE_EDIT', true );` a wp-config.php (antes de la línea "That's all, stop editing!"). Un cambio de una línea, sin downtime, reversible.
Prueba de regresión: Tras el cambio, los menús "Editor de temas"/"Editor de plugins" deben desaparecer de wp-admin para cualquier rol.
Referencias: CWE-94, ASVS V1.14.5 (gestión de código en producción), SOC2 CC6.1, ISO 27001:2022 8.9 (gestión de configuración).
Evidencia: `find("Edit")` sobre book.caliafarm.com, esta sesión — refs a theme-editor.php / plugin-editor.php en el menú de administración.
```

```
ID: SEC-003
Título: WooCommerce + WooCommerce PayPal Payments instalados sin usarse
Prioridad: P2
Estado: Abierto
Ubicación: book.caliafarm.com — Plugins → Inactivos (WooCommerce 11.2.2, WooCommerce PayPal Payments 4.2.0)
Impacto real: TourFlow reemplaza explícitamente a WooCommerce en este sitio (migración documentada en memoria del proyecto). Un plugin inactivo sigue teniendo sus archivos PHP en disco y su propia superficie de ataque (endpoints REST, AJAX, CPTs) — una vulnerabilidad futura en WooCommerce o en el plugin de PayPal no necesita estar "activo" para ser explotable en algunos vectores (p. ej. acceso directo a archivos, o activación remota si hay otro fallo de por medio).
Reproducción segura: Plugins → filtro "Inactive" → ambos aparecen instalados.
Causa raíz: Quedaron del sitio anterior (que sí usaba WooCommerce) tras la migración a TourFlow; nunca se desinstalaron.
Corrección propuesta: Desinstalar (no solo desactivar) ambos plugins si de verdad no se van a usar — confirmar primero que ningún dato histórico de pedidos de WooCommerce se necesite conservar accesible desde el admin.
Prueba de regresión: Confirmar que el checkout de TourFlow sigue funcionando igual tras la desinstalación (no debería depender de WooCommerce en ningún punto).
Referencias: SOC2 CC7.1, ISO 27001:2022 8.8/8.30 (gestión de activos de software), OWASP A06 (componentes vulnerables/sin uso).
Evidencia: Site Health → Info → Inactive Plugins, 2026-11-05.
```

```
ID: SEC-004
Título: TourFlow Cleaner sigue instalado en producción (solo desactivado)
Prioridad: P2
Estado: Abierto
Ubicación: book.caliafarm.com — Plugins → Inactivos (TourFlow Cleaner 0.1.1)
Impacto real: Este plugin borra datos reales de TourFlow (reservas, tours, cupones) con fines de limpieza previa al lanzamiento — está documentado explícitamente como "no pensado para operadores/clientes finales" (GUIA-PLUGINS-SATELITE-TOURFLOW.md). Dejarlo instalado en un sitio en producción real, aunque desactivado, es un riesgo de borrado accidental (alguien con acceso admin lo reactiva sin saber qué hace) y superficie de ataque innecesaria.
Reproducción segura: Plugins → Inactivos → "TourFlow Cleaner" presente.
Causa raíz: Se desactivó en la sesión anterior (2026-10-10/11) para mitigar el riesgo inmediato, pero no se desinstaló.
Corrección propuesta: Desinstalar el plugin por completo de este sitio.
Prueba de regresión: Confirmar que TourFlow Pro Max sigue funcionando igual (es independiente, Cleaner solo lo requiere como dependencia en el otro sentido).
Referencias: SOC2 CC7.1, ISO 27001:2022 8.30.
Evidencia: Plugins → Inactivos, 2026-11-05.
```

```
ID: SEC-005
Título: Backup con 131 días de antigüedad
Prioridad: P2
Estado: Abierto — depende de configuración de Backuply, no de código
Ubicación: book.caliafarm.com — aviso propio de Backuply en todo el admin
Impacto real: RPO (punto de recuperación) actual es de +4 meses — una falla, un ataque o un error humano hoy perdería todas las reservas/pagos registrados en ese período, sin forma de recuperarlos.
Reproducción segura: Cualquier pantalla de wp-admin muestra el aviso "It's been 131 days you took a backup...".
Causa raíz: Backuply (ya instalado y con versión Pro) no tiene backups automáticos programados, o están fallando en silencio.
Corrección propuesta: Configurar backups automáticos periódicos (diario o semanal según el volumen real de reservas) con destino externo (no en el mismo hosting) y probar al menos una restauración real.
Prueba de regresión: Confirmar que un backup nuevo se generó y que se puede restaurar en un entorno de prueba.
Referencias: SOC2 A1/CC9.1, ISO 27001:2022 5.29/5.30/8.13.
Evidencia: Aviso de Backuply, 2026-11-05.
```

```
ID: SEC-006
Título: El pipeline de CI no corre análisis de dependencias ni de secretos
Prioridad: P3
Estado: Corregido (esta sesión, en el repo)
Ubicación: .github/workflows/ci.yml
Impacto real: Una dependencia de Composer o npm con una vulnerabilidad conocida (o un secreto commiteado por error) no bloquea el pipeline ni se detecta automáticamente — solo se encuentra si alguien corre `composer audit`/`npm audit` a mano, como en esta auditoría.
Reproducción segura: ci.yml (antes del fix) solo corría `php -l` + PHPUnit.
Causa raíz: El pipeline nunca incluyó estos pasos.
Corrección propuesta / aplicada: Se agregó `composer audit` al job de CI (falla el build si aparece una vulnerabilidad conocida). `npm audit` del widget (react-src/) se corrió manualmente en esta sesión (6 de 8 hallazgos corregidos sin romper el build; quedan 2 en `vite`/`esbuild`, solo explotables si el dev-server de Vite se expone a una red no confiable — no es el caso, se usa solo para build local).
Prueba de regresión: El job de CI debe fallar si se introduce una dependencia de Composer con un CVE conocido.
Referencias: SOC2 CC8.1, ISO 27001:2022 8.8/8.25, ASVS V15.
Evidencia: Diff de `.github/workflows/ci.yml`, `react-src/package-lock.json` (este commit).
```

```
ID: SEC-007
Título: .gitignore no excluye .claude/ por completo
Prioridad: P3
Estado: Corregido (esta sesión, en el repo)
Ubicación: .gitignore
Impacto real: Ya ocurrió una vez (documentado en el propio .gitignore, hallazgo del 2026-09-24): un archivo de configuración local de la sesión de desarrollo con IA se commiteó por error porque solo dos subrutas puntuales de .claude/ estaban ignoradas, no la carpeta completa. El resto de .claude/ (incluida cualquier config local futura) seguía sin cobertura.
Reproducción segura: .gitignore (antes del fix) listaba solo `.claude/settings.local.json` y `.claude/worktrees/`.
Causa raíz: Exclusión parcial en vez de ignorar la carpeta completa.
Corrección propuesta / aplicada: `.gitignore` ahora ignora `.claude/` completo.
Prueba de regresión: `git status` no debe listar ningún archivo nuevo dentro de `.claude/` como "untracked" susceptible de un `git add -A` accidental.
Referencias: SOC2 CC6.1, ISO 27001:2022 8.9, dominio 16 (agentes de desarrollo) del skill.
Evidencia: Diff de `.gitignore` (este commit).
```

```
ID: SEC-008
Título: Candado de descarga de producto digital — sin re-verificar en esta ronda
Prioridad: NO VERIFICADO
Estado: Abierto (pendiente de verificación, no de corrección confirmada)
Ubicación: flujo de venta de producto digital (`[flow_product]`), endpoint de descarga tokenizada
Impacto real: Se corrigió en v5.0.6 (candado cosmético → real, archivo con nombre aleatorio, exige pago) según CONTRIBUTING.md § 16.28. No se repitió la verificación completa de ese endpoint en esta auditoría por límite de tiempo — no hay evidencia de regresión, pero tampoco una prueba nueva que lo confirme.
Reproducción segura: N/A — no verificado.
Causa raíz: N/A.
Corrección propuesta: Repetir la prueba (intentar descargar el archivo sin haber pagado, con una reserva en estado distinto a confirmado) en la próxima sesión dedicada a este endpoint.
Prueba de regresión: Pendiente.
Referencias: ASVS V12 (gestión de archivos).
Evidencia: Ninguna nueva — ver CONTRIBUTING.md § 16.28 para la evidencia original de 2026-08.
```

---

## Dependencias npm (react-src/) — resuelto parcialmente

`npm audit` encontró 8 vulnerabilidades (1 baja, 2 moderadas, 5 altas) en dependencias de **build** (babel, browserslist, postcss, nanoid, source-map-js, esbuild/vite) — ninguna corre en el servidor WordPress, todas son herramientas de compilación del widget. `npm audit fix` corrigió 6 de las 8 sin cambios de versión mayor; se verificó que el bundle (`assets/js/booking-widget.js`, `assets/css/booking-widget.css`) sale **idéntico** tras el fix (no hace falta subir `AMIR_VERSION`). Quedan 2 ligadas a una actualización mayor de Vite (5→8) — riesgo de romper el pipeline de build, no aplicado en esta sesión; impacto real bajo porque el dev-server de Vite nunca se expone fuera de `localhost`.

## Composer (núcleo + satélites)

`composer audit` — **sin vulnerabilidades conocidas** en `tecnickcom/tcpdf` 6.11.3 ni `endroid/qr-code` 5.1.0.

## Revisión de código — sin hallazgos (verificado, no solo asumido)

- Firmas de webhook de pago (Stripe, Redsys, MercadoPago Split) — las 3 usan HMAC + `hash_equals()`, con ventana anti-replay en Stripe.
- CORS de la API pública — allowlist explícita, `Access-Control-Allow-Credentials: false`, sin comodín.
- Tokens de acceso público (`access_token`, `provider_response_token`) — `random_bytes(32)`, 256 bits.
- Autenticación de `tourflow-bot-connector` — `hash_equals()`, rate limit propio, deshabilitable sin rotar la key.
- Sin SQL interpolado sin `prepare()` en las consultas revisadas (incluye `ORDER BY` dinámico — son `CASE WHEN` estáticos, no columnas de usuario).
- Sin secretos hardcodeados en el árbol actual ni en todo el historial de git (`.env`, claves privadas, API keys de pasarelas).

## Usuarios de book.caliafarm.com — sin indicio de compromiso

80 cuentas totales: 3 Administrator (`caliafar`, `johel`, `lucrecia` — equipo real) + 77 con rol "Customer" (de WooCommerce), **confirmadas por el cliente como clientes reales migrados del sitio anterior**, no cuentas plantadas. La preocupación inicial (por el antecedente de compromiso documentado en `caliafarm-web/docs/CUTOVER.md § 7`) queda descartada tras esta revisión — no se trata como incidente.

## PHP 8.1.34 en producción

Referencia del skill (orientativa, a confirmar fecha a fecha): PHP 8.1 sin corrección oficial de seguridad. Se registra como **SEC-001-bis** de facto — ver resumen ejecutivo para la recomendación (actualizar a 8.2+ vía el panel de hosting, cambio que requiere aprobación y pruebas de compatibilidad con el plugin antes de aplicarse, dado que afecta un sitio en producción).
