# radio

Escuchar 700+ radios argentinas desde la terminal, o desde el navegador sin instalar nada.

**Player web:** [mammoli.ar/radio](https://mammoli.ar/radio/) — funciona en celular, buscador en tiempo real, filtros por estado y género, descarga M3U para VLC/apps IPTV.

## Terminal — radio.sh

### Uso básico

```bash
./radio.sh <búsqueda> [reproductor]
```

`búsqueda` puede ser parte del nombre, frecuencia, provincia o cualquier texto que aparezca en el nombre de la emisora.

```bash
./radio.sh mendoza          # busca "mendoza" en el nombre
./radio.sh "99.1"           # por frecuencia
./radio.sh "cadena 3"       # nombre completo o parcial
./radio.sh nacional         # cualquier palabra del nombre
./radio.sh "radio 10"
```

### Reproductores

El segundo parámetro elige el reproductor:

| Parámetro | Reproductor | Comando |
|-----------|-------------|---------|
| *(omitido)* | mplayer | `mplayer -af lavcresample=44100 -cache 128 <url>` |
| `m` | mplayer | igual que arriba |
| `v` | VLC (headless) | `cvlc <url>` |
| `p` | mpv | `mpv <url>` |

```bash
./radio.sh "cadena 3"       # mplayer (default)
./radio.sh "cadena 3" m     # mplayer explícito
./radio.sh "cadena 3" v     # VLC
./radio.sh "cadena 3" p     # mpv
```

### Comportamiento según resultados

```bash
# Sin parámetros → lista las primeras emisoras disponibles
./radio.sh

# Una sola emisora encontrada → reproduce directamente
./radio.sh "rock and pop"

# Varias encontradas → muestra la lista para afinar la búsqueda
./radio.sh rock
# → "Se encontraron 8 emisoras para 'rock'. Ser más específico:"
# →   [012] Rock & Pop FM 95.9 * Buenos Aires
# →   [034] Rock & Pop Mendoza * Mendoza
# →   ...

# Ninguna encontrada → avisa y muestra todas
./radio.sh "emisora inexistente"
```

### Alias recomendado

Agregar a `~/.bashrc` o `~/.zshrc`:

```bash
alias radio='~/Scripts/radio/radio.sh'
```

Luego:

```bash
radio "cadena 3"
radio mendoza v
```

### Detener reproducción

`Ctrl+C` detiene cualquier reproductor.

## Requisitos

Al menos uno instalado:

```bash
sudo apt install mplayer   # recomendado
sudo apt install vlc
sudo apt install mpv
```

## Player web — mammoli.ar/radio

Accesible desde cualquier navegador sin instalar nada. Funcionalidades:

- Buscador en tiempo real (nombre, provincia, género)
- Filtros de estado: Activas / Dudosas / Caídas
- Filtros de género: música, noticias, pop, rock, etc.
- Filtro ★ Más escuchadas (ranking por reproducciones reales)
- Badge de codec y bitrate (AAC 128k, MP3 64k, etc.)
- Contador de oyentes en tiempo real
- Compartir emisora por link, WhatsApp o QR
- Abrir stream en VLC desde el browser

### Parámetros de URL

```
# Pre-seleccionar filtro de género al abrir
mammoli.ar/radio/?genero=noticias
mammoli.ar/radio/?genero=pop

# Pre-seleccionar estado
mammoli.ar/radio/?estado=ok       # solo activas
mammoli.ar/radio/?estado=all      # todas

# Combinar
mammoli.ar/radio/?genero=noticias&estado=ok

# Descargar playlist M3U (para VLC, apps IPTV, etc.)
mammoli.ar/radio/?m3u=1
mammoli.ar/radio/?m3u=1&genero=noticias
mammoli.ar/radio/?m3u=1&buscar=mendoza

# Buscar server-side (útil para scripts)
mammoli.ar/radio/?buscar=cadena+3
```

## Fuente de emisoras

- `emisoras.txt` — lista principal (formato propio, ~727 emisoras AR + UY)
- `emisoras.json` — versión enriquecida con logo, tags, codec, bitrate (generada por `enrich.py`)
- `radio.sh` lee `emisoras.txt` directamente
- El player web lee `emisoras.json` (fallback a `emisoras.txt`)

### Agregar o actualizar emisoras

Editar `emisoras.txt` con el formato:

```
[NNN] Nombre FM 104.1 * Provincia
https://streaming.url/stream

# Línea con # = emisora deshabilitada
# [NNN] Nombre deshabilitada
# https://...
```

Después de modificar `emisoras.txt`, regenerar el JSON:

```bash
python3 enrich.py          # solo Radio Browser (rápido, ~30s)
python3 enrich.py --icy    # también ICY headers (completo, ~3min)
```

### Verificar URLs activas

```bash
./verificar_urls.sh                        # muestra en pantalla
./verificar_urls.sh --output resultado.txt # guarda resultado
```

### Descubrir emisoras nuevas via Radio Browser

```bash
python3 crawler_radio_browser.py              # dry-run (solo muestra)
python3 crawler_radio_browser.py --apply      # agrega al archivo
python3 crawler_radio_browser.py --apply --commit --push  # + git
python3 crawler_radio_browser.py --max 50     # limitar a 50 nuevas
python3 crawler_radio_browser.py --no-verify  # sin verificar URLs
```

## Licencia

MIT License — Carlos Ariel Mammoli (LU2MCA), Mendoza, Argentina
