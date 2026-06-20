# NOTAS DE DESARROLLO — Radio Argentina

Player web en [mammoli.ar/radio](https://mammoli.ar/radio/) + script de terminal `radio.sh`.

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
