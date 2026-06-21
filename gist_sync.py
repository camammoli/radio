#!/usr/bin/env python3
"""
gist_sync.py — Sincroniza el directorio de radios con el gist de pisculichi.

Hace dos cosas:
  1. Actualiza el fork (camammoli) del gist con el contenido completo de emisoras.txt
  2. Si hubo emisoras nuevas en los últimos 7 días, postea un comentario en el
     gist original de pisculichi invitando a verlas en mammoli.ar/radio

Requiere: GITHUB_TOKEN en el entorno (con scope gist).
Uso:
    python3 gist_sync.py [--dry-run] [--since YYYY-MM-DD]
"""

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.request
import urllib.error
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

SCRIPT_DIR     = Path(__file__).parent
EMISORAS_TXT   = SCRIPT_DIR / 'emisoras.txt'

FORK_GIST_ID   = '21ce6e3ba07486bcd16a28cda967f0d9'   # camammoli fork
ORIG_GIST_ID   = 'fae88a2f5570ab22da53'               # pisculichi original
FORK_FILENAME  = 'radios_argentinas_mammoli.txt'

MAMMOLI_RADIO  = 'https://mammoli.ar/radio'

# ── Parser de emisoras.txt ────────────────────────────────────────────────────

def parse_emisoras(path: Path) -> list[dict]:
    """Devuelve lista de dicts {nombre, url, provincia}."""
    stations = []
    current_name = ''
    current_prov = 'Argentina'
    for line in path.read_text('utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        # Cabecera de emisora: [NNN] Nombre * Provincia, País
        m = re.match(r'^\[(?:#?\d+|#\d+)\]\s+(.+)', line)
        if m:
            rest = m.group(1)
            if ' * ' in rest:
                name_part, prov_part = rest.split(' * ', 1)
                current_name = name_part.strip()
                # Quitar ", Argentina" o ", País" del final
                prov_clean = re.sub(r',\s*(Argentina|Argentina\b.*)$', '', prov_part, flags=re.I).strip()
                current_prov = prov_clean or 'Argentina'
            else:
                current_name = rest.strip()
                current_prov = 'Argentina'
            continue
        # URL de stream
        if re.match(r'^https?://', line):
            if current_name:
                stations.append({
                    'nombre':   current_name,
                    'url':      line,
                    'provincia': current_prov,
                })
            current_name = ''
            current_prov = 'Argentina'
    return stations


# ── Generación del contenido del fork ────────────────────────────────────────

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

    # Agrupar por provincia, ordenar
    by_prov = defaultdict(list)
    for s in stations:
        by_prov[s['provincia']].append(s)

    # Primeras las nacionales/CABA, luego provincias alfabéticas, luego Argentina (sin provincia)
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
            # Columnas alineadas con tab para legibilidad
            url   = s['url']
            name  = s['nombre']
            lines.append(f'{url}\t{name}\t{prov}')
        lines.append('')

    return '\n'.join(lines)


# ── GitHub API helper ─────────────────────────────────────────────────────────

def gh_api(method: str, path: str, body: dict = None, token: str = '') -> dict | None:
    url = f'https://api.github.com{path}'
    data = json.dumps(body).encode() if body else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header('Authorization', f'token {token}')
    req.add_header('Accept', 'application/vnd.github+json')
    req.add_header('Content-Type', 'application/json')
    req.add_header('User-Agent', 'mammoli-radio/1.0')
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body_err = e.read().decode()
        print(f'  GitHub API error {e.code}: {body_err[:200]}', file=sys.stderr)
        return None


# ── Detección de emisoras nuevas (git log) ────────────────────────────────────

def get_new_stations(since_date: str) -> list[dict]:
    """
    Lee el git log de emisoras.txt desde `since_date` y extrae
    las emisoras que se agregaron (líneas [#NNN] añadidas).
    Devuelve lista de {nombre, numero, provincia}.
    """
    try:
        diff = subprocess.check_output(
            ['git', 'log', f'--since={since_date}', '-p', '--follow',
             '--format=', '--', 'emisoras.txt'],
            cwd=SCRIPT_DIR, text=True, stderr=subprocess.DEVNULL
        )
    except subprocess.CalledProcessError:
        return []

    new = []
    current_name = ''
    current_num  = ''
    for line in diff.splitlines():
        if not line.startswith('+'):
            current_name = ''
            current_num  = ''
            continue
        content = line[1:]
        m = re.match(r'^\[#?(\d+)\]\s+(.+)', content)
        if m:
            current_num  = m.group(1)
            rest = m.group(2)
            if ' * ' in rest:
                name_part, prov_part = rest.split(' * ', 1)
                prov_clean = re.sub(r',\s*Argentina.*$', '', prov_part, flags=re.I).strip()
                current_name = name_part.strip()
                current_prov = prov_clean or 'Argentina'
            else:
                current_name = rest.strip()
                current_prov = 'Argentina'
        elif re.match(r'^https?://', content) and current_name:
            # Filtrar estaciones de prueba/test
            if not re.search(r'(?:^|\s)TKT-\d+|prueba\s+tkt', current_name, re.I):
                new.append({
                    'numero':   current_num,
                    'nombre':   current_name,
                    'provincia': current_prov,
                    'url':      content.strip(),
                })
            current_name = ''

    return new


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--since', default='7 days ago',
                        help='Fecha desde la que detectar emisoras nuevas (default: "7 days ago")')
    args = parser.parse_args()

    token = os.environ.get('GITHUB_TOKEN') or os.environ.get('GITHUB_PAT') or ''
    if not token:
        # Intentar leer desde gh CLI (entorno de desarrollo local)
        try:
            token = subprocess.check_output(['gh', 'auth', 'token'],
                                            text=True, stderr=subprocess.DEVNULL).strip()
        except Exception:
            pass
    if not token:
        print('⚠ Sin GITHUB_TOKEN — se salta la parte de API.', file=sys.stderr)

    # ── 1. Parsear emisoras ───────────────────────────────────────────────────
    print(f'Leyendo {EMISORAS_TXT}...')
    stations = parse_emisoras(EMISORAS_TXT)
    print(f'  {len(stations)} emisoras parseadas')

    # ── 2. Actualizar fork ────────────────────────────────────────────────────
    fork_content = build_fork_content(stations)
    print(f'\nActualizando fork {FORK_GIST_ID}...')
    if args.dry_run:
        print('  [dry-run] Contenido del fork (primeras 10 líneas):')
        for l in fork_content.splitlines()[:10]:
            print(f'    {l}')
    elif token:
        result = gh_api('PATCH', f'/gists/{FORK_GIST_ID}', {
            'description': f'Radios Argentinas — {len(stations)} emisoras | mammoli.ar/radio',
            'files': {
                FORK_FILENAME: {'content': fork_content}
            }
        }, token)
        if result:
            print(f'  ✓ Fork actualizado: {result.get("html_url", "")}')
        else:
            print('  ✗ Error actualizando fork')
    else:
        print('  (sin token, saltando)')

    # ── 3. Detectar emisoras nuevas ───────────────────────────────────────────
    print(f'\nBuscando emisoras nuevas desde "{args.since}"...')
    new_stations = get_new_stations(args.since)
    print(f'  {len(new_stations)} emisoras nuevas')

    if not new_stations:
        print('Sin novedades para postear en el gist.')
        return

    # ── 4. Postear comentario en gist original ────────────────────────────────
    # Formato minimalista: solo nombre, provincia y URL de stream — como cualquier usuario del gist.
    # Si hay muchas emisoras nuevas, mostrar solo las primeras para no saturar el feed.
    CAP = 10
    if len(new_stations) > CAP:
        muestra = new_stations[:5]
        extra = '\n\n... y varias más.'
    else:
        muestra = new_stations
        extra = ''

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
