#!/usr/bin/env python3
"""
enrich.py — cruza emisoras.txt con radio-browser.info y genera emisoras.json
con logo, tags, homepage, codec, bitrate.

USO:
  ./enrich.py                    # solo Radio Browser
  ./enrich.py --icy              # también ICY headers para no-matcheadas
  ./enrich.py --workers 15       # hilos para ICY (default: 10)
  ./enrich.py --out emisoras.json
"""

import sys, os, re, json, argparse
import urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
EMISORAS   = os.path.join(SCRIPT_DIR, "emisoras.txt")
OUT_FILE   = os.path.join(SCRIPT_DIR, "emisoras.json")
UA         = "emisoras-enricher/1.0 (mammoli.ar; github.com/camammoli/radio)"
COUNTRIES  = ["AR", "UY"]
ICY_TIMEOUT = 4

# ── Normalización de URL ──────────────────────────────────────────────────────

def norm_url(url):
    return url.strip().lower().rstrip("/;").replace("­", "")

# ── Parser de emisoras.txt ────────────────────────────────────────────────────

def parse_emisoras(path):
    stations = []
    with open(path, encoding="utf-8") as f:
        lines = f.readlines()
    total = len(lines)
    for i, line in enumerate(lines):
        line = line.strip()
        m = re.match(r'^\[#?(\d+)\]\s+(.+)', line)
        if not m:
            continue
        numero = int(m.group(1))
        nombre = m.group(2).strip()
        url = ""
        for j in range(i + 1, min(i + 3, total)):
            sig = lines[j].strip()
            if sig and re.match(r"^https?://", sig):
                url = sig
                break
        if not url:
            continue
        provincia = ""
        pm = re.match(r'^(.+?)\s*\*\s*(.+)$', nombre)
        if pm:
            nombre    = pm.group(1).strip()
            provincia = pm.group(2).strip()
        stations.append({
            "n": numero, "nombre": nombre,
            "provincia": provincia, "url": url,
        })
    return stations

# ── Radio Browser API ─────────────────────────────────────────────────────────

def pick_server():
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

def fetch_rb(server, cc):
    url = (f"{server}/json/stations/search"
           f"?countrycode={cc}&hidebroken=false&limit=20000&order=clickcount&reverse=true")
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        return json.loads(resp.read())
    except Exception as e:
        print(f"  [!] Error RB {cc}: {e}", file=sys.stderr)
        return []

def build_rb_index(stations):
    """Índice URL normalizada → datos RB. Indexa url y url_resolved."""
    idx = {}
    for s in stations:
        for field in ("url_resolved", "url"):
            u = s.get(field, "").strip()
            if u:
                idx.setdefault(norm_url(u), s)
    return idx

def rb_to_meta(s):
    tags = [t.strip().lower() for t in s.get("tags", "").split(",") if t.strip()]
    return {
        "logo":     s.get("favicon", "").strip() or None,
        "tags":     tags,
        "homepage": s.get("homepage", "").strip() or None,
        "codec":    s.get("codec", "").upper() or None,
        "bitrate":  int(s.get("bitrate", 0)) or None,
        "rb_uuid":  s.get("stationuuid", ""),
        "rb_votes": int(s.get("votes", 0)),
        "rb_clicks": int(s.get("clickcount", 0)),
    }

# ── ICY headers ───────────────────────────────────────────────────────────────

def icy_meta(url):
    """Intenta extraer codec/bitrate de headers Icecast/Shoutcast."""
    try:
        req = urllib.request.Request(
            url, headers={"User-Agent": UA, "Icy-MetaData": "0"})
        resp = urllib.request.urlopen(req, timeout=ICY_TIMEOUT)
        headers = resp.headers
        resp.close()
        ct      = headers.get("Content-Type", "").lower()
        br_hdr  = headers.get("icy-br", "") or headers.get("x-audiocast-bitrate", "")
        codec = None
        if "aac" in ct or "aac" in url.lower():
            codec = "AAC"
        elif "ogg" in ct:
            codec = "OGG"
        elif "mpeg" in ct or "mp3" in ct:
            codec = "MP3"
        bitrate = int(br_hdr) if br_hdr.isdigit() else None
        return {"codec": codec, "bitrate": bitrate}
    except Exception:
        return {}

# ── Normalización de tags ─────────────────────────────────────────────────────

TAG_MAP = {
    # música
    "music": "música", "musica": "música", "música": "música",
    "pop": "pop", "rock": "rock", "cumbia": "cumbia", "folklore": "folklore",
    "folclore": "folklore", "tango": "tango", "jazz": "jazz", "reggaeton": "reggaetón",
    "reggaetón": "reggaetón", "tropical": "tropical", "cuarteto": "cuarteto",
    "electronica": "electrónica", "electrónica": "electrónica", "electronico": "electrónica",
    "clasica": "clásica", "clásica": "clásica", "classical": "clásica",
    "alternativa": "alternativa", "alternative": "alternativa",
    "hits": "hits", "top 40": "hits", "top40": "hits",
    # temática
    "news": "noticias", "noticias": "noticias", "talk": "noticias",
    "sports": "deportes", "deportes": "deportes",
    "christian": "cristiana", "religion": "cristiana", "gospel": "cristiana",
    "children": "infantil", "kids": "infantil",
    # formato
    "fm": None, "am": None, "radio": None, "argentina": None,
    "uruguay": None, "spanish": None, "castellano": None,
}

def normalize_tags(tags):
    seen, out = set(), []
    for t in tags:
        mapped = TAG_MAP.get(t.lower(), t.lower() if len(t) > 2 else None)
        if mapped and mapped not in seen:
            seen.add(mapped)
            out.append(mapped)
    return out[:6]  # máximo 6 tags por emisora

# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--icy",     action="store_true", help="Enriquecer con ICY headers")
    parser.add_argument("--workers", type=int, default=10)
    parser.add_argument("--out",     default=OUT_FILE)
    args = parser.parse_args()

    print(f"=== enrich.py  {datetime.now():%Y-%m-%d %H:%M} ===")

    # 1. Parsear emisoras.txt
    stations = parse_emisoras(EMISORAS)
    print(f"Emisoras en txt: {len(stations)}")

    # 2. Radio Browser
    server = pick_server()
    print(f"Servidor RB: {server}")
    rb_all = []
    for cc in COUNTRIES:
        data = fetch_rb(server, cc)
        print(f"  {cc}: {len(data)} estaciones")
        rb_all.extend(data)
    rb_idx = build_rb_index(rb_all)
    print(f"RB index: {len(rb_idx)} URLs")

    # 3. Cruzar
    matched = unmatched = 0
    enriched = []
    for s in stations:
        key = norm_url(s["url"])
        rb  = rb_idx.get(key)
        meta = rb_to_meta(rb) if rb else {"logo": None, "tags": [], "homepage": None,
                                           "codec": None, "bitrate": None,
                                           "rb_uuid": "", "rb_votes": 0, "rb_clicks": 0}
        meta["tags"] = normalize_tags(meta["tags"])
        entry = {**s, **meta}
        enriched.append(entry)
        if rb:
            matched += 1
        else:
            unmatched += 1

    print(f"Match RB: {matched}  |  Sin match: {unmatched}")

    # 4. ICY headers para las sin match
    if args.icy and unmatched > 0:
        no_match = [e for e in enriched if not e["rb_uuid"]]
        print(f"ICY headers para {len(no_match)} emisoras ({args.workers} workers)...")
        url_to_icy = {}
        with ThreadPoolExecutor(max_workers=args.workers) as ex:
            futs = {ex.submit(icy_meta, e["url"]): e["url"] for e in no_match}
            done = 0
            for f in as_completed(futs):
                done += 1
                if done % 20 == 0:
                    print(f"  {done}/{len(no_match)}...")
                url_to_icy[futs[f]] = f.result()
        for e in enriched:
            if not e["rb_uuid"] and e["url"] in url_to_icy:
                icy = url_to_icy[e["url"]]
                if icy.get("codec") and not e["codec"]:
                    e["codec"] = icy["codec"]
                if icy.get("bitrate") and not e["bitrate"]:
                    e["bitrate"] = icy["bitrate"]

    # 5. Guardar
    with open(args.out, "w", encoding="utf-8") as f:
        json.dump(enriched, f, ensure_ascii=False, indent=2)
    print(f"✓ Guardado: {args.out}  ({len(enriched)} emisoras)")

    # Resumen de cobertura
    with_logo    = sum(1 for e in enriched if e.get("logo"))
    with_tags    = sum(1 for e in enriched if e.get("tags"))
    with_codec   = sum(1 for e in enriched if e.get("codec"))
    with_homepage = sum(1 for e in enriched if e.get("homepage"))
    print(f"\nCobertura:")
    print(f"  logo:     {with_logo}/{len(enriched)} ({with_logo*100//len(enriched)}%)")
    print(f"  tags:     {with_tags}/{len(enriched)} ({with_tags*100//len(enriched)}%)")
    print(f"  codec:    {with_codec}/{len(enriched)} ({with_codec*100//len(enriched)}%)")
    print(f"  homepage: {with_homepage}/{len(enriched)} ({with_homepage*100//len(enriched)}%)")

if __name__ == "__main__":
    main()
