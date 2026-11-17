# Alcance de la auditoría de seguridad (hardening SOC2/ISO27001)

Fecha: 2026-11-05. Ficha de alcance confirmada con el responsable del proyecto antes de empezar.

```
APP Y REPOSITORIOS:
  - TourFlow (plugin WordPress, nombre interno "Amir Booking")
    github.com/johelp/tours-booking, rama fase1/seguridad-base-codigo2
    (main ya actualizado al mismo commit).
  - Incluye el núcleo (amir-booking/) + 5 plugins satélite en el mismo repo:
    redsys-for-tourflow, mercadopago-split-tourflow, tourflow-cleaner,
    tourflow-bot-connector, tourflow-automations.
  - Sitio en vivo: book.caliafarm.com (TourFlow Pro Max instalado, producción real).

OBJETIVO:
  Auditar y corregir de forma pragmática — los controles SOC2/ISO27001/ASVS se
  usan como checklist de calidad, no se arma el paquete formal de evidencia
  para una certificación real (no hay intención de certificarse ahora).

ENTORNOS Y ACCESO:
  - Código: lectura/escritura completa, local, rama de trabajo.
  - book.caliafarm.com: acceso de administrador real vía sesión de navegador
    del responsable del proyecto — producción real. Sin pentest activo
    (sin WPScan activo, sin ZAP) contra este sitio sin aprobación aparte.
  - book.amiradventours.com: FUERA de alcance este ciclo.

STACK:
  PHP ≥8.1 (WordPress, CPTs, REST API propia, $wpdb), MySQL/MariaDB,
  React 18 + Vite (react-src/, el widget de reservas), TCPDF, endroid/qr-code,
  Stripe JS SDK. Pasarelas de pago: Stripe, MercadoPago, Redsys.

HOSTING:
  book.caliafarm.com en hosting tipo cPanel/compartido (se detectaron
  Backuply, Loginizer, GoSMTP instalados) — sin confirmar si comparte
  infraestructura física con sitios de otros clientes ajenos a este trabajo.

DATOS Y CLASIFICACIÓN:
  Personales de clientes finales (nombre, email, teléfono), datos de
  reservas. Sin datos de tarjeta almacenados (tokenizado por la pasarela —
  a verificar en código). Sin datos de salud. Sin categoría especial de
  menores (aunque pueden viajar en una reserva, no se tratan datos propios
  de un menor).

JURISDICCIONES Y REGIÓN:
  México (MXN, Amir Adventours), España/UE (Redsys, GDPR — Caliafarm),
  Argentina (ARS).

ARQUITECTURA:
  No es un SaaS multi-tenant de una sola base compartida entre clientes
  finales — es un plugin que se instala por separado en el WordPress de
  cada cliente real (Caliafarm, Amir Adventours, Visit Sicily Experiences),
  más los 5 plugins satélite. Encaja con el módulo "Agencias y hosting
  compartido" del skill, aunque el responsable es el desarrollador, no una
  agencia con staff propio.

IA:
  - tourflow-bot-connector: API de solo lectura para un chatbot externo
    (autenticación por API key fija por sitio).
  - El desarrollo del propio plugin se hace con agentes de código
    (Claude Code) — aplica el dominio 16 (agentes de desarrollo).

MARCOS:
  SOC2 Security/Availability/Confidentiality (orientativo, sin período de
  observación real) · ISO 27001:2022 Anexo A (orientativo) · ASVS L2.

MÓDULOS QUE APLICAN:
  WordPress (criterios, sin WooCommerce) · PHP y MySQL · Node.js (solo build
  del widget, no runtime de servidor) · Plataformas de hosting compartido ·
  Agencias y hosting compartido · IA/agentes/MCP · Datos sensibles y
  privacidad (GDPR vía Redsys/España).

RESPONSABLE Y APROBADOR:
  Johel Pastorino — única persona, sin cadena de aprobación formal.

AUTORIZADO:
  Sí — repo propio, y sitio de un cliente real sobre el que el responsable
  ya tiene acceso administrativo autorizado y pidió explícitamente
  correcciones en esta misma sesión (ver historial de la conversación).

REQUIERE APROBACIÓN:
  - Cualquier cambio en book.caliafarm.com (sitio en vivo).
  - Cualquier cambio de código de riesgo medio/alto (rompe compatibilidad,
    cambios de esquema de base de datos, afecta flujos de pago en
    producción).
  - Bajo riesgo en el repo (headers, validaciones, secretos, hardening de
    configuración, higiene de .gitignore, CI) se corrige directo, con un
    commit/PR por hallazgo.

SUPUESTOS:
  - book.amiradventours.com queda fuera de este ciclo (otra sesión).
  - No se ejecutan escaneos activos contra book.caliafarm.com — solo
    inspección de configuración vía el acceso admin ya autorizado
    (versión de WP/PHP visible, cabeceras HTTP, wp-config, plugins de
    terceros instalados).
  - Ya hubo una ronda de auditoría de seguridad previa real en este repo
    (v5.13.3, documentada en CONTRIBUTING.md § 16.101) que corrigió
    hallazgos de pagos/autenticación/lógica de negocio — esta auditoría la
    retoma como base y NO repite esos hallazgos ya cerrados, pero cubre
    todo el código agregado después (proveedores externos, habitaciones,
    headless, depósitos, panel de gestión, satélites).
```
