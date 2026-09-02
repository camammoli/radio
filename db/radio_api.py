#!/usr/bin/env python3
"""
radio_api.py — cliente HTTP para api/crawler_ingest.php.

Reemplaza a radio_db.py para los crawlers que corren en GitHub Actions: los
runners no tienen forma de conectar directo a MySQL (Remote MySQL del hosting
no acepta IPs sin whitelistear, y los runners no tienen IP fija), así que en
vez de tocar la base directo, los crawlers piden/mandan datos por HTTPS a un
endpoint autenticado que corre en el propio servidor.

Uso:
    from db.radio_api import get, post
    rows = get("stations_check_context")
    post("check_streams_report", started_at=..., results=[...])

Config (variables de entorno, seteadas en el workflow desde secrets):
    RADIO_API_BASE   — default https://mammoli.ar/radio/api/crawler_ingest.php
    CRAWLER_TOKEN     — obligatorio, mismo valor que CRAWLER_TOKEN en config.php
"""

import json
import os
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = os.environ.get("RADIO_API_BASE", "https://mammoli.ar/radio/api/crawler_ingest.php")
TOKEN    = os.environ.get("CRAWLER_TOKEN", "")

# Al probar este endpoint se vieron 406 de ModSecurity en varios POST seguidos
# en poco tiempo (mismo patrón de fondo que sugerir.php/contacto.php/admin.php,
# ver memoria feedback_waf_post_bloqueado) — no se pudo aislar si es por ráfaga
# (rate/anomaly scoring) o por el cliente HTTP en sí: con pausas entre intentos,
# tanto un POST real de ~1270 resultados (156KB) como uno chico pasaron bien
# por `curl`. Se dejó `curl` (subprocess) en vez de urllib.request para el POST
# por ser lo que se probó funcionando de punta a punta contra producción; si
# vuelve a fallar con 406 en uso real (no en pruebas en ráfaga), revisar de
# nuevo — puede no ser el cliente sino simplemente repetir el intento.
UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36")


class RadioApiError(RuntimeError):
    pass


def _request(method: str, accion: str, params: dict | None = None,
             payload: dict | None = None, timeout: int = 60):
    if not TOKEN:
        raise RadioApiError("CRAWLER_TOKEN no configurado (variable de entorno)")

    if method == "GET":
        qs = urllib.parse.urlencode({**(params or {}), "accion": accion, "token": TOKEN})
        req = urllib.request.Request(f"{BASE_URL}?{qs}", headers={"User-Agent": UA})
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                raw = resp.read().decode("utf-8")
        except urllib.error.HTTPError as e:
            raw_err = e.read().decode("utf-8", errors="replace")
            raise RadioApiError(f"HTTP {e.code} en {accion}: {raw_err[:300]}") from e
    else:
        body = dict(payload or {})
        body["accion"] = accion
        data = json.dumps(body)
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False) as f:
            f.write(data)
            tmp_path = f.name
        try:
            proc = subprocess.run(
                ["curl", "-s", "--http1.1", "-X", "POST", BASE_URL,
                 "-H", f"X-Crawler-Token: {TOKEN}",
                 "-H", "Content-Type: application/json",
                 "-H", f"User-Agent: {UA}",
                 "--data-binary", f"@{tmp_path}",
                 "--max-time", str(timeout)],
                capture_output=True, text=True, check=False,
            )
        finally:
            os.unlink(tmp_path)
        if proc.returncode != 0:
            raise RadioApiError(f"curl falló (exit {proc.returncode}) en {accion}: {proc.stderr[:300]}")
        raw = proc.stdout

    try:
        out = json.loads(raw)
    except json.JSONDecodeError as e:
        raise RadioApiError(f"Respuesta no-JSON de {accion}: {raw[:300]}") from e

    if not out.get("ok"):
        raise RadioApiError(f"API error en {accion}: {out.get('error')}")
    return out.get("data")


def get(accion: str, **params):
    return _request("GET", accion, params=params)


def post(accion: str, **payload):
    return _request("POST", accion, payload=payload)
