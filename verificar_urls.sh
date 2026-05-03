#!/bin/bash
#
# verificar_urls.sh — verifica los streams de emisoras.txt y genera status.json
#
# USO:
#   ./verificar_urls.sh                     # verifica y muestra en pantalla
#   ./verificar_urls.sh --json              # además genera web/status.json
#   ./verificar_urls.sh --json --upload     # genera y sube al hosting
#   ./verificar_urls.sh --quiet             # sin output (modo cron)
#   ./verificar_urls.sh --help
#
# CRON — recomendado cada 6 horas:
#   0 */6 * * * /home/carlos/Scripts/radio/verificar_urls.sh --json --upload --quiet 2>&1
#
# SALIDA JSON: web/status.json  →  se publica en mammoli.ar/radio/status.json
# El player web lo lee al cargar y muestra un punto verde/rojo/amarillo por emisora.
#
# DEPENDENCIAS: curl  |  lftp (solo para --upload)

# ── Configuración ─────────────────────────────────────────────────────────────
SCRIPT_DIR="$(dirname "$(readlink -f "$0")")"
EMISORAS="$SCRIPT_DIR/emisoras.txt"
JSON_OUT="$SCRIPT_DIR/web/status.json"
TIMEOUT=5            # segundos por URL

FTP_HOST="mammoli.ar"
FTP_USER="carlos@mammoli.ar"
FTP_PASS='E?x79}NC+4+~'
FTP_DEST="/radio/status.json"

# ── Parámetros ────────────────────────────────────────────────────────────────
DO_JSON=false
DO_UPLOAD=false
QUIET=false

for arg in "$@"; do
    case "$arg" in
        --json)         DO_JSON=true ;;
        --upload)       DO_JSON=true; DO_UPLOAD=true ;;
        --quiet)        QUIET=true ;;
        -h|--help)      sed -n '3,21p' "$0"; exit 0 ;;
        *)              echo "Parámetro desconocido: $arg. Usá --help."; exit 1 ;;
    esac
done

# ── Helpers ───────────────────────────────────────────────────────────────────
log() { $QUIET || printf '%s\n' "$@"; }

# Escapa caracteres especiales JSON en una cadena
json_esc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

# ── Parsear emisoras.txt ──────────────────────────────────────────────────────
# Reconoce tanto [NNN] como [#NNN] — mismo criterio que radio.sh
declare -a NOMBRES URLS
prev_nombre=""
while IFS= read -r linea; do
    linea="${linea%%$'\r'}"
    [[ "$linea" =~ ^### ]] && continue
    [[ -z "$linea" ]] && continue

    if [[ "$linea" =~ ^\[#?[0-9]+\] ]]; then
        prev_nombre="$linea"
    elif [[ "$linea" =~ ^https?:// ]] && [[ -n "$prev_nombre" ]]; then
        NOMBRES+=("$prev_nombre")
        URLS+=("$linea")
        prev_nombre=""
    else
        prev_nombre=""
    fi
done < "$EMISORAS"

TOTAL=${#URLS[@]}

if [[ $TOTAL -eq 0 ]]; then
    echo "Error: no se encontraron emisoras en $EMISORAS"
    exit 1
fi

log "Verificando $TOTAL emisoras (timeout: ${TIMEOUT}s por URL)..."
log "Esto puede tardar varios minutos."
$DO_JSON  && log "Salida JSON: $JSON_OUT"
$DO_UPLOAD && log "Upload FTP: https://${FTP_HOST}${FTP_DEST}"
log ""

# ── Arrays para el JSON ───────────────────────────────────────────────────────
declare -a RES_URL RES_ESTADO RES_EXTRA

COUNT_OK=0
COUNT_DEAD=0
COUNT_TIMEOUT=0

# ── Verificar cada URL ────────────────────────────────────────────────────────
for i in "${!URLS[@]}"; do
    url="${URLS[$i]}"
    nombre="${NOMBRES[$i]}"
    num=$((i + 1))

    # Probar la versión HTTPS (lo que usa el browser en una página HTTPS)
    test_url="$url"
    [[ "$url" == http://* ]] && test_url="${url/http:\/\//https://}"

    result=$(curl -s -o /dev/null \
        -w "%{http_code}|%{time_total}" \
        --max-time    "$TIMEOUT" \
        --connect-timeout 4 \
        -L --max-redirs 3 \
        "$test_url" 2>/dev/null)

    http_code="${result%%|*}"
    time_raw="${result##*|}"
    # Convertir segundos decimales a ms enteros (bc si disponible, fallback a 0)
    ms=$(echo "${time_raw} * 1000 / 1" | bc 2>/dev/null || echo "0")

    RES_URL+=("$url")

    if [[ "$http_code" =~ ^[23] ]]; then
        RES_ESTADO+=("ok")
        RES_EXTRA+=("\"ms\": ${ms:-0}")
        COUNT_OK=$((COUNT_OK + 1))
        log "✓ [$num/$TOTAL] $nombre"
    elif [[ "$http_code" == "000" ]]; then
        RES_ESTADO+=("timeout")
        RES_EXTRA+=("\"ms\": ${TIMEOUT}000")
        COUNT_TIMEOUT=$((COUNT_TIMEOUT + 1))
        log "⏱ [$num/$TOTAL] $nombre"
    else
        RES_ESTADO+=("muerto")
        RES_EXTRA+=("\"codigo\": $http_code")
        COUNT_DEAD=$((COUNT_DEAD + 1))
        log "✗ [$num/$TOTAL] $nombre  (HTTP $http_code)"
    fi
done

log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "Total: $TOTAL | ✓ OK: $COUNT_OK | ✗ Muertos: $COUNT_DEAD | ⏱ Timeout: $COUNT_TIMEOUT"

# ── Generar JSON ──────────────────────────────────────────────────────────────
if $DO_JSON; then
    LAST=$((TOTAL - 1))
    {
        echo "{"
        echo "  \"generado\": \"$(date '+%Y-%m-%d %H:%M:%S')\","
        echo "  \"total\": $TOTAL,"
        echo "  \"ok\": $COUNT_OK,"
        echo "  \"muertos\": $COUNT_DEAD,"
        echo "  \"timeout\": $COUNT_TIMEOUT,"
        echo "  \"streams\": {"
        for i in "${!RES_URL[@]}"; do
            sep=","
            [[ $i -eq $LAST ]] && sep=""
            url_j=$(json_esc "${RES_URL[$i]}")
            echo "    \"${url_j}\": {\"estado\": \"${RES_ESTADO[$i]}\", ${RES_EXTRA[$i]}}${sep}"
        done
        echo "  }"
        echo "}"
    } > "$JSON_OUT"
    log "JSON guardado: $JSON_OUT"
fi

# ── Upload FTP ────────────────────────────────────────────────────────────────
if $DO_UPLOAD; then
    log "Subiendo al hosting..."
    lftp -u "${FTP_USER},${FTP_PASS}" "$FTP_HOST" << LFTP
set ssl:verify-certificate no
put ${JSON_OUT} -o ${FTP_DEST}
quit
LFTP
    if [[ $? -eq 0 ]]; then
        log "✓ Subido: https://${FTP_HOST}${FTP_DEST}"
    else
        log "✗ Error al subir. Verificá conectividad FTP."
        exit 1
    fi
fi
