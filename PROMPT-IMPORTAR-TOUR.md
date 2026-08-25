# Prompt — generar el JSON importable de un tour para TourFlow

Pegá este prompt completo en cualquier asistente de IA (Claude, ChatGPT, etc.), seguido de la info cruda del tour (folleto, texto de una web, notas sueltas, lo que tengas — no hace falta que esté ordenado). El resultado es un JSON listo para cargar en TourFlow.

**Nota**: el import automático ya existe (TourFlow → Configuración → 📥 Importar tours desde JSON, ver `CONTRIBUTING.md § 15.9` y § 16.44 para el detalle de qué se agregó después). Pegá el JSON generado directo ahí.

---

## Prompt

```
Sos un asistente que convierte información suelta de un tour turístico (folleto,
texto de una web, notas de un operador) en un JSON estructurado para importarlo
a TourFlow, un sistema de reservas de tours.

Reglas:
1. Devolvé SOLO el JSON, sin texto antes ni después, sin bloques de código markdown.
2. Completá TODOS los campos del esquema de abajo. Si un dato no está en la info
   que te paso, dejalo como string vacío "" (texto) o array vacío [] (listas) —
   NUNCA inventes datos que no te di (precio, duración, edad mínima, ubicación
   exacta, etc.). Es preferible un campo vacío a un dato incorrecto.
3. Los campos terminados en "_es" y "_en" son el mismo contenido en español e
   inglés. Si la info que te paso viene en un solo idioma, traducí al otro de
   forma natural (no literal palabra por palabra) — mantené el tono turístico/
   comercial en ambos.
4. "highlights", "includes", "excludes" son listas de frases cortas (4-6 palabras
   cada una idealmente), no párrafos. 4 a 6 ítems cada una si hay info suficiente.
5. "itinerary_stops": solo completalo si la info describe una secuencia real de
   paradas/etapas del tour (ej. "salimos del punto X, después vamos a Y, por
   último Z"). Si la descripción es narrativa sin pasos claros, dejá el array
   vacío — no inventes una secuencia que no existe.
6. "detail_facts": 2 a 4 datos rápidos tipo ícono+etiqueta+valor (ej. idioma
   del tour, tamaño de grupo, nivel de dificultad, qué incluye el transporte).
   Elegí un emoji simple y relevante para "icon" en cada uno.
7. "duration_minutes" es un número entero en minutos (ej. "3 horas" → 180).
   Si la duración no es exacta (ej. "medio día"), usá tu mejor estimación
   razonable y no lo dejes vacío salvo que no haya ninguna pista de duración.
8. "min_age", "max_capacity", "min_passengers" son números enteros. Si no hay
   dato, usá: min_age=0, max_capacity=10, min_passengers=1 (valores por
   defecto razonables del sistema, no lo dejes vacío).
9. "meeting_lat"/"meeting_lng" solo si la info da una ubicación identificable
   (nombre de lugar conocido, dirección) — si no estás seguro de las
   coordenadas exactas, dejalos en null en vez de inventar un número.
10. "gallery_images" solo si te paso URLs de fotos reales — si no tengo fotos
    todavía, array vacío, no inventes URLs.
11. "price_model" es "percapita" (precio por persona, lo más común) salvo que
    la info diga explícitamente que el precio es por grupo/vehículo completo
    sin importar cuántas personas — en ese caso "group".
12. "slug" es el nombre del tour en minúsculas, sin acentos, con guiones en vez
    de espacios (ej. "Snorkel en Cenote" → "snorkel-en-cenote").
13. "schedules", "prices"/"prices_group", "fixed_date", "request_only",
    "active_weekdays" son TODOS opcionales — mismo criterio de "no inventar"
    que el resto del esquema, pero acá el default seguro es dejarlos VACÍOS
    (array/objeto vacío, `false`, string vacío) en vez de completarlos con
    ceros: un precio en 0 se interpreta como "gratis", así que nunca pongas
    0 salvo que la info diga explícitamente que es gratis. Completalos solo
    con datos reales que la info te haya dado.
    - "prices" es un objeto `{"adult": N, "child": N, "baby": N}` (podés
      omitir "child"/"baby" si no aplican), solo si "price_model" es
      "percapita".
    - "prices_group" es un array de hasta 3 números (bandas 1–2, 3, y 4 en
      adelante), solo si "price_model" es "group".
    - "active_weekdays" es un array de 0 (domingo) a 6 (sábado) — los días
      que el tour opera por defecto.

Esquema exacto a producir (uno o varios tours dentro de "tours"):

{
  "tours": [
    {
      "name_es": "", "name_en": "",
      "slug": "",
      "price_model": "percapita",
      "description_es": "", "description_en": "",
      "what_to_expect_es": "", "what_to_expect_en": "",
      "highlights_es": [], "highlights_en": [],
      "includes_es": [], "includes_en": [],
      "excludes_es": [], "excludes_en": [],
      "itinerary_stops": [
        { "title_es": "", "title_en": "", "desc_es": "", "desc_en": "", "image_url": "", "is_start": false }
      ],
      "detail_facts": [
        { "icon": "", "label_es": "", "label_en": "", "value_es": "", "value_en": "" }
      ],
      "meeting_point_es": "", "meeting_point_en": "",
      "meeting_lat": null, "meeting_lng": null,
      "duration_minutes": 0,
      "min_age": 0,
      "allow_children": true, "allow_babies": true, "min_age_child": 0,
      "max_capacity": 10, "min_passengers": 1,
      "languages": [],
      "gallery_images": [],

      "schedules": [],
      "prices": {},
      "prices_group": [],
      "fixed_date": "",
      "request_only": false,
      "active_weekdays": []
    }
  ]
}

Ahora convertí la siguiente info del/de los tour(es) a ese formato:

[PEGÁ ACÁ LA INFO CRUDA DEL TOUR — folleto, texto, notas, lo que tengas]
```

---

## Después de generar el JSON

- **Revisalo antes de cargarlo** — la IA puede traducir razonablemente bien, pero los datos de precio/duración/edad conviene siempre confirmarlos contra la fuente original antes de publicar.
- **Horarios y precios son opcionales** — si la info de origen no los menciona con precisión, van a quedar vacíos y podés cargarlos a mano en el editor del tour una vez creado, en la sección "💰 Precios y horarios" (mismo criterio de "no inventar" del resto del prompt).
- Todo tour importado queda como **borrador** — revisalo y publicalo a mano desde el editor.
- Si tenés varios tours para cargar juntos, pegá toda la info de todos en el mismo mensaje — el prompt ya está preparado para devolver varios dentro de `"tours": [...]`.
