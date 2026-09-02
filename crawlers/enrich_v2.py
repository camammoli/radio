#!/usr/bin/env python3
"""
enrich_v2.py — cruza emisoras en DB con radio-browser.info y actualiza
logo, tags, homepage, codec, bitrate, rb_uuid, rb_votes, rb_clicks.

También actualiza icy_cache.supported si detecta headers ICY.

USO:
  python3 crawlers/enrich_v2.py              # solo radio-browser
  python3 crawlers/enrich_v2.py --icy        # también verifica ICY para sin-match
  python3 crawlers/enrich_v2.py --workers 15
  python3 crawlers/enrich_v2.py --force      # re-enrich aunque ya tengan rb_uuid
  python3 crawlers/enrich_v2.py --quiet
"""

import sys
import os
import re
import json
import argparse
import urllib.request
import urllib.error
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from db.radio_api import get, post

UA         = "emisoras-enricher/2.0 (mammoli.ar)"
COUNTRIES  = ["AR", "UY"]
ICY_TIMEOUT = 4

TAG_MAP = {
    "music": "música", "musica": "música", "música": "música",
    "pop": "pop", "rock": "rock", "cumbia": "cumbia", "folklore": "folklore",
    "folclore": "folklore", "tango": "tango", "jazz": "jazz",
    "reggaeton": "reggaetón", "reggaetón": "reggaetón", "tropical": "tropical",
    "cuarteto": "cuarteto", "electronica": "electrónica", "electrónica": "electrónica",
    "clasica": "clásica", "clásica": "clásica", "classical": "clásica",
    "alternativa": "alternativa", "alternative": "alternativa",
    "hits": "hits", "top 40": "hits", "top40": "hits",
    "news": "noticias", "noticias": "noticias", "talk": "noticias",
    "sports": "deportes", "deportes": "deportes",
    "christian": "cristiana", "religion": "cristiana", "gospel": "cristiana",
    "children": "infantil", "kids": "infantil",
    "fm": None, "am": None, "radio": None, "argentina": None,
    "uruguay": None, "spanish": None, "castellano": None,
}


def norm_url(url: str) -> str:
    return url.replace("\xad", "").strip().lower().rstrip("/;")


def normalize_tags(tags: list) -> list:
    seen, out = set(), []
    for t in tags:
        mapped = TAG_MAP.get(t.lower(), t.lower() if len(t) > 2 else None)
        if mapped and mapped not in seen:
            seen.add(mapped)
            out.append(mapped)
    return out[:6]


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


def fetch_rb(server: str, cc: str) -> list:
    url = (f"{server}/json/stations/search"
           f"?countrycode={cc}&hidebroken=false&limit=20000&order=clickcount&reverse=true")
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        return json.loads(resp.read())
    except Exception as e:
        print(f"  [!] Error RB {cc}: {e}", file=sys.stderr)
        return []


def build_rb_index(stations: list) -> dict:
    idx = {}
    for s in stations:
        for field in ("url_resolved", "url"):
            u = s.get(field, "").strip()
            if u:
                idx.setdefault(norm_url(u), s)
    return idx


def icy_check(url: str) -> dict:
    try:
        req = urllib.request.Request(
            url, headers={"User-Agent": UA, "Icy-MetaData": "1"})
        resp = urllib.request.urlopen(req, timeout=ICY_TIMEOUT)
        headers = dict(resp.headers)
        resp.close()
        icy_supported = 1 if headers.get("icy-metaint") else 0
        icy_name = (headers.get("icy-name") or "").strip() or None
        ct = headers.get("Content-Type", "").lower()
        codec = None
        br   = None
        if "aac" in ct:
            codec = "AAC"
        elif "ogg" in ct:
            codec = "OGG"
        elif "mpeg" in ct or "mp3" in ct:
            codec = "MP3"
        br_hdr = headers.get("icy-br", "") or headers.get("x-audiocast-bitrate", "")
        if br_hdr.strip().isdigit():
            br = int(br_hdr.strip())
        return {"icy_supported": icy_supported, "icy_name": icy_name,
                "codec": codec, "bitrate": br}
    except Exception:
        return {"icy_supported": 0, "icy_name": None, "codec": None, "bitrate": None}


def main():
    parser = argparse.ArgumentParser(description="Enriquecimiento de emisoras v2")
    parser.add_argument("--icy",     action="store_true")
    parser.add_argument("--workers", type=int, default=10)
    parser.add_argument("--force",   action="store_true", help="Re-enrich todas, incluso con rb_uuid")
    parser.add_argument("--quiet",   action="store_true")
    args = parser.parse_args()

    def log(msg=""):
        if not args.quiet:
            print(msg)

    log(f"=== enrich_v2.py  {datetime.now():%Y-%m-%d %H:%M} ===")

    started_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

    # stations_check_context trae prev_icy; stations_full no lo tiene, así que
    # se combinan: la lista de emisoras sale de stations_full (tiene rb_uuid),
    # y prev_icy de stations_check_context (misma llave station_id).
    all_stations = [s for s in get("stations_full") if s["approved"]]
    prev_icy_by_id = {r["id"]: r["prev_icy"] for r in get("stations_check_context")}

    rows = all_stations if args.force else [s for s in all_stations if not s.get("rb_uuid")]
    for r in rows:
        r["prev_icy"] = prev_icy_by_id.get(r["id"], 0)
    log(f"Emisoras a enriquecer: {len(rows)}")

    # Radio Browser
    server = pick_server()
    log(f"Servidor RB: {server}")
    rb_all = []
    for cc in COUNTRIES:
        data = fetch_rb(server, cc)
        log(f"  {cc}: {len(data)} estaciones")
        rb_all.extend(data)
    rb_idx = build_rb_index(rb_all)
    log(f"RB index: {len(rb_idx)} URLs")

    matched = unmatched = 0
    events_detected = 0
    stations_payload = []

    for row in rows:
        key = norm_url(row["url"])
        rb  = rb_idx.get(key)

        if rb:
            tags = normalize_tags([t.strip().lower() for t in rb.get("tags", "").split(",") if t.strip()])
            logo      = rb.get("favicon", "").strip() or None
            homepage  = rb.get("homepage", "").strip() or None
            codec     = rb.get("codec", "").upper() or None
            bitrate   = int(rb.get("bitrate", 0)) or None
            rb_uuid   = rb.get("stationuuid", "")
            rb_votes  = int(rb.get("votes", 0))
            rb_clicks = int(rb.get("clickcount", 0))
            matched  += 1
        else:
            tags = []
            logo = homepage = codec = rb_uuid = None
            bitrate = rb_votes = rb_clicks = 0
            unmatched += 1

        stations_payload.append({
            "id": row["id"], "logo": logo, "tags": tags, "homepage": homepage,
            "codec": codec, "bitrate": bitrate, "rb_uuid": rb_uuid or None,
            "rb_votes": rb_votes, "rb_clicks": rb_clicks,
        })
    updates = len(stations_payload)
    icy_payload = []

    # ICY check para los sin match (o todos con --icy)
    icy_targets = [r for r in rows if not rb_idx.get(norm_url(r["url"]))] if not args.force else list(rows)
    if args.icy and icy_targets:
        log(f"\nICY check para {len(icy_targets)} emisoras ({args.workers} workers)...")
        icy_results = {}
        with ThreadPoolExecutor(max_workers=args.workers) as ex:
            futs = {ex.submit(icy_check, r["url"]): r for r in icy_targets}
            done = 0
            for f in as_completed(futs):
                row = futs[f]
                icy_results[row["id"]] = f.result()
                done += 1
                if done % 50 == 0:
                    log(f"  {done}/{len(icy_targets)}...")

        for row in icy_targets:
            ic = icy_results.get(row["id"], {})
            if not ic:
                continue
            icy_payload.append({
                "id": row["id"],
                "icy_supported": ic.get("icy_supported", 0),
                "icy_name": ic.get("icy_name"),
                "prev_icy": row["prev_icy"],
                "codec": ic.get("codec"),
                "bitrate": ic.get("bitrate"),
            })
            if bool(ic.get("icy_supported", 0)) != bool(row["prev_icy"]):
                ev = "icy_gained" if ic.get("icy_supported") else "icy_lost"
                events_detected += 1
                log(f"  ► {ev:12s}  {row['nombre']}")

    out = post(
        "enrich_report",
        started_at=started_at,
        stations=stations_payload,
        icy=icy_payload,
        notes=f"Match RB: {matched}, sin match: {unmatched}",
    )

    log()
    log(f"Match RB: {matched}  |  Sin match: {unmatched}")
    log(f"Actualizaciones: {out['stations_updated']}  |  Eventos ICY: {out['events_detected']}")


if __name__ == "__main__":
    main()
