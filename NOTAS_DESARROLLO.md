# NOTAS DE DESARROLLO — Radio Argentina

Player web en [mammoli.ar/radio](https://mammoli.ar/radio/) + script de terminal `radio.sh`.

---

## TKT-0710 — 2026-06-25 — Radio v2: fix ICY crawler + HLS lazy load + share API + beta estabilización

### Contexto
Beta v2 en `/radio/beta/`. Producción en `/radio/` sigue en v1 (revertido en sesión anterior).
Varios problemas detectados durante las pruebas beta y resueltos en esta sesión.

### Causa raíz: icy_cache.stream_title siempre NULL

El crawler `check_streams_v2.py` llamaba a `_read_icy_title()` pero todos los títulos
llegaban como NULL. Diagnóstico: la función leía el primer bloque de metadata ICY y si
`meta_len == 0` retornaba `None` inmediatamente. Algunos servidores (Shoutcast/SHOUTcast en
`solumedia.com.ar:81xx`) envían el **primer bloque vacío** y el título aparece recién en el
segundo o tercer bloque.

**Fix:** loop de hasta 4 bloques; timeout mínimo de 15s (a 48 kbps leer 16 KB tarda ~2.7s,
necesitamos tiempo para al menos 2 bloques). También se extendió la ventana del batch endpoint
de 2h a 7h (el crawler corre cada 6h → había 4h de ventana muerta donde el batch devolvía `{}`).

### El cron de GitHub Actions no corría

`check-streams-v2.yml` solo existía en la rama `v2`. GitHub Actions solo agenda crons desde
la rama por defecto (`master`). Agregado a `master` con la condición `if` eliminada (el checkout
siempre usa `ref: v2`). Primer run manual disparado desde `gh workflow run`.

### Otros cambios v2 en esta sesión

**HLS.js lazy loading** (`player.js`)
- HLS.js (543 KB) no se carga hasta que el usuario clickea una emisora `.m3u8`
- Sistema de callbacks para manejar requests concurrentes mientras carga
- `getAudio()` expuesto en la API pública del player

**Share API** (`api/share.php`)
- Nuevo endpoint `GET /api/share?slug=SLUG&channel=copy|wa|qr`
- Notifica por Telegram si `NOTIFY_OYENTES=true` (producción) o silencioso si false (beta)
- Integrado en `listing.php` y `station.php` via `pingShare()`

**Mejoras de UI en listing.php**
- Campo "Verificado" (last_checked) visible en cada tarjeta de emisora
- ICY title pasivo vía `GET /api/nowplaying?batch=1` al cargar la página
- Volume slider en la barra del player
- CSS `.station-icy-passive` para el título pasivo

**station.php**
- Volume control show/hide según estado del player (en `onState` callback)
- `pingShare()` en botones de compartir

**head.php**
- Meta `noindex, nofollow` cuando `RADIO_BASE` está definido (staging)

**robots.txt** (producción)
- `Disallow: /radio/beta/` y `Disallow: /radio/api/`

### Archivos modificados
- `crawlers/check_streams_v2.py` — fix `_read_icy_title()`, timeout, loop 4 bloques
- `web/api/nowplaying.php` — cURL state machine, batch endpoint, ventana 7h
- `web/api/share.php` — nuevo endpoint
- `web/assets/player.js` — HLS lazy loading, getAudio()
- `web/assets/style.css` — `.station-icy-passive`
- `web/components/head.php` — noindex en staging
- `web/pages/listing.php` — verificado, ICY pasivo, volume slider, pingShare
- `web/pages/station.php` — volume control, pingShare
- `.github/workflows/check-streams-v2.yml` — agregado a `master` para habilitar cron

### Deploy
- Commits: `98628ca` (v2) + `63fbccb` (master workflow)
- FTP: `nowplaying.php` a `/radio/api/` y `/radio/beta/api/`
- GitHub Actions workflow disparado manualmente post-fix

---

## TKT-0711 — 2026-06-25 — Radio v2: ICY tiempo real + card sync + cron PHP

### Contexto
Continuación de TKT-0710. El título ICY en el reproductor se actualizaba pero la
tarjeta correspondiente en el listado quedaba "pegada" con el dato viejo del batch.
Además se necesitaba un crawler PHP rápido para refrescar los títulos cada 10-15
minutos desde cPanel, sin depender del cron de GitHub Actions (cada 6h).

### Fetch ICY tiempo real

**Estrategia híbrida browser + servidor:**
- Streams HTTPS: el browser hace `fetch()` + `ReadableStream` directamente (CORS libre en
  Shoutcast). Se parsea el stream ICY con un loop de hasta 4 bloques por si el primero viene vacío.
- Streams HTTP en página HTTPS: el browser no puede (mixed content). El servidor PHP hace
  `fetch_icy_title()` en tiempo real (vía `nowplaying.php`) con el mismo loop multi-bloque.
- Ambos caminos llaman al callback `onNowPlaying(title)`.

**`player.js`**: `fetchIcyBrowser()` → Uint8Array loop; `fetchNPServer()` → `/api/nowplaying`;
`fetchNP()` elige estrategia según protocolo y URL. Poll cada 30s mientras reproduce.

**`nowplaying.php`**: `fetch_icy_title()` con cURL + `WRITEFUNCTION` que implementa la misma
state machine. Timeout mínimo 15s; loop de 4 intentos para bloques vacíos. Cache TTL 60s;
fallback a caché vieja si el fetch real-time falla.

### Sincronización player → tarjeta del listado

`onNowPlaying` en `listing.php` antes solo actualizaba `#player-np` (barra del player).
Ahora también actualiza `.station-icy-passive` dentro de la tarjeta activa:
- Si el elemento no existe, lo crea dentro de `.station-info`.
- Si `title` es null, limpia el texto (no elimina el elemento para evitar layout shift).

### Welcome toast v2

Toast grande (una sola vez por usuario) a los 90s de reproducción continua:
- Lista de mejoras en lenguaje coloquial, aviso de no-tracking, mini encuesta (rating +
  lugar), botón CTA. Se guarda en `localStorage` bajo `radio_welcome_v2`.
- Timer se cancela si el usuario detiene la reproducción; reinicia si vuelve a escuchar.

### "en el aire" pulsing label

`station.php`: `#st-np` muestra `● en el aire — {título}` con `.np-dot` animado (pulse 1.5s).
`listing.php`: tarjetas pasivas muestran `♪ en el aire — {título}`.
Player bar: `#player-np` con texto `♪ en el aire — {título}` al reproducir.

### `crawlers/icy_refresh.php` — cron PHP

Script CLI que usa cURL Multi (20 conexiones simultáneas) para refrescar `stream_title`
en `icy_cache` para todas las emisoras con `supported=1`. Diseñado para cPanel cron.

- Detecta paths automáticamente (producción flat vs dev con `web/`)
- Lote de 20 handles simultáneos, 20s timeout por conexión
- Misma state machine ICY (stdClass como estado compartido por el handle del objeto)
- Actualiza `last_title_change` solo si el título cambia
- Output log legible: `+ slug: Artista — Tema`

**Configurar en cPanel:**
```
*/10 * * * *  php /home/mammoli/public_html/radio/crawlers/icy_refresh.php >> /home/mammoli/logs/icy.log 2>&1
```

### Archivos modificados
- `web/pages/listing.php` — `onNowPlaying` sincroniza tarjeta activa
- `crawlers/icy_refresh.php` — nuevo, cron cURL Multi ICY

### Deploy
- FTP beta: `listing.php` → `/radio/beta/pages/listing.php`
- FTP nuevo dir: `/radio/crawlers/icy_refresh.php`
- Cron cPanel: pendiente de configurar por Carlos

---

## Nota operativa — Ancho de banda del hosting

El stream de audio va **directo** desde el servidor de la radio al navegador del oyente.
mammoli.ar NO actúa como proxy ni retransmite el audio.

Lo único que pasa por el hosting es:
- Carga inicial de la página (~50KB, una vez por visita)
- Heartbeats cada 30s (~200 bytes por request)
- Consultas a listeners.php y status.json

Una persona escuchando 5 horas genera menos de 1MB en el hosting.
Con 1000 oyentes simultáneos el impacto en ancho de banda sería igualmente insignificante.

---

## TKT-0681 — 2026-06-16 — SEO: páginas individuales por emisora

### Contexto
Google Search Console mostraba 0 impresiones para búsquedas por nombre de emisora específica
(ej: "FM Sol Mendoza"). El directorio era una única página con 827 emisoras — imposible que
Google la asociara a una emisora particular.

### Lo que se hizo
- `_radio_slug()` + `_radio_full_slug()`: genera slugs URL a partir de nombre + ciudad
- Interceptor en `index.php`: detecta `?station=slug`, carga la emisora y renderiza página
  individual con `<title>`, `<meta description>`, `<link canonical>`, JSON-LD `RadioStation`
  y player minimalista. Las emisoras con `estado: muerto` reciben `<meta name="robots" content="noindex">`
- `.htaccess`: rewrite `/radio/{slug}/` → `index.php?station={slug}` + `sitemap.xml` → `sitemap.php`
- `sitemap.php`: genera XML dinámico con todas las emisoras que no son `muerto` (~791 URLs)
- `index.php` (directorio): ícono `⬈` en nombre de cada emisora → link a su página individual
  (invisible en reposo, visible en hover, no interfiere con el player)
- `robots.txt` (mammoli-site): eliminado `Disallow: /radio/`, bloqueados solo endpoints internos;
  agregado `Sitemap: https://mammoli.ar/radio/sitemap.xml`

### Pendiente
- Solicitar reindexación en Google Search Console (manual)
- Enviar sitemap de radio en GSC: `https://mammoli.ar/radio/sitemap.xml`

---

## TKT-0680 — 2026-05-19/20 — Player web: oyentes, ranking, géneros, tema claro

### Contexto
El player web existía con buscador y filtros básicos. Se agregaron múltiples mejoras en dos jornadas.

### Funcionalidades implementadas

**Oyentes en tiempo real + ranking**
- `listeners.php`: heartbeat cada 30s, TTL 90s, Page Visibility API para móvil
- `plays.json`: contador histórico de reproducciones por emisora
- Badge "N escuchando" visible solo cuando hay oyentes activos
- Filtro "★ Más escuchadas" en fila de estado (solo emisoras activas)

**Enriquecimiento de emisoras**
- `enrich.py`: cruza `emisoras.txt` con Radio Browser API + ICY headers
- Genera `emisoras.json` con logo, tags, codec, bitrate, homepage
- Hook pre-commit: si `emisoras.txt` cambia → regenera `emisoras.json` automáticamente
- Resultado: 138/727 matcheadas por URL, 248 con codec, 90 con logo

**Filtros**
- Estado (Todas/Activas/Dudosas/Caídas/★ Más escuchadas) AND Categoría
- Botón "Categorías ▾" colapsa panel de géneros (oculto por defecto)
- Al seleccionar categoría: muestra nombre en botón ("Categorías: noticias ✕")
- "★ Más escuchadas" ignora el género activo (ranking global)
- Seleccionar género con ★ activo vuelve a Activas
- Buscador busca en nombre + provincia + tags (géneros)

**URL params**
- `?genero=noticias`, `?estado=ok`, `?m3u=1&genero=noticias`, `?buscar=`, `?n=NNN`
- `?n=NNN`: scroll a emisora compartida, arranca en "Todas" para verla aunque esté caída

**Tema claro/oscuro**
- Botón movido de fixed top-right a fila de badges junto al cafecito (2026-05-20)
- Ícono muestra destino: ☀️ Modo claro / 🌙 Modo oscuro
- Persiste en localStorage
- Overrides completos para todos los colores hardcodeados

**Compartir**
- Link, WhatsApp, QR por emisora
- Banner "Tocá para escuchar" al llegar por link compartido, desaparece a los 6s con fade
- shared-highlight (borde pulsante) persiste hasta que el usuario reproduce cualquier emisora (2026-05-20)

**SEO (2026-05-20)**
- Título: "Radio Argentina en vivo · N emisoras online"
- Open Graph completo: og:type, og:site_name, og:url, og:title, og:description
- Twitter Card: summary con title y description
- `<link rel=canonical>` explícito + canonical dinámico para ?n=
- Indexación solicitada en Google Search Console

**Toast de apoyo**
- Aparece a los 20s, dura 12s, una vez por día (localStorage TTL 24h)

### Archivos clave
- `web/index.php` — player web completo
- `web/listeners.php` — oyentes + ranking
- `web/log.php` — logging CSV a `web/logs/accesos_YYYY-MM.csv`
- `enrich.py` — genera `emisoras.json`
- `emisoras.json` — generado, no editar a mano
- `.git/hooks/pre-commit` — sincronización automática txt→json

### Deploy
FTP a mammoli.ar: `lftp` con credenciales en `/radio/`. GitHub: `camammoli/radio`.

---

## TKT-0691 — 2026-06-08 — Historial de streams + sugerencias de emisoras

### Historial de evolución de streams
- `verificar_urls.sh`: después de generar `status.json`, append snapshot a `web/status_history.json` (máx 360 entradas = 90 días)
- `check-streams.yml`: descarga `status_history.json` existente del servidor vía FTP antes de correr, para que el append acumule entre runs
- `web/estadisticas.php`: página con gráfico Chart.js (ok/caídas/timeout), comparativa (ahora vs 24h/7d/30d) y tabla de últimos 30 snapshots
- Rango seleccionable: 7d / 30d / 90d

### Sugerencias de emisoras
- `web/sugerir.php`: formulario público — valida URL, verifica stream con cURL (HEAD + fallback GET), guarda en `web/data/sugerencias.json`, notifica por Telegram
- `web/admin_sugerencias.php?key=RADIO_ADMIN_KEY`: panel admin — tabs pendiente/aprobadas/rechazadas, botones aprobar/rechazar, en aprobación genera línea lista para pegar en `emisoras.txt` + Telegram
- `web/config.php` (gitignoreado): RADIO_ADMIN_KEY, TG_TOKEN, TG_CHAT_ID
- `web/data/` (gitignoreado): sugerencias.json + .htaccess (Deny from all)
- `web/index.php`: links a Estadísticas y Sugerir emisora en el header

### Flujo de incorporación de sugerencia aprobada
1. Usuario sugiere → backend verifica stream → guarda como "pendiente"
2. Admin aprueba en admin_sugerencias.php → genera línea formateada para emisoras.txt
3. Carlos pega en `emisoras.txt` + commit → pre-commit hook regenera `emisoras.json` → deploy

---

## TKT-0692 — 2026-06-16 — SEO: páginas individuales por emisora

### Contexto
Google Search Console mostraba 0 impresiones para búsquedas por nombre de emisora específica
(ej: "FM Sol Mendoza"). El directorio era una única página con 827 emisoras — imposible que
Google la asociara a una emisora particular.

### Lo que se hizo
- `_radio_slug()` + `_radio_full_slug()`: genera slugs URL a partir de nombre + ciudad
- Interceptor en `index.php`: detecta `?station=slug`, carga la emisora y renderiza página
  individual con `<title>`, `<meta description>`, `<link canonical>`, JSON-LD `RadioStation`
  y player minimalista. Las emisoras con `estado: muerto` reciben `<meta name="robots" content="noindex">`
- `.htaccess`: rewrite `/radio/{slug}/` → `index.php?station={slug}` + `sitemap.xml` → `sitemap.php`
- `sitemap.php`: genera XML dinámico con todas las emisoras que no son `muerto` (~791 URLs)
- `index.php` (directorio): ícono `⬈` en nombre de cada emisora → link a su página individual
- `robots.txt`: eliminado `Disallow: /radio/`, bloqueados solo endpoints internos;
  agregado `Sitemap: https://mammoli.ar/radio/sitemap.xml`

### Resultado (medido 3 días después)
- 16/06: 227 impresiones · 17/06: 1.259 impresiones (x60 en 48hs)
- Páginas individuales ya indexadas: Radio Mitre (142 imp), Pop Radio (57), Estación Urbana (42)
- Posición promedio ~43 — se espera mejora gradual con el tiempo

### Pendiente
- Solicitar reindexación manual en Google Search Console
- Monitorear posiciones por emisora en 2-3 semanas

---

## TKT-0693 — 2026-06-19 — Corrección de nombres en emisoras.txt + plays.json + dedup

### Contexto
Análisis de logs reveló que tres emisoras tenían la URL del stream como nombre (entrada
malformada desde el crawler). El oyente de Resistencia no pudo escuchar Aspen ni Delta por
este motivo. plays.json también tenía esas URLs como claves.

### Lo que se hizo
- `emisoras.txt`: corregidos tres nombres malformados:
  - `[133] http//cdn2.instream.audio8007/stream` → `[133] Futurock`
  - `[#486] http//14983.live.streamtheworld.com3690/ASPENAAC_SC` → `[#486] Aspen`
  - `[109] http//cdn.instream.audio9069/stream` → `[109] Delta`
- `web/plays.json` (servidor): eliminadas las tres claves con URL rota (los plays
  históricos de esas entradas —4 en total— se perdieron; futuros plays se registran
  con nombre correcto)
- `dedup_urls.py`: script nuevo — detecta entradas con URL exactamente igual, conserva
  la de mayor metadata (logo > homepage > tags > codec > nombre más largo), elimina el
  resto. Dry-run por defecto; `--apply` para ejecutar. Resultado de la primera corrida:
  0 duplicados de URL exacta (los 142 nombres repetidos son emisoras distintas en
  distintas ciudades — correcto).

---

## TKT-0694 — 2026-06-19 — Notificaciones Telegram de oyentes (debug)

### Contexto
Se quería visibilidad en tiempo real de cuándo hay oyentes, sin tener que revisar logs.
Implementado como feature de debug desactivable desde config.

### Lo que se hizo
- `web/listeners.php`: cuando `$isNew && $station` (primera sesión de un oyente),
  si `NOTIFY_OYENTES` está activo, envía mensaje Telegram vía cURL con:
  nombre de emisora, IP del oyente y cantidad de oyentes activos.
  Timeout de 3s para no bloquear la respuesta al cliente.
  IP se lee de `HTTP_X_FORWARDED_FOR` (primer valor) con fallback a `REMOTE_ADDR`.
- `web/config.php` (gitignoreado, servidor): agregada constante `NOTIFY_OYENTES = true`
- `web/config.example.php`: agregada constante `NOTIFY_OYENTES = false` como default

### Activar / desactivar
`config.php` en el servidor → cambiar `NOTIFY_OYENTES` a `true` o `false`. Sin deploy.

### Formato del mensaje
```
🎙 Oyente: Dínamo 100.9
🌐 IP: 190.247.73.253
👥 Activos: 1
```

---

## TKT-0695 — 2026-06-20 — +331 emisoras desde Radio Browser API + filtro por provincias

### Contexto
928 emisoras era menos de la mitad de los directorios líderes (~1750). El buscador ya
filtraba por provincia vía texto libre pero no era obvio ni rápido.

### Emisoras incorporadas
- Fuente: `de1.api.radio-browser.info` — endpoint `/json/stations/search?countrycode=AR`
- 778 estaciones disponibles en API; 331 nuevas (no presentes en nuestras URLs)
- Formato: `[#NNN] Nombre * Provincia, Argentina` — provincia normalizada
- Total: 928 → 1259 emisoras
- `emisoras.json` regenerado: 33% logo / 34% tags / 53% codec / 52% homepage

### Filtro por provincias (UX)
- Panel "Provincias ▾" (mismo patrón que "Categorías ▾")
- 24 provincias con ≥4 emisoras, muestra conteo en cada botón
- Normalización de variantes: CABA/Ciudad Autonoma/Capital Federal → CABA;
  Córdoba/Córdoba(Argentina) → Córdoba; Provincia de Buenos Aires → Buenos Aires; etc.
- `applyFilters()` actualizado con `matchesProv()` — AND con género/estado/buscador
- Soporte `?provincia=Mendoza` en URL params
- Compatible con todos los filtros existentes

### Archivos modificados
- `emisoras.txt`: 331 entradas nuevas al final
- `emisoras.json`: regenerado por pre-commit hook
- `web/index.php`: PHP province_list/province_terms + CSS f-prov/f-provcat/province-panel + JS panel

---

## Historial de pendientes resueltos

- ✅ P1 Toast: key cambiada a `toast_ts_v2`, setItem movido al cierre (2026-05-22)
- ✅ P3 GitHub Action crawler: `.github/workflows/check-streams.yml` — cada 6hs (2026-05-22)
- ✅ TKT-0687: verificación paralela (30 workers) — de 30min+timeout a 2min (2026-05-22)
- ✅ TKT-0686: contraseña FTP eliminada del historial público, movida a `.ftp.conf` + GitHub Secret (2026-05-22)
- ✅ TKT-0691: historial de streams + sugerencias (2026-06-08)
- ✅ TKT-0692: SEO páginas individuales por emisora + sitemap (2026-06-16)
- ✅ TKT-0693: corrección de nombres malformados en emisoras.txt + dedup_urls.py (2026-06-19)
- ✅ TKT-0694: notificaciones Telegram de oyentes, desactivable con NOTIFY_OYENTES (2026-06-19)
- ✅ TKT-0695: +331 emisoras (928→1259) + panel filtro Provincias (2026-06-20)
- ✅ TKT-0696: crawler hunt_stations.py + GitHub Action hunt-stations.yml (2026-06-20)
- ✅ TKT-0697: aprobación automática vía GitHub Action add-station.yml (2026-06-20)
- ✅ TKT-0698: páginas individuales enriquecidas + participación (2026-06-20)

---

## TKT-0697 — 2026-06-20 — Aprobación automática de sugerencias vía GitHub Action

### Contexto
El panel de admin generaba una línea de texto para copiar manualmente a emisoras.txt,
seguido de git commit + deploy manual. Con el crawler trayendo lotes de sugerencias,
ese flujo no escala.

### Lo que se hizo
- `add-station.yml` (nuevo workflow): recibe `nombre`, `url`, `provincia`, `sug_id`
  como inputs de `workflow_dispatch`. Agrega la entrada a `emisoras.txt` calculando
  el número siguiente con bash, regenera `emisoras.json` con `enrich.py`, hace commit
  y push (permissions: contents: write), deploy FTP, y notifica por Telegram.
  Tiempo de ejecución: ~13 segundos.
- `web/admin_sugerencias.php`: acción `aprobar` ahora llama `github_dispatch()` que
  hace POST a la GitHub API (`/repos/camammoli/radio/actions/workflows/add-station.yml/dispatches`)
  usando `GITHUB_PAT` de `config.php`. Guarda `gh_dispatch: 'ok'|'error'` en sugerencias.json.
  Flash message indica éxito ("aparecerá en ~30 segundos") o error de API.
- `web/config.php` (servidor): agregada constante `GITHUB_PAT`
- `web/config.example.php`: agregada constante `GITHUB_PAT = ''`

### Flujo resultante
Aprobar en panel → PHP dispara Action → commit + deploy FTP en ~13s → Telegram → live

---

## TKT-0698 — 2026-06-20 — Participación y páginas individuales mejoradas

### Contexto
Las páginas individuales tenían lo mínimo (player, estado, info técnica). Con 1259 emisoras
y tráfico SEO creciente (x60 impresiones en 48hs desde TKT-0692), valía enriquecer cada
página y agregar más puntos de entrada a `sugerir.php`.

### Lo que se hizo

**Páginas individuales (`web/index.php`, bloque `?station=`):**
- Meta description enriquecida: provincia, géneros, codec/bitrate, total del directorio
- BreadcrumbList JSON-LD (complementa el RadioStation ya existente)
- OG image ya existía para logos; mejorada la meta description que la acompaña
- Sección "Otras radios de [provincia]": hasta 5 emisoras de la misma provincia,
  con logo (o ícono 📻 fallback), nombre, géneros y link a su página individual
- Botón "Reportar caída": POST en la misma página, notifica por Telegram vía TG_TOKEN,
  redirige con `?reportado=1` para mostrar confirmación
- Botón "Compartir": usa `navigator.share` en móvil, `clipboard.writeText` en desktop
- Link "¿Conocés otra radio de [provincia]? →": link a `sugerir.php?provincia=X`

**Página principal:**
- Cabecera: mientras `$total < 1500`, muestra "ayudanos a llegar a 1500 →" junto al conteo
- Cuando búsqueda/filtro da 0 resultados, aparece "¿No encontrás tu radio? Sugerila →"
- Footer nuevo: "Directorio actualizado el DD/MM/YYYY HH:MM" leyendo `count.json` (ya escrito
  en cada carga de `index.php`); link a `mammoli.ar`

**`web/sugerir.php`:**
- Formulario ahora acepta `?provincia=X` para prefill del campo "Provincia / País"
  (antes solo leía `$_POST`, ahora lee `$_GET` como fallback)

**`web/index.php`:** carga `config.php` (gitignoreado) para TG_TOKEN/TG_CHAT_ID necesario
  en el handler de reporte de caída. Patrón idéntico al de `admin_sugerencias.php`.


---

## TKT-0699 — 2026-06-20 — Corrección URL Continental + Respuestas gist + Mundial v2 actualizado

### Contexto
Retomando sesión anterior (TKT-0698). Tareas pendientes:
1. Actualizar mundial_v2.xlsx con resultados del 20/06/2026
2. Responder emails de radio (gist pisculichi/radios_nacionales.txt)

### mundial_v2.xlsx — Correcciones

Grupos que jugaron el 20/06/2026 (Groups E y F):
- **Grupo E**: Alemania 2-1 Costa de Marfil, Ecuador 0-0 Curazao
- **Grupo F**: Países Bajos 5-1 Suecia

Además se detectaron errores en los datos de jornada 1 (grupos H, I, J, K, L):
- Grupo H: Uruguay/Arabia Saudita no ganaron — fue 1-1 y España 0-0 Cabo Verde
- Grupo I: Noruega 4-1 Irak (no 3-0)
- Grupo J: Argentina 3-0 Argelia, Austria 3-1 Jordania (datos originales incorrectos)
- Grupo K: R.D.Congo empató 1-1 con Portugal (no ganó)
- Grupo L: Ghana ganó 1-0 a Panamá (no empató)

Se corrigieron ambas hojas (Por Grupo y Tabla General) con script Python.

### Gist pisculichi/radios_nacionales.txt — Respuestas

Leídos ~966 comentarios, identificados los recientes de 2026:

| Usuario | Pregunta | Respuesta dada |
|---------|----------|----------------|
| anibeat | Continental rota | URL streamtheworld (comment 6209813) |
| matferna | Led FM + Blackie | Confirmado que están en mammoli.ar/radio (comment 6209814) |
| dariomineria | Qué apps usar | VLC + mammoli.ar/radio (comment 6209815) |
| Guskrilon | MMS + Misiones FMs | Explicación MMS + Radio Light URL + no URLs para Classic/Express (comment 6209816) |

No se encontraron URLs para FM Classic 90.3 y FM Express 96.5 (Misiones) — sitios sin stream expuesto.

### URL Continental actualizada

Entrada #070 tenía URL rota `https://edge02.radiohdvivo.com/continental`.
Actualizada a `https://20833.live.streamtheworld.com/CONTINENTALAAC.aac`.

**Incidente deploy**: deploy FTP con `--delete` eliminó emisoras.json, emisoras.txt,
plays.json, data/sugerencias.json y count.json del servidor (son archivos que viven
solo en el servidor, no en web/). Se restauraron manualmente con lftp put.
**Lección**: el deploy a /radio/ NO debe usar `--delete` o deben excluirse los
archivos de datos (emisoras.json, emisoras.txt, plays.json, plays/*.json,
data/sugerencias.json, count.json, listeners.json, logs/).

---

## TKT-0709 — 2026-06-24 — V2-009: Cutover a producción

### Resumen
Deploy completo de v2 a mammoli.ar/radio/. Producción migrada de PHP monolítico + JSON planos a arquitectura SQLite + API REST + páginas separadas.

### Proceso
1. Mirror `web/` → `/radio/` sin --delete (conserva datos de servidor: plays.json, status.json, emisoras.json, etc.)
2. Excluir config.php del mirror → subir production config.php manualmente con RADIO_DB definido
3. Subir SQLite DB a `/radio/db/radio_v2.sqlite`
4. Limpiar `/radio/web/` espurio (mirror accidental de sesión anterior)

### Bugs encontrados y corregidos en cutover
- **RADIO_DB path**: `_db.php` tenía default `__DIR__ . '/../../db/'` (2 niveles arriba desde api/) → correcto para staging (beta/api/), incorrecto para prod (api/). Fix: definir en config.php como `__DIR__ . '/db/radio_v2.sqlite'`. Default cambiado a `/../db/` (1 nivel).
- **playlist.php WHERE**: `approved = 1` en WHERE era inválido — `v_stations` ya filtra approved y no expone esa columna. Eliminado.
- **sitemap.php**: reescrito para leer slugs del DB (v_stations) en lugar de JSON de GitHub.

### Verificación final (todos OK)
```
https://mammoli.ar/radio/                                     → 1257 emisoras en vivo
https://mammoli.ar/radio/radio-rivadavia-buenos-aires/        → página individual
https://mammoli.ar/radio/api/stations?limit=2                 → JSON {ok:true, total:1257}
https://mammoli.ar/radio/api/playlist.m3u                     → #EXTM3U, 1198 emisoras
https://mammoli.ar/radio/?m3u=1                               → 301 → api/playlist.php → M3U
https://mammoli.ar/radio/sitemap.xml                          → 1199 URLs con slugs v2
```

### Estado post-cutover
- Producción: v2 activo. SQLite como fuente de verdad.
- V1 emisoras.json + emisoras.txt: siguen en servidor (no borrados). radio.sh CLI los usa.
- Staging /radio/beta/: sigue activo (config actualizada también).
- GitHub Actions check-streams.yml: sigue corriendo (actualiza status.json v1, no SQLite). 
  Pendiente: migrar a check-streams-v2.yml cuando GitHub Action pueda bajar/subir DB.

---

## TKT-0708 — 2026-06-24 — V2: crawlers SQLite + radio2.sh CLI + staging /radio/beta/

### Resumen
Continuación del desarrollo v2 — completado V2-006 a V2-008.

### V2-006: Crawlers SQLite

**`db/radio_db.py`** — módulo Python para conexión SQLite compartida (WAL, row_factory, busy_timeout=5000)

**`crawlers/check_streams_v2.py`**
- Verificación HTTP paralela (30 workers por default)
- Detecta y registra en station_events: `went_down`, `came_back`, `icy_gained`, `icy_lost`
- Actualiza `stream_status` (UPSERT), `stream_history`, `icy_cache`
- `--notify`: envía eventos pendientes a Telegram en bloque (max 20 por run)
- `--icy`: lee StreamTitle vía socket raw para ICY streams activos
- Registra cada run en `crawler_runs`

**`crawlers/enrich_v2.py`**
- Descarga Radio Browser API (AR+UY), cruza por URL normalizada
- Actualiza logo, tags, homepage, codec, bitrate, rb_uuid, rb_votes, rb_clicks en DB
- `--icy`: para sin-match, verifica ICY headers → detecta icy_gained/icy_lost
- `--force`: re-enrich aunque ya tengan rb_uuid

**`crawlers/hunt_stations_v2.py`**
- Descubre emisoras nuevas en AR+UY que no están en la DB
- Inserta con `approved=0` (requieren aprobación) o `--approve` para directo
- Verifica URL antes de insertar, slug único generado en Python

**GitHub Actions v2**
- `check-streams-v2.yml`: cron cada 6hs — download DB → check → upload
- `enrich-v2.yml`: cron días 1 y 15 — download DB → enrich → upload
- Ambos pasan TG_TOKEN/TG_CHAT_ID desde secrets

### V2-007: CLI radio2.sh

**`radio2.sh`** — reemplaza radio.sh consumiendo API REST en lugar de emisoras.txt:
- `radio2.sh` → lista top 20 más escuchadas (API call, tabla con ♪ + provincia + plays)
- `radio2.sh <búsqueda>` → busca en API, menú numerado si hay múltiples resultados
- Muestra: estado (●), ICY (♪), provincia, listener count, now-playing actual
- Monitor ICY en background: cada 30s actualiza `♪ Ahora suena:` mientras reproduce
- Soporte mplayer/cvlc/mpv (default mplayer)
- Variable `RADIO_API` para apuntar a otro endpoint

### V2-008: Staging /radio/beta/

- `RADIO_BASE` constant en config.php controla el prefijo de assets y manifest
- `head.php` y `station.php` usan `RADIO_BASE` (default `/radio`)
- Deploy a `/radio/beta/` con config específico (`RADIO_BASE=/radio/beta`, `NOTIFY_OYENTES=false`)
- DB SQLite subida a `/radio/db/radio_v2.sqlite` en servidor
- `.htaccess` específico para beta con `RewriteBase /radio/beta/`

### Verificación staging
```
https://mammoli.ar/radio/beta/                     → listing OK (1257 emisoras)
https://mammoli.ar/radio/beta/radio-rivadavia-buenos-aires/  → station page OK
https://mammoli.ar/radio/beta/api/stations?limit=3 → API JSON OK
```

### Pendiente
- V2-009: cutover producción — requiere aprobación de Carlos

---

## TKT-0707 — 2026-06-24 — V2: Arquitectura completa — modelo de datos, API, player, pages

### Contexto
V1 creció hasta un monolito de ~1811 líneas en index.php + JSON planos. Refactoring estructural
completo a V2 en rama `v2`, sin romper producción en `master`.

### Decisiones de arquitectura
- **SQLite con WAL** como base de datos (reemplaza emisoras.json, status.json, plays.json, icy_stations.json)
- **PDO singleton** `radio_db()` — todos los endpoints lo usan, sin conexiones duplicadas
- **Slugs únicos** generados por `_radio_slug()` / `_radio_full_slug()`, con sufijo `-{n}` anti-colisión
- **9 tablas** + 2 vistas: stations, stream_status, stream_history, station_events, icy_cache, plays, listeners, surveys, crawler_runs + v_stations + v_active_listeners
- **API REST** en `/radio/api/` con helpers `api_response` / `api_error` / `api_method`
- **M3U stable**: `/radio/api/playlist.m3u` con 301 desde `?m3u=1` para backward compat
- **Factory function** `RadioPlayer(opts)` — sin clases, sin `this` binding — estados: idle/connecting/playing/buffering/error
- **HLS.js** desde CDN para adaptive streams; fallback a `<audio>` nativo
- **Page Visibility API** + sendBeacon para heartbeat mobile-safe
- **Server-side render** del listing: PHP genera todas las cards, JS filtra en cliente (sin SSR/hydration)
- **CSS namespace `rp-*`** para player, variables CSS para temas dark/light

### Tickets incluidos
- V2-001: diseño + docs/V2_DESIGN.md + db/schema.sql (9 tablas + 2 vistas)
- V2-002: migrate_v1.py — lector JSON → SQLite (1257 emisoras migradas, slug gen idéntico a PHP)
- V2-003: API REST — stations.php, playlist.php, listeners.php, nowplaying.php, survey.php, suggest.php
- V2-004: player unificado — assets/player.js, assets/player.css, assets/theme.js
- V2-005: router + pages — index.php (router), pages/listing.php, pages/station.php, components/head.php, assets/style.css

### Archivos creados / modificados (ramas v2)
```
db/schema.sql
db/migrate_v1.py
web/api/_db.php
web/api/_helpers.php
web/api/stations.php
web/api/playlist.php
web/api/listeners.php
web/api/nowplaying.php
web/api/survey.php
web/api/suggest.php
web/api/.htaccess
web/.htaccess          (rewrites para /api/{endpoint} y /api/stations/{slug})
web/index.php          (router limpio, 37 líneas)
web/pages/listing.php
web/pages/station.php
web/components/head.php
web/assets/player.js
web/assets/player.css
web/assets/theme.js
web/assets/style.css
```

### Pendientes V2
- V2-006: crawlers → escribir en SQLite + station_events (icy_gained/lost, came_back, went_down)
- V2-007: radio2.sh — CLI que consume API, muestra ICY + listener count
- V2-008: staging /radio/beta/ + test migration completa
- V2-009: cutover producción — FTP deploy v2 → /radio/

---

## TKT-0706 — 2026-06-24 — Fix: heartbeat oyentes en páginas individuales + badge ICY más visible

### Problema
Las páginas individuales de emisora (`?station=slug`) no registraban oyentes en `listeners.php`:
- No se enviaba notificación a Telegram al reproducir desde esa URL
- El contador de oyentes activos no se incrementaba
- El badge ICY "♪" era demasiado discreto (translúcido, sin texto)

### Solución

**Heartbeat en páginas de estación** (`index.php` — sección station, JS)
- Se agrega SID único por sesión (`Math.random() + Date.now()` en base 36)
- `lPing()`: llama `listeners.php?action=ping&sid=X&station=NOMBRE` al iniciar reproducción
- Heartbeat cada 30s con `setInterval` mientras el audio está activo
- `lStop()` con `sendBeacon` en `pause`, `error` y `beforeunload`
- Reutiliza el mismo `listeners.php` que el listado → misma lógica Telegram, mismo contador

**Badge ICY más visible** (CSS + JS del listado principal)
- Antes: "♪" 10px, fondo `rgba(167,139,250,.12)`, color `#a78bfa`
- Ahora: "♪ ahora suena" 10px bold, fondo sólido `#7c3aed`, texto `#fff`
- Tooltip actualizado: "Esta emisora muestra la canción que está sonando"

### Verificación
- Probado con `listeners.php` real: La Brújula 24, LV12, Frecuencia Plus, Delta, Alfa 91.5 — todas responden con ICY OK
- Confirmar en Telegram que llegan notificaciones al reproducir desde `/radio/?station=...`

---

## TKT-0705 — 2026-06-24 — Íconos PWA + estandarización UI + badges ICY metadata

### Contexto
Continuación de TKT-0704. Cuatro mejoras agrupadas en un solo commit.

### Cambios

**Íconos PWA** (`icon-192.png`, `icon-512.png`)
- Generados con Python PIL: antena de radio + ondas azules sobre fondo #111827
- Referenciados en `manifest.json` como `"purpose": "any maskable"`
- Sin dependencias externas de diseño

**Estandarización de páginas individuales** (`index.php` — sección station)
- Header unificado `<header class="site-header">` igual al de la página principal
- Barra de compartir idéntica a la del listado: 🔗 Copiar link / 💬 WhatsApp / ⬛ QR
- Modal QR con `api.qrserver.com`, misma lógica que en el player principal
- Mismo sistema de tema (localStorage `radio_theme`) compartido entre todas las páginas

**ICY metadata detection** (`icy_stations.json`, nuevo)
- Script Python con threading (50 hilos, timeout 5s) verificó 690 streams HTTP
- 147/690 streams soportan ICY metadata (21%)
- Badge `♪` clase `.icy-badge` (píldora violeta) en el listado general
- JS fetch carga `icy_stations.json` y aplica badges al DOM después de render

### Archivos nuevos
- `icon-192.png`, `icon-512.png` — íconos PWA
- `icy_stations.json` — 147 URLs con soporte ICY (regenerar periódicamente)

---

## TKT-0704 — 2026-06-24 — Plan de marketing: dark/light + PWA + schema + survey + now playing + SEO

### Contexto
Plan de marketing no invasivo implementado integralmente. Foco: visibilidad orgánica y
retención de oyentes sin publicidad, popups ni dark patterns.

### Cambios implementados

**Tema oscuro/claro** (CSS variables en `index.php`)
- Variables `:root` para `--bg`, `--surface`, `--border`, `--text`, `--muted`, `--accent`
- Override `body.light` para modo claro
- Toggle persistido en `localStorage` clave `radio_theme`, compartido entre todas las páginas
- Mismo sistema aplicado a páginas de emisora individual

**PWA — Progressive Web App** (`manifest.json`, `sw.js`)
- `manifest.json`: nombre, scope `/radio/`, display standalone, colores, íconos 192/512
- `sw.js`: service worker con precache del shell (`/radio/`, `manifest.json`)
- Network-first para endpoints dinámicos (proxy.php, nowplaying.php, survey.php, etc.)
- Meta tags apple-mobile-web-app-* para iOS
- Registro del SW en `index.php` al final del JS

**Schema.org JSON-LD** (en páginas individuales)
- Tipo `RadioBroadcastService` con `broadcastFrequency` extraída por regex del nombre
- `potentialAction: ListenAction` con `target` = URL del stream
- `og:audio` meta tag para embeds en redes sociales

**Survey de satisfacción** (`survey.php`, toast en `index.php`)
- Toast aparece tras 3 minutos continuos de reproducción
- Opciones: 👍 / 😐 / 👎 (ratings 1/0/-1)
- Cooldown 30 días por emisora (si ya valoró), 7 días si cerró sin valorar
- Keys localStorage: `survey_v1_{slug}`
- `survey.php`: guarda en `data/survey.csv` con timestamp, IP, rating, station
- No bloquea reproducción, cierre instantáneo

**Now playing** (`nowplaying.php`, poller JS)
- `nowplaying.php`: fetcha stream con `Icy-MetaData: 1`, lee metaint bytes, parsea `StreamTitle=`
- Caché 30s en `/tmp/radio_np_MD5.json` para no sobrecargar el stream
- JS en páginas individuales: polling cada 30s mientras está reproduciéndose
- Muestra artista/título en el player si el stream lo soporta

**Páginas SEO por provincia** (en `index.php` — listado principal)
- `$filtro_prov_seo`: si `?provincia=` está en la URL, ajusta `$page_title`, `$page_desc`, `$page_canon`
- Ejemplo: `/radio/?provincia=mendoza` → "Radios de Mendoza | Radio Argentina"

### Archivos nuevos
- `manifest.json` — manifiesto PWA
- `sw.js` — service worker
- `nowplaying.php` — endpoint ICY metadata
- `survey.php` — endpoint encuesta de satisfacción

---

## TKT-0703 — 2026-06-22 — Google Analytics 4 + Klimax recuperada

**Google Analytics 4** agregado a `web/index.php` (directorio y páginas individuales).
Condicional: solo se activa si `config.php` define `GA_ID`. ID configurado: `G-BRGB9LNXXY`.
No afecta SEO ni Search Console — los complementa. Ad blockers de escritorio ocultan
algunos hits; en mobile funciona correctamente.

**Klimax #594** URL reemplazada vía candidatos_recuperados.json:
`http://streamall.alsolnet.com:443/klimaxok` → `https://streamall.alsolnet.com/fmklimax`

---

## TKT-0702 — 2026-06-22 — Tracking por stream + búsqueda activa de URLs caídas

**track_since.py** (nuevo): corre después de cada check (cada 6hs) y mantiene
`web/stream_since.json` con la fecha en que cada URL entró en timeout/muerto.
Cuando una URL se recupera, se borra del registro. Permite saber cuánto lleva
cada stream caído (dato que status_history.json no tenía — solo guardaba totales).

**recuperar_caidas.py** (extendido): nuevos flags:
- `--include-timeout`: busca también URLs en timeout, no solo muertas
- `--output-json FILE`: guarda candidatos en JSON sin tocar emisoras.txt
- `--limit N`: procesa máximo N URLs

**check-streams.yml**: descarga stream_since.json antes del check, corre
track_since.py después y lo sube al servidor.

**hunt-stations.yml**: nuevo paso "Buscar URLs alternativas" — descarga status.json,
corre recuperar_caidas.py --output-json, sube candidatos_recuperados.json al servidor
e incluye el conteo en el mensaje de Telegram. Timeout del job extendido a 35min.

---

## TKT-0701 — 2026-06-21 — Comentario gist sin publicidad

`gist_sync.py`: el comentario semanal que el bot postea en el gist original de pisculichi
pasó a formato minimalista — solo nombre, provincia y URL de stream, como hace cualquier
usuario del gist. Se eliminó el texto promocional y el link a mammoli.ar/radio.

Motivo: postear publicidad automatizada en un espacio comunitario se considera spam.

Si hay más de 10 emisoras nuevas en la semana, el comentario muestra solo las primeras 5
y dice "... y varias más." sin revelar el número exacto. Evita comentarios largos en el feed.

**Archivo:** `gist_sync.py` — función `main()`, bloque "Postear comentario en gist original".

---

## TKT-0700 — 2026-06-21 — Sincronización bidireccional con gist pisculichi/radios_nacionales.txt

### Contexto
El gist https://gist.github.com/pisculichi/fae88a2f5570ab22da53 es una referencia
histórica de URLs de radios AR con comunidad activa (~966 comentarios, 37 forks).
Carlos ya había comentado allí como camammoli. Se implementó integración completa.

### Archivos nuevos / modificados

- `gist_sync.py` — nuevo script de sincronización:
  - Parsea emisoras.txt → genera archivo formateado por provincia
  - PATCH al fork via GitHub API
  - Detecta emisoras nuevas (git log --since) → postea comentario en gist original
  - Filtro de estaciones de prueba (TKT-NNN)
  - Token: GITHUB_TOKEN env var → fallback gh CLI

- `hunt_stations.py` — dos nuevas fuentes:
  - `gist-file`: lee el archivo del gist de pisculichi (URLs curadas desde 2015)
  - `gist-comments`: escanea comentarios desde 2024 buscando URLs http(s)

- `.github/workflows/hunt-stations.yml` — nuevo step post-crawler:
  `python3 gist_sync.py --since "7 days ago"` con secrets.GITHUB_PAT

### Estado inicial
- Fork creado: https://gist.github.com/camammoli/21ce6e3ba07486bcd16a28cda967f0d9
- Fork actualizado con 1257 emisoras formateadas (21/06/2026)
- Primer comentario del bot posteado (id 6210260) en el gist original
- Nota: primer run detectó 334 "nuevas" por batch imports recientes de TKT-0695/0698.
  Los runs semanales siguientes tendrán sets pequeños (5-20 estaciones normalmente).

### Pendientes
- Verificar que secrets.GITHUB_PAT tenga scope `gist` en GitHub Actions
- Próximo lunes: confirmar que el step de sync corra sin errores en el workflow
