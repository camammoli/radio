#!/usr/bin/env python3
"""
hunt_stations.py — Caza emisoras argentinas de fuentes públicas.
Verifica streams en paralelo, evita duplicados, agrega candidatos verificados
a sugerencias.json para revisión en el panel de admin.

Uso:
    python3 hunt_stations.py --sugerencias /ruta/sugerencias.json
    python3 hunt_stations.py --sugerencias /ruta/sugerencias.json --dry-run
"""

import json
import re
import sys
import time
import uuid
import argparse
import concurrent.futures
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError
from collections import defaultdict

SCRIPT_DIR = Path(__file__).parent

# ── Fuentes públicas de emisoras ──────────────────────────────────────────────
# Cada fuente es un dict con 'type' y parámetros específicos.
# Tipo 'radio-browser': consulta la API de radio-browser.info
# Tipo 'm3u': descarga y parsea una playlist pública (CC0/MIT)
SOURCES = [
    # Radio Browser — AR por clickcount (las más populares primero)
    {
        'type': 'radio-browser',
        'url': 'https://de1.api.radio-browser.info/json/stations/search'
               '?countrycode=AR&hidebroken=false&limit=3000&order=clickcount&reverse=true',
    },
    # Radio Browser — AR por votos (distinto ordenamiento, puede dar resultados distintos)
    {
        'type': 'radio-browser',
        'url': 'https://nl1.api.radio-browser.info/json/stations/search'
               '?countrycode=AR&hidebroken=false&limit=3000&order=votes&reverse=true',
    },
    # Radio Browser — por nombre de país (algunos tienen "Argentina" sin countrycode correcto)
    {
        'type': 'radio-browser',
        'url': 'https://de1.api.radio-browser.info/json/stations/search'
               '?country=Argentina&hidebroken=false&limit=3000&order=name',
    },
    # Radio Browser — por tag "argentina" (emisoras tagueadas pero con countrycode incorrecto)
    {
        'type': 'radio-browser',
        'url': 'https://de1.api.radio-browser.info/json/stations/search'
               '?tag=argentina&hidebroken=false&limit=1000&order=clickcount&reverse=true',
    },
    # Fuentes M3U adicionales: agregar aquí cuando haya listas verificadas de radio AR.
    # IPTV-org (ar.m3u) descartado: contiene canales de TV sin distinción de radio.
]

# ── Normalización de provincias ───────────────────────────────────────────────
PROVINCE_MAP = {
    'buenos aires': 'Buenos Aires',
    'provincia de buenos aires': 'Buenos Aires',
    'ciudad autonoma de buenos aires': 'CABA',
    'ciudad autonoma': 'CABA',
    'ciudad de buenos aires': 'CABA',
    'buenos aires caba': 'CABA',
    'caba': 'CABA',
    'capital federal': 'CABA',
    'córdoba': 'Córdoba',
    'cordoba': 'Córdoba',
    'santa fe': 'Santa Fe',
    'rosario': 'Santa Fe',
    'mendoza': 'Mendoza',
    'corrientes': 'Corrientes',
    'la pampa': 'La Pampa',
    'salta': 'Salta',
    'jujuy': 'Jujuy',
    'misiones': 'Misiones',
    'posadas': 'Misiones',
    'entre ríos': 'Entre Ríos',
    'entre rios': 'Entre Ríos',
    'río negro': 'Río Negro',
    'rio negro': 'Río Negro',
    'bariloche': 'Río Negro',
    'neuquén': 'Neuquén',
    'neuquen': 'Neuquén',
    'san juan': 'San Juan',
    'tucumán': 'Tucumán',
    'tucuman': 'Tucumán',
    'chaco': 'Chaco',
    'resistencia': 'Chaco',
    'chubut': 'Chubut',
    'santa cruz': 'Santa Cruz',
    'tierra del fuego': 'Tierra del Fuego',
    'san luis': 'San Luis',
    'santiago del estero': 'Santiago del Estero',
    'catamarca': 'Catamarca',
    'la rioja': 'La Rioja',
    'formosa': 'Formosa',
}

def normalize_province(state: str) -> str:
    if not state:
        return 'Argentina'
    key = state.lower().strip()
    for fragment, norm in PROVINCE_MAP.items():
        if fragment in key:
            return norm
    return 'Argentina'


# ── Carga de URLs conocidas ───────────────────────────────────────────────────

def load_existing_urls() -> set:
    urls = set()
    txt = SCRIPT_DIR / 'emisoras.txt'
    if txt.exists():
        for line in txt.read_text(encoding='utf-8').splitlines():
            line = line.strip()
            if re.match(r'^https?://', line):
                urls.add(line.lower().rstrip('/'))
    return urls


def load_existing_suggestions(path: Path) -> set:
    if not path.exists():
        return set()
    try:
        data = json.loads(path.read_text(encoding='utf-8'))
        return {s['url'].lower().rstrip('/') for s in data if s.get('url')}
    except Exception:
        return set()


# ── Fetchers por tipo de fuente ───────────────────────────────────────────────

def _http_get(url: str, timeout: int = 30) -> bytes:
    req = Request(url, headers={'User-Agent': 'mammoli-radio-hunter/1.0'})
    with urlopen(req, timeout=timeout) as r:
        return r.read()


def fetch_radio_browser(source: dict, known: set = None) -> list:
    """Devuelve lista de dicts {nombre, url, provincia}.
    Compara url Y url_resolved contra known para evitar falsos nuevos por redirect."""
    try:
        raw = _http_get(source['url'])
        data = json.loads(raw.decode('utf-8'))
    except Exception as e:
        print(f"  ✗ Error Radio Browser ({source['url'][:60]}): {e}", flush=True)
        return []

    results = []
    for s in data:
        url_resolved = (s.get('url_resolved') or '').strip()
        url_original = (s.get('url') or '').strip()
        # Preferir url_resolved, pero verificar ambas contra known
        url = url_resolved or url_original
        if not url or not url.startswith('http'):
            continue
        # Si cualquiera de las dos URLs ya la tenemos, saltar
        if known:
            if url_resolved.lower().rstrip('/') in known:
                continue
            if url_original.lower().rstrip('/') in known:
                continue
        name = s.get('name', '').strip().strip('\t ')
        if not name or name.startswith('http') or len(name) < 2:
            continue
        results.append({
            'nombre':    name,
            'url':       url,
            'url_orig':  url_original,
            'provincia': normalize_province(s.get('state', '')),
            'fuente':    'radio-browser',
        })
    return results


TV_KEYWORDS = {'tv', 'television', 'televisión', 'canal', 'channel', 'hd', 'sd',
               '720p', '1080p', '576p', '480p', '4k', 'uhd', 'vivo', 'en vivo',
               'news', 'noticias tv'}

def _is_tv(name: str) -> bool:
    low = name.lower()
    return any(kw in low for kw in TV_KEYWORDS)


def fetch_m3u(source: dict) -> list:
    """Parsea una playlist M3U pública y extrae entradas de radio (filtra TV)."""
    try:
        raw = _http_get(source['url']).decode('utf-8', errors='replace')
    except Exception as e:
        print(f"  ✗ Error M3U ({source['url'][:60]}): {e}", flush=True)
        return []

    results = []
    lines = raw.splitlines()
    name = ''
    country_tag = ''
    audio_only = source.get('audio_only', False)

    for line in lines:
        line = line.strip()
        if line.startswith('#EXTINF'):
            name_m = re.search(r',(.+)$', line)
            name = name_m.group(1).strip() if name_m else ''
            ctry_m = re.search(r'tvg-country="([^"]*)"', line, re.I)
            country_tag = ctry_m.group(1).strip() if ctry_m else ''
        elif line.startswith('http'):
            if name and len(name) >= 2:
                # Filtrar contenido de TV
                if audio_only and _is_tv(name):
                    name = ''
                    continue
                # Filtrar entradas claramente de video (m3u8 de TV suelen tener resolución)
                if audio_only and re.search(r'\b\d{3,4}p\b', name, re.I):
                    name = ''
                    continue
                results.append({
                    'nombre':    name,
                    'url':       line,
                    'provincia': 'Argentina',
                    'fuente':    'm3u',
                })
            name = ''
            country_tag = ''

    return results


def hunt_all_sources(known: set) -> list:
    """Consulta todas las fuentes y devuelve candidatos sin duplicar URL.
    'known' son las URLs ya existentes (emisoras.txt + sugerencias previas)."""
    dispatch = {
        'radio-browser': fetch_radio_browser,
        'm3u':           fetch_m3u,
    }
    seen_urls = set(known)  # copia; se expande con cada fuente para dedup cross-source
    candidates = []

    for src in SOURCES:
        fetcher = dispatch.get(src['type'])
        if not fetcher:
            continue
        print(f"  → {src['type']}: {src['url'][:70]}...", flush=True)
        if src['type'] == 'radio-browser':
            items = fetcher(src, known=seen_urls)
        else:
            items = fetcher(src)
        new = 0
        for item in items:
            url_norm = item['url'].lower().rstrip('/')
            if url_norm not in seen_urls:
                seen_urls.add(url_norm)
                # También registrar url_orig si existe
                if item.get('url_orig'):
                    seen_urls.add(item['url_orig'].lower().rstrip('/'))
                candidates.append(item)
                new += 1
        print(f"     {len(items)} obtenidas, {new} nuevas para este ciclo", flush=True)

    return candidates


# ── Verificación de streams ───────────────────────────────────────────────────

def verify_stream(url: str, timeout: int = 10) -> dict:
    """HEAD con fallback GET; retorna {'ok', 'code', 'audio'}."""
    def _check(method):
        try:
            req = Request(url)
            req.get_method = lambda: method
            req.add_header('User-Agent', 'Mozilla/5.0 (compatible; radio-check/1.0)')
            req.add_header('Icy-MetaData', '0')
            if method == 'GET':
                req.add_header('Range', 'bytes=0-2047')
            with urlopen(req, timeout=timeout) as r:
                ct = (r.headers.get('Content-Type') or '').lower()
                code = r.status
                # Leer un poco para confirmar flujo de datos
                if method == 'GET':
                    r.read(512)
                audio = any(x in ct for x in ['audio', 'ogg', 'mpegurl', 'x-mpeg'])
                return {'ok': 200 <= code < 400, 'code': code, 'audio': audio}
        except (HTTPError, URLError, OSError):
            return None

    # Para HLS (.m3u8) hacer solo GET
    if '.m3u8' in url.lower():
        r = _check('GET')
        return r or {'ok': False, 'code': 0, 'audio': False}

    r = _check('HEAD')
    if r and r['ok']:
        return r

    # Fallback GET
    r = _check('GET')
    return r or {'ok': False, 'code': 0, 'audio': False}


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Caza y verifica emisoras argentinas.')
    parser.add_argument('--sugerencias', required=True,
                        help='Ruta a sugerencias.json (se descarga del servidor antes de correr)')
    parser.add_argument('--dry-run', action='store_true',
                        help='No escribe nada, solo muestra qué haría')
    parser.add_argument('--workers', type=int, default=25,
                        help='Threads de verificación (default: 25)')
    parser.add_argument('--timeout', type=int, default=10,
                        help='Timeout por stream en segundos (default: 10)')
    args = parser.parse_args()

    sug_path = Path(args.sugerencias)

    # ── 1. Cargar URLs conocidas ──────────────────────────────────────────────
    print('Cargando URLs conocidas...', flush=True)
    existing  = load_existing_urls()
    suggested = load_existing_suggestions(sug_path)
    known     = existing | suggested
    print(f'  Emisoras en directorio : {len(existing)}')
    print(f'  Ya en sugerencias      : {len(suggested)}')

    # ── 2. Cazar candidatos ───────────────────────────────────────────────────
    print('\nConsultando fuentes...', flush=True)
    new_candidates = hunt_all_sources(known)
    print(f'\nCandidatos nuevos (sin duplicar): {len(new_candidates)}')

    if not new_candidates:
        print('Sin novedades.')
        print('HUNT_FOUND=0')
        print('HUNT_FAILED=0')
        print('HUNT_CHECKED=0')
        return

    # ── 3. Verificar en paralelo ──────────────────────────────────────────────
    print(f'\nVerificando {len(new_candidates)} streams ({args.workers} workers, timeout {args.timeout}s)...', flush=True)

    verified = []
    failed   = 0

    def check_one(c):
        return c, verify_stream(c['url'], args.timeout)

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as ex:
        futures = {ex.submit(check_one, c): c for c in new_candidates}
        done = 0
        for f in concurrent.futures.as_completed(futures):
            done += 1
            c, result = f.result()
            if result['ok']:
                c['http_code'] = result['code']
                c['is_audio']  = result['audio']
                verified.append(c)
            else:
                failed += 1
            if done % 50 == 0 or done == len(new_candidates):
                print(f'  {done}/{len(new_candidates)} — {len(verified)} ok / {failed} caídas', flush=True)

    print(f'\nResultado: {len(verified)} activas / {failed} caídas de {len(new_candidates)} verificadas')

    # ── 4. Agrupar por provincia para el resumen ──────────────────────────────
    by_prov = defaultdict(list)
    for c in verified:
        by_prov[c['provincia']].append(c['nombre'])

    print('\nPor provincia:')
    for prov, names in sorted(by_prov.items(), key=lambda x: -len(x[1])):
        print(f'  {len(names):3d}  {prov}')
        for n in names[:3]:
            print(f'         · {n}')
        if len(names) > 3:
            print(f'         ... y {len(names)-3} más')

    # Exportar resumen para el workflow
    print(f'\nHUNT_FOUND={len(verified)}')
    print(f'HUNT_FAILED={failed}')
    print(f'HUNT_CHECKED={len(new_candidates)}')

    if args.dry_run:
        print('\n[dry-run] No se escribió nada.')
        return

    if not verified:
        print('Nada verificado para guardar.')
        return

    # ── 5. Agregar a sugerencias.json ─────────────────────────────────────────
    sugerencias = []
    if sug_path.exists():
        try:
            sugerencias = json.loads(sug_path.read_text(encoding='utf-8'))
        except Exception:
            sugerencias = []

    ts = time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime())
    for c in verified:
        prov = c['provincia']
        prov_str = f'{prov}, Argentina' if prov and prov != 'Argentina' else 'Argentina'
        sugerencias.append({
            'id':        'sug_' + uuid.uuid4().hex[:12],
            'ts':        ts,
            'nombre':    c['nombre'],
            'url':       c['url'],
            'provincia': prov_str,
            'contacto':  '🤖 crawler',
            'estado':    'pendiente',
            'http_code': c.get('http_code', 200),
            'is_audio':  c.get('is_audio', False),
            'fuente':    c.get('fuente', 'crawler'),
        })

    sug_path.write_text(
        json.dumps(sugerencias, ensure_ascii=False, indent=2),
        encoding='utf-8',
    )
    print(f'\n✓ {len(verified)} candidatos guardados en {sug_path}')


if __name__ == '__main__':
    main()
