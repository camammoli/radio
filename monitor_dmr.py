#!/usr/bin/env python3
"""
monitor_dmr.py — Monitor de talkgroups DMR en BrandMeister
Muestra actividad en tiempo real en los TGs configurados.

REQUISITO: API key de BrandMeister (gratuita)
  1. Ir a https://brandmeister.network → Profile → API keys → Create
  2. Copiar el key generado
  3. Guardarlo en ~/.config/monitor_dmr.key  (recomendado)
     o pasarlo con --key API_KEY cada vez

USO:
  ./monitor_dmr.py                        # usa el key guardado, TGs Argentina
  ./monitor_dmr.py --key API_KEY          # key por parámetro
  ./monitor_dmr.py --tg 722 91 9          # TGs específicos
  ./monitor_dmr.py --demo                 # modo demo (sin conexión)
  ./monitor_dmr.py --help

DEPENDENCIAS:
  pip3 install websockets        (conexión tiempo real)
  pip3 install requests          (lookup radioid.net — opcional)
"""

import asyncio
import json
import sys
import os
import argparse
import signal
import time
from datetime import datetime
from collections import deque, OrderedDict

# ── Colores ANSI ─────────────────────────────────────────────────────────────
R  = '\033[91m'   # rojo
G  = '\033[92m'   # verde
Y  = '\033[93m'   # amarillo
B  = '\033[94m'   # azul
C  = '\033[96m'   # cyan
W  = '\033[97m'   # blanco
GR = '\033[90m'   # gris
BL = '\033[1m'    # bold
RE = '\033[0m'    # reset

# ── Nombres de TGs conocidos ─────────────────────────────────────────────────
TG_NAMES = {
    1:    "Local",
    2:    "Cluster",
    8:    "Regional",
    9:    "Local",
    91:   "World-wide",
    93:   "North America",
    722:  "Argentina",
    7220: "AR-0",
    7221: "AR-1",
    7222: "AR-2",
    7223: "AR-3",
    7224: "AR-4",
    7225: "AR-5",
    7226: "AR-6",
    7227: "AR-7",
    7228: "AR-8",
    7229: "AR-9",
}

DEFAULT_TGS = [722, 9, 91, 7223]
CONFIG_FILE = os.path.expanduser("~/.config/monitor_dmr.key")
WS_URL      = "wss://api.brandmeister.network/v2/device/?accesskey={key}"

# ── Estado global ────────────────────────────────────────────────────────────
calls       = deque(maxlen=20)      # historial de llamadas
active_calls = OrderedDict()        # TG: call_info (en el aire ahora)
active_tgs  = set(DEFAULT_TGS)
callsign_cache = {}                  # DMR_ID → {callsign, name, city}
last_update = None

# ── Helpers ───────────────────────────────────────────────────────────────────
def tg_label(tg_id):
    tg_id = int(tg_id)
    name  = TG_NAMES.get(tg_id, '')
    return f"TG{tg_id}" + (f" {name}" if name else "")

def fmt_dur(secs):
    if secs is None: return "en aire…"
    s = int(secs)
    return f"{s//60}m{s%60:02d}s" if s >= 60 else f"{s}s"

def fmt_time(iso):
    if not iso: return "--:--"
    try:    return iso[-8:][:5]
    except: return "--:--"

def lookup_callsign(dmr_id):
    """Consulta radioid.net por indicativo — resultado cacheado."""
    if not dmr_id or dmr_id in callsign_cache:
        return callsign_cache.get(dmr_id, {})
    try:
        import requests
        r = requests.get(
            f"https://radioid.net/api/dmr/user/?id={dmr_id}",
            timeout=3
        )
        if r.ok:
            results = r.json().get("results", [])
            if results:
                u = results[0]
                info = {
                    "callsign": u.get("callsign", str(dmr_id)),
                    "name":     u.get("fname", "") + " " + u.get("surname", ""),
                    "city":     u.get("city", ""),
                    "country":  u.get("country", ""),
                }
                callsign_cache[dmr_id] = info
                return info
    except Exception:
        pass
    callsign_cache[dmr_id] = {}
    return {}

# ── Render ───────────────────────────────────────────────────────────────────
def draw():
    ts = datetime.now().strftime("%H:%M:%S")
    tg_str = "  ".join(tg_label(t) for t in sorted(active_tgs))

    lines = []
    lines.append(f"\033[2J\033[H")   # clear + cursor home
    lines.append(f"{BL}{C}{'═'*64}{RE}")
    lines.append(f"{BL}{C}  📡  Monitor DMR  ·  BrandMeister{RE}")
    lines.append(f"{BL}{C}{'═'*64}{RE}")
    lines.append(f"  {GR}Actualizado: {ts}   TGs: {tg_str}{RE}")
    lines.append("")

    if active_calls:
        lines.append(f"  {BL}{G}▶ EN EL AIRE{RE}")
        for tg_id, c in active_calls.items():
            desde = fmt_time(c.get('start'))
            cs    = c.get('callsign', '?')
            link  = c.get('link', '?')[:22]
            extra = c.get('name', '')
            lines.append(
                f"  {G}●{RE}  {BL}{cs:<10}{RE}"
                f"  {Y}{tg_label(tg_id):<16}{RE}"
                f"  desde {GR}{desde}{RE}"
                f"  {GR}vía {link}{RE}"
            )
            if extra.strip():
                lines.append(f"      {GR}{extra.strip()}{RE}")
        lines.append("")

    if calls:
        lines.append(f"  {BL}{W}HISTORIAL{RE}")
        lines.append(
            f"  {GR}{'HORA':<7} {'INDICATIVO':<12} {'TG':<20} {'DUR':<9} ENLACE{RE}"
        )
        lines.append(f"  {GR}{'─'*58}{RE}")
        for c in reversed(list(calls)):
            hora = fmt_time(c.get('start'))
            cs   = c.get('callsign', '?')
            tg   = tg_label(c.get('tg', 0))
            dur  = fmt_dur(c.get('duration'))
            link = c.get('link', '?')[:18]
            lines.append(
                f"  {GR}{hora:<7}{RE}{BL}{cs:<12}{RE}"
                f"{Y}{tg:<20}{RE}{dur:<9}  {GR}{link}{RE}"
            )

    lines.append("")
    lines.append(f"  {GR}Ctrl+C para salir{RE}")
    print("".join(lines), end='', flush=True)

# ── Procesamiento de eventos WebSocket ───────────────────────────────────────
def process_event(data, tg_filter):
    """Procesa un evento del feed de BrandMeister."""
    try:
        event  = data.get('EventType', data.get('event', 0))
        tg_id  = int(data.get('DestinationID', data.get('talkgroup', 0)))
        src_id = data.get('SourceID', data.get('source_id'))
        cs     = data.get('SourceCall', data.get('SourceCallsign', str(src_id)))
        name   = data.get('SourceName', data.get('name', ''))
        link   = data.get('LinkName', data.get('link_name', data.get('repeater', '?')))
        start  = data.get('Start', data.get('start', ''))
        stop   = data.get('Stop', data.get('stop'))
        dur    = data.get('Duration', data.get('duration'))

        if tg_filter and tg_id not in tg_filter:
            return

        call_info = {
            'callsign': cs,
            'name':     name,
            'tg':       tg_id,
            'link':     link or '?',
            'start':    start,
            'duration': dur,
        }

        if event in (0, 'START', 'voice_start'):     # inicio de transmisión
            active_calls[tg_id] = call_info
        elif event in (1, 'END', 'voice_end') or stop:  # fin
            active_calls.pop(tg_id, None)
            if dur is None and start:
                try:
                    t0 = datetime.fromisoformat(start.replace(' ', 'T'))
                    dur = (datetime.utcnow() - t0).total_seconds()
                except Exception:
                    dur = None
            call_info['duration'] = dur
            calls.append(call_info)

        draw()
    except Exception:
        pass

# ── Conexión WebSocket ────────────────────────────────────────────────────────
async def monitor_live(api_key, tg_filter):
    import websockets
    url = WS_URL.format(key=api_key)
    print(f"Conectando a BrandMeister…")
    try:
        async with websockets.connect(url, ping_interval=20, ping_timeout=10) as ws:
            draw()
            async for message in ws:
                try:
                    data = json.loads(message)
                    process_event(data, tg_filter)
                except json.JSONDecodeError:
                    pass
    except Exception as e:
        print(f"\n{R}Error de conexión: {e}{RE}")
        print(f"Verificá el API key en {CONFIG_FILE}")
        sys.exit(1)

# ── Modo demo ────────────────────────────────────────────────────────────────
async def demo_mode():
    """Simula actividad DMR para mostrar la interfaz."""
    import random

    demo_calls = [
        {"SourceCall":"LU2MCA","SourceName":"Carlos Mammoli","DestinationID":722,"LinkName":"LU2MCA-Pi","SourceID":7221084},
        {"SourceCall":"LU3AGB","SourceName":"Gabriel","DestinationID":722,"LinkName":"LU3AGB-hotspot","SourceID":7220015},
        {"SourceCall":"LU1FDU","SourceName":"Fernando","DestinationID":7223,"LinkName":"BM-7242","SourceID":7221099},
        {"SourceCall":"LW1DAZ","SourceName":"Diego","DestinationID":91,"LinkName":"LW1DAZ-MMDVM","SourceID":7220088},
        {"SourceCall":"CX3CE","SourceName":"Ricardo","DestinationID":722,"LinkName":"CX-BM-1","SourceID":7480023},
    ]

    print(f"{C}Modo demo — simulando actividad DMR…{RE}")
    await asyncio.sleep(1)
    draw()

    for i, dc in enumerate(demo_calls):
        await asyncio.sleep(random.uniform(1.5, 3.0))
        tg   = dc['DestinationID']
        start = datetime.utcnow().isoformat()
        dc['EventType'] = 0
        dc['Start'] = start
        process_event(dc, None)
        await asyncio.sleep(random.uniform(2.0, 6.0))
        dur = random.uniform(3, 15)
        dc['EventType'] = 1
        dc['Duration'] = dur
        dc['Stop'] = datetime.utcnow().isoformat()
        process_event(dc, None)

    await asyncio.sleep(2)
    print(f"\n{G}Demo completado. Para el feed real usá: ./monitor_dmr.py --key TU_API_KEY{RE}\n")

# ── Main ─────────────────────────────────────────────────────────────────────
def main():
    global active_tgs

    parser = argparse.ArgumentParser(
        description="Monitor de talkgroups DMR en BrandMeister",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Obtener API key (gratis):
  https://brandmeister.network → Profile → API keys → Create

Guardar el key permanentemente:
  echo "TU_API_KEY" > ~/.config/monitor_dmr.key

Ejemplos:
  ./monitor_dmr.py --demo
  ./monitor_dmr.py --key abc123def456
  ./monitor_dmr.py --tg 722 9 91 7223
  ./monitor_dmr.py --tg 722 --key abc123def456
        """
    )
    parser.add_argument('--key',  help='API key de BrandMeister')
    parser.add_argument('--tg',   nargs='+', type=int, default=DEFAULT_TGS,
                        help=f'TGs a monitorear (default: {DEFAULT_TGS})')
    parser.add_argument('--demo', action='store_true',
                        help='Modo demo sin conectarse a BrandMeister')
    args = parser.parse_args()

    active_tgs = set(args.tg)

    signal.signal(signal.SIGINT, lambda *_: sys.exit(0))

    if args.demo:
        asyncio.run(demo_mode())
        return

    # Buscar API key
    api_key = args.key
    if not api_key and os.path.exists(CONFIG_FILE):
        with open(CONFIG_FILE) as f:
            api_key = f.read().strip()

    if not api_key:
        print(f"{R}Falta el API key de BrandMeister.{RE}")
        print(f"Obtené uno en: https://brandmeister.network → Profile → API keys")
        print(f"Guardalo en:   echo 'TU_KEY' > {CONFIG_FILE}")
        print(f"O usá:         ./monitor_dmr.py --key TU_KEY")
        print(f"\nPara ver la interfaz sin conectarte:  ./monitor_dmr.py --demo")
        sys.exit(1)

    try:
        asyncio.run(monitor_live(api_key, set(args.tg)))
    except KeyboardInterrupt:
        print(f"\n{GR}Monitor detenido.{RE}\n")

if __name__ == '__main__':
    main()
