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

# Causa real del 406 de ModSecurity, encontrada por bisección (2026-09-02):
# un User-Agent que DICE ser Chrome, sin el resto de las señales de un
# navegador real (Accept, Accept-Language, Sec-Fetch-*, etc.), dispara la
# detección de "browser impersonation" del WAF — probado exhaustivamente:
# el mismo payload exacto pasa siempre con UA honesto (curl default, o un
# UA de bot declarado) y falla siempre con UA de navegador falso, con o sin
# --http1.1, con cualquier tamaño de body. Lección: para un cliente que NO
# es un navegador, nunca fingir que lo es — declarar qué es en realidad.
UA = "radio-ar-crawler/1.0 (+https://mammoli.ar/radio)"


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
