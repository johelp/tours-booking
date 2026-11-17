# Resumen ejecutivo — auditoría de hardening (2026-11-05)

**Objetivo**: auditar y corregir de forma pragmática (checklist SOC2/ISO27001/ASVS como vara de calidad — ver `alcance.md`). Esto **no** es una certificación ni un informe de una firma auditora: es una revisión técnica con hallazgos y correcciones verificadas.

## Qué se revisó

- Código de TourFlow (núcleo + 5 plugins satélite): autenticación, autorización, inyección, secretos, dependencias.
- Configuración real de `book.caliafarm.com` (sitio en vivo, solo lectura — sin escaneos activos).

## Buenas noticias

El código del plugin está, en general, bien construido en los puntos más sensibles:
- Los 3 webhooks de pago (Stripe, Redsys, MercadoPago Split) verifican firma con `hash_equals()` — nada de comparaciones vulnerables a timing attacks.
- Los tokens de acceso público (reserva del cliente, respuesta del proveedor) usan 256 bits de aleatoriedad real (`random_bytes`).
- CORS con lista explícita de orígenes, sin comodín.
- Sin inyección SQL detectada en la revisión (todas las consultas con `prepare()`, incluso las que tienen `ORDER BY` dinámico).
- Sin un solo secreto hardcodeado en el código actual ni en todo el historial de git.
- Las 80 cuentas de usuario del sitio en vivo (3 admin + 77 "Customer") son legítimas — confirmado por el cliente, son clientes reales migrados del sitio anterior con WooCommerce. Se descarta la sospecha inicial de compromiso.
- `composer audit`: cero vulnerabilidades conocidas en las dependencias de PHP.

## Lo que se corrigió ya (en este repo, bajo riesgo)

| # | Qué | Dónde |
|---|-----|-------|
| SEC-006 | El pipeline de CI no corría ningún análisis de dependencias — ahora `composer audit` y `npm audit` (umbral `critical`) bloquean el build si aparece algo nuevo | `.github/workflows/ci.yml` |
| SEC-006 | 6 de 8 vulnerabilidades de dependencias de build del widget (`react-src/`) corregidas sin romper nada — el bundle final (`booking-widget.js`/`.css`) sale byte-idéntico | `react-src/package-lock.json` |
| SEC-007 | `.gitignore` ignoraba solo 2 subrutas puntuales de `.claude/` (ya hubo un archivo sensible commiteado por este motivo en el pasado) — ahora ignora la carpeta completa | `.gitignore` |

161 tests en verde, `php -l` limpio, sin cambios funcionales para el cliente final.

## Lo que queda pendiente de una decisión tuya (no lo toqué — afecta producción)

| # | Hallazgo | Prioridad | Por qué no lo corregí ya |
|---|----------|-----------|---------------------------|
| SEC-001 | Las 3 cuentas admin de `book.caliafarm.com` no tienen MFA (Loginizer PRO ya con licencia activa, 2FA sin configurar) | **P1** | Cada admin tiene que configurar su propia app de autenticación — no lo puedo hacer por vos |
| SEC-001B | PHP 8.1.34 en producción, versión sin parches de seguridad oficiales según la referencia que usé | **P1** | Cambio de versión de runtime en un sitio en vivo real — requiere coordinar con el hosting y probar el checkout completo antes |
| SEC-002 | `DISALLOW_FILE_EDIT` no está activo — cualquier admin puede editar PHP de temas/plugins desde el navegador | **P1** | Es un cambio en `wp-config.php` del sitio en vivo — técnicamente trivial (una línea), pero está fuera de lo que puedo tocar sin tu aprobación por el alcance que acordamos |
| SEC-003 | WooCommerce + WooCommerce PayPal Payments instalados sin usarse (quedaron de la migración) | P2 | Sitio en vivo — hay que confirmar que no se necesite ningún dato histórico antes de desinstalar |
| SEC-004 | TourFlow Cleaner (borra datos reales) sigue instalado, solo desactivado | P2 | Sitio en vivo |
| SEC-005 | Último backup: 131 días | P2 | Config de Backuply en el sitio en vivo, no del código |
| — | Vite/esbuild: la corrección real requiere Vite 8, que cambia el bundle del widget (34 vs 56 módulos transformados al probarlo) — lo probé y lo revertí, no es seguro aplicarlo a ciegas | P3, diferido | Necesita una sesión con prueba visual/funcional completa del widget de reserva antes de aceptar el cambio |
| SEC-008 | No re-verifiqué el candado de descarga de producto digital (se había corregido en v5.0.6) | NO VERIFICADO | Falta de tiempo en esta pasada, no un hallazgo nuevo |

**El más urgente de los tres P1 (SEC-001, MFA) es el único que depende 100% de vos** — es la corrección de mayor impacto por menor esfuerzo real de todo este listado: Loginizer PRO ya está pagado y activo, solo falta prenderlo.

## Qué no resuelve esto (igual que dice el skill)

No hay informe SOC2 ni certificación ISO 27001 — eso lo emite una firma CPA / un organismo acreditado, con políticas, período de observación y auditoría externa que van más allá del código. Si en algún momento un cliente de Caliafarm/Amir Adventours pide un cuestionario de seguridad formal, este documento + `03-hallazgos.md` son el punto de partida, no el informe final.

## Próximos pasos sugeridos

1. Decidir sobre los 3 hallazgos P1 — empezar por SEC-001 (MFA), es el más barato de activar.
2. Cuando se decida actuar sobre `book.caliafarm.com`, lo hago con tu aprobación explícita por cada cambio (así quedamos en el alcance).
3. `book.amiradventours.com` queda para una sesión aparte — no se tocó ni se revisó nada ahí.
4. Repetir esta auditoría después de cada cambio grande (próxima vez que se toque el circuito de pagos o se agregue otro satélite).

Evidencia completa: `security/alcance.md`, `security/03-hallazgos.md`.
