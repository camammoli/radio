#!/bin/bash
# Verifica qué URLs de emisoras.txt siguen activas.
# Genera un reporte en la terminal y opcionalmente un archivo de resultados.
#
# Uso: ./verificar_urls.sh [--output archivo.txt]
# Requiere: curl

EMISORAS="$(dirname "$(readlink -f "$0")")/emisoras.txt"
OUTPUT=""
TIMEOUT=8
ACTIVAS=0
MUERTAS=0
TOTAL=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output) OUTPUT="$2"; shift 2 ;;
        *) echo "Uso: $0 [--output resultado.txt]"; exit 1 ;;
    esac
done

[[ -n "$OUTPUT" ]] && > "$OUTPUT"

echo "Verificando URLs de $EMISORAS (timeout: ${TIMEOUT}s por URL)..."
echo "Esto puede tardar varios minutos."
echo ""

while IFS= read -r line; do
    # Saltar comentarios y líneas vacías
    [[ "$line" =~ ^# ]] && continue
    [[ -z "$line" ]] && continue
    # Solo procesar líneas con URL
    [[ ! "$line" =~ ^https?:// ]] && continue

    TOTAL=$((TOTAL + 1))
    if curl -s --max-time "$TIMEOUT" -o /dev/null -w "%{http_code}" "$line" | grep -qE "^[23]"; then
        echo "✓ $line"
        ACTIVAS=$((ACTIVAS + 1))
        [[ -n "$OUTPUT" ]] && echo "OK: $line" >> "$OUTPUT"
    else
        echo "✗ $line"
        MUERTAS=$((MUERTAS + 1))
        [[ -n "$OUTPUT" ]] && echo "DEAD: $line" >> "$OUTPUT"
    fi
done < "$EMISORAS"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Total: $TOTAL | Activas: $ACTIVAS | Muertas: $MUERTAS"
[[ -n "$OUTPUT" ]] && echo "Resultado guardado en: $OUTPUT"
