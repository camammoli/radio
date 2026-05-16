#!/usr/bin/env python3
"""
crawler_radio_browser.py — descubre emisoras nuevas via radio-browser.info
y las agrega a emisoras.txt si no están ya incluidas.

USO:
  ./crawler_radio_browser.py                        # dry-run: solo muestra
  ./crawler_radio_browser.py --apply                # agrega al archivo
  ./crawler_radio_browser.py --apply --commit       # agrega + git commit
  ./crawler_radio_browser.py --apply --commit --push  # + git push
  ./crawler_radio_browser.py --max 30               # limitar a 30 nuevas
  ./crawler_radio_browser.py --no-verify            # no verificar cada URL

CRON — recomendado cada 2 semanas:
  0 9 1,15 * * /home/carlos/Scripts/radio/crawler_radio_browser.py --apply --commit --push --quiet 2>&1
"""

import sys
import os
import json
import socket
import urllib.request
import urllib.error
import subprocess
import re
import argparse
from datetime import datetime

# ── Configuración ──────────────────────────────────────────────────────────────
SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
EMISORAS    = os.path.join(SCRIPT_DIR, "emisoras.txt")
COUNTRIES   = ["AR", "UY"]
UA          = "emisoras-crawler/1.0 (mammoli.ar; github.com/camammoli/radio)"
API_TIMEOUT = 15
VER_TIMEOUT = 7
DEFAULT_MAX = 100

# Content-Types que acepta un reproductor de audio
AUDIO_TYPES = ("audio/", "video/", "application/ogg", "application/octet-stream",
               "mpegurl", "x-mpegurl", "vnd.apple.mpegurl")


# ── Helpers de API ─────────────────────────────────────────────────────────────

def pick_server():
    """
    Elige un servidor activo de radio-browser.info.
    La API recomienda usar el hostname (no IP directa) para que los
    virtual hosts respondan correctamente.
    """
    candidates = [
        "https://de1.api.radio-browser.info",
        "https://nl1.api.radio-browser.info",
        "https://at1.api.radio-browser.info",
    ]
    probe = "/json/stats"
    for base in candidates:
        try:
            req = urllib.request.Request(
                base + probe, headers={"User-Agent": UA}
            )
            urllib.request.urlopen(req, timeout=6).close()
            return base
        except Exception:
            continue
    return candidates[0]  # intentar igual con el primero


def fetch_stations(server, countrycode):
    """Descarga todas las estaciones verificadas de un país."""
    url = (
        f"{server}/json/stations/search"
        f"?countrycode={countrycode}"
        f"&lastcheckok=1"
        f"&hidebroken=true"
        f"&limit=10000"
        f"&order=clickcount"
        f"&reverse=true"
    )
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        resp = urllib.request.urlopen(req, timeout=API_TIMEOUT)
        return json.loads(resp.read())
    except Exception as e:
        print(f"  [!] Error descargando {countrycode}: {e}", file=sys.stderr)
        return []


# ── Helpers de archivo ─────────────────────────────────────────────────────────

def load_existing_urls(path):
    """Devuelve un set con todas las URLs (normalizadas) ya en emisoras.txt."""
    urls = set()
    try:
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if re.match(r"^https?://", line):
                    urls.add(_norm(line))
    except FileNotFoundError:
        pass
    return urls


def _norm(url):
    """Normaliza una URL para comparación: minúsculas, sin trailing junk."""
    # Eliminar soft-hyphens y caracteres de control raros
    url = url.replace("­", "")
    url = url.strip()
    # Bajar a minúsculas y quitar trailing /;
    return url.lower().rstrip("/;")


def get_next_number(path):
    """Devuelve el próximo número de entrada disponible."""
    max_n = 667
    try:
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                m = re.match(r"^\[#?(\d+)\]", line)
                if m:
                    max_n = max(max_n, int(m.group(1)))
    except FileNotFoundError:
        pass
    return max_n + 1


# ── Verificación de URL ────────────────────────────────────────────────────────

def verify(url):
    """
    Devuelve True si la URL responde con audio válido.
    Usa urllib para no depender de curl externo.
    """
    try:
        req = urllib.request.Request(
            url,
            headers={"User-Agent": UA, "Icy-MetaData": "0"}
        )
        resp = urllib.request.urlopen(req, timeout=VER_TIMEOUT)
        code = resp.status
        ct   = resp.headers.get("Content-Type", "").lower()
        resp.close()
        if code >= 400:
            return False
        # Si devuelve audio/* explícito, es bueno
        if any(t in ct for t in AUDIO_TYPES):
            return True
        # Streams Icecast/Shoutcast a veces dan text/html en el header
        # pero code 200 igual es señal suficiente
        return code == 200
    except urllib.error.HTTPError as e:
        return e.code < 400
    except Exception:
        return False


# ── Lógica principal ───────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Crawler de emisoras via radio-browser.info")
    parser.add_argument("--apply",     action="store_true", help="Escribir al archivo")
    parser.add_argument("--commit",    action="store_true", help="git commit tras agregar")
    parser.add_argument("--push",      action="store_true", help="git push tras commit")
    parser.add_argument("--no-verify", action="store_true", help="No verificar URLs individualmente")
    parser.add_argument("--max",       type=int, default=DEFAULT_MAX, help="Máximo de nuevas emisoras")
    parser.add_argument("--quiet",     action="store_true", help="Silencioso (solo errores)")
    args = parser.parse_args()

    do_verify = not args.no_verify

    def log(msg=""):
        if not args.quiet:
            print(msg)

    log(f"=== crawler_radio_browser.py  {datetime.now().strftime('%Y-%m-%d %H:%M')} ===")
    log(f"Países: {', '.join(COUNTRIES)}  |  Verificar URLs: {do_verify}  |  Max nuevas: {args.max}")
    log()

    # 1. Cargar URLs existentes
    existing = load_existing_urls(EMISORAS)
    log(f"URLs ya en archivo: {len(existing)}")

    # 2. Seleccionar servidor API
    server = pick_server()
    log(f"Servidor API: {server}")
    log()

    # 3. Descargar estaciones por país
    all_stations = []
    for cc in COUNTRIES:
        log(f"Descargando {cc}...", )
        stations = fetch_stations(server, cc)
        log(f"  {len(stations)} estaciones recibidas")
        all_stations.extend(stations)

    log(f"\nTotal descargado: {len(all_stations)} estaciones")

    # 4. Filtrar nuevas
    seen_in_run = set()
    candidates  = []

    for s in all_stations:
        url = (s.get("url_resolved") or s.get("url", "")).strip()
        if not url or not re.match(r"^https?://", url):
            continue

        key = _norm(url)
        if key in existing:
            continue
        if key in seen_in_run:
            continue

        seen_in_run.add(key)
        candidates.append(s)

    log(f"Candidatas nuevas (no en archivo): {len(candidates)}")

    if not candidates:
        log("Nada nuevo. Saliendo.")
        return

    # 5. Verificar y seleccionar
    log()
    nuevas      = []
    verificadas = 0
    fallidas    = 0

    for s in candidates:
        if len(nuevas) >= args.max:
            break

        url     = (s.get("url_resolved") or s.get("url", "")).strip()
        name    = s.get("name", "").strip()
        state   = s.get("state", "").strip()
        country = s.get("country", "Argentina").strip()
        codec   = s.get("codec", "").upper()
        bitrate = s.get("bitrate", 0)

        if do_verify:
            ok = verify(url)
            if ok:
                verificadas += 1
                status = "✓"
            else:
                fallidas += 1
                status = "✗"
                log(f"  {status} [{fallidas:3d} fail] {name}")
                continue
        else:
            status = "?"

        location = state if state else country
        codec_info = f" [{codec} {bitrate}kbps]" if codec and bitrate else ""

        log(f"  {status} {name}{codec_info}  ({location})")
        nuevas.append({
            "name":     name,
            "url":      url,
            "location": location,
        })

    log()
    log(f"Nuevas verificadas: {len(nuevas)}"
        + (f"  |  Fallidas: {fallidas}" if do_verify else ""))

    if not nuevas:
        log("Sin emisoras nuevas válidas.")
        return

    # 6. Generar texto a agregar
    next_n  = get_next_number(EMISORAS)
    bloque  = "\n"
    for s in nuevas:
        bloque += f"[#{next_n}] {s['name']} * {s['location']}\n{s['url']}\n\n"
        next_n += 1

    # 7. Aplicar o mostrar
    if not args.apply:
        log("\n── DRY-RUN: bloque a agregar ──────────────────────────────────")
        log(bloque)
        log("── Pasá --apply para escribir al archivo. ─────────────────────")
        return

    with open(EMISORAS, "a", encoding="utf-8") as f:
        f.write(bloque)
    log(f"✓ {len(nuevas)} emisoras agregadas a {EMISORAS}")

    # 8. Git commit
    if args.commit:
        msg = (
            f"crawler: +{len(nuevas)} emisoras nuevas via radio-browser.info\n\n"
            f"Países: {', '.join(COUNTRIES)}\n"
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

    # 9. Git push
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
