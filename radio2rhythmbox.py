#!/usr/bin/env python3
"""
radio2rhythmbox.py — Importa emisoras de mammoli.ar/radio a Rhythmbox.

Uso:
  1. Cerrá Rhythmbox.
  2. Corré: python3 radio2rhythmbox.py
  3. Abrí Rhythmbox → sección "Radio".

En ejecuciones posteriores reemplaza las emisoras anteriores sin tocar
las estaciones que hayas agregado manualmente.
El script hace un backup de rhythmdb.xml antes de modificar.
"""

import re, shutil, sqlite3, sys
from datetime import datetime
from pathlib import Path
from xml.sax.saxutils import escape

RADIO_DB = Path(__file__).parent / "db" / "radio_v2.sqlite"
RDB      = Path.home() / ".local/share/rhythmbox/rhythmdb.xml"

MARK_START = "<!-- mammoli-radio-start -->"
MARK_END   = "<!-- mammoli-radio-end -->"

# ── Leer emisoras desde la DB local ───────────────────────────────────────────

if not RADIO_DB.exists():
    print(f"ERROR: no se encuentra {RADIO_DB}", file=sys.stderr)
    print("Corré el script desde la carpeta del repo radio.", file=sys.stderr)
    sys.exit(1)

print(f"Leyendo emisoras desde {RADIO_DB}...")
con = sqlite3.connect(RADIO_DB)
con.row_factory = sqlite3.Row
rows = con.execute(
    "SELECT nombre, url, provincia, tags, codec, bitrate "
    "FROM v_stations WHERE estado='ok' ORDER BY nombre"
).fetchall()
con.close()
stations = [dict(r) for r in rows]
print(f"  {len(stations)} emisoras disponibles.")

# ── Generar bloque XML ────────────────────────────────────────────────────────

def make_entry(s):
    title = escape(s.get("nombre") or "")
    uri   = escape(s.get("url") or "")
    genre = escape(", ".join(s.get("tags") or []) or "Radio Argentina")
    prov  = escape(s.get("provincia") or "")
    codec = (s.get("codec") or "").lower()
    br    = int(s.get("bitrate") or 0)
    lines = [
        '  <entry type="iradio">',
        f'    <title>{title}</title>',
        f'    <genre>{genre}</genre>',
        f'    <uri>{uri}</uri>',
        f'    <comment>{prov}</comment>',
    ]
    if br:    lines.append(f'    <bitrate>{br}</bitrate>')
    if codec: lines.append(f'    <media-type>audio/{codec}</media-type>')
    lines.append('  </entry>')
    return "\n".join(lines)

block = (
    MARK_START + "\n"
    + "\n".join(make_entry(s) for s in stations) + "\n"
    + MARK_END
)

# ── Leer / crear rhythmdb.xml ─────────────────────────────────────────────────

if RDB.exists():
    bak = RDB.with_suffix(f".xml.bak_{datetime.now().strftime('%Y%m%d_%H%M%S')}")
    shutil.copy2(RDB, bak)
    print(f"  Backup: {bak}")
    content = RDB.read_text(encoding="utf-8")
else:
    RDB.parent.mkdir(parents=True, exist_ok=True)
    content = '<?xml version="1.0" standalone="yes"?>\n<rhythmdb version="2.0">\n</rhythmdb>\n'
    print("  rhythmdb.xml no existía — creado desde cero.")

# ── Reemplazar bloque anterior o insertar al final ───────────────────────────

if MARK_START in content:
    content = re.sub(
        re.escape(MARK_START) + r".*?" + re.escape(MARK_END),
        block, content, flags=re.DOTALL
    )
    print("  Bloque anterior reemplazado.")
else:
    content = content.replace("</rhythmdb>", block + "\n</rhythmdb>")

RDB.write_text(content, encoding="utf-8")
print(f"\n✓ {len(stations)} emisoras escritas en {RDB}")
print("  Abrí Rhythmbox → sección «Radio».")
