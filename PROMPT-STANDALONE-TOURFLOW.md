# Prompt de arranque — TourFlow standalone (independencia total de WordPress)

Copiar y pegar esto como primer mensaje de la sesión nueva, **en una carpeta/repo separado** de este plugin de WordPress.

---

Vamos a evaluar y, si el diálogo confirma que tiene sentido, empezar a construir una versión de **TourFlow completamente independiente de WordPress** — backend propio (se pensó en Node + Supabase o similar, no es una decisión cerrada) + un frontend 100% personalizable por cliente. El driver de negocio es concreto: escalar a un modelo **SaaS real** (hoy la multi-tenencia depende de WordPress Multisite) y poder ofrecerle a cada cliente un sitio a medida en tecnologías modernas — más rápido que WordPress, con la posibilidad de sumar apps nativas después — en vez de estar atados a un WordPress Multisite por familia de sitios.

**Esto NO es una migración incremental del plugin actual — es un rewrite nuevo**, con la lógica de negocio del plugin de WordPress como el único activo directamente reusable (precios, disponibilidad, reservas, pagos). El panel de administración, el CMS de contenido, la autenticación y la multi-tenencia se reconstruyen desde cero.

## Leer primero, en este orden

El plugin de WordPress (`Tour Plugin/`, repo hermano de este) ya tiene todo el análisis de qué es barato y qué es caro de desacoplar — no arrancar de cero ese análisis:

1. **`CONTRIBUTING.md § 17` completo** (las 4 subsecciones) — el análisis pedido por el cliente: qué ya es barato de desacoplar (§ 17.1: REST API, pagos/cron/email, capa de datos), qué es genuinamente caro (§ 17.2: panel de admin con 18 pantallas, CMS de tours con Media Library, multi-tenencia vía Multisite), la conclusión honesta (§ 17.3: esto es un rewrite, no una migración, con el orden de riesgo sugerido — frontend público primero, pagos/cron/email en paralelo, panel de admin al final) y la señal de intención real (§ 17.4 — este mismo pedido, con los dos requisitos ya fijados: cero "amir" en el código nuevo, y documentación + sugerencias de despliegue completas para la desarrolladora del cliente).
2. **`SPEC-HEADLESS.md`** — spec ya cerrado (aunque sin construir) de desacoplar *solo* el frontend público manteniendo WordPress como backend. Útil como mapa de qué necesita el frontend público (catálogo de tours, checkout, confirmación) independientemente de dónde viva el backend.
3. **`GUIA-INTEGRACION-API.md`** — el contrato REST actual (`amir/v1` + `flow/v1`, este último exclusivo de la edición Pro Max), ya probado en la práctica por una desarrolladora externa integrando un frontend en React/Next.js contra el plugin de WordPress. Es la referencia más concreta de qué payloads/flujos ya funcionan en producción — el backend nuevo probablemente quiere exponer una API equivalente (mismos conceptos: cotizar antes de reservar, reserva en estado pendiente hasta confirmar el pago, `access_token` en vez de login para consultar una reserva, etc.), no necesariamente los mismos nombres de ruta.
4. **`README.md`** (del plugin de WordPress) — arquitectura y modelo de datos completo (tablas, relaciones) como referencia de qué modela hoy el dominio del negocio.

## Los dos requisitos ya fijados (no volver a preguntar)

- **Cero "amir" en ningún lado** — namespace, tablas, variables, nombres de paquete, todo. A diferencia del plugin de WordPress (donde "amir"/`AmirBooking\` se mantiene en el código histórico por compatibilidad hacia atrás), acá es greenfield: no hay nada legacy que preservar, así que el naming limpio se define una vez y se aplica sin excepciones.
- **Documentación completa para que la desarrolladora del cliente pueda trabajar sin bloquearse** — no solo referencia de API (como ya existe hoy en `GUIA-INTEGRACION-API.md` para el plugin de WordPress), sino también **sugerencias concretas de despliegue**: dónde hostear el backend, dónde el frontend, variables de entorno, CI/CD, cómo se maneja cada ambiente (dev/staging/prod). Mismo nivel de detalle que ya recibe hoy con el plugin de WordPress, aplicado a un stack completamente distinto.

## Todo lo demás es diálogo abierto — no asumir, preguntar

A diferencia de otros prompts de arranque de este proyecto (ver `PROMPT_MARKETPLACE.md` como ejemplo, donde el spec ya estaba cerrado), **acá casi nada está decidido todavía**. Antes de escribir una sola línea de código o siquiera un `package.json`, dialogar a fondo:

1. **Stack real**: ¿Node + Supabase sigue en pie tras pensarlo, o vale la pena comparar con alternativas (Next.js full-stack con Postgres directo, Deno + Deno Deploy, Cloudflare Workers + D1/Postgres, otra cosa)? ¿Qué pesa más — velocidad de desarrollo, costo de hosting, o qué tan fácil es para la desarrolladora del cliente sumarse?
2. **Alcance de la primera fase**: ¿arranca por el frontend público (catálogo + checkout, como ya sugiere § 17.3 como el paso de menor riesgo) dejando el panel de admin en WordPress todavía, o se encara todo junto?
3. **Modelo de multi-tenencia real**: ¿una base de datos compartida con `tenant_id` en cada tabla, una base de datos por cliente, o algo intermedio? Esto es la base de todo lo demás — Supabase (si se confirma) tiene su propio patrón recomendado (Row Level Security por `tenant_id`) que vale la pena evaluar en detalle antes de definir el esquema.
4. **Autenticación**: si se usa Supabase, ¿Supabase Auth cubre lo que hace falta (login del operador, roles tipo Tour Manager de hoy), o hace falta algo custom encima?
5. **Qué lógica de dominio se porta tal cual vs. se reconstruye**: precios (`PricingEngine`), disponibilidad (`AvailabilityEngine` + el motor de habitaciones más nuevo con su intervalo semi-abierto de check-in/check-out), política de cancelación, `PaymentGatewayInterface` (el patrón de abstracción de pasarela ya probado con Stripe y Mercado Pago) — son los candidatos más claros a portar la lógica (no el código PHP en sí) al lenguaje nuevo.
6. **CMS de contenido**: hoy WordPress da gratis Media Library, revisiones, editor de posts. ¿Qué lo reemplaza? (Supabase Storage + un editor propio, un headless CMS de terceros, algo a medida.)
7. **Plan de despliegue concreto** — una vez el stack esté decidido: entornos, dominios, cómo se gestionan secretos/variables de entorno, pipeline de CI/CD, y cómo se ve el día 1 de la desarrolladora del cliente clonando el repo.

No avanzar a escribir código hasta que estos puntos tengan respuesta — el objetivo de la primera sesión es cerrar decisiones, no producir un scaffold apurado.
