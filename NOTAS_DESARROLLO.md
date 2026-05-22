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

## Historial de pendientes resueltos

- ✅ P1 Toast: key cambiada a `toast_ts_v2`, setItem movido al cierre (2026-05-22)
- ✅ P3 GitHub Action crawler: `.github/workflows/check-streams.yml` — cada 6hs (2026-05-22)
- 📅 Bots de mantenimiento: reactivar lunes 2026-05-26 (gestión, no radio)
