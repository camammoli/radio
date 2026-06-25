# Radio Argentina v2 — Documento de Diseño

**Estado:** en producción desde 2026-06-25  
**Rama:** `master` (= v2). Rama `v2` preservada. `v1-archive` = snapshot de v1.

---

## Contexto — Por qué v2

v1 fue construida por acreción: cada feature se pegó encima de la anterior en un solo archivo `index.php` que llegó a 1811 líneas. El modelo de datos eran archivos JSON planos con race conditions en escrituras concurrentes. Los crawlers no tenían memoria — cada run partía de cero. El player tenía dos implementaciones distintas (listado vs. página individual) que divergieron y requirieron fixes manuales.

v2 se diseñó top-down con el mismo stack (PHP + vanilla JS, sin frameworks, sin build steps) pero con separación de responsabilidades real.

---

## Principios

- **Mismo stack, distinto orden.** PHP + vanilla JS + SQLite. Sin frameworks, sin npm, sin transpiladores.
- **Un solo player.** Un componente JS reutilizable, mismo comportamiento en listado, página individual y CLI.
- **Los crawlers tienen memoria.** Escriben a la base de datos, leen historial, detectan cambios.
- **Contratos estables.** La URL del M3U no cambia. Las URLs de emisoras no cambian. Los usuarios no saben que hubo un v2.
- **KISS.** Nada que no tenga un caso de uso real hoy.

---

## Estrategia de ramas

```
camammoli/radio
├── master      →  v2 en producción
├── v2          →  desarrollo (mergeada a master en cutover 2026-06-25)
└── v1-archive  →  snapshot v1 antes del cutover (tag: v1-final)
```

Las GitHub Actions corren en `master` (default branch). El checkout es siempre `ref: v2` para los workflows de crawlers, ya que el código de crawlers está en esa rama.

Rollback a v1: subir `/v1-archive/index.php` vía FTP a `/radio/index.php`. El resto del sitio no interfiere — v1 era un monolito autocontenido.

---

## Arquitectura implementada

### Backend

```
web/
├── index.php          ← router (35 líneas): ?m3u=1→301, ?n→slug, ?station→page, default→listing
├── admin.php          ← panel admin con auth sesión PHP (noindex, sin redirect en login)
├── sitemap.php        ← sitemap dinámico desde v_stations (SQLite)
├── api/
│   ├── _db.php        ← PDO singleton, WAL mode, busy_timeout=3000
│   ├── _helpers.php   ← api_response(), api_error(), client_ip(), ip_hash()
│   ├── stations.php   ← GET /api/stations[?slug=][?search=][?estado=][?genero=]
│   ├── playlist.php   ← GET /api/playlist.m3u[?buscar=][?genero=][?estado=]
│   ├── listeners.php  ← POST ping/stop, GET count/top
│   ├── nowplaying.php ← GET ICY metadata por URL o batch por slug
│   ├── survey.php     ← POST rating (-1/0/1) + location
│   ├── suggest.php    ← POST sugerencia de emisora
│   └── share.php      ← POST notificación de compartir
├── pages/
│   ├── listing.php    ← directorio, filtros client-side, ICY sync en card activa
│   └── station.php    ← página individual (3x JSON-LD, VLC, QR, compartir, volume)
├── components/
│   └── head.php       ← <head> compartido, usa constante RADIO_BASE
└── assets/
    ├── player.js      ← RadioPlayer(opts): idle→connecting→playing→buffering→error
    ├── player.css     ← namespace rp-*, variables CSS dark/light
    ├── theme.js       ← RadioTheme.init(btn), localStorage 'radio_theme'
    └── style.css      ← CSS global
```

### Crawlers

```
crawlers/
├── check_streams_v2.py  ← verifica streams (30 workers), detecta went_down/came_back/icy_*
├── enrich_v2.py         ← Radio Browser API → logo, codec, bitrate, rb_votes
├── hunt_stations_v2.py  ← descubre emisoras nuevas (inserta con approved=0)
├── icy_refresh.php      ← cURL Multi PHP (20 concurrentes), cron cPanel cada 10min
└── db/radio_db.py       ← conexión Python compartida
```

### Base de datos (`db/radio_v2.sqlite`)

9 tablas + 2 vistas:

| Tabla | Contenido |
|---|---|
| `stations` | directorio (~1200 emisoras AR) |
| `stream_status` | estado actual (ok/dudoso/muerto) |
| `stream_history` | historial de verificaciones |
| `station_events` | eventos detectados por crawlers |
| `icy_cache` | título ICY actual + supported flag |
| `plays` | historial de reproducciones |
| `listeners` | oyentes activos (TTL 90s) |
| `surveys` | calificaciones (rating -1/0/1 + location) |
| `crawler_runs` | log de ejecuciones |

Vistas: `v_stations` (join completo con estado, ICY, plays, votes), `v_active_listeners`.

Slugs únicos: `_radio_slug()` PHP / `_slug()` Python — accent norm + lowercase + sufijo `-{n}` anti-colisión.

---

## Contratos que no se rompieron

| Contrato | v1 | v2 |
|---|---|---|
| URL del M3U | `?m3u=1` | `/api/playlist.m3u` + redirect 301 desde `?m3u=1` ✓ |
| URLs de emisoras | `/radio/{slug}/` | igual ✓ |
| radio.sh | descarga M3U, filtra localmente | sin cambios ✓ |
| emisoras.json / emisoras.txt | en repo | siguen presentes (para radio.sh CLI) ✓ |

---

## GitHub Actions

| Workflow | Frecuencia | Qué hace |
|---|---|---|
| `check-streams-v2.yml` | cada 6hs | verifica streams, Telegram, sube DB |
| `enrich-v2.yml` | días 1 y 15 | enriquece metadatos, sube DB |

Ambos: descarga DB por FTP → corre crawler → sube DB actualizada. Secrets: `FTP_PASS`, `TG_TOKEN`, `TG_CHAT_ID`.

---

## Tickets de desarrollo v2

| TKT | Descripción | Estado |
|---|---|---|
| V2-001 | Modelo de datos + schema.sql | ✅ completado |
| V2-002 | Script de migración v1 → v2 | ✅ completado (db/migrate_v1.py) |
| V2-003 | API REST: stations, playlist, listeners, nowplaying, survey, suggest, share | ✅ completado |
| V2-004 | Player unificado (player.js) con HLS.js lazy + volumen + ICY sync | ✅ completado |
| V2-005 | Router + páginas listing y station (SEO, JSON-LD, QR, VLC) | ✅ completado |
| V2-006 | Crawlers v2 con memoria + station_events + Telegram | ✅ completado |
| V2-007 | CLI v2 (radio2.sh) con API + ICY + oyentes | ✅ completado |
| V2-008 | Admin panel (auth, encuestas, sugerencias, ICY activas, crawler log) | ✅ completado |
| V2-009 | Cutover a producción (2026-06-25) | ✅ completado |
