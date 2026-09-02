#!/usr/bin/env python3
"""
check_streams_v2.py — verifica los streams de la DB y detecta cambios de estado.

Desde TKT-0720 (migración a MySQL) este script ya NO toca la base directo —
solo hace el trabajo de red (chequear cada URL, leer ICY) y manda el resultado
a api/crawler_ingest.php, que es quien escribe (mismo motor que usa el resto
del sitio). Ver db/radio_api.py.

Detecta y registra en station_events (server-side, en el endpoint):
  - went_down   (ok/timeout → muerto, después de N fallos consecutivos)
  - came_back   (muerto → ok)
  - icy_gained  (icy_supported 0 → 1)
  - icy_lost    (icy_supported 1 → 0)

USO:
  python3 crawlers/check_streams_v2.py                 # verifica y muestra resumen
  python3 crawlers/check_streams_v2.py --notify        # envía eventos nuevos a Telegram
  python3 crawlers/check_streams_v2.py --workers 40    # hilos paralelos (default 30)
  python3 crawlers/check_streams_v2.py --timeout 7     # segundos por URL (default 5)
  python3 crawlers/check_streams_v2.py --quiet         # sin output
  python3 crawlers/check_streams_v2.py --icy           # también verifica ICY metadata

Variables de entorno requeridas: CRAWLER_TOKEN (+ opcional RADIO_API_BASE).
"""

import sys
import os
import re
import time
import socket
import argparse
import urllib.request
import urllib.error
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from db.radio_api import get, post

UA          = "radio-checker/2.0 (mammoli.ar)"


# ── HTTP check ────────────────────────────────────────────────────────────────

def check_url(url: str, timeout: int) -> dict:
    t0 = time.monotonic()
    try:
        req = urllib.request.Request(
            url, headers={"User-Agent": UA, "Icy-MetaData": "1"}
        )
        resp = urllib.request.urlopen(req, timeout=timeout)
        code = resp.status
        headers = dict(resp.headers)
        resp.close()
        ms = int((time.monotonic() - t0) * 1000)

        icy_supported = 1 if headers.get("icy-metaint") else 0
        icy_name      = (headers.get("icy-name") or "").strip() or None

        if code >= 400:
            return {"estado": "muerto", "http_code": code, "ms": ms,
                    "icy_supported": 0, "icy_name": None}

        return {"estado": "ok", "http_code": code, "ms": ms,
                "icy_supported": icy_supported, "icy_name": icy_name}

    except urllib.error.HTTPError as e:
        ms = int((time.monotonic() - t0) * 1000)
        estado = "muerto" if e.code >= 400 else "ok"
        return {"estado": estado, "http_code": e.code, "ms": ms,
                "icy_supported": 0, "icy_name": None}
    except Exception:
        ms = int((time.monotonic() - t0) * 1000)
        return {"estado": "timeout", "http_code": None, "ms": ms,
                "icy_supported": 0, "icy_name": None}


# ── ICY metadata (StreamTitle) ────────────────────────────────────────────────

def _read_icy_title(url: str, timeout: int) -> str | None:
    """Lee StreamTitle del stream ICY vía socket raw.

    Intenta hasta 4 bloques de metadata porque algunos servidores envían
    el primer bloque vacío (meta_len=0) antes de incluir el StreamTitle.
    """
    try:
        m = re.match(r"https?://([^/:]+)(?::(\d+))?(/.*)$", url)
        if not m:
            return None
        host, port_s, path = m.group(1), m.group(2), m.group(3)
        port = int(port_s) if port_s else 80

        icy_timeout = max(timeout, 15)
        s = socket.create_connection((host, port), timeout=icy_timeout)
        req = (
            f"GET {path} HTTP/1.0\r\n"
            f"Host: {host}\r\n"
            f"User-Agent: {UA}\r\n"
            f"Icy-MetaData: 1\r\n"
            f"Connection: close\r\n\r\n"
        )
        s.sendall(req.encode())

        buf = b""
        while b"\r\n\r\n" not in buf:
            chunk = s.recv(4096)
            if not chunk:
                break
            buf += chunk

        header_part = buf.split(b"\r\n\r\n")[0].decode("utf-8", errors="replace")
        metaint = None
        for line in header_part.split("\r\n"):
            if line.lower().startswith("icy-metaint:"):
                try:
                    metaint = int(line.split(":", 1)[1].strip())
                except ValueError:
                    pass

        if not metaint:
            s.close()
            return None

        audio_buf = buf.split(b"\r\n\r\n", 1)[1]
        for _ in range(4):
            needed = metaint - len(audio_buf)
            if needed > 0:
                while needed > 0:
                    chunk = s.recv(min(needed, 4096))
                    if not chunk:
                        s.close()
                        return None
                    audio_buf += chunk
                    needed -= len(chunk)

            if len(audio_buf) < metaint:
                s.close()
                return None

            audio_buf = audio_buf[metaint:]

            meta_len_byte = s.recv(1)
            if not meta_len_byte:
                s.close()
                return None
            meta_len = meta_len_byte[0] * 16

            if meta_len == 0:
                continue

            meta_buf = b""
            while len(meta_buf) < meta_len:
                chunk = s.recv(meta_len - len(meta_buf))
                if not chunk:
                    break
                meta_buf += chunk

            s.close()
            meta_str = meta_buf.decode("utf-8", errors="replace").strip("\x00")
            m2 = re.search(r"StreamTitle='([^']*)'", meta_str)
            title = m2.group(1).strip() if m2 else None
            return title if title else None

        s.close()
        return None

    except Exception:
        return None


# ── Telegram notify ───────────────────────────────────────────────────────────

def _send_telegram(token: str, chat_id: str, text: str):
    try:
        import urllib.parse
        params = urllib.parse.urlencode({"chat_id": chat_id, "text": text})
        url = f"https://api.telegram.org/bot{token}/sendMessage"
        req = urllib.request.Request(
            url, data=params.encode(), method="POST",
            headers={"Content-Type": "application/x-www-form-urlencoded"}
        )
        urllib.request.urlopen(req, timeout=5).close()
    except Exception:
        pass


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Verificador de streams v2")
    parser.add_argument("--workers",  type=int, default=30)
    parser.add_argument("--timeout",  type=int, default=5)
    parser.add_argument("--notify",   action="store_true", help="Enviar eventos a Telegram")
    parser.add_argument("--icy",      action="store_true", help="Leer StreamTitle ICY")
    parser.add_argument("--quiet",    action="store_true")
    args = parser.parse_args()

    def log(msg=""):
        if not args.quiet:
            print(msg)

    started_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    log(f"=== check_streams_v2.py  {datetime.now():%Y-%m-%d %H:%M} ===")

    tg_token   = os.environ.get("TG_TOKEN", "")
    tg_chat_id = os.environ.get("TG_CHAT_ID", "")

    rows = get("stations_check_context")
    log(f"Emisoras a verificar: {len(rows)}")

    # Verificar en paralelo
    results_by_id = {}
    with ThreadPoolExecutor(max_workers=args.workers) as ex:
        futs = {ex.submit(check_url, r["url"], args.timeout): r for r in rows}
        done = 0
        for f in as_completed(futs):
            row = futs[f]
            results_by_id[row["id"]] = f.result()
            done += 1
            if done % 100 == 0:
                log(f"  {done}/{len(rows)}...")

    log("Verificación HTTP completa.")

    # Leer ICY StreamTitle donde corresponda (secuencial — ya son pocas: solo
    # las que dieron icy_supported=1 en el check HTTP)
    icy_titles = {}
    if args.icy:
        for row in rows:
            res = results_by_id.get(row["id"])
            if res and res["icy_supported"]:
                title = _read_icy_title(row["url"], args.timeout)
                if title:
                    icy_titles[row["id"]] = title

    # Armar payload de resultados
    api_results = []
    count_ok = count_dead = count_timeout = 0
    for row in rows:
        res = results_by_id.get(row["id"], {"estado": "timeout", "http_code": None,
                                             "ms": 0, "icy_supported": 0, "icy_name": None})
        nuevo = res["estado"]
        if nuevo == "ok": count_ok += 1
        elif nuevo == "timeout": count_timeout += 1
        else: count_dead += 1

        api_results.append({
            "station_id": row["id"],
            "estado": nuevo,
            "http_code": res["http_code"],
            "response_ms": res["ms"],
            "icy_supported": res["icy_supported"],
            "icy_name": res["icy_name"],
        })

    log("Enviando resultados a la API...")
    out = post(
        "check_streams_report",
        started_at=started_at,
        results=api_results,
        icy_titles=icy_titles,
    )

    log()
    log(f"OK: {out['ok_count']}  |  Timeout: {out['timeout_count']}  |  Muertos: {out['dead_count']}")
    log(f"Eventos detectados: {out['events_detected']}  |  Errores DB: {out['errors']}")

    if args.notify and tg_token and tg_chat_id:
        pending = out.get("pending_notify") or []
        if pending:
            lines = [f"📡 Radio AR — {len(pending)} cambio{'s' if len(pending) > 1 else ''}:"]
            icons = {
                "came_back":  "✅",
                "went_down":  "❌",
                "icy_gained": "♪ ",
                "icy_lost":   "  ",
            }
            for ev in pending:
                icon = icons.get(ev["event_type"], "•")
                lines.append(f"{icon} {ev['nombre']} ({ev['event_type']})")
            _send_telegram(tg_token, tg_chat_id, "\n".join(lines))
            log(f"✓ Telegram: {len(pending)} eventos notificados")


if __name__ == "__main__":
    main()
