#!/usr/bin/env python3
"""
recuperar_caidas.py — reemplaza URLs muertas buscando la emisora por nombre
en radio-browser.info y verificando la nueva URL antes de aplicar.

Lee las URLs marcadas como "muerto" en web/status.json, busca cada emisora
por nombre en la API (AR + UY), y propone o aplica el reemplazo.

USO:
  ./recuperar_caidas.py              # dry-run: muestra propuestas sin tocar nada
  ./recuperar_caidas.py --apply      # aplica los reemplazos en emisoras.txt
  ./recuperar_caidas.py --apply --commit --push  # + git
  ./recuperar_caidas.py --no-verify  # no verificar la nueva URL (más rápido, menos seguro)
  ./recuperar_caidas.py --min 0.5    # umbral de similitud de nombre (default: 0.6)

CRON — recomendado cada domingo después de verificar_urls.sh:
  0 4 * * 0 python3 /home/carlos/Scripts/radio/recuperar_caidas.py --apply --commit --push --quiet 2>&1
"""

import sys
import os
import json
import re
import urllib.request
import urllib.error
import urllib.parse
import subprocess
import argparse
from datetime import datetime
from difflib import SequenceMatcher

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
EMISORAS    = os.path.join(SCRIPT_DIR, "emisoras.txt")
STATUS_JSON = os.path.join(SCRIPT_DIR, "web", "status.json")
COUNTRIES   = ["AR", "UY"]
UA          = "emisoras-crawler/1.0 (mammoli.ar; github.com/camammoli/radio)"
API_TIMEOUT = 15
VER_TIMEOUT = 7
DEF_MIN_SIM = 0.6

AUDIO_TYPES = ("audio/", "video/", "application/ogg", "application/octet-stream",
               "mpegurl", "x-mpegurl", "vnd.apple.mpegurl")


# ── API helpers ────────────────────────────────────────────────────────────────

def pick_server():
    candidates = [
        "https://de1.api.radio-browser.info",
        "https://nl1.api.radio-browser.info",
        "https://at1.api.radio-browser.info",
    ]
    for base in candidates:
        try:
            req = urllib.request.Request(base + "/json/stats", headers={"User-Agent": UA})
            urllib.request.urlopen(req, timeout=6).close()
            return base
        except Exception:
            continue
    return candidates[0]


def search_by_name(server, name, countrycode):
    url = (
        f"{server}/json/stations/search"
        f"?name={urllib.parse.quote(name)}"
        f"&countrycode={countrycode}"
        f"&lastcheckok=1&hidebroken=true"
        f"&limit=5&order=clickcount&reverse=true"
    )
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        resp = urllib.request.urlopen(req, timeout=API_TIMEOUT)
        return json.loads(resp.read())
    except Exception:
        return []


# ── Helpers ────────────────────────────────────────────────────────────────────

def similarity(a, b):
    a = re.sub(r"[^\w\s]", "", a.lower())
    b = re.sub(r"[^\w\s]", "", b.lower())
    return SequenceMatcher(None, a, b).ratio()


def _norm(url):
    url = url.replace("­", "")
    return url.strip().lower().rstrip("/;")


def verify(url):
    try:
        req = urllib.request.Request(
            url, headers={"User-Agent": UA, "Icy-MetaData": "0"}
        )
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


def load_status(path):
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
        return {url: info["estado"] for url, info in data.get("streams", {}).items()}
    except Exception:
        return {}


def load_existing_urls(path):
    urls = set()
    try:
        with open(path, encoding="utf-8") as f:
            for line in f:
                s = line.strip()
                if re.match(r"^https?://", s):
                    urls.add(_norm(s))
    except FileNotFoundError:
        pass
    return urls


def parse_blocks(path):
    with open(path, "r", encoding="utf-8") as f:
        raw = f.read()
    blocks  = []
    current = []
    for line in raw.splitlines(keepends=True):
        stripped = line.strip()
        if stripped.startswith("###") or stripped == "":
            if current:
                blocks.append(current)
                current = []
            blocks.append([line])
        elif re.match(r"^\[#?\d+\]", stripped):
            if current:
                blocks.append(current)
            current = [line]
        elif re.match(r"^https?://", stripped):
            current.append(line)
            blocks.append(current)
            current = []
        else:
            if current:
                current.append(line)
            else:
                blocks.append([line])
    if current:
        blocks.append(current)
    return blocks


# ── Main ───────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Recupera streams caídos via radio-browser.info")
    parser.add_argument("--apply",     action="store_true", help="Aplicar reemplazos en emisoras.txt")
    parser.add_argument("--commit",    action="store_true", help="git commit")
    parser.add_argument("--push",      action="store_true", help="git push")
    parser.add_argument("--no-verify", action="store_true", help="No verificar la nueva URL")
    parser.add_argument("--min",       type=float, default=DEF_MIN_SIM, help="Umbral de similitud (0-1)")
    parser.add_argument("--quiet",     action="store_true", help="Sin output")
    args = parser.parse_args()

    def log(msg=""):
        if not args.quiet:
            print(msg)

    log(f"=== recuperar_caidas.py  {datetime.now().strftime('%Y-%m-%d %H:%M')} ===")

    # 1. Cargar status.json
    status    = load_status(STATUS_JSON)
    dead_urls = {url for url, estado in status.items() if estado == "muerto"}
    log(f"URLs muertas en status.json : {len(dead_urls)}")
    if not dead_urls:
        log("Sin URLs muertas. Saliendo.")
        return

    # 2. Parsear emisoras.txt
    blocks        = parse_blocks(EMISORAS)
    existing_urls = load_existing_urls(EMISORAS)

    # Mapa url_norm → índice de bloque
    url_to_block = {}
    for i, block in enumerate(blocks):
        url_line = next(
            (l.strip() for l in block if re.match(r"^https?://", l.strip())), None
        )
        if url_line:
            url_to_block[_norm(url_line)] = i

    # 3. Conectar API
    server = pick_server()
    log(f"Servidor API : {server}")
    log(f"Umbral nombre: {args.min:.0%}  |  Verificar: {not args.no_verify}")
    log()

    # 4. Buscar reemplazos
    replacements = []  # (block_idx, old_url, new_url, score, api_name)
    no_match     = []

    for dead_url in sorted(dead_urls):
        block_idx = url_to_block.get(_norm(dead_url))
        if block_idx is None:
            continue
        block = blocks[block_idx]

        # Nombre de la emisora: "[#42] Radio Nacional * Buenos Aires" → "Radio Nacional"
        name_line = next(
            (l.strip() for l in block if re.match(r"^\[#?\d+\]", l.strip())), None
        )
        if not name_line:
            continue
        m = re.match(r"^\[#?\d+\]\s+(.+?)(?:\s*\*.*)?$", name_line)
        if not m:
            continue
        station_name = m.group(1).strip()

        best_url      = None
        best_score    = 0
        best_api_name = ""

        for cc in COUNTRIES:
            for result in search_by_name(server, station_name, cc):
                api_name = result.get("name", "").strip()
                score    = similarity(station_name, api_name)
                if score < args.min:
                    continue
                url = (result.get("url_resolved") or result.get("url", "")).strip()
                if not url or not re.match(r"^https?://", url):
                    continue
                if _norm(url) == _norm(dead_url):
                    continue
                if _norm(url) in existing_urls:
                    continue
                if score > best_score:
                    best_score    = score
                    best_url      = url
                    best_api_name = api_name

        if not best_url:
            no_match.append(station_name)
            log(f"  ✗ {station_name}")
            log(f"      sin candidato en API")
            continue

        if not args.no_verify:
            if not verify(best_url):
                log(f"  ✗ {station_name}")
                log(f"      candidato no responde: {best_url}")
                no_match.append(station_name)
                continue

        log(f"  ✓ {station_name}")
        log(f"      {dead_url}")
        log(f"    → {best_url}  ({best_api_name}, {best_score:.0%})")
        replacements.append((block_idx, dead_url, best_url))

    log()
    log(f"Reemplazos encontrados : {len(replacements)}")
    log(f"Sin candidato/fallido  : {len(no_match)}")

    if not replacements:
        log("Nada para hacer. Saliendo.")
        return

    if not args.apply:
        log("\nPasá --apply para aplicar los cambios.")
        return

    # 5. Aplicar en los bloques
    for block_idx, old_url, new_url in replacements:
        block = blocks[block_idx]
        for i, line in enumerate(block):
            if line.strip() == old_url:
                block[i] = new_url + "\n"
                break

    # 6. Reescribir emisoras.txt
    with open(EMISORAS, "w", encoding="utf-8") as f:
        for block in blocks:
            f.writelines(block)
    log(f"✓ {len(replacements)} URLs reemplazadas en {EMISORAS}")

    # 7. Git commit
    if args.commit:
        n   = len(replacements)
        msg = (
            f"fix: recuperar {n} stream{'s' if n != 1 else ''} caído{'s' if n != 1 else ''} via radio-browser.info\n\n"
            f"Fecha: {datetime.now().strftime('%Y-%m-%d')}\n\n"
            f"Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
        )
        try:
            subprocess.run(
                ["git", "-C", SCRIPT_DIR, "add", "emisoras.txt"],
                check=True, capture_output=True
            )
            subprocess.run(
                ["git", "-C", SCRIPT_DIR, "commit", "-m", msg],
                check=True, capture_output=True
            )
            log("✓ git commit OK")
        except subprocess.CalledProcessError as e:
            print(f"[!] git commit falló: {e.stderr.decode()}", file=sys.stderr)
            return

    # 8. Git push
    if args.push:
        try:
            subprocess.run(
                ["git", "-C", SCRIPT_DIR, "push", "origin", "master"],
                check=True, capture_output=True
            )
            log("✓ git push OK")
        except subprocess.CalledProcessError as e:
            print(f"[!] git push falló: {e.stderr.decode()}", file=sys.stderr)


if __name__ == "__main__":
    main()
