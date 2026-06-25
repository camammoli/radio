#!/usr/bin/env python3
"""
hunt_stations_v2.py — descubre emisoras nuevas via radio-browser.info
y las inserta en la DB con approved=0 (requieren aprobación).

USO:
  python3 crawlers/hunt_stations_v2.py           # dry-run
  python3 crawlers/hunt_stations_v2.py --apply   # inserta en DB (approved=0)
  python3 crawlers/hunt_stations_v2.py --approve # inserta aprobadas directamente
  python3 crawlers/hunt_stations_v2.py --max 50
  python3 crawlers/hunt_stations_v2.py --quiet
"""

import sys
import os
import re
import json
import time
import argparse
import unicodedata
import urllib.request
import urllib.error
from datetime import datetime

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from db.radio_db import get_db

UA         = "emisoras-crawler/2.0 (mammoli.ar)"
COUNTRIES  = ["AR", "UY"]
VER_TIMEOUT = 6
DEFAULT_MAX = 100
AUDIO_TYPES = ("audio/", "video/", "application/ogg", "application/octet-stream",
               "mpegurl", "x-mpegurl", "vnd.apple.mpegurl")


def norm_url(url: str) -> str:
    return url.replace("\xad", "").strip().lower().rstrip("/;")


def _slug(nombre: str) -> str:
    nombre = nombre.lower()
    nombre = unicodedata.normalize("NFD", nombre)
    nombre = "".join(c for c in nombre if unicodedata.category(c) != "Mn")
    nombre = re.sub(r"[^a-z0-9]+", "-", nombre)
    return nombre.strip("-")


def unique_slug(db, base: str) -> str:
    slug = base
    n = 2
    while db.execute("SELECT 1 FROM stations WHERE slug = ?", (slug,)).fetchone():
        slug = f"{base}-{n}"
        n += 1
    return slug


def pick_server() -> str:
    for base in ["https://de1.api.radio-browser.info",
                 "https://nl1.api.radio-browser.info",
                 "https://at1.api.radio-browser.info"]:
        try:
            req = urllib.request.Request(base + "/json/stats", headers={"User-Agent": UA})
            urllib.request.urlopen(req, timeout=6).close()
            return base
        except Exception:
            continue
    return "https://de1.api.radio-browser.info"


def fetch_stations(server: str, cc: str) -> list:
    url = (f"{server}/json/stations/search"
           f"?countrycode={cc}&lastcheckok=1&hidebroken=true"
           f"&limit=10000&order=clickcount&reverse=true")
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        resp = urllib.request.urlopen(req, timeout=15)
        return json.loads(resp.read())
    except Exception as e:
        print(f"  [!] Error {cc}: {e}", file=sys.stderr)
        return []


def verify(url: str) -> bool:
    try:
        req = urllib.request.Request(
            url, headers={"User-Agent": UA, "Icy-MetaData": "0"})
        resp = urllib.request.urlopen(req, timeout=VER_TIMEOUT)
        code = resp.status
        ct   = resp.headers.get("Content-Type", "").lower()
        resp.close()
        if code >= 400:
            return False
        if any(t in ct for t in AUDIO_TYPES):
            return True
        return code == 200
    except urllib.error.HTTPError as e:
        return e.code < 400
    except Exception:
        return False


def main():
    parser = argparse.ArgumentParser(description="Crawler de nuevas emisoras v2")
    parser.add_argument("--apply",     action="store_true", help="Insertar en DB (approved=0)")
    parser.add_argument("--approve",   action="store_true", help="Insertar aprobadas (approved=1)")
    parser.add_argument("--no-verify", action="store_true")
    parser.add_argument("--max",       type=int, default=DEFAULT_MAX)
    parser.add_argument("--quiet",     action="store_true")
    parser.add_argument("--db",        default=None)
    args = parser.parse_args()

    do_insert  = args.apply or args.approve
    approved   = 1 if args.approve else 0

    def log(msg=""):
        if not args.quiet:
            print(msg)

    log(f"=== hunt_stations_v2.py  {datetime.now():%Y-%m-%d %H:%M} ===")

    db = get_db(args.db)

    run_id = db.execute(
        "INSERT INTO crawler_runs (crawler, started_at) VALUES (?, datetime('now'))",
        ("hunt-stations",)
    ).lastrowid
    db.commit()

    # URLs ya en DB
    existing = {
        norm_url(r["url"])
        for r in db.execute("SELECT url FROM stations").fetchall()
    }
    log(f"URLs ya en DB: {len(existing)}")

    # Descargar de Radio Browser
    server = pick_server()
    log(f"Servidor API: {server}")
    all_stations = []
    for cc in COUNTRIES:
        data = fetch_stations(server, cc)
        log(f"  {cc}: {len(data)}")
        all_stations.extend(data)

    # Filtrar candidatas nuevas
    seen_in_run = set()
    candidates  = []
    for s in all_stations:
        url = (s.get("url_resolved") or s.get("url", "")).strip()
        if not url or not re.match(r"^https?://", url):
            continue
        key = norm_url(url)
        if key in existing or key in seen_in_run:
            continue
        seen_in_run.add(key)
        candidates.append(s)

    log(f"Candidatas nuevas: {len(candidates)}")

    if not candidates:
        log("Nada nuevo. Saliendo.")
        db.execute("UPDATE crawler_runs SET finished_at = datetime('now'), notes = 'sin candidatas' WHERE id = ?", (run_id,))
        db.commit()
        db.close()
        return

    # Verificar y insertar
    nuevas   = 0
    fallidas = 0

    for s in candidates:
        if nuevas >= args.max:
            break

        url     = (s.get("url_resolved") or s.get("url", "")).strip()
        nombre  = s.get("name", "").strip()
        state   = s.get("state", "").strip()
        country = s.get("country", "Argentina").strip()
        prov    = f"{state}, {country}" if state else country
        tags    = [t.strip().lower() for t in s.get("tags", "").split(",") if t.strip()]
        logo    = s.get("favicon", "").strip() or None
        homepage = s.get("homepage", "").strip() or None
        codec   = s.get("codec", "").upper() or None
        bitrate = int(s.get("bitrate", 0)) or None
        rb_uuid = s.get("stationuuid", "")
        rb_votes = int(s.get("votes", 0))
        rb_clicks = int(s.get("clickcount", 0))
        icy_sup = 1 if s.get("icy-metaint") else 0

        if not args.no_verify:
            if not verify(url):
                fallidas += 1
                continue

        slug = unique_slug(db, _slug(nombre))
        log(f"  + {nombre} ({prov}) → {slug}")

        if do_insert:
            try:
                db.execute("""
                    INSERT INTO stations
                        (slug, nombre, url, provincia, tags, logo, homepage,
                         codec, bitrate, rb_uuid, rb_votes, rb_clicks,
                         source, approved)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'radio-browser', ?)
                """, (slug, nombre, url, prov,
                      json.dumps(tags[:6], ensure_ascii=False),
                      logo, homepage, codec, bitrate,
                      rb_uuid, rb_votes, rb_clicks, approved))
                station_id = db.execute("SELECT last_insert_rowid()").fetchone()[0]

                # icy_cache inicial
                if icy_sup:
                    db.execute("""
                        INSERT INTO icy_cache (station_id, supported, last_checked)
                        VALUES (?, 1, datetime('now'))
                    """, (station_id,))

                db.commit()
            except Exception as e:
                log(f"    [!] Error insert: {e}")
                continue

        nuevas += 1

    db.execute("""
        UPDATE crawler_runs
        SET finished_at = datetime('now'),
            stations_checked = ?,
            changes_detected = ?,
            errors = ?
        WHERE id = ?
    """, (len(candidates), nuevas, fallidas, run_id))
    db.commit()
    db.close()

    log()
    log(f"Nuevas {'insertadas' if do_insert else '(dry-run)'}: {nuevas}  |  Fallidas: {fallidas}")
    if not do_insert:
        log("Pasá --apply para insertar (approved=0) o --approve para aprobar directamente.")


if __name__ == "__main__":
    main()
