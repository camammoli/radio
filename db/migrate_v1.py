#!/usr/bin/env python3
"""
migrate_v1.py — Migra datos de v1 (JSON planos) a radio_v2.sqlite.

Fuentes:
  emisoras.json        → stations + stream_status + icy_cache
  web/status.json      → stream_status (estado + ms)
  web/icy_stations.json → icy_cache (supported=1)
  web/plays.json       → plays (si existe)
  web/data/survey.csv  → surveys (si existe)

Uso:
  python3 db/migrate_v1.py [--dry-run]
"""

import json
import sqlite3
import re
import os
import sys
import hashlib
import csv
from pathlib import Path
from datetime import datetime

BASE   = Path(__file__).parent.parent          # raíz del repo
DB     = BASE / 'db' / 'radio_v2.sqlite'
SCHEMA = BASE / 'db' / 'schema.sql'

DRY_RUN = '--dry-run' in sys.argv

# ── Slug (misma lógica que PHP) ───────────────────────────────────────────────

_ACCENT = str.maketrans(
    'áàâäéèêëíìîïóòôöúùûüñç',
    'aaaaeeeeiiiioooouuuunc'
)

def _base_slug(s: dict) -> str:
    text = s['nombre']
    if s.get('provincia'):
        text += ' ' + s['provincia'].split(',')[0].strip()
    text = text.lower().translate(_ACCENT)
    text = re.sub(r'[^a-z0-9]+', '-', text)
    return text.strip('-')

def build_slug_index(stations: list) -> dict:
    """slug_base → n del primer dueño (para resolver colisiones igual que PHP)."""
    idx = {}
    for s in stations:
        b = _base_slug(s)
        if b not in idx:
            idx[b] = s['n']
    return idx

def full_slug(s: dict, idx: dict) -> str:
    b = _base_slug(s)
    return b if idx[b] == s['n'] else f'{b}-{s["n"]}'

# ── Helpers ───────────────────────────────────────────────────────────────────

def ip_hash(ip: str) -> str:
    return hashlib.sha256(ip.strip().encode()).hexdigest()[:16]

def log(msg: str):
    print(f'  {msg}')

# ── Migración principal ───────────────────────────────────────────────────────

def migrate():
    print(f'\nRadio AR v2 — Migración de datos')
    print(f'  DB:      {DB}')
    print(f'  Dry run: {DRY_RUN}\n')

    # ── Cargar fuentes ────────────────────────────────────────────────────────

    emisoras_path = BASE / 'emisoras.json'
    status_path   = BASE / 'web' / 'status.json'
    icy_path      = BASE / 'web' / 'icy_stations.json'
    plays_path    = BASE / 'web' / 'plays.json'
    survey_path   = BASE / 'web' / 'data' / 'survey.csv'

    with open(emisoras_path) as f:
        emisoras = json.load(f)
    log(f'emisoras.json:       {len(emisoras)} emisoras')

    with open(status_path) as f:
        status_raw = json.load(f)
    streams_status = status_raw.get('streams', {})
    log(f'status.json:         {len(streams_status)} streams')

    with open(icy_path) as f:
        icy_urls = set(json.load(f))
    log(f'icy_stations.json:   {len(icy_urls)} URLs con ICY')

    plays_data = {}
    if plays_path.exists():
        with open(plays_path) as f:
            plays_data = json.load(f)
        log(f'plays.json:          {len(plays_data)} emisoras, {sum(plays_data.values())} reproducciones')
    else:
        log('plays.json:          no existe (se omite)')

    surveys_rows = []
    if survey_path.exists():
        with open(survey_path, newline='') as f:
            reader = csv.reader(f)
            surveys_rows = list(reader)
        log(f'survey.csv:          {len(surveys_rows)} entradas')
    else:
        log('survey.csv:          no existe (se omite)')

    if DRY_RUN:
        print('\n[dry-run] Sin cambios en la base de datos.')
        return

    # ── Crear / abrir DB ──────────────────────────────────────────────────────

    DB.parent.mkdir(exist_ok=True)
    conn = sqlite3.connect(DB)
    conn.execute('PRAGMA journal_mode = WAL')
    conn.execute('PRAGMA foreign_keys = ON')

    with open(SCHEMA) as f:
        conn.executescript(f.read())

    # Verificar si ya hay datos
    existing = conn.execute('SELECT COUNT(*) FROM stations').fetchone()[0]
    if existing > 0:
        print(f'\n⚠️  La DB ya tiene {existing} emisoras.')
        resp = input('   ¿Borrar y re-migrar? [s/N] ').strip().lower()
        if resp != 's':
            print('Cancelado.')
            conn.close()
            return
        conn.execute('DELETE FROM surveys')
        conn.execute('DELETE FROM plays')
        conn.execute('DELETE FROM icy_cache')
        conn.execute('DELETE FROM stream_status')
        conn.execute('DELETE FROM stations')
        conn.commit()
        print('   Tablas vaciadas.\n')

    # ── stations ──────────────────────────────────────────────────────────────

    print('→ Migrando stations...')
    slug_idx = build_slug_index(emisoras)
    slug_collision = 0
    inserted_stations = {}  # url → id

    for s in emisoras:
        slug = full_slug(s, slug_idx)
        if slug_idx.get(_base_slug(s)) != s['n']:
            slug_collision += 1

        conn.execute('''
            INSERT INTO stations
              (n, slug, nombre, url, provincia, tags, codec, bitrate,
               homepage, logo, source, rb_uuid, rb_votes, rb_clicks)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ''', (
            s.get('n'),
            slug,
            s['nombre'],
            s['url'],
            s.get('provincia') or None,
            json.dumps(s.get('tags') or [], ensure_ascii=False),
            s.get('codec') or None,
            s.get('bitrate') or None,
            s.get('homepage') or None,
            s.get('logo') or None,
            'radio-browser' if s.get('rb_uuid') else 'manual',
            s.get('rb_uuid') or None,
            s.get('rb_votes') or 0,
            s.get('rb_clicks') or 0,
        ))

    conn.commit()
    total_stations = conn.execute('SELECT COUNT(*) FROM stations').fetchone()[0]
    log(f'stations: {total_stations} insertadas ({slug_collision} slugs con sufijo -n)')

    # Construir mapa url → id para las tablas siguientes
    for row in conn.execute('SELECT id, url FROM stations'):
        inserted_stations[row[1]] = row[0]

    # ── stream_status ─────────────────────────────────────────────────────────

    print('→ Migrando stream_status...')
    status_ok = status_sin_id = 0
    now = datetime.utcnow().isoformat(timespec='seconds')

    for url, data in streams_status.items():
        sid = inserted_stations.get(url)
        if sid is None:
            status_sin_id += 1
            continue
        estado = data.get('estado', 'unknown')
        ms     = data.get('ms')
        conn.execute('''
            INSERT INTO stream_status
              (station_id, estado, response_ms, last_checked, last_ok)
            VALUES (?,?,?,?,?)
        ''', (
            sid,
            estado,
            ms,
            now,
            now if estado == 'ok' else None,
        ))
        status_ok += 1

    conn.commit()
    log(f'stream_status: {status_ok} insertados, {status_sin_id} sin match de URL')

    # ── icy_cache ─────────────────────────────────────────────────────────────

    print('→ Migrando icy_cache...')
    icy_ok = icy_sin_id = 0

    for url in icy_urls:
        sid = inserted_stations.get(url)
        if sid is None:
            icy_sin_id += 1
            continue
        conn.execute('''
            INSERT INTO icy_cache (station_id, supported, last_checked)
            VALUES (?,1,?)
        ''', (sid, now))
        icy_ok += 1

    # Emisoras que NO están en icy_urls → supported=0
    for url, sid in inserted_stations.items():
        if url not in icy_urls:
            conn.execute('''
                INSERT OR IGNORE INTO icy_cache (station_id, supported, last_checked)
                VALUES (?,0,?)
            ''', (sid, now))

    conn.commit()
    log(f'icy_cache: {icy_ok} con ICY=1, {icy_sin_id} URLs ICY sin match (HTTPS no verificadas)')

    # ── plays (legado) ────────────────────────────────────────────────────────

    if plays_data:
        print('→ Migrando plays (legado)...')
        # plays.json tiene {nombre: count}. No hay URL, buscamos por nombre.
        nombre_to_id = {
            row[0]: row[1]
            for row in conn.execute('SELECT nombre, id FROM stations')
        }
        plays_ok = plays_sin_match = plays_total = 0

        for nombre, count in plays_data.items():
            sid = nombre_to_id.get(nombre)
            if sid is None:
                plays_sin_match += 1
                continue
            # Insertar count filas con source='legacy'
            conn.executemany(
                'INSERT INTO plays (station_id, source) VALUES (?,?)',
                [(sid, 'legacy')] * count
            )
            plays_ok    += 1
            plays_total += count

        conn.commit()
        log(f'plays: {plays_total} registros legacy en {plays_ok} emisoras, {plays_sin_match} sin match')

    # ── surveys ───────────────────────────────────────────────────────────────

    if surveys_rows:
        print('→ Migrando surveys...')
        nombre_to_id = {
            row[0]: row[1]
            for row in conn.execute('SELECT nombre, id FROM stations')
        }
        surveys_ok = surveys_skip = 0

        for row in surveys_rows:
            # Formato esperado: timestamp, rating, station_name, ip
            if len(row) < 3:
                surveys_skip += 1
                continue
            try:
                ts, rating_str, station_name = row[0], row[1], row[2]
                ip = row[3] if len(row) > 3 else ''
                rating = int(rating_str)
                if rating not in (-1, 0, 1):
                    surveys_skip += 1
                    continue
                sid = nombre_to_id.get(station_name)
                conn.execute('''
                    INSERT INTO surveys (station_id, rating, created_at, ip_hash)
                    VALUES (?,?,?,?)
                ''', (sid, rating, ts, ip_hash(ip) if ip else None))
                surveys_ok += 1
            except (ValueError, IndexError):
                surveys_skip += 1

        conn.commit()
        log(f'surveys: {surveys_ok} insertadas, {surveys_skip} omitidas')

    # ── Registro en crawler_runs ──────────────────────────────────────────────

    conn.execute('''
        INSERT INTO crawler_runs (crawler, started_at, finished_at, stations_checked, notes)
        VALUES ('migrate_v1', ?, ?, ?, ?)
    ''', (now, datetime.utcnow().isoformat(timespec='seconds'),
          total_stations, 'Migración inicial desde v1'))
    conn.commit()

    # ── Resumen ───────────────────────────────────────────────────────────────

    print('\n✓ Migración completada\n')
    for table in ['stations', 'stream_status', 'icy_cache', 'plays', 'surveys']:
        count = conn.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0]
        print(f'  {table:<20} {count:>6} filas')

    # Sanity check con la vista
    view_count = conn.execute('SELECT COUNT(*) FROM v_stations').fetchone()[0]
    ok_count   = conn.execute("SELECT COUNT(*) FROM v_stations WHERE estado='ok'").fetchone()[0]
    icy_count  = conn.execute('SELECT COUNT(*) FROM v_stations WHERE icy_supported=1').fetchone()[0]
    print(f'\n  v_stations (vista)   {view_count:>6} emisoras')
    print(f'    → ok:              {ok_count:>6}')
    print(f'    → con ICY:         {icy_count:>6}')

    conn.close()


if __name__ == '__main__':
    migrate()
