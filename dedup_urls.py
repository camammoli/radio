#!/usr/bin/env python3
"""
dedup_urls.py — elimina entradas duplicadas por URL exacta en emisoras.txt.
Para cada grupo de duplicados conserva la entrada con más metadata en emisoras.json.
Criterio de "mejor": logo > homepage > tags > codec > nombre más largo.

Uso:
    python3 dedup_urls.py              # dry-run: muestra qué eliminaría
    python3 dedup_urls.py --apply      # aplica los cambios
"""

import re, json, sys
from pathlib import Path

SCRIPT_DIR   = Path(__file__).parent
EMISORAS_TXT = SCRIPT_DIR / 'emisoras.txt'
EMISORAS_JSON= SCRIPT_DIR / 'emisoras.json'

def parse_txt(path):
    """Devuelve lista de dicts: {num, nombre, url, bloque}"""
    text  = path.read_text(encoding='utf-8')
    lines = text.splitlines()
    entries = []
    i = 0
    while i < len(lines):
        m = re.match(r'^\[(#?\d+)\]\s+(.+)', lines[i])
        if m:
            num    = m.group(1)
            nombre = m.group(2).strip()
            url    = ''
            bloque_lines = [lines[i]]
            j = i + 1
            while j < len(lines):
                sig = lines[j].strip()
                if re.match(r'^\[(#?\d+)\]', sig):
                    break
                bloque_lines.append(lines[j])
                if not url and re.match(r'^https?://', sig):
                    url = sig
                j += 1
            entries.append({
                'num':    num,
                'nombre': nombre,
                'url':    url,
                'bloque': bloque_lines,
                'start':  i,
                'end':    j,
            })
            i = j
        else:
            i += 1
    return entries

def load_json_index(path):
    """Devuelve dict url → metadata dict"""
    if not path.exists():
        return {}
    data = json.loads(path.read_text(encoding='utf-8'))
    return {e['url']: e for e in data if e.get('url')}

def score(entry, json_idx):
    """Puntuación de calidad: más alto = mejor entrada."""
    meta = json_idx.get(entry['url'], {})
    return (
        bool(meta.get('logo')),
        bool(meta.get('homepage')),
        bool(meta.get('tags')),
        bool(meta.get('codec')),
        len(entry['nombre']),
    )

def main():
    apply = '--apply' in sys.argv

    entries  = parse_txt(EMISORAS_TXT)
    json_idx = load_json_index(EMISORAS_JSON)

    # Agrupar por URL (ignorar entradas sin URL)
    by_url = {}
    for e in entries:
        if not e['url']:
            continue
        by_url.setdefault(e['url'], []).append(e)

    dups = {url: group for url, group in by_url.items() if len(group) > 1}

    if not dups:
        print('Sin duplicados de URL exacta.')
        return

    print(f'URLs duplicadas encontradas: {len(dups)}\n')
    to_remove = set()

    for url, group in sorted(dups.items()):
        scored = sorted(group, key=lambda e: score(e, json_idx), reverse=True)
        best   = scored[0]
        losers = scored[1:]
        print(f'URL: {url}')
        print(f'  ✓ CONSERVAR [{best["num"]}] {best["nombre"]}')
        for e in losers:
            print(f'  ✗ ELIMINAR  [{e["num"]}] {e["nombre"]}')
            to_remove.add(e['num'])
        print()

    print(f'Total a eliminar: {len(to_remove)} entradas')

    if not apply:
        print('\nDry-run — usá --apply para aplicar los cambios.')
        return

    # Reconstruir el archivo sin las entradas eliminadas
    new_blocks = []
    for e in entries:
        if e['num'] in to_remove:
            continue
        new_blocks.append('\n'.join(e['bloque']))

    # Preservar líneas fuera de bloques [N] (encabezados, comentarios, líneas vacías entre bloques)
    text      = EMISORAS_TXT.read_text(encoding='utf-8')
    lines     = text.splitlines(keepends=True)

    # Marcar líneas que pertenecen a entradas eliminadas
    occupied = set()
    for e in entries:
        if e['num'] in to_remove:
            for i in range(e['start'], e['end']):
                occupied.add(i)

    new_lines = []
    prev_blank = False
    for i, line in enumerate(lines):
        if i in occupied:
            continue
        # Colapsar múltiples líneas en blanco consecutivas
        if line.strip() == '':
            if prev_blank:
                continue
            prev_blank = True
        else:
            prev_blank = False
        new_lines.append(line)

    EMISORAS_TXT.write_text(''.join(new_lines), encoding='utf-8')
    print(f'\n✓ emisoras.txt actualizado — {len(to_remove)} entradas eliminadas.')

if __name__ == '__main__':
    main()
