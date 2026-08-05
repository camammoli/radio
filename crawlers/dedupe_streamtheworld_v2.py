#!/usr/bin/env python3
"""
dedupe_streamtheworld_v2.py — detecta emisoras duplicadas que apuntan al
mismo stream real de streamtheworld.com por vías distintas (servidor de
borde fijo hardcodeado vs. API de redirección oficial) y oculta las
variantes frágiles cuando existe una variante robusta confirmada.

Por qué existe: streamtheworld.com asigna servidores de borde numéricos
(ej. 14983.live.streamtheworld.com:3690) que van quedando obsoletos con
el tiempo (DNS que deja de resolver, puertos que dejan de responder). La
forma robusta de referenciar una emisora de esa CDN es la API de
redirección (playerservices.streamtheworld.com/api/livestream-redirect/
CALLSIGN), que siempre resuelve a un servidor vivo al momento de la
petición. hunt_stations_v2.py no detecta este tipo de duplicado porque
dedupea por URL exacta (norm_url), y dos URLs de la misma emisora real
nunca son textualmente iguales.

Casos que resuelve automáticamente (--apply):
  - Un grupo (mismo callsign) tiene exactamente 1 variante de redirección
    y 1+ variantes de servidor fijo → oculta las fijas (approved=0),
    conserva la de redirección. Reversible (solo cambia approved).

Casos que NO resuelve solo, únicamente reporta (requieren criterio manual):
  - Grupo con 2+ variantes de redirección (ej. mismo stream en distintos
    formatos .aac/.m3u8) — no hay una respuesta obviamente correcta.
  - Grupo sin ninguna variante de redirección (todas fijas) — puede que
    ninguna funcione, o que haga falta buscar la URL de redirección real.
  - Emisoras con el campo `nombre` roto (contiene una URL en vez de un
    nombre — bug conocido de un crawler viejo).

USO:
  python3 crawlers/dedupe_streamtheworld_v2.py                # dry-run, solo reporta
  python3 crawlers/dedupe_streamtheworld_v2.py --apply         # aplica los casos seguros
  python3 crawlers/dedupe_streamtheworld_v2.py --apply --notify
  python3 crawlers/dedupe_streamtheworld_v2.py --db ruta.sqlite
"""

import sys
import os
import re
import argparse
from datetime import datetime

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from db.radio_db import get_db

REDIRECT_RE = re.compile(r'livestream-redirect/([A-Za-z0-9_]+)', re.I)
FIXED_EDGE_RE = re.compile(r'streamtheworld\.com(?::\d+)?/([A-Za-z0-9_]+)', re.I)


def callsign(url: str):
    """Extrae el identificador real detrás de una URL de streamtheworld.com,
    sea vía API de redirección o servidor de borde fijo. None si no matchea."""
    m = REDIRECT_RE.search(url)
    if m:
        return m.group(1).upper()
    m = FIXED_EDGE_RE.search(url)
    if m:
        seg = re.sub(r'\.(mp3|aac)$', '', m.group(1), flags=re.I)
        return seg.upper()
    return None


def find_groups(rows):
    """Agrupa filas de stations (approved=1) por callsign de streamtheworld."""
    groups = {}
    for r in rows:
        if 'streamtheworld.com' not in r['url'].lower():
            continue
        cs = callsign(r['url'])
        if not cs:
            continue
        groups.setdefault(cs, []).append(r)
    return {cs: entries for cs, entries in groups.items() if len(entries) > 1}


def send_telegram(token, chat_id, text):
    try:
        import urllib.request, urllib.parse
        params = urllib.parse.urlencode({"chat_id": chat_id, "text": text})
        url = f"https://api.telegram.org/bot{token}/sendMessage"
        req = urllib.request.Request(
            url, data=params.encode(), method="POST",
            headers={"Content-Type": "application/x-www-form-urlencoded"}
        )
        urllib.request.urlopen(req, timeout=5).close()
    except Exception:
        pass


def load_telegram_config():
    tg_token = os.environ.get("TG_TOKEN", "")
    tg_chat_id = os.environ.get("TG_CHAT_ID", "")
    try:
        conf_path = os.path.join(os.path.dirname(__file__), "..", "web", "config.php")
        if os.path.exists(conf_path):
            with open(conf_path) as f:
                content = f.read()
            if not tg_token:
                m = re.search(r"TG_TOKEN['\"]?\s*,?\s*'([^']+)'", content)
                if m: tg_token = m.group(1)
            if not tg_chat_id:
                m = re.search(r"TG_CHAT_ID['\"]?\s*,?\s*'([^']+)'", content)
                if m: tg_chat_id = m.group(1)
    except Exception:
        pass
    return tg_token, tg_chat_id


def main():
    parser = argparse.ArgumentParser(description="Deduplicador de emisoras streamtheworld.com")
    parser.add_argument("--apply",  action="store_true", help="Aplica los casos seguros (default: dry-run)")
    parser.add_argument("--notify", action="store_true", help="Envía resumen a Telegram")
    parser.add_argument("--quiet",  action="store_true")
    parser.add_argument("--db",     default=None, help="Ruta alternativa a radio_v2.sqlite")
    args = parser.parse_args()

    def log(msg=""):
        if not args.quiet:
            print(msg)

    log(f"=== dedupe_streamtheworld_v2.py  {datetime.now():%Y-%m-%d %H:%M} ===")

    db = get_db(args.db)

    run_id = db.execute(
        "INSERT INTO crawler_runs (crawler, started_at) VALUES (?, datetime('now'))",
        ("dedupe-streamtheworld",)
    ).lastrowid
    db.commit()

    rows = db.execute(
        "SELECT id, slug, nombre, url FROM stations WHERE approved = 1"
    ).fetchall()
    log(f"Emisoras aprobadas: {len(rows)}")

    groups = find_groups(rows)
    log(f"Grupos streamtheworld con >1 entrada: {len(groups)}")

    auto_hidden = []      # casos resueltos automaticamente
    needs_review = []     # casos que requieren criterio manual

    for cs, entries in groups.items():
        redirect_entries = [e for e in entries if 'livestream-redirect' in e['url'].lower()]
        fixed_entries = [e for e in entries if e not in redirect_entries]

        if len(redirect_entries) == 1 and fixed_entries:
            keep = redirect_entries[0]
            for e in fixed_entries:
                auto_hidden.append((e, keep))
        else:
            needs_review.append((cs, entries, redirect_entries))

    # Bug conocido: nombre con una URL pegada adentro
    broken_names = [r for r in rows if re.match(r'^https?[:/]', r['nombre'] or '', re.I)]

    log(f"\nCasos seguros a {'aplicar' if args.apply else 'aplicar (dry-run, no se tocó nada)'}: {len(auto_hidden)}")
    for e, keep in auto_hidden:
        log(f"  ocultar {e['slug']!r} ({e['url']}) -> se prefiere {keep['slug']!r}")

    log(f"\nCasos que requieren revisión manual: {len(needs_review)}")
    for cs, entries, redirect_entries in needs_review:
        motivo = "sin variante de redirección" if not redirect_entries else "múltiples variantes de redirección"
        log(f"  [{cs}] {motivo}:")
        for e in entries:
            log(f"    - {e['slug']!r}: {e['url']}")

    if broken_names:
        log(f"\nEmisoras con `nombre` roto (contiene una URL): {len(broken_names)}")
        for r in broken_names:
            log(f"  {r['slug']!r}: nombre={r['nombre']!r}")

    applied = []
    if args.apply and auto_hidden:
        for e, keep in auto_hidden:
            db.execute(
                "UPDATE stations SET approved = 0, updated_at = datetime('now') WHERE id = ?",
                (e['id'],)
            )
            db.execute(
                """INSERT INTO station_events (station_id, event_type, old_value, new_value, detected_at)
                   VALUES (?, 'dedup_hidden', ?, ?, datetime('now'))""",
                (e['id'], e['url'], keep['slug'])
            )
            applied.append((e, keep))
        db.commit()
        log(f"\nAplicado: {len(applied)} emisoras ocultadas.")
    elif auto_hidden:
        log("\n(dry-run: no se aplicó nada. Usar --apply para ocultar las de arriba)")

    db.execute("""
        UPDATE crawler_runs
        SET finished_at = datetime('now'),
            stations_checked = ?,
            changes_detected = ?,
            notes = ?
        WHERE id = ?
    """, (len(rows), len(applied), f"{len(needs_review)} grupos pendientes de revisión manual", run_id))
    db.commit()
    db.close()

    if args.notify and (applied or needs_review or broken_names):
        tg_token, tg_chat_id = load_telegram_config()
        if tg_token and tg_chat_id:
            lines = [f"🔁 dedupe_streamtheworld_v2 — {datetime.now():%Y-%m-%d %H:%M}"]
            if applied:
                lines.append(f"\nOcultadas automáticamente ({len(applied)}):")
                for e, keep in applied:
                    lines.append(f"  • {e['nombre']} ({e['slug']}) → se prefirió {keep['slug']}")
            if needs_review:
                lines.append(f"\nPendientes de revisión manual ({len(needs_review)} grupos):")
                for cs, entries, redirect_entries in needs_review:
                    lines.append(f"  • {cs}: {', '.join(e['slug'] for e in entries)}")
            if broken_names:
                lines.append(f"\nNombres rotos detectados ({len(broken_names)}):")
                for r in broken_names:
                    lines.append(f"  • {r['slug']}")
            send_telegram(tg_token, tg_chat_id, "\n".join(lines))


if __name__ == "__main__":
    main()
