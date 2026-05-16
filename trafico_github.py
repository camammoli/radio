#!/usr/bin/env python3
"""
trafico_github.py — snapshot del tráfico del repo camammoli/radio via GitHub API.

La API de GitHub solo guarda 14 días de historial. Este script toma snapshots
cada 3 días y acumula los datos en trafico_github.json para no perder nada.

USO:
  ./trafico_github.py              # toma snapshot y actualiza trafico_github.json
  ./trafico_github.py --show       # muestra el resumen acumulado (no hace snapshot)
  ./trafico_github.py --quiet      # sin output (modo cron)

CONFIGURACIÓN:
  Requiere un Personal Access Token de GitHub con permiso "repo" (read).
  Guardarlo en ~/.config/radio_gh_token  (solo lectura para el usuario):
    echo "ghp_XXXXXXXXXX" > ~/.config/radio_gh_token && chmod 600 ~/.config/radio_gh_token

CRON — recomendado cada 3 días:
  0 8 */3 * * python3 /home/carlos/Scripts/radio/trafico_github.py --quiet 2>&1
"""

import sys
import os
import json
import urllib.request
import urllib.error
from datetime import datetime, timezone, date

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
DATA_FILE   = os.path.join(SCRIPT_DIR, "trafico_github.json")
TOKEN_FILE  = os.path.expanduser("~/.config/radio_gh_token")
REPO        = "camammoli/radio"
API_BASE    = "https://api.github.com"


def load_token():
    if os.path.exists(TOKEN_FILE):
        with open(TOKEN_FILE) as f:
            tok = f.read().strip()
            if tok:
                return tok
    # Fallback: variable de entorno
    return os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN") or ""


def gh_get(path, token):
    url = API_BASE + path
    req = urllib.request.Request(url, headers={
        "Accept":               "application/vnd.github+json",
        "Authorization":        f"Bearer {token}",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent":           "radio-trafico/1.0",
    })
    try:
        resp = urllib.request.urlopen(req, timeout=15)
        return json.loads(resp.read())
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        raise RuntimeError(f"HTTP {e.code} en {path}: {body[:200]}")
    except Exception as e:
        raise RuntimeError(f"Error en {path}: {e}")


def load_data():
    if os.path.exists(DATA_FILE):
        with open(DATA_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    return {
        "repo":      REPO,
        "snapshots": [],
        "views":     {},   # fecha → {count, uniques}
        "clones":    {},
        "referrers": {},   # domain → {count, uniques}  (acumulado por semanas)
    }


def save_data(data):
    with open(DATA_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)


def merge_daily(existing, new_items, key="timestamp"):
    """Acumula series diarias sin duplicar fechas (usa max del count)."""
    for item in new_items:
        day = item[key][:10]  # YYYY-MM-DD
        if day not in existing:
            existing[day] = {"count": item["count"], "uniques": item["uniques"]}
        else:
            # Tomar el mayor (la API puede corregir el día anterior)
            existing[day]["count"]   = max(existing[day]["count"],   item["count"])
            existing[day]["uniques"] = max(existing[day]["uniques"], item["uniques"])


def merge_referrers(existing, new_items):
    """Acumula referrers — sobrescribe con el snapshot más reciente."""
    for item in new_items:
        dom = item["referrer"]
        if dom not in existing:
            existing[dom] = {"count": item["count"], "uniques": item["uniques"]}
        else:
            existing[dom]["count"]   = max(existing[dom]["count"],   item["count"])
            existing[dom]["uniques"] = max(existing[dom]["uniques"], item["uniques"])


def do_snapshot(data, token, log):
    log(f"=== trafico_github.py  {datetime.now().strftime('%Y-%m-%d %H:%M')} ===")

    views_raw     = gh_get(f"/repos/{REPO}/traffic/views",              token)
    clones_raw    = gh_get(f"/repos/{REPO}/traffic/clones",             token)
    referrers_raw = gh_get(f"/repos/{REPO}/traffic/popular/referrers",  token)
    paths_raw     = gh_get(f"/repos/{REPO}/traffic/popular/paths",      token)

    merge_daily(data["views"],  views_raw.get("views",  []))
    merge_daily(data["clones"], clones_raw.get("clones", []))
    merge_referrers(data["referrers"], referrers_raw)

    # Guardar snapshot de rutas populares (pueden cambiar, no acumular)
    if "paths" not in data:
        data["paths"] = []
    data["paths"] = paths_raw  # solo el más reciente

    # Registrar cuándo se hizo el snapshot
    data["snapshots"].append(datetime.now(timezone.utc).isoformat()[:19] + "Z")
    # Mantener solo los últimos 100 timestamps
    data["snapshots"] = data["snapshots"][-100:]

    save_data(data)

    total_v = sum(v["count"]   for v in data["views"].values())
    uniq_v  = sum(v["uniques"] for v in data["views"].values())
    total_c = sum(v["count"]   for v in data["clones"].values())
    uniq_c  = sum(v["uniques"] for v in data["clones"].values())

    log(f"  Views  acumuladas : {total_v:,}  ({uniq_v:,} únicas)")
    log(f"  Clones acumulados : {total_c:,}  ({uniq_c:,} únicos)")
    log(f"  Días con datos    : views={len(data['views'])}, clones={len(data['clones'])}")
    log(f"  Referrers top     : {len(data['referrers'])}")
    log(f"✓ Guardado en {DATA_FILE}")


def do_show(data, log):
    log(f"=== Tráfico acumulado — {REPO} ===")
    log(f"  Snapshots tomados : {len(data['snapshots'])}")
    if data["snapshots"]:
        log(f"  Último snapshot   : {data['snapshots'][-1]}")
    log()

    # Views por día (últimas 30 entradas)
    days_v = sorted(data["views"].items())
    if days_v:
        log("  Views por día (recientes):")
        for day, v in days_v[-30:]:
            log(f"    {day}  {v['count']:>5} visitas  {v['uniques']:>4} únicas")
        log()

    # Clones por día
    days_c = sorted(data["clones"].items())
    if days_c:
        log("  Clones por día (recientes):")
        for day, v in days_c[-30:]:
            log(f"    {day}  {v['count']:>5} clones  {v['uniques']:>4} únicos")
        log()

    # Referrers
    if data.get("referrers"):
        log("  Referrers (acumulado):")
        for dom, v in sorted(data["referrers"].items(), key=lambda x: -x[1]["count"])[:15]:
            log(f"    {dom:<40} {v['count']:>5} vistas  {v['uniques']:>4} únicas")
        log()

    # Rutas populares
    if data.get("paths"):
        log("  Rutas populares (último snapshot):")
        for p in data["paths"][:10]:
            log(f"    {p.get('path','?'):<45} {p.get('count',0):>5}  {p.get('uniques',0):>4} únicos")


def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--show",  action="store_true", help="Solo mostrar datos acumulados")
    parser.add_argument("--quiet", action="store_true", help="Sin output")
    args = parser.parse_args()

    def log(msg=""):
        if not args.quiet:
            print(msg)

    data = load_data()

    if args.show:
        do_show(data, log)
        return

    token = load_token()
    if not token:
        print(
            "ERROR: no hay token de GitHub.\n"
            f"  Crear: echo 'ghp_XXX' > {TOKEN_FILE} && chmod 600 {TOKEN_FILE}",
            file=sys.stderr
        )
        sys.exit(1)

    try:
        do_snapshot(data, token, log)
    except RuntimeError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
