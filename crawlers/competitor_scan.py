#!/usr/bin/env python3
"""
competitor_scan.py — Escanea fuentes de competencia y compara con la DB.
Envía informe por Telegram. Corre como GitHub Action semanal.

Fuentes:
  1. Radio Browser API (radio-browser.info) — JSON, 1000+ emisoras AR
  2. Sitios en COMPETITOR_SITES.txt — parser específico o heurístico
  3. Links descubiertos al scrapear cada sitio — nuevos competidores potenciales

Uso local:
    python3 crawlers/competitor_scan.py [--db path/to/radio_v2.sqlite]
"""

import re, sys, os, base64, sqlite3, unicodedata, json, time
import urllib.request, urllib.parse
from datetime import datetime

# ── Config ────────────────────────────────────────────────────────────────────

DB_PATH    = os.getenv('RADIO_DB_PATH', 'db/radio_v2.sqlite')
TG_TOKEN   = os.getenv('TG_TOKEN', '')
TG_CHAT_ID = os.getenv('TG_CHAT_ID', '')

SITES_FILE = os.path.join(os.path.dirname(__file__), 'COMPETITOR_SITES.txt')

UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
MAX_ITEMS  = 20   # items por sección en Telegram

# Dominios propios a ignorar en el descubrimiento de links
OWN_DOMAINS = {'mammoli.ar', 'radio-browser.info', 'myradioenvivo.ar'}

# Dominios de CDN — comparar URL completa en vez de solo dominio
CDN_HOSTS = {
    'playerservices.streamtheworld.com',
    'frontend.radiohdvivo.com',
    'radios.solumedia.com',
    'cdn2.instream.audio',
    'ipanel.instream.audio',
    'server.laradio.online',
    'cdn1.tvlin.net',
}


# ── Normalización ─────────────────────────────────────────────────────────────

def norm_name(s: str) -> str:
    s = s.lower()
    s = unicodedata.normalize('NFD', s)
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    s = re.sub(r'[^a-z0-9]+', ' ', s).strip()
    return s


def url_key(url: str) -> str:
    """Clave de comparación: dominio para hosts únicos, URL completa para CDNs."""
    try:
        parsed = urllib.parse.urlparse(url.lower())
        host   = parsed.netloc
        return url.lower() if host in CDN_HOSTS else host
    except Exception:
        return url.lower()


# ── Fetch ─────────────────────────────────────────────────────────────────────

def fetch(url: str, timeout: int = 30, json_mode: bool = False):
    headers = {'User-Agent': UA, 'Accept': 'application/json' if json_mode else 'text/html',
               'Accept-Language': 'es-AR,es;q=0.9'}
    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        raw = r.read().decode('utf-8', errors='replace')
    return json.loads(raw) if json_mode else raw


# ── Fuente 1: Radio Browser API ───────────────────────────────────────────────

RB_SERVERS = [
    'https://de1.api.radio-browser.info',
    'https://nl1.api.radio-browser.info',
    'https://at1.api.radio-browser.info',
]

def source_radio_browser() -> list[dict]:
    """Descarga todas las emisoras de Argentina desde Radio Browser API."""
    for server in RB_SERVERS:
        url = f'{server}/json/stations/bycountryexact/Argentina?limit=5000&hidebroken=false&order=name'
        try:
            data = fetch(url, json_mode=True)
            stations = []
            for s in data:
                name = (s.get('name') or '').strip()
                url_ = (s.get('url') or '').strip()
                if name and url_ and url_.startswith('http'):
                    stations.append({'nombre': name, 'url': url_, 'source': 'radio-browser'})
            print(f'  Radio Browser: {len(stations)} emisoras desde {server}')
            return stations
        except Exception as e:
            print(f'  Radio Browser error ({server}): {e}')
    return []


# ── Fuente 2: Parsers de sitios ───────────────────────────────────────────────

def parse_myradioenvivo(html: str) -> list[dict]:
    """myradioenvivo.ar — streams en data-src base64, nombres en data-name."""
    pattern = r'data-rid="\d+"[^>]*data-src="([A-Za-z0-9+/=]+)"[^>]*data-listen="[^"]*"[^>]*data-name="([^"]+)"'
    stations = []
    for src, name in re.findall(pattern, html):
        try:
            url = base64.b64decode(src + '==').decode('utf-8', errors='replace').strip()
        except Exception:
            continue
        if url.startswith('http'):
            stations.append({'nombre': name.strip(), 'url': url})
    return stations


def parse_generic(html: str, base_url: str) -> list[dict]:
    """
    Parser heurístico para sitios desconocidos.
    Busca URLs de audio directas, m3u8, streams embebidos en atributos data-*.
    Empareja cada URL con el texto más cercano como nombre.
    """
    stations = []
    seen_urls = set()

    # Patrón 1: URLs de audio directas en atributos href/src/data-*
    stream_pattern = re.compile(
        r'(?:href|src|data-[a-z-]+)=["\']'
        r'(https?://[^"\'<>\s]{10,})'
        r'["\']',
        re.I
    )
    audio_exts = re.compile(r'\.(mp3|aac|m3u8|ogg|opus|flac|pls|asx)(\?|$)', re.I)
    stream_hosts = re.compile(
        r'(shout|icecast|stream|radio|audio|live|listen|cast|play|broadcast)', re.I
    )

    for m in stream_pattern.finditer(html):
        url = m.group(1)
        if url in seen_urls:
            continue
        if not (audio_exts.search(url) or stream_hosts.search(url)):
            continue
        # Evitar logos, imágenes, scripts
        if re.search(r'\.(jpg|jpeg|png|gif|svg|webp|css|js|woff|ico)(\?|$)', url, re.I):
            continue

        # Buscar texto cercano como nombre (±300 chars alrededor del match)
        start = max(0, m.start() - 300)
        ctx = html[start:m.end() + 100]
        txt = re.sub(r'<[^>]+>', ' ', ctx)
        txt = re.sub(r'\s+', ' ', txt).strip()
        words = [w for w in txt.split() if len(w) > 3 and not w.startswith('http')]
        nombre = ' '.join(words[-8:]) if words else url

        seen_urls.add(url)
        stations.append({'nombre': nombre[:60], 'url': url})

    # Patrón 2: base64 en data-src (como myradioenvivo)
    for src in re.findall(r'data-src=["\']([A-Za-z0-9+/=]{20,})["\']', html):
        try:
            decoded = base64.b64decode(src + '==').decode('utf-8', errors='replace').strip()
            if decoded.startswith('http') and decoded not in seen_urls:
                seen_urls.add(decoded)
                stations.append({'nombre': '', 'url': decoded})
        except Exception:
            pass

    return stations


# ── Descubrimiento de nuevos competidores ─────────────────────────────────────

def discover_competitor_links(html: str, known_domains: set) -> list[str]:
    """Extrae links a dominios .ar desconocidos que parezcan directorios de radio."""
    radio_kw = re.compile(r'radio|fm|am|emisora|stream|online', re.I)
    discovered = set()
    for url in re.findall(r'https?://([a-z0-9.-]+\.ar)[/"\']', html, re.I):
        domain = url.lower()
        if domain in known_domains or domain in OWN_DOMAINS:
            continue
        if radio_kw.search(domain):
            discovered.add(domain)
    return sorted(discovered)


# ── Carga COMPETITOR_SITES.txt ────────────────────────────────────────────────

def load_site_targets() -> list[dict]:
    """
    Lee COMPETITOR_SITES.txt. Formato (una URL por línea, # para comentarios):
        https://ejemplo.ar/radios/   # nombre_parser_opcional
    """
    targets = [
        {
            'id':     'myradioenvivo',
            'name':   'myradioenvivo.ar',
            'url':    'https://myradioenvivo.ar/',
            'parser': 'myradioenvivo',
        }
    ]
    if not os.path.exists(SITES_FILE):
        return targets

    with open(SITES_FILE) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            parts = line.split('#')
            url = parts[0].strip()
            parser = parts[1].strip() if len(parts) > 1 else 'generic'
            if url.startswith('http'):
                parsed = urllib.parse.urlparse(url)
                targets.append({
                    'id':     parsed.netloc,
                    'name':   parsed.netloc,
                    'url':    url,
                    'parser': parser,
                })
    return targets


# ── Comparación ───────────────────────────────────────────────────────────────

def load_db_stations(db_path: str) -> list[dict]:
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        'SELECT id, nombre, url, slug FROM stations WHERE approved=1 AND url IS NOT NULL'
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]


def compare(competitor: list[dict], db_stations: list[dict]) -> dict:
    db_by_name = {}
    for s in db_stations:
        db_by_name.setdefault(norm_name(s['nombre']), s)

    db_by_urlkey = {}
    for s in db_stations:
        db_by_urlkey.setdefault(url_key(s['url']), s)

    new_stations, alt_urls, already = [], [], []

    for cs in competitor:
        if not cs.get('url', '').startswith('http'):
            continue
        cn = norm_name(cs['nombre'])
        ck = url_key(cs['url'])

        name_match = None
        if cn:
            for db_n, db_s in db_by_name.items():
                if cn == db_n or (len(cn) > 5 and (cn in db_n or db_n in cn)):
                    name_match = db_s
                    break

        url_match = db_by_urlkey.get(ck)

        if url_match or (name_match and url_match):
            already.append(cs)
        elif name_match and not url_match:
            alt_urls.append({**cs, 'db_station': name_match})
        else:
            new_stations.append(cs)

    return {'new': new_stations, 'alt_urls': alt_urls, 'existing': already}


# ── Telegram ──────────────────────────────────────────────────────────────────

def send_telegram(msg: str):
    if not TG_TOKEN or not TG_CHAT_ID:
        print('[TG] Sin credenciales — stdout:')
        print(msg)
        return
    data = urllib.parse.urlencode(
        {'chat_id': TG_CHAT_ID, 'text': msg, 'parse_mode': 'HTML'}
    ).encode()
    req = urllib.request.Request(
        f'https://api.telegram.org/bot{TG_TOKEN}/sendMessage',
        data=data, method='POST'
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            ok = json.loads(r.read()).get('ok')
            print(f'[TG] {"OK" if ok else "error"}')
    except Exception as e:
        print(f'[TG] Error: {e}')


def build_report(source_name: str, competitor_count: int,
                 db_count: int, result: dict,
                 new_domains: list[str] = None) -> str:
    now  = datetime.utcnow().strftime('%d/%m/%Y %H:%M UTC')
    new  = result['new']
    alts = result['alt_urls']
    lines = [
        f'🔍 <b>Competencia — {source_name}</b>',
        f'📅 {now}',
        f'',
        f'📊 Ellos: {competitor_count}  |  Nosotros: {db_count}',
        f'🆕 Posibles nuevas: {len(new)}',
        f'🔄 URLs alternativas: {len(alts)}',
        f'✅ Ya tenemos: {len(result["existing"])}',
    ]
    if new:
        lines += ['', '━ <b>POSIBLES NUEVAS</b>']
        for cs in new[:MAX_ITEMS]:
            nom = cs['nombre'] or '(sin nombre)'
            lines.append(f'• {nom}')
            lines.append(f'  <code>{cs["url"][:75]}</code>')
        if len(new) > MAX_ITEMS:
            lines.append(f'  … y {len(new) - MAX_ITEMS} más')
    if alts:
        lines += ['', '━ <b>URLs ALTERNATIVAS</b>']
        for item in alts[:MAX_ITEMS]:
            db_s = item['db_station']
            lines.append(f'• {item["nombre"]} (nosotros: {db_s["slug"]})')
            lines.append(f'  <code>{item["url"][:70]}</code>')
        if len(alts) > MAX_ITEMS:
            lines.append(f'  … y {len(alts) - MAX_ITEMS} más')
    if not new and not alts:
        lines += ['', '💚 Sin novedades — ya tenemos todo.']
    if new_domains:
        lines += ['', '🕵️ <b>Nuevos sitios descubiertos</b>']
        for d in new_domains[:8]:
            lines.append(f'  • {d}  → agregar a COMPETITOR_SITES.txt')
    return '\n'.join(lines)


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    db_path = DB_PATH
    if '--db' in sys.argv:
        db_path = sys.argv[sys.argv.index('--db') + 1]
    if not os.path.exists(db_path):
        print(f'ERROR: DB no encontrada en {db_path}'); sys.exit(1)

    db_stations = load_db_stations(db_path)
    print(f'DB: {len(db_stations)} emisoras aprobadas')

    known_domains = {urllib.parse.urlparse(s['url']).netloc.lower() for s in db_stations}
    known_domains |= OWN_DOMAINS

    # ── Fuente 1: Radio Browser API ──────────────────────────────────────────
    print('\n→ Radio Browser API...')
    rb_stations = source_radio_browser()
    if rb_stations:
        result = compare(rb_stations, db_stations)
        print(f'  Nuevas: {len(result["new"])}  Alt URLs: {len(result["alt_urls"])}')
        msg = build_report('radio-browser.info', len(rb_stations), len(db_stations), result)
        send_telegram(msg)
        time.sleep(2)

    # ── Fuente 2: Sitios específicos ─────────────────────────────────────────
    targets = load_site_targets()
    all_discovered = set()

    for target in targets:
        print(f'\n→ {target["name"]}...')
        try:
            html = fetch(target['url'])
        except Exception as e:
            print(f'  ERROR: {e}'); continue

        # Parser
        if target['parser'] == 'myradioenvivo':
            stations = parse_myradioenvivo(html)
        else:
            stations = parse_generic(html, target['url'])
        print(f'  Extraídas: {len(stations)}')

        # Descubrimiento de nuevos competidores
        discovered = discover_competitor_links(html, known_domains)
        all_discovered.update(discovered)

        if not stations:
            continue

        result = compare(stations, db_stations)
        print(f'  Nuevas: {len(result["new"])}  Alt: {len(result["alt_urls"])}')

        # Solo reportar si hay novedades (o si es la primera vez)
        if result['new'] or result['alt_urls'] or len(result['existing']) < 5:
            msg = build_report(
                target['name'], len(stations), len(db_stations), result,
                new_domains=sorted(all_discovered) if all_discovered else None
            )
            send_telegram(msg)
            time.sleep(2)

    # Resumen de dominios descubiertos si no se incluyeron en ningún reporte anterior
    if all_discovered:
        print(f'\n🕵️ Dominios nuevos descubiertos: {sorted(all_discovered)}')

    print('\nListo.')


if __name__ == '__main__':
    main()
