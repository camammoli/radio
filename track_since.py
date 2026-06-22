#!/usr/bin/env python3
"""
track_since.py — Registra la fecha en que cada URL entró en timeout/muerto.

Lee web/status.json y actualiza web/stream_since.json:
  - URLs que pasan a timeout/muerto: se anota la fecha de hoy (solo si no estaban ya)
  - URLs que vuelven a ok: se eliminan del registro

El resultado permite saber cuánto lleva cada stream caído y filtrar
los que llevan semanas sin recuperarse.

USO (llamado por check-streams.yml después de verificar_urls.sh):
    python3 track_since.py
"""

import json
from datetime import date
from pathlib import Path

SCRIPT_DIR = Path(__file__).parent
STATUS     = SCRIPT_DIR / 'web' / 'status.json'
SINCE      = SCRIPT_DIR / 'web' / 'stream_since.json'


def main():
    try:
        status = json.loads(STATUS.read_text('utf-8'))
    except Exception as e:
        print(f'track_since: no se pudo leer status.json: {e}')
        return

    try:
        since = json.loads(SINCE.read_text('utf-8'))
    except Exception:
        since = {}

    today = date.today().isoformat()
    n_added = n_removed = 0

    for url, info in status.get('streams', {}).items():
        estado = info.get('estado', '')
        if estado in ('timeout', 'muerto'):
            if url not in since:
                since[url] = {'since': today, 'estado': estado}
                n_added += 1
            else:
                since[url]['estado'] = estado  # actualiza si escaló de timeout a muerto
        else:
            if url in since:
                del since[url]
                n_removed += 1

    SINCE.write_text(json.dumps(since, ensure_ascii=False, indent=2), 'utf-8')

    n_muertos  = sum(1 for v in since.values() if v['estado'] == 'muerto')
    n_timeouts = sum(1 for v in since.values() if v['estado'] == 'timeout')
    print(f'track_since: tracked {n_muertos} muertas + {n_timeouts} timeout '
          f'(+{n_added} nuevas, -{n_removed} recuperadas)')


if __name__ == '__main__':
    main()
