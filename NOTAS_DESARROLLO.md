# NOTAS DE DESARROLLO — Radio Argentina

Player web en [mammoli.ar/radio](https://mammoli.ar/radio/) + script de terminal `radio.sh`.

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
- Botón 🌙/☀️ fijo arriba a la derecha
- Persiste en localStorage
- Overrides completos para todos los colores hardcodeados

**Compartir**
- Link, WhatsApp, QR por emisora
- Banner "Tocá para escuchar" al llegar por link compartido, desaparece a los 6s con fade

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

## Pendientes — TKT-0681 (próxima sesión)

### P1 — Link compartido no scroll a la emisora correcta
Al llegar con `?n=50`, el querySelector encuentra la emisora pero si está oculta por filtros
el scroll falla y "salta a cualquiera". Fix: asegurarse de que el elemento esté visible antes
de hacer scrollIntoView, o esperar a que se apliquen los filtros del status.json.

### P2 — Toast no aparece
El toast con localStorage TTL 24h fue implementado pero Carlos reporta que no aparece.
Posible causa: la key anterior (`toast_v2` en sessionStorage) ya estaba marcada y hay conflicto,
o el localStorage `toast_ts` quedó seteado. Verificar y resetear lógica.

### P3 — Revisar bots de mantenimiento
Los bots de email/gestión estaban temporalmente deshabilitados (semana del 2026-05-19).
Reactivar el lunes 2026-05-25 y verificar estado: modo legal, identidad escalonada,
firmas dinámicas, pending por cuenta. Ver `project_email_bot.md` y `feedback_bot_deploy.md`.
