#!/bin/bash
# radio.sh — Escuchar radios argentinas desde la terminal
# Autor: Carlos Ariel Mammoli (LU2MCA) — Mendoza, Argentina
# Uso: radio.sh [búsqueda] [reproductor]
#   búsqueda    : parte del nombre o frecuencia (ej: "mendoza", "104.1")
#   reproductor : m=mplayer (default), v=vlc, p=mpv

SCRIPT=$(readlink -f "$0")
DIR_BASE=$(dirname "$SCRIPT")
EMISORAS="$DIR_BASE/emisoras.txt"

if [[ ! -f "$EMISORAS" ]]; then
    echo "Error: no se encuentra $EMISORAS"
    exit 1
fi

# Sin argumento: listar todo
if [[ -z "$1" ]]; then
    echo "Uso: $(basename "$0") <búsqueda> [m|v|p]"
    echo "     m = mplayer (default), v = cvlc, p = mpv"
    echo ""
    grep -v "#" "$EMISORAS" | grep -v "://" | head -60
    echo ""
    echo "... y más. Usá: $(basename "$0") mendoza"
    exit 0
fi

cuantos=$(grep -v "^#" "$EMISORAS" | grep -c -i "$1")

if [[ $cuantos -eq 0 ]]; then
    echo "No se encontraron emisoras para: '$1'"
    echo "Emisoras disponibles:"
    grep -v "^#" "$EMISORAS" | grep -v "://" | grep -i ""
    exit 1
elif [[ $cuantos -gt 1 ]]; then
    echo "Se encontraron $cuantos emisoras para '$1'. Ser más específico:"
    grep -v "^#" "$EMISORAS" | grep -v "://" | grep -i "$1"
    exit 0
fi

# Exactamente una: reproducir
linea=$(grep -n -m 1 -i "$1" "$EMISORAS" | cut -d ":" -f1)
linea=$((linea + 1))
url=$(awk "NR==$linea" "$EMISORAS")
nombre=$(grep -m 1 -i "$1" "$EMISORAS")

echo "▶ Reproduciendo: $nombre"
echo "  URL: $url"
echo "  (Ctrl+C para detener)"

case "${2,,}" in
    v) cvlc "$url" 2>/dev/null ;;
    p) mpv "$url" ;;
    *) mplayer -af lavcresample=44100 -cache 128 "$url" ;;
esac
