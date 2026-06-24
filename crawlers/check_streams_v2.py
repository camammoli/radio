#!/usr/bin/env python3
"""
check_streams_v2.py — verifica los streams de la DB y detecta cambios de estado.

Detecta y registra en station_events:
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
from datetime import datetime

# Importar helper de DB desde el directorio padre
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from db.radio_db import get_db

UA          = "radio-checker/2.0 (mammoli.ar)"
DOWN_AFTER  = 2   # fallos consecutivos para marcar went_down


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
    """Lee StreamTitle del stream ICY vía socket raw."""
    try:
        m = re.match(r"https?://([^/:]+)(?::(\d+))?(/.*)$", url)
        if not m:
            return None
        host, port_s, path = m.group(1), m.group(2), m.group(3)
        port = int(port_s) if port_s else 80

        s = socket.create_connection((host, port), timeout=timeout)
        req = (
            f"GET {path} HTTP/1.0\r\n"
            f"Host: {host}\r\n"
            f"User-Agent: {UA}\r\n"
            f"Icy-MetaData: 1\r\n"
            f"Connection: close\r\n\r\n"
        )
        s.sendall(req.encode())

        # Leer headers
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

        # Leer hasta el primer bloque de metadata
        audio_buf = buf.split(b"\r\n\r\n", 1)[1]
        needed = metaint - len(audio_buf)
        if needed > 0:
            while needed > 0:
                chunk = s.recv(min(needed, 4096))
                if not chunk:
                    break
                audio_buf += chunk
                needed -= len(chunk)

        if len(audio_buf) < metaint:
            s.close()
            return None

        meta_len_byte = s.recv(1)
        if not meta_len_byte:
            s.close()
            return None
        meta_len = meta_len_byte[0] * 16
        if meta_len == 0:
            s.close()
            return None

        meta_buf = b""
        while len(meta_buf) < meta_len:
            chunk = s.recv(meta_len - len(meta_buf))
            if not chunk:
                break
            meta_buf += chunk

        s.close()
        meta_str = meta_buf.decode("utf-8", errors="replace").strip("\x00")
        m2 = re.search(r"StreamTitle='([^']*)'", meta_str)
        return m2.group(1).strip() if m2 else None

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
    parser.add_argument("--db",       default=None, help="Ruta alternativa a radio_v2.sqlite")
    args = parser.parse_args()

    def log(msg=""):
        if not args.quiet:
            print(msg)

    log(f"=== check_streams_v2.py  {datetime.now():%Y-%m-%d %H:%M} ===")

    db = get_db(args.db)

    # Cargar config de Telegram desde entorno o config.py
    tg_token   = os.environ.get("TG_TOKEN", "")
    tg_chat_id = os.environ.get("TG_CHAT_ID", "")
    try:
        conf_path = os.path.join(os.path.dirname(__file__), "..", "web", "config.php")
        if os.path.exists(conf_path):
            with open(conf_path) as f:
                for line in f:
                    if "TG_TOKEN" in line and not tg_token:
                        m = re.search(r"'([^']+)'", line.split("TG_TOKEN")[1])
                        if m:
                            tg_token = m.group(1)
                    if "TG_CHAT_ID" in line and not tg_chat_id:
                        m = re.search(r"'([^']+)'", line.split("TG_CHAT_ID")[1])
                        if m:
                            tg_chat_id = m.group(1)
    except Exception:
        pass

    # Registrar inicio de run
    run_id = db.execute(
        "INSERT INTO crawler_runs (crawler, started_at) VALUES (?, datetime('now'))",
        ("check-streams",)
    ).lastrowid
    db.commit()

    # Cargar emisoras
    rows = db.execute("""
        SELECT s.id, s.slug, s.nombre, s.url,
               COALESCE(ss.estado, 'unknown')         AS prev_estado,
               COALESCE(ss.consecutive_failures, 0)   AS prev_fails,
               COALESCE(ic.supported, 0)              AS prev_icy
        FROM stations s
        LEFT JOIN stream_status ss ON ss.station_id = s.id
        LEFT JOIN icy_cache     ic ON ic.station_id = s.id
        WHERE s.approved = 1
        ORDER BY s.id
    """).fetchall()

    log(f"Emisoras a verificar: {len(rows)}")

    # Verificar en paralelo
    results = {}
    with ThreadPoolExecutor(max_workers=args.workers) as ex:
        futs = {ex.submit(check_url, r["url"], args.timeout): r for r in rows}
        done = 0
        for f in as_completed(futs):
            row = futs[f]
            results[row["id"]] = f.result()
            done += 1
            if done % 100 == 0:
                log(f"  {done}/{len(rows)}...")

    log(f"Verificación HTTP completa.")

    # Procesar resultados
    ts = datetime.utcnow().isoformat(timespec="seconds")
    count_ok = count_dead = count_timeout = 0
    events_detected = 0
    errors = 0

    for row in rows:
        sid   = row["id"]
        res   = results.get(sid, {"estado": "timeout", "http_code": None, "ms": 0,
                                   "icy_supported": 0, "icy_name": None})
        nuevo = res["estado"]
        prev  = row["prev_estado"]
        prev_fails = row["prev_fails"]
        prev_icy   = row["prev_icy"]

        if nuevo == "ok":
            count_ok += 1
            new_fails = 0
        elif nuevo == "timeout":
            count_timeout += 1
            new_fails = prev_fails + 1
        else:
            count_dead += 1
            new_fails = prev_fails + 1

        # UPSERT stream_status
        try:
            db.execute("""
                INSERT INTO stream_status
                    (station_id, estado, http_code, response_ms, consecutive_failures,
                     last_checked, last_ok, updated_at)
                VALUES (?, ?, ?, ?, ?, datetime('now'), ?, datetime('now'))
                ON CONFLICT(station_id) DO UPDATE SET
                    estado               = excluded.estado,
                    http_code            = excluded.http_code,
                    response_ms          = excluded.response_ms,
                    consecutive_failures = excluded.consecutive_failures,
                    last_checked         = excluded.last_checked,
                    last_ok              = CASE WHEN excluded.estado = 'ok'
                                               THEN excluded.last_ok
                                               ELSE stream_status.last_ok END,
                    updated_at           = excluded.updated_at
            """, (
                sid, nuevo, res["http_code"], res["ms"], new_fails,
                ts if nuevo == "ok" else None
            ))

            # stream_history
            db.execute("""
                INSERT INTO stream_history
                    (station_id, checked_at, estado, http_code, response_ms, icy_supported, icy_name)
                VALUES (?, datetime('now'), ?, ?, ?, ?, ?)
            """, (sid, nuevo, res["http_code"], res["ms"],
                  res["icy_supported"], res["icy_name"]))

        except Exception as e:
            errors += 1
            log(f"  [!] Error DB para {row['slug']}: {e}")
            continue

        # ── Detectar evento de estado ─────────────────────────────────────────
        ev = None
        if nuevo == "ok" and prev in ("muerto", "unknown"):
            ev = ("came_back", prev, nuevo)
        elif nuevo == "muerto" and new_fails >= DOWN_AFTER and prev != "muerto":
            ev = ("went_down", prev, nuevo)

        if ev:
            db.execute("""
                INSERT INTO station_events (station_id, event_type, old_value, new_value)
                VALUES (?, ?, ?, ?)
            """, (sid, ev[0], ev[1], ev[2]))
            events_detected += 1
            log(f"  ► {ev[0]:12s}  {row['nombre']}")

        # ── Detectar cambio ICY ───────────────────────────────────────────────
        cur_icy = res["icy_supported"]

        # Si el check HTTP ya dio icy_supported, actualizar icy_cache
        if cur_icy != prev_icy:
            ev_icy = "icy_gained" if cur_icy else "icy_lost"
            db.execute("""
                INSERT INTO station_events (station_id, event_type, old_value, new_value)
                VALUES (?, ?, ?, ?)
            """, (sid, ev_icy, str(prev_icy), str(cur_icy)))
            events_detected += 1
            log(f"  ► {ev_icy:12s}  {row['nombre']}")

        # UPSERT icy_cache
        stream_title = None
        if args.icy and cur_icy:
            stream_title = _read_icy_title(row["url"], args.timeout)

        db.execute("""
            INSERT INTO icy_cache (station_id, supported, icy_name, stream_title, last_checked)
            VALUES (?, ?, ?, ?, datetime('now'))
            ON CONFLICT(station_id) DO UPDATE SET
                supported    = excluded.supported,
                icy_name     = excluded.icy_name,
                stream_title = CASE WHEN excluded.stream_title IS NOT NULL
                                    THEN excluded.stream_title
                                    ELSE icy_cache.stream_title END,
                last_title_change = CASE
                    WHEN excluded.stream_title IS NOT NULL
                     AND excluded.stream_title != icy_cache.stream_title
                    THEN datetime('now')
                    ELSE icy_cache.last_title_change END,
                last_checked = excluded.last_checked
        """, (sid, cur_icy, res["icy_name"], stream_title))

    db.commit()

    # Cerrar run
    db.execute("""
        UPDATE crawler_runs
        SET finished_at = datetime('now'),
            stations_checked = ?,
            changes_detected = ?,
            errors = ?
        WHERE id = ?
    """, (len(rows), events_detected, errors, run_id))
    db.commit()

    log()
    log(f"OK: {count_ok}  |  Timeout: {count_timeout}  |  Muertos: {count_dead}")
    log(f"Eventos detectados: {events_detected}  |  Errores DB: {errors}")

    # Notificar eventos pendientes vía Telegram
    if args.notify and tg_token and tg_chat_id:
        pending = db.execute("""
            SELECT se.event_type, s.nombre, se.old_value, se.new_value
            FROM station_events se
            JOIN stations s ON s.id = se.station_id
            WHERE se.notified = 0
            ORDER BY se.detected_at DESC
            LIMIT 20
        """).fetchall()

        if pending:
            lines = [f"📡 Radio AR — {len(pending)} cambio{'s' if len(pending)>1 else ''}:"]
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

            db.execute("UPDATE station_events SET notified = 1 WHERE notified = 0")
            db.commit()
            log(f"✓ Telegram: {len(pending)} eventos notificados")

    db.close()


if __name__ == "__main__":
    main()
