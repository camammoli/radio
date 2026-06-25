# Radio Argentina

Escuchar ~1200 radios argentinas desde la terminal, o desde el navegador sin instalar nada.

**Player web:** [mammoli.ar/radio](https://mammoli.ar/radio/) — funciona en celular, buscador en tiempo real, filtros por estado y género, ICY now-playing, encuestas, compartir por link/WhatsApp/QR, descarga M3U.

---

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
```

### Reproductores

| Parámetro | Reproductor |
|-----------|-------------|
| *(omitido)* | mplayer |
| `m` | mplayer explícito |
| `v` | VLC headless (`cvlc`) |
| `p` | mpv |

```bash
./radio.sh "cadena 3"       # mplayer (default)
./radio.sh "cadena 3" v     # VLC
./radio.sh "cadena 3" p     # mpv
```

### Alias recomendado

```bash
# ~/.bashrc o ~/.zshrc
alias radio='~/Scripts/radio/radio.sh'
```

### radio2.sh — CLI con API v2

`radio2.sh` consume la API REST y muestra oyentes en tiempo real e ICY now-playing:

```bash
./radio2.sh mendoza         # busca y reproduce
./radio2.sh "cadena 3" v    # mismos parámetros que radio.sh
```

## Requisitos CLI

```bash
sudo apt install mplayer   # recomendado
sudo apt install vlc
sudo apt install mpv
```

---

## Player web — mammoli.ar/radio

Accesible desde cualquier navegador sin instalar nada.

- Buscador en tiempo real (nombre, provincia, género)
- Filtros: Activas / Dudosas / Caídas / Más escuchadas
- Filtros de género: música, noticias, pop, rock, etc.
- ICY now-playing en tiempo real (título de canción/programa)
- Badge de codec y bitrate (AAC 128k, MP3 64k, etc.)
- Contador de oyentes en tiempo real
- Compartir por link, WhatsApp o QR
- Abrir stream en VLC desde el browser
- Encuesta de satisfacción (👍 / 😐 / 👎)
- PWA: instalable en celular, funciona offline
- Dark/light mode

### Parámetros de URL

```
# Filtro de género al abrir
mammoli.ar/radio/?genero=noticias
mammoli.ar/radio/?genero=pop

# Filtro de estado
mammoli.ar/radio/?estado=ok       # solo activas
mammoli.ar/radio/?estado=all      # todas

# Combinar
mammoli.ar/radio/?genero=noticias&estado=ok

# Descargar playlist M3U (para VLC, apps IPTV, etc.)
mammoli.ar/radio/?m3u=1
mammoli.ar/radio/api/playlist.m3u

# Buscar server-side (útil para scripts)
mammoli.ar/radio/?buscar=cadena+3

# Página de emisora individual
mammoli.ar/radio/{slug}/
```

---

## Arquitectura (v2)

```
web/
├── index.php          ← router (35 líneas)
├── admin.php          ← panel de administración (auth requerida)
├── sitemap.php        ← sitemap dinámico desde SQLite
├── api/
│   ├── stations.php   ← GET /api/stations[?slug=]
│   ├── playlist.php   ← GET /api/playlist.m3u
│   ├── listeners.php  ← ping/stop/count/top
│   ├── nowplaying.php ← ICY metadata
│   ├── survey.php     ← POST rating + location
│   ├── suggest.php    ← POST sugerencia de emisora
│   └── share.php      ← notificación de compartir
├── pages/
│   ├── listing.php    ← directorio principal
│   └── station.php    ← página individual (SEO, JSON-LD)
├── components/
│   └── head.php       ← <head> compartido
└── assets/
    ├── player.js      ← RadioPlayer() — estados idle/connecting/playing/buffering/error
    ├── player.css     ← estilos del player
    ├── theme.js       ← dark/light toggle
    └── style.css      ← CSS global

crawlers/
├── check_streams_v2.py  ← verifica streams (30 workers paralelos), detecta cambios
├── enrich_v2.py         ← enriquece con Radio Browser API (logo, codec, bitrate)
├── hunt_stations_v2.py  ← descubre emisoras nuevas
└── icy_refresh.php      ← refresca ICY titles (cURL Multi, 20 concurrentes, cron 10min)

db/
└── radio_v2.sqlite    ← base de datos (gitignoreada)
```

### Base de datos

SQLite con 9 tablas + 2 vistas:

| Tabla | Contenido |
|---|---|
| `stations` | directorio de emisoras (~1200) |
| `stream_status` | estado actual por emisora |
| `stream_history` | historial de verificaciones |
| `station_events` | eventos: came_back, went_down, icy_gained, icy_lost |
| `icy_cache` | título ICY actual por emisora |
| `plays` | historial de reproducciones |
| `listeners` | oyentes activos (TTL 90s) |
| `surveys` | calificaciones de usuarios |
| `crawler_runs` | log de ejecuciones de crawlers |

Vistas: `v_stations` (join completo), `v_active_listeners`.

---

## Crawlers

### check_streams_v2.py — verificación periódica de URLs

Verifica todas las emisoras en paralelo (30 workers). Detecta cambios y genera eventos:

```bash
python3 crawlers/check_streams_v2.py            # verificar sin notificar
python3 crawlers/check_streams_v2.py --notify   # notifica cambios por Telegram
python3 crawlers/check_streams_v2.py --icy      # también refresca ICY titles
python3 crawlers/check_streams_v2.py --workers 40
```

Corre automáticamente cada 6hs via GitHub Actions (`check-streams-v2.yml`).

### enrich_v2.py — enriquecimiento de metadatos

Actualiza logo, tags, codec, bitrate y votos desde Radio Browser API:

```bash
python3 crawlers/enrich_v2.py
```

Corre automáticamente los días 1 y 15 de cada mes via GitHub Actions (`enrich-v2.yml`).

### hunt_stations_v2.py — descubrimiento de emisoras

Busca emisoras argentinas nuevas en Radio Browser y las inserta con `approved=0` para revisión:

```bash
python3 crawlers/hunt_stations_v2.py
```

### icy_refresh.php — ICY now-playing en tiempo real

Script PHP para cPanel cron. Barre todas las emisoras con `icy_supported=1` usando cURL Multi (20 conexiones concurrentes) y actualiza `icy_cache`. Corre cada 10 minutos.

---

## GitHub Actions

| Workflow | Frecuencia | Qué hace |
|---|---|---|
| `check-streams-v2.yml` | cada 6hs | verifica streams, notifica cambios por Telegram |
| `enrich-v2.yml` | días 1 y 15 | enriquece metadatos desde Radio Browser |

Los workflows se activan desde `master` (default branch). Ambos hacen checkout de `v2`, descargan la DB por FTP, corren el crawler, y suben la DB actualizada.

---

## Configuración (`config.php`)

Copiar `config.example.php` como `config.php` y completar:

```php
define('RADIO_ADMIN_KEY', '...');   // clave interna de API
define('ADMIN_USER', 'admin');       // usuario del panel /admin.php
define('ADMIN_PASS', '...');         // contraseña del panel
define('TG_TOKEN',  '...');          // token del bot de Telegram
define('TG_CHAT_ID', '...');         // chat donde notificar
define('NOTIFY_OYENTES', false);     // true = notificar nuevos oyentes
define('GA_ID', '');                 // Google Analytics 4 (vacío = desactivado)
// Opcional:
define('RADIO_DB', '/ruta/a/radio_v2.sqlite');
define('RADIO_BASE', '/radio');      // prefijo URL (para staging en subpath)
```

---

## Panel de administración

Accesible en `/radio/admin.php` con usuario y contraseña (configurados en `config.php`).

Secciones: resumen de estadísticas, encuestas con resultado por estación, sugerencias de nuevas emisoras (aprobar/rechazar), ICY titles activos con indicador de frescura, log de crawlers.

---

## Ramas

| Rama | Descripción |
|---|---|
| `master` | producción — v2 activa en mammoli.ar/radio/ |
| `v2` | desarrollo — mergeada a master en cutover 2026-06-25 |
| `v1-archive` | snapshot de v1 antes del cutover (tag: `v1-final`) |

---

## Licencia

MIT License — Carlos Ariel Mammoli (LU2MCA), Mendoza, Argentina
