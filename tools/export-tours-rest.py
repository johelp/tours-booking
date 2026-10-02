#!/usr/bin/env python3
"""
Exporta tours de una instalación de TourFlow (vía su REST API pública) al
formato JSON que acepta TourFlow → Configuración → 📥 Importar
(includes/core/class-tour-importer.php).

Pensado para una migración a una instalación limpia (GUIA-HEADLESS-CALIAFARM.md
§ 5): no toca nada en el sitio de origen, solo hace GET a endpoints públicos.

Uso:
    python3 tools/export-tours-rest.py \
        --base https://caliafarm.com/stag/wp-json \
        --ids 11,12 \
        --out tours-export.json

    --base   Raíz de la REST API del sitio de origen (termina en /wp-json).
    --ids    IDs extra a exportar además del listado público. Necesario para
             tours con "Ocultar de listados" activo (GET /tours no los
             devuelve). Son los IDs de la columna "ID" de TourFlow → Tours.
    --only   Exportar SOLO estos IDs (ignora el listado público).
    --out    Archivo JSON de salida (default: tours-export.json). Al lado se
             escribe <out>-reporte.md con lo que hay que completar a mano.
    --per-tour  Además, un JSON por tour, para importarlos de a uno
             (recomendado: el importador baja las fotos en la misma petición).

Límites (la API pública no expone estos datos — van al reporte):
    - Tours en borrador/inactivos: no se pueden leer por REST.
    - Días operativos, lista de interés, depósito, reserva directa, ocultar
      de listados, nota extra de email, costo/margen: no expuestos.
    - Proveedor, extras del tour, video, "Armá tu tour", nombre de cada
      integrante: expuestos, pero el importador no los carga.
    - Precios por horario o por temporada: el importador solo carga los
      genéricos.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

UA = 'TourFlow-export/1.0 (+migración a instalación limpia)'


def get(base, path):
    url = base.rstrip('/') + path
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        raise RuntimeError(f'HTTP {e.code} en {path}') from None
    except urllib.error.URLError as e:
        # Python.org en macOS viene sin certificados raíz instalados
        # (CERTIFICATE_VERIFY_FAILED) — curl usa los del sistema.
        if 'CERTIFICATE_VERIFY_FAILED' not in str(e):
            raise
        out = subprocess.run(['curl', '-sS', '-f', '-A', UA, '-H', 'Accept: application/json', url],
                             capture_output=True, text=True)
        if out.returncode != 0:
            raise RuntimeError(f'{out.stderr.strip()} en {path}') from None
        return json.loads(out.stdout)


def group_price_ranges(max_capacity):
    """Misma lógica que TourPostType::group_price_ranges() — las bandas que
    el importador va a crear, en el mismo orden."""
    m = max(1, int(max_capacity or 10))
    if m <= 2:
        return [(1, m)]
    if m == 3:
        return [(1, 2), (3, 3)]
    return [(1, 2), (3, 3), (4, m)]


_SIZE_SUFFIX = re.compile(r'-\d+x\d+(?=\.[A-Za-z0-9]+$)')


def url_ok(url):
    out = subprocess.run(['curl', '-sS', '-o', '/dev/null', '-I', '-L', '-w', '%{http_code}', '-A', UA, url],
                         capture_output=True, text=True)
    return out.stdout.strip() == '200'


def original_image(url, cache={}):
    """La API devuelve el tamaño 'large' (ej. foto-1024x576.jpg). Para la
    instalación nueva conviene subir el original — WordPress regenera ahí
    todos los tamaños. Si el original no responde, se queda con el 'large'."""
    if url in cache:
        return cache[url]
    orig = _SIZE_SUFFIX.sub('', url)
    cache[url] = orig if orig != url and url_ok(orig) else url
    return cache[url]


def url_of(img):
    if isinstance(img, str):
        return img
    if isinstance(img, dict):
        return img.get('url') or img.get('src') or ''
    return ''


def zip_bilingual(es_list, en_list, keys):
    """Une las versiones es/en de una lista de objetos por posición.
    keys: {'campo_rest': ('campo_es', 'campo_en')}"""
    out = []
    for i, es in enumerate(es_list or []):
        en = (en_list or [])[i] if i < len(en_list or []) else {}
        item = {}
        for k, (k_es, k_en) in keys.items():
            item[k_es] = es.get(k) or ''
            item[k_en] = en.get(k) or ''
        out.append(item)
    return out


def build(es, en, notes):
    t = {
        'name_es': es.get('name') or '',
        'name_en': en.get('name') or '',
        'slug': es.get('slug') or '',
        'price_model': es.get('price_model') or 'percapita',
        'description_es': es.get('description') or '',
        'description_en': en.get('description') or '',
        'what_to_expect_es': es.get('what_to_expect') or '',
        'what_to_expect_en': en.get('what_to_expect') or '',
        'highlights_es': es.get('highlights') or [],
        'highlights_en': en.get('highlights') or [],
        'includes_es': es.get('includes') or [],
        'includes_en': en.get('includes') or [],
        'excludes_es': es.get('excludes') or [],
        'excludes_en': en.get('excludes') or [],
        'meeting_point_es': es.get('meeting_point') or '',
        'meeting_point_en': en.get('meeting_point') or '',
        'meeting_lat': es.get('meeting_lat'),
        'meeting_lng': es.get('meeting_lng'),
        'duration_minutes': es.get('duration_minutes') or 0,
        'min_age': es.get('min_age') or 0,
        'allow_children': bool(es.get('allow_children', True)),
        'allow_babies': bool(es.get('allow_babies', True)),
        'min_age_child': es.get('min_age_child') or 0,
        'max_capacity': es.get('max_capacity') or 10,
        'min_passengers': es.get('min_passengers') or 1,
        'languages': es.get('languages') or [],
        # dict.fromkeys: dedup conservando orden (la destacada suele repetirse en la galería)
        'gallery_images': list(dict.fromkeys(original_image(u) for u in (url_of(g) for g in (es.get('gallery_images') or [])) if u)),
        'fixed_date': es.get('fixed_date') or '',
        'request_only': bool(es.get('request_only')),
    }

    # En /stag hay tours con el texto en inglés cargado en el slot ES y el EN
    # vacío — el frontend pide lang=en y mostraría la ficha sin descripción.
    for field in ('description', 'what_to_expect'):
        if t[f'{field}_es'] and not t[f'{field}_en']:
            t[f'{field}_en'] = t[f'{field}_es']
            notes.append(f'`{field}_en` estaba vacío en origen — se copió el texto del campo ES (revisar idioma de ambos en el editor).')

    stops = zip_bilingual(es.get('itinerary_stops'), en.get('itinerary_stops'),
                          {'title': ('title_es', 'title_en'), 'desc': ('desc_es', 'desc_en')})
    for i, s in enumerate(stops):
        s['is_start'] = bool((es.get('itinerary_stops') or [])[i].get('is_start'))
    if stops:
        t['itinerary_stops'] = stops
    if any((s.get('image_url') or '') for s in (es.get('itinerary_stops') or [])):
        notes.append('Itinerario: hay paradas con foto — el importador no carga fotos de paradas, subirlas a mano.')

    facts = zip_bilingual(es.get('detail_facts'), en.get('detail_facts'),
                          {'label': ('label_es', 'label_en'), 'value': ('value_es', 'value_en')})
    for i, f in enumerate(facts):
        f['icon'] = (es.get('detail_facts') or [])[i].get('icon') or ''
    if facts:
        t['detail_facts'] = facts

    faq = zip_bilingual(es.get('faq_items'), en.get('faq_items'),
                        {'question': ('question_es', 'question_en'), 'answer': ('answer_es', 'answer_en')})
    if faq:
        t['faq_items'] = faq

    # Horarios (labels es/en unidos por id)
    en_sched = {s['id']: s for s in (en.get('schedules') or [])}
    t['schedules'] = [{
        'time_start': (s.get('time_start') or '')[:5],
        'time_end': (s.get('time_end') or '')[:5],
        'label_es': s.get('label') or '',
        'label_en': (en_sched.get(s['id']) or {}).get('label') or '',
    } for s in (es.get('schedules') or [])]

    # Precios: solo filas genéricas (sin horario)
    prices = es.get('prices') or []
    if any(p.get('schedule_id') for p in prices):
        notes.append('Precios: hay precios por horario — el importador no los carga, completarlos en el editor.')
    generic = [p for p in prices if not p.get('schedule_id')]

    if t['price_model'] == 'group':
        rows = [p for p in generic if p.get('person_type') == 'group']
        bands = group_price_ranges(t['max_capacity'])
        values = []
        for bmin, bmax in bands:
            match = [p for p in rows if (p.get('group_min') or 1) <= bmin and (p.get('group_max') or 9999) >= bmax]
            values.append(match[0]['price_mxn'] if match else 0)
        t['prices_group'] = values
        origin = sorted(((p.get('group_min'), p.get('group_max'), p['price_mxn']) for p in rows), key=lambda x: (x[0] or 0))
        target = [f'{a}–{b}: {v}' for (a, b), v in zip(bands, values)]
        if [(a, b) for a, b, _ in origin] != bands:
            notes.append(f'Precio por grupo: las bandas de origen {origin} no coinciden con las que crea el importador '
                         f'para capacidad {t["max_capacity"]} → quedaron {target}. Revisar en el editor.')
        if 0 in values:
            notes.append('Precio por grupo: alguna banda quedó sin precio (0) — completarla en el editor.')
    else:
        by_type = {}
        for p in generic:
            by_type.setdefault(p.get('person_type'), []).append(p['price_mxn'])
        t['prices'] = {k: v[0] for k, v in by_type.items() if k in ('adult', 'child', 'baby')}
        dup = {k: v for k, v in by_type.items() if len(v) > 1}
        if dup:
            notes.append(f'Precios: hay más de un precio vigente por tipo {dup} (probablemente uno de temporada) '
                         f'— se tomó el primero; revisar en el editor.')

    # Expuestos pero no importables
    if es.get('provider_id'):
        notes.append(f'Proveedor: el tour tiene provider_id={es["provider_id"]} en origen — asignar el proveedor (y su costo) a mano. '
                     f'Sin proveedor, caliafarm-web lo muestra en otro grupo del catálogo.')
    if es.get('addons'):
        names = ', '.join(f'{a.get("name")} ({a.get("price_mxn")})' for a in es['addons'])
        notes.append(f'Extras del tour: {names} — cargarlos a mano.')
    if es.get('video_url'):
        notes.append(f'Video: {es["video_url"]} — pegarlo en el editor.')
    if es.get('custom_quote'):
        notes.append('"Armá tu tour" activado en origen — activarlo a mano.')
    if es.get('require_participant_names'):
        notes.append('"Requiere nombre de cada integrante" activado en origen — activarlo a mano.')
    if es.get('categories'):
        cats = ', '.join(c.get('name_es') or c.get('slug') if isinstance(c, dict) else str(c) for c in es['categories'])
        notes.append(f'Categorías: {cats} — crearlas (Tours → Categorías) y asignarlas a mano.')
    return t


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--base', required=True)
    ap.add_argument('--ids', default='')
    ap.add_argument('--only', default='')
    ap.add_argument('--out', default='tours-export.json')
    ap.add_argument('--per-tour', action='store_true',
                    help='Además, un JSON por tour (<out>-<slug>.json) para importarlos de a uno '
                         '(el importador descarga las fotos dentro de la misma petición y puede cortarse por tiempo).')
    a = ap.parse_args()

    parse = lambda s: [int(x) for x in s.replace(' ', '').split(',') if x]
    if a.only:
        ids = parse(a.only)
    else:
        listing = get(a.base, '/amir/v1/tours?lang=es')
        listing = listing.get('tours', listing) if isinstance(listing, dict) else listing
        ids = [int(t['id']) for t in listing] + [i for i in parse(a.ids) if i not in {int(t['id']) for t in listing}]

    tours, report = [], []
    for tid in ids:
        try:
            es = get(a.base, f'/amir/v1/tours/{tid}?lang=es')
            en = get(a.base, f'/amir/v1/tours/{tid}?lang=en')
        except RuntimeError as e:
            print(f'  ✗ tour {tid}: {e} (¿borrador, inactivo o ID equivocado?)', file=sys.stderr)
            report.append((tid, '—', '—', [f'No se pudo leer: {e}. Recrearlo a mano si hace falta.']))
            continue
        notes = []
        tours.append(build(es, en, notes))
        report.append((tid, es.get('slug'), es.get('name'), notes))
        print(f'  ✓ {tid} {es.get("slug")} — {len(notes)} pendiente(s)', file=sys.stderr)

    os.makedirs(os.path.dirname(os.path.abspath(a.out)), exist_ok=True)
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump({'tours': tours}, f, ensure_ascii=False, indent=2)

    stem = a.out.rsplit('.', 1)[0]
    if a.per_tour:
        for t in tours:
            with open(f'{stem}-{t["slug"]}.json', 'w', encoding='utf-8') as f:
                json.dump({'tours': [t]}, f, ensure_ascii=False, indent=2)

    rep = stem + '-reporte.md'
    with open(rep, 'w', encoding='utf-8') as f:
        f.write(f'# Reporte de exportación de tours\n\nOrigen: `{a.base}` · {len(tours)} tour(s) exportados.\n\n')
        f.write('Para TODOS los tours, revisar a mano después de importar (la API pública no los expone): '
                'días operativos, reglas de disponibilidad, lista de interés, depósito parcial, reserva directa, '
                'ocultar de listados, nota extra del email de confirmación, costo/margen.\n\n')
        f.write('Anotar el **ID nuevo** de cada tour en la instalación destino (columna "ID" de TourFlow → Tours) '
                'para actualizar referencias fijas del frontend (ej. `src/pages/sicilia-mia.astro`).\n\n')
        f.write('| ID origen | Slug | Nombre | ID nuevo |\n|---|---|---|---|\n')
        for tid, slug, name, _ in report:
            f.write(f'| {tid} | `{slug}` | {name} | |\n')
        f.write('\n## Pendientes por tour\n')
        for tid, slug, name, notes in report:
            f.write(f'\n### {name} (`{slug}`, ID origen {tid})\n\n')
            f.write(''.join(f'- [ ] {n}\n' for n in notes) if notes else '- Nada específico — solo la revisión general de arriba.\n')

    print(f'\n→ {a.out} ({len(tours)} tours)\n→ {rep}', file=sys.stderr)


if __name__ == '__main__':
    main()
