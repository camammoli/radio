#!/usr/bin/env python3
"""
gist_sync.py — Sincroniza el directorio de radios con el gist de pisculichi.

Hace dos cosas:
  1. Actualiza el fork (camammoli) del gist con el contenido completo de la DB
  2. Si hubo emisoras nuevas en los últimos N días, postea un comentario en el
     gist original de pisculichi invitando a verlas en mammoli.ar/radio

Requiere: GITHUB_TOKEN en el entorno (con scope gist).
Uso:
    python3 gist_sync.py [--dry-run] [--days 7] [--db /path/to/radio_v2.sqlite]
"""

import argparse
import json
import os
import subprocess
import sys
import urllib.request
import urllib.error
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from db.radio_db import get_db

FORK_GIST_ID   = '21ce6e3ba07486bcd16a28cda967f0d9'   # camammoli fork
ORIG_GIST_ID   = 'fae88a2f5570ab22da53'               # pisculichi original
FORK_FILENAME  = 'radios_argentinas_mammoli.txt'

MAMMOLI_RADIO  = 'https://mammoli.ar/radio'


def load_stations(db) -> list[dict]:
    rows = db.execute("""
        SELECT nombre, url, COALESCE(provincia, 'Argentina') AS provincia
        FROM stations
        WHERE approved = 1
        ORDER BY provincia, nombre
    """).fetchall()
    return [dict(r) for r in rows]


def get_new_stations(db, days: int) -> list[dict]:
    rows = db.execute("""
        SELECT nombre, url, COALESCE(provincia, 'Argentina') AS provincia
        FROM stations
        WHERE approved = 1
          AND datetime(created_at) >= datetime('now', ?)
        ORDER BY created_at DESC
    """, (f'-{days} days',)).fetchall()
    return [dict(r) for r in rows]


def build_fork_content(stations: list[dict]) -> str:
    now = datetime.now(timezone.utc).strftime('%Y-%m-%d')
    lines = [
        f'# Radios Argentinas — {MAMMOLI_RADIO}',
        f'# {len(stations)} emisoras | Actualizado {now}',
        f'# Fuente: https://github.com/camammoli/radio',
        f'#',
        f'# Formato:  URL   <TAB>   Nombre   <TAB>   Provincia',
        f'# Para escuchar online (player, buscador, filtros): {MAMMOLI_RADIO}',
        '',
    ]

    by_prov = defaultdict(list)
    for s in stations:
        by_prov[s['provincia']].append(s)

    ORDER_FIRST = ['CABA', 'Buenos Aires']
    ORDER_LAST  = ['Argentina']

    def prov_sort(p):
        if p in ORDER_FIRST:
            return (0, ORDER_FIRST.index(p), p)
        if p in ORDER_LAST:
            return (2, 0, p)
        return (1, 0, p)

    for prov in sorted(by_prov.keys(), key=prov_sort):
        estaciones = sorted(by_prov[prov], key=lambda s: s['nombre'].lower())
        lines.append(f'# === {prov} ===')
        for s in estaciones:
            lines.append(f'{s["url"]}\t{s["nombre"]}\t{prov}')
        lines.append('')

    return '\n'.join(lines)


def gh_api(method: str, path: str, body: dict = None, token: str = '') -> dict | None:
    url = f'https://api.github.com{path}'
    data = json.dumps(body).encode() if body else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header('Authorization', f'token {token}')
    req.add_header('Accept', 'application/vnd.github+json')
    req.add_header('Content-Type', 'application/json')
    req.add_header('User-Agent', 'mammoli-radio/2.0')
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body_err = e.read().decode()
        print(f'  GitHub API error {e.code}: {body_err[:200]}', file=sys.stderr)
        return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--days', type=int, default=7,
                        help='Ventana de días para detectar emisoras nuevas (default: 7)')
    parser.add_argument('--db', default=None, help='Ruta a radio_v2.sqlite')
    args = parser.parse_args()

    token = os.environ.get('GITHUB_TOKEN') or os.environ.get('GITHUB_PAT') or ''
    if not token:
        try:
            token = subprocess.check_output(['gh', 'auth', 'token'],
                                            text=True, stderr=subprocess.DEVNULL).strip()
        except Exception:
            pass
    if not token:
        print('⚠ Sin GITHUB_TOKEN — se salta la parte de API.', file=sys.stderr)

    db = get_db(args.db)

    # ── 1. Cargar emisoras desde DB ───────────────────────────────────────────
    stations = load_stations(db)
    print(f'{len(stations)} emisoras aprobadas en DB')

    # ── 2. Actualizar fork ────────────────────────────────────────────────────
    fork_content = build_fork_content(stations)
    print(f'\nActualizando fork {FORK_GIST_ID}...')
    if args.dry_run:
        print('  [dry-run] Primeras 10 líneas:')
        for l in fork_content.splitlines()[:10]:
            print(f'    {l}')
    elif token:
        result = gh_api('PATCH', f'/gists/{FORK_GIST_ID}', {
            'description': f'Radios Argentinas — {len(stations)} emisoras | mammoli.ar/radio',
            'files': {FORK_FILENAME: {'content': fork_content}}
        }, token)
        if result:
            print(f'  ✓ Fork actualizado: {result.get("html_url", "")}')
        else:
            print('  ✗ Error actualizando fork')
    else:
        print('  (sin token, saltando)')

    # ── 3. Detectar emisoras nuevas ───────────────────────────────────────────
    new_stations = get_new_stations(db, args.days)
    print(f'\nEmisoras nuevas (últimos {args.days} días): {len(new_stations)}')

    db.close()

    if not new_stations:
        print('Sin novedades para postear en el gist original.')
        return

    # ── 4. Postear comentario en gist original ────────────────────────────────
    CAP = 10
    muestra = new_stations[:5] if len(new_stations) > CAP else new_stations
    extra   = '\n\n... y varias más.' if len(new_stations) > CAP else ''

    lineas = [f'{s["nombre"]} ({s["provincia"]})\n{s["url"]}' for s in muestra]
    comentario = '\n\n'.join(lineas) + extra

    print(f'\nPostear en gist original ({ORIG_GIST_ID}):')
    print(comentario)

    if args.dry_run:
        print('\n[dry-run] Comentario no enviado.')
    elif token:
        result = gh_api('POST', f'/gists/{ORIG_GIST_ID}/comments',
                        {'body': comentario}, token)
        if result:
            print(f'  ✓ Comentario publicado (id {result.get("id")})')
        else:
            print('  ✗ Error publicando comentario')
    else:
        print('  (sin token, saltando)')


if __name__ == '__main__':
    main()
