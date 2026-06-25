# Radio Argentina v2 — Documento de Diseño

**Estado:** en desarrollo  
**Rama:** `v2` (master = v1 en producción, intocable)  
**Fecha de inicio:** 2026-06-24  

---

## Contexto — Por qué v2

v1 fue construida por acreción: cada feature se pegó encima de la anterior en un solo archivo `index.php` que llegó a 1811 líneas. El modelo de datos son archivos JSON planos con race conditions en escrituras concurrentes. Los crawlers no tienen memoria — cada run parte de cero. El player tiene dos implementaciones distintas (listado vs. página individual) que divergieron y requirieron fixes manuales.

v2 se diseña top-down con el mismo stack (PHP + vanilla JS, sin frameworks, sin build steps) pero con separación de responsabilidades real.

---

## Principios

- **Mismo stack, distinto orden.** PHP + vanilla JS + SQLite. Sin frameworks, sin npm, sin transpiladores.
- **Un solo player.** Un componente JS reutilizable, mismo comportamiento en listado, página individual y CLI.
- **Los crawlers tienen memoria.** Escriben a la base de datos, leen historial, detectan cambios.
- **Contratos estables.** La URL del M3U no cambia. Las URLs de emisoras no cambian. Los usuarios no saben que hubo un v2.
- **KISS.** Nada que no tenga un caso de uso real hoy.

---

## Estrategia de repo

```
camammoli/radio
├── master  →  v1 en producción (no tocar)
├── v2      →  desarrollo v2
└── cli/    →  radio.sh + evolución CLI (en ambas ramas)
```

- Las GitHub Actions de deploy solo corren en `master`.
- Cuando v2 esté listo: merge a `master`, FTP deploy, corte instantáneo.
- Rollback: FTP deploy del estado anterior de `master` (2 minutos).

---

## Arquitectura

### Backend

```
web/
├── index.php          ← router liviano (no lógica de negocio)
├── api/
│   ├── stations.php   ← GET /api/stations, GET /api/stations/{slug}
│   ├── playlist.php   ← GET /api/playlist.m3u  (reemplaza ?m3u=1)
│   ├── listeners.php  ← ping / stop / count / top
│   ├── nowplaying.php ← ICY metadata por URL
│   ├── survey.php     ← POST rating
│   └── suggest.php    ← POST sugerencia
├── pages/
│   ├── listing.php    ← directorio principal
│   └── station.php    ← página individual de emisora
├── components/
│   └── player.php     ← HTML del player (incluido desde listing y station)
├── assets/
│   ├── player.js      ← componente JS único del player
│   ├── theme.js       ← dark/light toggle
│   └── style.css      ← variables + componentes
└── db/
    └── radio.sqlite   ← base de datos (gitignoreada)
```

### CLI (`cli/`)

```
cli/
├── radio.sh           ← v1 actual (no romper)
└── radio2.sh          ← v2: consume API, muestra ICY/oyentes, mismo branding
```

---

## Player unificado

Un solo `player.js` instanciado igual en listado y en página individual:

```
estados: idle → connecting → playing → buffering → stopped → error
```

Mismo comportamiento en todos los contextos:
- Heartbeat a `listeners.php` (ping al iniciar, cada 30s, stop en pausa/error/beforeunload)
- ICY polling cada 30s mientras reproduce (si el stream lo soporta)
- Survey de satisfacción a los 3 minutos
- Compartir: link / WhatsApp / QR

En el **listado**: el player flota sobre la grilla, permite cambiar de emisora sin recargar.  
En la **página individual**: el player es el elemento central de la página.  
En el **CLI**: mismo modelo de estados, render en terminal (ANSI).

---

## Contratos que no pueden romperse

| Contrato | v1 | v2 |
|---|---|---|
| URL del M3U | `?m3u=1` | `/api/playlist.m3u` + redirect 301 desde `?m3u=1` |
| URLs de emisoras | `/radio/{slug}/` | igual |
| URLs de páginas | `/radio/?provincia=X` etc. | igual |
| radio.sh | descarga M3U, filtra localmente | sin cambios (M3U sigue existiendo) |

---

## Modelo de datos

Ver `db/schema.sql`.

Tablas principales:
- `stations` — directorio de emisoras
- `stream_status` — estado actual por emisora (actualizado por crawler)
- `stream_history` — log de cada verificación (da memoria a los crawlers)
- `station_events` — cambios detectados: ICY ganada/perdida, URL cambió, volvió online
- `icy_cache` — estado ICY actual + última canción detectada
- `plays` — historial de reproducciones
- `listeners` — oyentes activos (TTL 90s)
- `surveys` — calificaciones
- `crawler_runs` — log de ejecuciones de crawlers

---

## Evolución de crawlers

### check-streams (hoy: verifica si el stream responde)
**v2:** además actualiza `stream_status`, inserta en `stream_history`, detecta cambios contra el run anterior y genera `station_events`.

Eventos que detecta:
- `came_back` — estaba muerto, ahora responde
- `went_down` — estaba ok, ahora no responde
- `icy_gained` — ahora tiene ICY metadata, antes no
- `icy_lost` — tenía ICY metadata, ya no
- `url_changed` — redirect permanente a nueva URL
- `codec_changed` — cambió el formato del stream

Cada evento con `notified = 0` dispara Telegram automáticamente.

### icy-check (hoy: script manual que genera icy_stations.json)
**v2:** job periódico (Actions, diario o semanal) que actualiza `icy_cache` en la DB. El JSON estático desaparece — el badge en el listado lo sirve la API.

### hunt-stations (hoy: busca emisoras nuevas)
**v2:** igual, pero inserta en `stations` en lugar de proponer a emisoras.txt. Mantiene `source` para saber de dónde vino cada emisora.

---

## Migración de datos (v1 → v2, antes del corte)

| Archivo v1 | Destino v2 |
|---|---|
| `emisoras.json` | tabla `stations` |
| `web/plays.json` | tabla `plays` (bulk insert) |
| `web/data/survey.csv` | tabla `surveys` |
| `web/icy_stations.json` | tabla `icy_cache` (supported=true) |
| `web/listeners.json` | descartar (datos transientes) |
| `web/status.json` | tabla `stream_status` |

Script: `db/migrate_v1.py` — corre una sola vez antes del corte.

---

## CLI v2 — Modernización

- Mismo branding que el web (nombre, versión, tagline en header ANSI)
- Consume `/api/stations` para buscar por nombre/provincia/género
- Muestra oyentes en tiempo real al reproducir (query a `listeners.php`)
- Muestra ICY now-playing en el terminal si el stream lo soporta
- Sigue siendo shell puro (bash), sin dependencias nuevas
- Parámetros existentes de radio.sh: todos compatibles

---

## Staging y rollout

1. Desarrollar en rama `v2`, testear en `/radio/beta/` (subpath temporal)
2. Cuando player unificado + API + páginas funcionen: corte
3. Corte = merge a `master` + FTP deploy
4. Rollback = FTP deploy del último estado de `master` antes del merge

v2.0 puede salir sin algunas features menores de v1 si están documentadas para v2.1.  
La condición mínima para el corte: M3U funciona, player funciona, crawlers escriben a DB.

---

## Tickets de desarrollo v2

| TKT | Descripción | Estado |
|---|---|---|
| V2-001 | Modelo de datos + schema.sql | en curso |
| V2-002 | Script de migración v1 → v2 | pendiente |
| V2-003 | API REST: stations, playlist, listeners | pendiente |
| V2-004 | Player unificado (player.js) | pendiente |
| V2-005 | Router + páginas listing y station | pendiente |
| V2-006 | Crawlers escriben a DB + station_events | pendiente |
| V2-007 | CLI v2 (radio2.sh) con API + ICY + oyentes | pendiente |
| V2-008 | Script de migración + staging test | pendiente |
| V2-009 | Cutover a producción | pendiente |
