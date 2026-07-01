#!/usr/bin/env python3
"""
competitor_scan.py — Escanea directorios de radio competidores y compara con la DB.
Informa por Telegram. Diseñado para correr como GitHub Action semanal (o manual).

Uso local:
    python3 crawlers/competitor_scan.py [--db path/to/radio_v2.sqlite]

En GitHub Actions: requiere secrets TG_TOKEN, TG_CHAT_ID.
La DB se descarga de FTP antes de correr este script (ver competitor-scan.yml).
"""

import re
import sys
import os
import base64
import sqlite3
import unicodedata
import urllib.request
import urllib.parse
from datetime import datetime

# ── Configuración ─────────────────────────────────────────────────────────────

DB_PATH = os.getenv('RADIO_DB_PATH', 'db/radio_v2.sqlite')
TG_TOKEN   = os.getenv('TG_TOKEN', '')
TG_CHAT_ID = os.getenv('TG_CHAT_ID', '')

UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'

TARGETS = [
    {
        'id':     'myradioenvivo',
        'name':   'myradioenvivo.ar',
        'url':    'https://myradioenvivo.ar/',
        'parser': 'parse_myradioenvivo',
    },
    # Agregar más targets acá — cada uno necesita su función parse_*
]

MAX_REPORT_ITEMS = 20   # límite de items por sección en el Telegram


# ── Parsers por sitio ─────────────────────────────────────────────────────────

def parse_myradioenvivo(html: str) -> list[dict]:
    """Extrae emisoras de myradioenvivo.ar.
    Los streams están en data-src como base64; los nombres en data-name."""
    pattern = r'data-rid="(\d+)"[^>]*data-src="([A-Za-z0-9+/=]+)"[^>]*data-listen="[^"]*"[^>]*data-name="([^"]+)"'
    stations = []
    for rid, src, name in re.findall(pattern, html):
        try:
            url = base64.b64decode(src + '==').decode('utf-8', errors='replace').strip()
        except Exception:
            continue
        if url.startswith('http'):
            stations.append({'rid': rid, 'nombre': name.strip(), 'url': url})
    return stations


# ── Normalización ─────────────────────────────────────────────────────────────

def norm_name(s: str) -> str:
    s = s.lower()
    s = unicodedata.normalize('NFD', s)
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    s = re.sub(r'[^a-z0-9]+', ' ', s).strip()
    return s


def url_domain(url: str) -> str:
    try:
        parsed = urllib.parse.urlparse(url)
        host = parsed.netloc.lower()
        # Ignorar dominios genéricos de CDN — comparo path también
        cdn_hosts = {'playerservices.streamtheworld.com', 'frontend.radiohdvivo.com',
                     'radios.solumedia.com', 'cdn2.instream.audio', 'ipanel.instream.audio'}
        if host in cdn_hosts:
            return url.lower()          # Comparar URL completa en CDNs compartidos
        return host
    except Exception:
        return url.lower()


# ── Fetch ─────────────────────────────────────────────────────────────────────

def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers={
        'User-Agent': UA,
        'Accept': 'text/html,application/xhtml+xml',
        'Accept-Language': 'es-AR,es;q=0.9',
    })
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode('utf-8', errors='replace')


# ── Carga de DB ───────────────────────────────────────────────────────────────

def load_db_stations(db_path: str) -> list[dict]:
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        'SELECT id, nombre, url, slug FROM stations WHERE approved=1 AND url IS NOT NULL'
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]


# ── Comparación ───────────────────────────────────────────────────────────────

def compare(competitor_stations: list[dict], db_stations: list[dict]) -> dict:
    db_names   = {norm_name(s['nombre']): s for s in db_stations}
    db_domains = {}
    for s in db_stations:
        d = url_domain(s['url'])
        db_domains.setdefault(d, []).append(s)

    new_stations  = []   # nombre Y URL sin match
    alt_urls      = []   # nombre match pero URL diferente
    already_have  = []   # ambos match

    for cs in competitor_stations:
        cn  = norm_name(cs['nombre'])
        cd  = url_domain(cs['url'])

        name_match = None
        for db_n, db_s in db_names.items():
            if cn == db_n or (len(cn) > 4 and (cn in db_n or db_n in cn)):
                name_match = db_s
                break

        url_match = bool(db_domains.get(cd))

        if name_match and url_match:
            already_have.append(cs)
        elif name_match and not url_match:
            alt_urls.append({**cs, 'db_station': name_match})
        elif not name_match and url_match:
            already_have.append(cs)  # misma URL/CDN path, diferente nombre
        else:
            new_stations.append(cs)

    return {
        'new':       new_stations,
        'alt_urls':  alt_urls,
        'existing':  already_have,
    }


# ── Telegram ──────────────────────────────────────────────────────────────────

def send_telegram(msg: str):
    if not TG_TOKEN or not TG_CHAT_ID:
        print('[TG] Sin credenciales, imprimiendo en stdout:')
        print(msg)
        return
    data = urllib.parse.urlencode({
        'chat_id':    TG_CHAT_ID,
        'text':       msg,
        'parse_mode': 'HTML',
    }).encode()
    req = urllib.request.Request(
        f'https://api.telegram.org/bot{TG_TOKEN}/sendMessage',
        data=data, method='POST'
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            print('[TG] Enviado OK' if r.status == 200 else f'[TG] Status {r.status}')
    except Exception as e:
        print(f'[TG] Error: {e}')


def build_report(target_name: str, competitor_count: int,
                 db_count: int, result: dict) -> str:
    now  = datetime.utcnow().strftime('%d/%m/%Y %H:%M UTC')
    new  = result['new']
    alts = result['alt_urls']

    lines = [
        f'🔍 <b>Escaneo competencia — {target_name}</b>',
        f'📅 {now}',
        f'',
        f'📊 Ellos: {competitor_count} emisoras  |  Nosotros: {db_count}',
        f'🆕 Posibles nuevas: {len(new)}',
        f'🔄 URLs alternativas: {len(alts)}',
        f'✅ Ya tenemos: {competitor_count - len(new) - len(alts)}',
    ]

    if new:
        lines += ['', '━━ <b>POSIBLES NUEVAS</b> ━━']
        for cs in new[:MAX_REPORT_ITEMS]:
            lines.append(f'• {cs["nombre"]}')
            lines.append(f'  <code>{cs["url"][:80]}</code>')
        if len(new) > MAX_REPORT_ITEMS:
            lines.append(f'  … y {len(new) - MAX_REPORT_ITEMS} más')

    if alts:
        lines += ['', '━━ <b>URLs ALTERNATIVAS</b> ━━']
        for item in alts[:MAX_REPORT_ITEMS]:
            db_s = item['db_station']
            lines.append(f'• {item["nombre"]} (tenemos: {db_s["slug"]})')
            lines.append(f'  Ellos: <code>{item["url"][:70]}</code>')
        if len(alts) > MAX_REPORT_ITEMS:
            lines.append(f'  … y {len(alts) - MAX_REPORT_ITEMS} más')

    if not new and not alts:
        lines += ['', '💚 Sin novedades — ya tenemos todo lo que tiene la competencia.']

    return '\n'.join(lines)


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    # Soporte para --db desde CLI
    db_path = DB_PATH
    if '--db' in sys.argv:
        db_path = sys.argv[sys.argv.index('--db') + 1]

    if not os.path.exists(db_path):
        print(f'ERROR: DB no encontrada en {db_path}')
        sys.exit(1)

    db_stations = load_db_stations(db_path)
    print(f'DB cargada: {len(db_stations)} emisoras aprobadas')

    for target in TARGETS:
        print(f'\n→ Escaneando {target["name"]} ...')
        try:
            html = fetch(target['url'])
        except Exception as e:
            print(f'  ERROR fetch: {e}')
            continue

        parser_fn = globals().get(target['parser'])
        if not parser_fn:
            print(f'  ERROR: parser "{target["parser"]}" no encontrado')
            continue

        competitor_stations = parser_fn(html)
        print(f'  Emisoras extraídas: {len(competitor_stations)}')

        result = compare(competitor_stations, db_stations)
        print(f'  Nuevas: {len(result["new"])}  Alt URLs: {len(result["alt_urls"])}  Ya tenemos: {len(result["existing"])}')

        report = build_report(
            target_name=target['name'],
            competitor_count=len(competitor_stations),
            db_count=len(db_stations),
            result=result,
        )
        send_telegram(report)

    print('\nListo.')


if __name__ == '__main__':
    main()
