#!/bin/bash
# radio2.sh — Radio Argentina CLI v2
# Consulta la API REST, muestra ICY now-playing y contador de oyentes.
#
# USO:
#   radio2.sh                      # lista las 20 más escuchadas
#   radio2.sh <búsqueda>           # busca por nombre o provincia
#   radio2.sh <búsqueda> [m|v|p]  # m=mplayer (default), v=cvlc, p=mpv
#
# VARIABLE de entorno:
#   RADIO_API — base URL de la API (default: https://mammoli.ar/radio/api)
#
# Requiere: curl, python3 (para JSON), mplayer/cvlc/mpv

RADIO_API="${RADIO_API:-https://mammoli.ar/radio/api}"
UA="radio-cli/2.0 (mammoli.ar)"

# ── Colores ────────────────────────────────────────────────────────────────────
RED=$'\e[31m'; GRN=$'\e[32m'; YLW=$'\e[33m'; CYN=$'\e[36m'; BLD=$'\e[1m'; RST=$'\e[0m'

# ── Helpers ────────────────────────────────────────────────────────────────────

api_get() {
    curl -sf -A "$UA" --max-time 8 "$RADIO_API/$1"
}

json_field() {
    # json_field <json_string> <field>
    python3 -c "import sys,json; d=json.loads(sys.argv[1]); print(d.get(sys.argv[2],'') or '')" "$1" "$2" 2>/dev/null
}

json_array_len() {
    python3 -c "import sys,json; d=json.loads(sys.argv[1]); print(len(d.get('data',d) if isinstance(d,dict) else d))" "$1" 2>/dev/null
}

# Imprime un separador del ancho de la terminal
sep() { printf '%*s\n' "${COLUMNS:-60}" '' | tr ' ' '─'; }

# ── Sin argumento: listar top 20 ───────────────────────────────────────────────

if [[ -z "$1" ]]; then
    echo "${BLD}📻 Radio Argentina — las más escuchadas${RST}"
    sep
    resp=$(api_get "stations?limit=20&estado=ok") || {
        echo "No se pudo conectar con la API ($RADIO_API). Verificá tu conexión."
        exit 1
    }
    python3 - "$resp" << 'PYEOF'
import sys, json

resp = json.loads(sys.argv[1])
stations = resp.get("data", [])
if not stations:
    print("Sin resultados.")
    sys.exit(0)

for i, s in enumerate(stations, 1):
    icy = "♪" if s.get("icy_supported") else " "
    prov = (s.get("provincia") or "").split(",")[0].strip()[:18]
    prov_col = f"  {prov:<18}" if prov else ""
    plays = s.get("total_plays") or 0
    plays_s = f"  {plays} plays" if plays > 10 else ""
    print(f"  {i:>2}. {icy} {s['nombre']:<40}{prov_col}{plays_s}")
PYEOF
    sep
    echo "  Búsqueda: ${BLD}radio2.sh mendoza${RST}   Reproducir: ${BLD}radio2.sh 'rock and pop'${RST}"
    exit 0
fi

# ── Buscar ─────────────────────────────────────────────────────────────────────

QUERY="$1"
PLAYER="${2:-m}"

resp=$(api_get "stations?q=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$QUERY")&limit=10") || {
    echo "Error conectando con la API. Verificá tu conexión."
    exit 1
}

# Parsear resultados con Python
RESULT=$(python3 - "$resp" "$QUERY" << 'PYEOF'
import sys, json

resp  = json.loads(sys.argv[1])
query = sys.argv[2].lower()
stations = resp.get("data", [])

if not stations:
    print("COUNT=0")
    sys.exit(0)

print(f"COUNT={len(stations)}")
for i, s in enumerate(stations):
    slug    = s.get("slug", "")
    nombre  = s.get("nombre", "")
    url     = s.get("url", "")
    prov    = (s.get("provincia") or "").split(",")[0].strip()
    estado  = s.get("estado", "unknown")
    icy     = "1" if s.get("icy_supported") else "0"
    plays   = str(s.get("total_plays") or 0)
    print(f"SLUG_{i}={slug}")
    print(f"NOMBRE_{i}={nombre}")
    print(f"URL_{i}={url}")
    print(f"PROV_{i}={prov}")
    print(f"ESTADO_{i}={estado}")
    print(f"ICY_{i}={icy}")
    print(f"PLAYS_{i}={plays}")
PYEOF
)

eval "$RESULT"

if [[ "${COUNT}" -eq 0 ]]; then
    echo "No se encontraron emisoras para: '${QUERY}'"
    echo "Probá: radio2.sh mendoza | radio2.sh 'FM' | radio2.sh 'noticias'"
    exit 1
fi

# Si hay más de una, mostrar menú
PICK=0
if [[ "${COUNT}" -gt 1 ]]; then
    echo "${BLD}📻 ${COUNT} resultados para '${QUERY}':${RST}"
    sep
    for i in $(seq 0 $((COUNT - 1))); do
        eval "N=\$NOMBRE_$i; P=\$PROV_$i; E=\$ESTADO_$i; ICY=\$ICY_$i"
        if   [[ "$E" == "ok" ]];     then DOT="${GRN}●${RST}"
        elif [[ "$E" == "muerto" ]]; then DOT="${RED}●${RST}"
        else                              DOT="${YLW}●${RST}"
        fi
        [[ "$ICY" == "1" ]] && ICO=" ${CYN}♪${RST}" || ICO=""
        PROV_S="${P:+  (${P})}"
        printf "  %2d. %b %s%s%b\n" "$((i+1))" "$DOT" "$N" "$ICO" "$PROV_S"
    done
    sep
    printf "Elegí un número (1-%d): " "$COUNT"
    read -r sel
    [[ "$sel" =~ ^[0-9]+$ ]] && (( sel >= 1 && sel <= COUNT )) || { echo "Número inválido."; exit 1; }
    PICK=$((sel - 1))
fi

eval "SLUG=\$SLUG_$PICK; NOMBRE=\$NOMBRE_$PICK; URL=\$URL_$PICK; PROV=\$PROV_$PICK; ICY=\$ICY_$PICK"

# ── Mostrar info ───────────────────────────────────────────────────────────────

sep
echo "${BLD}▶ ${NOMBRE}${RST}"
[[ -n "$PROV" ]] && echo "  📍 ${PROV}"
echo "  🔗 ${URL}"

# Listeners activos
LISTENERS=$(api_get "listeners?slug=${SLUG}" 2>/dev/null | python3 -c "
import sys,json
try:
    d=json.loads(sys.stdin.read())
    n=d.get('data',{}).get('count',0)
    print(n if n and n>1 else '')
except:
    pass
" 2>/dev/null)
[[ -n "$LISTENERS" ]] && echo "  👥 ${LISTENERS} oyentes ahora"

# Now playing (ICY)
if [[ "$ICY" == "1" ]]; then
    NP=$(api_get "nowplaying?slug=${SLUG}" 2>/dev/null | python3 -c "
import sys,json
try:
    d=json.loads(sys.stdin.read())
    t=d.get('data',{}).get('title','')
    print(t if t else '')
except:
    pass
" 2>/dev/null)
    [[ -n "$NP" ]] && echo "  ${CYN}♪ Ahora suena: ${NP}${RST}"
fi

sep
echo "  (Ctrl+C para detener)"

# ── Reproducir con monitor de ICY ──────────────────────────────────────────────

_play() {
    case "${PLAYER,,}" in
        v) cvlc --intf dummy --play-and-exit "$URL" 2>/dev/null ;;
        p) mpv "$URL" ;;
        *) mplayer -af lavcresample=44100 -cache 128 "$URL" 2>/dev/null ;;
    esac
}

# Monitor ICY en segundo plano (solo si la emisora tiene metadata)
if [[ "$ICY" == "1" ]]; then
    (
        LAST_NP=""
        while true; do
            sleep 30
            NP=$(api_get "nowplaying?slug=${SLUG}" 2>/dev/null | python3 -c "
import sys,json
try:
    d=json.loads(sys.stdin.read())
    t=d.get('data',{}).get('title','')
    print(t if t else '')
except:
    pass
" 2>/dev/null)
            if [[ -n "$NP" && "$NP" != "$LAST_NP" ]]; then
                echo -e "\r  ${CYN}♪ Ahora suena: ${NP}${RST}     "
                LAST_NP="$NP"
            fi
        done
    ) &
    ICY_PID=$!
fi

_play

# Matar monitor al terminar
[[ -n "$ICY_PID" ]] && kill "$ICY_PID" 2>/dev/null

echo ""
echo "✓ Reproducción finalizada."
