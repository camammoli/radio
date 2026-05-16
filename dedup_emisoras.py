#!/usr/bin/env python3
"""
dedup_emisoras.py — elimina entradas duplicadas de emisoras.txt.

Criterio: primera aparición de cada URL gana; las repeticiones se eliminan.
La normalización es la misma que usa el crawler para que ambos sean consistentes.

USO:
  ./dedup_emisoras.py              # dry-run: muestra duplicadas sin tocar el archivo
  ./dedup_emisoras.py --apply      # reescribe el archivo sin duplicadas
  ./dedup_emisoras.py --quiet      # sin output (modo cron)
"""

import sys
import os
import re
import argparse

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
EMISORAS    = os.path.join(SCRIPT_DIR, "emisoras.txt")


def _norm(url):
    url = url.replace("­", "")  # soft-hyphen
    return url.strip().lower().rstrip("/;")


def parse_blocks(path):
    """
    Devuelve lista de bloques. Cada bloque es una lista de líneas
    (incluyendo la línea de nombre, la URL y el salto final).
    Las líneas de encabezado (###) y vacías sueltas se preservan como
    bloques de un solo elemento.
    """
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
            # línea suelta no reconocida → preservar
            if current:
                current.append(line)
            else:
                blocks.append([line])

    if current:
        blocks.append(current)

    return blocks


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply",  action="store_true")
    parser.add_argument("--quiet",  action="store_true")
    args = parser.parse_args()

    def log(msg=""):
        if not args.quiet:
            print(msg)

    blocks   = parse_blocks(EMISORAS)
    seen     = set()
    kept     = []
    removed  = []

    for block in blocks:
        # Buscar URL dentro del bloque
        url_line = next(
            (l.strip() for l in block if re.match(r"^https?://", l.strip())),
            None
        )
        if url_line is None:
            # bloque sin URL (encabezado, línea vacía, etc.) → siempre conservar
            kept.append(block)
            continue

        key = _norm(url_line)
        if key in seen:
            name = next(
                (l.strip() for l in block if re.match(r"^\[#?\d+\]", l.strip())),
                url_line
            )
            removed.append((name, url_line))
            log(f"  ✗ DUPLICADA  {name}")
            log(f"              {url_line}")
        else:
            seen.add(key)
            kept.append(block)

    log(f"\nEntradas originales : {sum(1 for b in blocks if any(re.match(r'^https?://', l.strip()) for l in b))}")
    log(f"Duplicadas removidas: {len(removed)}")
    log(f"Entradas restantes  : {sum(1 for b in kept  if any(re.match(r'^https?://', l.strip()) for l in b))}")

    if not removed:
        log("Sin duplicadas. Nada que hacer.")
        return

    if not args.apply:
        log("\nPasá --apply para reescribir el archivo.")
        return

    with open(EMISORAS, "w", encoding="utf-8") as f:
        for block in kept:
            f.writelines(block)

    log(f"\n✓ {EMISORAS} reescrito sin duplicadas.")


if __name__ == "__main__":
    main()
