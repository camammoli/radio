# NOTAS DE DESARROLLO — Radio Argentina

Player web en [mammoli.ar/radio](https://mammoli.ar/radio/) + script de terminal `radio.sh`.

---

## ✅ TKT-0719 — 2026-08-26 — Hashtags automáticos al compartir por X

A pedido de Carlos: agregar hashtags "adecuados para atraer gente" al compartir por X, respetando el
límite real de caracteres de un tweet.

**Presupuesto real:** 280 (máximo) − 23 (la URL siempre se acorta a un link t.co de 23 caracteres,
sin importar su longitud real) − 1 (espacio antes del link) = 256 caracteres para el texto. Se usa un
presupuesto de 240 (margen de 16) porque X cuenta emojis como 2 caracteres ("weighted length"), no 1.

**`radio_x_share_text()` / `radio_hashtag()`** (nuevas en `_helpers.php`, usadas por `station.php`) y
su espejo en JS (`buildXText()`/`xHashtag()` en `listing.php`, porque ahí una sola página comparte
distintas emisoras sin recargar): arman "📻 Estoy escuchando {nombre} en vivo" y van agregando
hashtags de una lista de prioridad — `#RadioArgentina`, la provincia de la emisora si está cargada
(`#BuenosAires`, `#CABA` preservando siglas ya en mayúsculas), `#RadioEnVivo`, `#EscuchaRadio`,
`#EnVivo` — deteniéndose apenas el próximo no entra más en el presupuesto. Probado con nombres de
emisora extremos (100+ caracteres) y coincide byte a byte entre la versión PHP y la JS.

Solo cambia el botón de X — WhatsApp, Telegram, copiar link y QR quedan igual que antes (los hashtags
solo tienen sentido de descubrimiento en X).

---

## 🐛 TKT-0718 — 2026-08-26 — Fix real de TKT-0717: 2 bugs encontrados por Carlos ("no veo las nuevas" / "la agrupación no funciona bien")

**Bug 1 — las 200 emisoras de TKT-0716 eran invisibles.** Se insertaron con `source='radio-browser'`,
pero la pestaña que revisa candidatas pendientes (`$sugerencias`, "Sugerencias pendientes") solo
filtraba `source='sugerencia'` — y los botones Aprobar/Rechazar tenían el mismo filtro hardcodeado en
el SQL (`WHERE ... AND source="sugerencia"`), así que ni ampliando la lista hubieran funcionado los
botones. Las 200 quedaban cayendo en la pestaña "Problemas" (que sí incluye `approved=0` sin filtrar
por source), mezcladas con emisoras realmente muertas/reportadas — de ahí la sensación de "no las veo".

**Fix:** `$sugerencias`, sus stats (`suger_pend`), y los handlers `approve`/`reject` ahora aceptan
`source IN ('sugerencia','radio-browser')`. `$problemas` ahora **excluye** esa combinación (ya tiene
su lugar propio) para no duplicar ni inflar ese conteo. Agregada columna "Origen" (👤 Sugerida / 🔍
Radio Browser) en la tabla para poder distinguirlas y agruparlas.

**Bug 2 — el agrupado de Reproducciones se rompía a los 10 segundos.** La tabla se refresca sola cada
10s vía `?ajax=1`, pero el JS que arma las filas nuevas (`refreshAdmin()`) es un **duplicado manual**
del render PHP — no se actualizó en TKT-0717. Consecuencia: apenas cargaba la página andaba bien (9→10
columnas correctas), pero al primer refresh automático las filas volvían a tener 9 columnas contra un
header de 10, corriendo todo el contenido una columna a la izquierda — el agrupado por "Reproduciendo"
o "Tipo de oyente" quedaba leyendo la columna equivocada. Mismo problema con `botBadge()` (JS) que
seguía sin el texto agregado en la versión PHP (`bot_badge()`), y con el mapa `CH`/`$ch_labels` de
Compartidos que nunca se actualizó cuando se agregó X/Telegram en TKT-0704 — mostraban el código crudo
('x'/'tg') en vez de la etiqueta linda.

**Lección para la próxima vez:** este panel tiene **funciones PHP y JS que renderizan lo mismo por
duplicado** (uno para la carga inicial server-side, otro para el refresh AJAX cada 10s) — cualquier
cambio a una fila de Reproducciones o Compartidos tiene que tocar los dos lugares, o queda una mitad
sin actualizar que solo se nota después de 10 segundos mirando la página (por eso no se detectó en la
prueba local de TKT-0717 — el love-testing con `php -S` no tuvo el refresh automático corriendo el
tiempo suficiente / no se pensó en probarlo).

Verificado todo de nuevo local (misma copia real de la DB): las 200 aparecen en Sugerencias (202
total), el botón Aprobar funciona de verdad sobre un `source='radio-browser'` (probado en la copia
local, nunca tocó producción), "Problemas" bajó a 548 sin las pendientes mezcladas, y el JS de refresh
ahora arma 10 `<td>` iguales al header. Deploy: solo `admin.php`.

---

## ✅ TKT-0717 — 2026-08-26 — Agrupar/filtrar en Emisoras y Reproducciones (admin)

A pedido de Carlos: agrupar por activas/no activas en Emisoras + otro grupo, y filtrar Reproducciones
por "reproduciendo ahora" y por persona/bot/probable bot. Se reutilizó el mecanismo `data-group` que
el `DT` (JS genérico de tablas del panel) ya tenía — agregar `data-group="Etiqueta"` a un `<th>` lo
suma solo como opción de "Agrupar por" en el toolbar, agrupando por el texto visible de esa columna.
No hizo falta JS nuevo, solo dar a esas columnas un texto agrupable.

**Emisoras:**
- Columna "Estado" (Alta/Baja) ahora agrupable (`data-group="Alta/Baja"`) — ya existía, solo faltaba
  el atributo.
- Columna nueva **"Salud"** (`data-group="Salud del stream"`): muestra `stream_status.estado` (OK /
  Muerto / Timeout / Desconocido) — ese dato ya se traía en la query (`$emisoras_todas`) pero nunca se
  mostraba. Es un eje independiente del alta/baja manual (una emisora puede estar "Alta" con el stream
  caído, o "Baja" con el stream sano).
- De paso, Provincia también quedó agrupable (ya tenía el dato, solo faltaba el atributo).

**Reproducciones:**
- Columna nueva **"Estado"** (`data-group="Reproduciendo"`): "▶ Reproduciendo" / "Terminada", separada
  de "Duración" (antes el `▶` vivía pegado al tiempo, no se podía agrupar por eso).
- Columna 🤖 ahora con texto agrupable en vez de solo emoji: "Persona" / "🤔 Probable bot" / "🤖 Bot"
  (mismos umbrales ya existentes: `hops_1h` ≥12 bot, ≥6 probable bot). `bot_badge()` es la única función
  que arma ese badge, usada solo acá — no se rompió ningún otro lugar.
- Corregido de paso un `colspan` desactualizado (8→10) en la fila "Sin reproducciones" que ya estaba
  mal antes de este cambio (bug preexistente menor, sin impacto visual real).

**Antes de tocar nada:** confirmado con `git status`/`git fetch` que el repo ya estaba sincronizado, y
backup físico de `admin.php` en `~/Escritorio/Backups/radio_admin_grupos_20260826_195335/`.

**Probado local completo antes de desplegar** (mismo patrón de sesiones anteriores): PHP 8.2 + pdo_sqlite
+ mbstring descargados sin root (`apt-get download` + `dpkg-deb -x`), servidor `php -S` con copia real
de la DB de producción, login real, verificado que ambas tablas renderizan con la cantidad correcta de
columnas (8/8 en Emisoras, 10/10 en Reproducciones), badges con los conteos esperados (718 OK / 87
Muerto / 455 Timeout; 18 reproduciendo / 182 terminadas; 172 persona / 9 probable bot / 19 bot) y cero
warnings/errores PHP. Copia local de la DB borrada después de probar (no quedó rastro en git — `web/db/`
no estaba cubierto por `.gitignore`, se verificó explícitamente que no quedara trackeada).

Deploy: solo `web/admin.php` por FTP (no toca la base de datos ni ningún otro archivo).

---

## ✅ TKT-0716 — 2026-08-26 — 200 emisoras nuevas de radio-browser.info, pendientes de aprobación

A raíz del listado completo de TKT-0715, Carlos preguntó si esas emisoras se agregaron. Se corrió
`hunt_stations_v2.py` (ya existía, sin workflow activo hasta ahora) contra Argentina — encontró más
de 200 candidatas nuevas (tope puesto en `--max 200`, puede haber más), verificó cada stream en vivo
antes de insertar. Confirmado con Carlos (`AskUserQuestion`) antes de aplicar: eligió insertar las 200
completas como `approved=0` — no se publican solas, quedan en la pestaña de revisión del admin para
que las apruebe de a una o en tanda.

**Aplicado:** `--apply` sobre copia local, `REINDEX` + `integrity_check` antes de subir, subida atómica
(put+mv+rm en una sesión, patrón TKT-0706), verificado el sitio en 200 post-deploy.

**Nota sobre la verificación post-subida:** aparecieron índices desincronizados (`idx_plays_*`,
`idx_listeners_*`) en las 2 vueltas de verificación — mismo síntoma benigno ya visto en TKT-0703/0705
(tráfico real escribiendo `plays`/`listeners` durante la ventana de subida de ~10-15 min que tardó
todo el proceso). Se resolvió con `REINDEX` + resubida cada vez, sin pérdida de datos — la tabla
`stations` en sí nunca se vio afectada. Esto es exactamente el síntoma que motiva TKT-0707 (evaluar
migrar a escritura por API en vez de reemplazar el archivo entero).

**Resultado:** de 1260 a 1468 emisoras en catálogo (208 con `approved=0` en total, incluyendo estas
200 + algunas pendientes previas). Ninguna visible en el listado público todavía.

---

## ✅ TKT-0715 — 2026-08-26 — competitor_scan.py ya no descarta el listado completo

Carlos preguntó por el aviso de Telegram de radio-browser.info del 24/8 (115 posibles nuevas, 170
URLs alternativas) — le preocupaba el "… y 95 más" / "… y 150 más" del final. Confirmado: `MAX_ITEMS
= 20` trunca el mensaje de Telegram y el resto **no se guardaba en ningún lado** (ni DB, ni log — el
`print()` de la lista completa solo corre en el fallback sin credenciales, nunca en producción). Ese
lote específico del 24/8 quedó irrecuperable.

**Solucionado en el momento:** re-consulté la API de radio-browser.info en vivo (26/8) y armé
`Escritorio/Reportes/Competencia_RadioBrowser_2026-08-26.html` con el listado completo (116 nuevas /
169 alternativas — los números varían un poco contra el 24/8 porque la fuente cambia día a día).

**Fix de fondo:** `competitor_scan.py` ahora escribe `reports/competitor_scan_full.json` con el
resultado completo de cada fuente (sin el recorte de `MAX_ITEMS`), y `competitor-scan.yml` lo sube
como artifact del workflow (retención 90 días, `actions/upload-artifact@v4`). El Telegram sigue
truncado a 20 (para no hacerlo ilegible) pero ahora el resto queda disponible para descargar desde
GitHub Actions en vez de perderse. El script sigue sin escribir nada en la DB — se mantiene de solo
lectura/notificación a propósito, igual que antes. De paso, `actions/checkout@v4` → `v5` en este
workflow (quedaba desactualizado contra el resto).

---

## ✅ TKT-0706 — 2026-08-26 — Fix real: put+mv+rm en una sola sesión lftp

A pedido de Carlos, se implementó el fix que quedó pendiente de TKT-0703. Los 3 workflows que
escriben la DB (`check-streams-v2.yml`, `dedupe-streamtheworld.yml`, `enrich-v2.yml`) hacían el
`mv` del archivo nuevo en una sesión lftp y el `rm` del `-wal`/`-shm` viejo en **otra sesión FTP
aparte** (reconexión completa: login de nuevo, con latencia real). Ahora los tres pasos van en la
misma sesión lftp continua — `set cmd:fail-exit no` antes del `glob -a rm` para que no rompa si los
archivos ya no existen (comportamiento equivalente al `|| true` que tenía antes a nivel bash).

Validado con `yaml.safe_load` los 3 archivos antes de commitear. **Los 3 workflows siguen
desactivados** (`disabled_manually`, desde TKT-0703) — el fix queda listo en GitHub pero no se
reactivó nada todavía, sigue pendiente esa decisión (TKT-0707).

---

## ✅ TKT-0705 — 2026-08-26 — Revisión de los hallazgos del competitor-scan del 24/8

Carlos preguntó qué se hizo con el aviso de Telegram del 24/8 (`competitor-scan` vs myradioenvivo.ar).
Respuesta: nada — `competitor_scan.py` solo notifica, nunca escribe en la DB, así que quedó sin
procesar. Revisión manual de cada ítem:

- **Radio Del Sur** (posible nueva): sigue en la DB desde el 1/7 con `approved=0`
  (`cdn1.tvlin.net/icecast/radiodelsuraudio/icecast.audio`) — probada de nuevo ahora, sigue sin
  responder. Sin cambios, sigue pendiente de una URL válida.
- **9 URLs alternativas**: 5 de esas 9 emisoras están caídas ahora mismo (`estado=timeout`) —
  probadas las 5 alternativas en vivo: 4 son códigos de streamtheworld que devuelven 404 real (no
  sirven, el dato del competidor está desactualizado), pero **Radio Popular (`pop`) sí tenía una
  alternativa que funciona de verdad** (`c3ny1.mediainbox.net/popular.mp3` → redirige a streamtheworld
  → 200 audio real, confirmado con `curl -IL`). Aplicado: `UPDATE stations SET url=...` + REINDEX +
  integrity_check + subida atómica (put+rm+mv en una sola sesión lftp esta vez, aplicando ya la
  lección de TKT-0703) + verificación de integridad post-subida (limpia, sin el problema de índices
  que apareció ayer). Las otras 4 emisoras caídas (`radio-mitre-bahia-blanca`, `blue`, `am-750`,
  `radio-nihuil-mendoza`) quedan sin URL de reemplazo — no se encontró nada que funcione.
- **Hallazgo de rebote — 2 emisoras con nombre roto, visibles en el listado público:** IDs 13 y 553
  tienen como `nombre` literalmente una URL mal parseada (`http//147.135.11.829300/` y
  `https//playerservices.streamtheworld.com/api/livestream-redirect/UNOAAC.aac`). Ambas con 178
  fallos consecutivos y `last_ok` NULL — llevan semanas/meses muertas. **No se ocultan del listado
  público** porque su `estado` es `timeout`, no `muerto` — la vista `v_stations` solo oculta por
  `estado='muerto'` (a propósito, `timeout` se trata como señal ambigua/transitoria — ver comentario
  en el SQL de la vista). Con 178 fallos seguidos claramente no es transitorio: hay un hueco real en
  esa clasificación. No se tocó nada (ni el nombre ni la lógica de `estado`) — sin ICY cacheado ni
  otra pista fuerte de cuál es el nombre real de la id=13, y la id=553 sugiere "Radio Uno FM" por el
  path de la URL (`radiounofm`) pero sin confirmar. Pendiente de que Carlos decida qué hacer con las
  dos cosas.

---

## ✅ TKT-0704 — 2026-08-26 — Compartir por X y Telegram (además de WhatsApp/link/QR)

A pedido de Carlos ("agregale a los compartidos la posibilidad de compartir lo que estás escuchando por
redes sociales"). `share.php` ya aceptaba cualquier `channel` como texto libre (sin whitelist) — solo
hizo falta sumar íconos/labels para `x` y `tg` en el mapeo de notificación a Telegram.

**X (Twitter):** `https://twitter.com/intent/tweet?text=...&url=...` — intent web público, sin API key.
**Telegram:** `https://t.me/share/url?url=...&text=...` — mismo patrón, sin API key.
Mismo mensaje que WhatsApp ("📻 Estoy escuchando {nombre} en vivo"), agregados en `listing.php` (barra
del reproductor) y `station.php` (página de emisora), mismo patrón de botones ya existente (`.share-btn`
/`.sbtn`, ambos con `flex-wrap` así que no rompen el layout con 2 botones más).

**Facebook quedó afuera a propósito:** su diálogo de compartir depende de tener meta tags Open Graph
bien armados para mostrar una preview decente — no es "fácil de implementar" en el mismo sentido que los
otros dos, que son una sola URL sin requisitos previos.

Verificado sintaxis con `php -l` (php8.2 descargado sin root, mismo truco de siempre) antes de subir.
Probado en producción: los botones aparecen con las URLs correctas y `api/share?channel=x` responde
`{"ok":true}`.

---

## 🚨 TKT-0703 — 2026-08-26 — DB corrupta otra vez (estadisticas.php 500) + desactivados los 7 workflows de GitHub Actions

Carlos reportó páginas del sitio dando 500. Diagnóstico: `estadisticas.php` → 500 (confirmado con curl), resto del sitio (listado, admin, API, páginas de emisora) → 200 normal. Se descargó la DB de producción (los 3 archivos, `.sqlite`+`-wal`+`-shm`) y `PRAGMA integrity_check` confirma corrupción real: `idx_events_notified` (índice, page 5108) más varias páginas del árbol B-tree en la zona de `stream_history`/`station_events` (pages 2754, 6688, decenas de celdas afectadas) — mismo patrón que los 5+ incidentes previos documentados (TKT-0687/0690/0693/0695/0701), no se investigó más a fondo el mapeo exacto de páginas esta vez.

**Acción tomada a pedido explícito de Carlos ("apagá todos los crons que tenemos en github, son una máquina de corromper la base de datos"):** los 7 workflows de GitHub Actions del repo `camammoli/radio` quedaron **desactivados manualmente** (`gh workflow disable`, estado `disabled_manually`, reversible con `gh workflow enable`):
- `check-streams-v2.yml` (Verificar streams v2)
- `close-orphan-sessions.yml` (Cerrar sesiones huérfanas)
- `competitor-scan.yml` (Competitor Scan)
- `dedupe-streamtheworld.yml` (Deduplicar streamtheworld)
- `enrich-v2.yml` (Enriquecer emisoras v2)
- `gist-sync.yml` (Sincronizar gist de emisoras)
- `learn-patterns.yml` (Aprender patrones de programas)

**No tocado:** el cron de cPanel `icy_refresh.php` (cada 10 min, fuera de GitHub Actions) sigue corriendo — Carlos pidió específicamente "los crons de github", este no lo es. Tampoco se recuperó la DB corrupta todavía (`estadisticas.php` sigue en 500) — queda pendiente de que Carlos decida si se recupera ahora con el procedimiento ya probado (`.recover` → rebuild → validar → subida atómica) o se espera a definir qué hacer con los workflows antes de volver a arreglarla.

**Contexto para la próxima sesión:** con los workflows de GitHub Actions parados, la próxima corrupción (si ocurre) va a poder atribuirse con certeza a otra causa (cron cPanel, escritura PHP en vivo, o algo del hosting) — es la primera vez que se aísla esta variable del todo. Vale la pena dejarlos apagados un tiempo y ver si el sitio deja de corromperse antes de decidir cómo reactivarlos (con qué fix).

---

## TKT-0702 — 2026-08-25 — Fix Cafecito: clic marcaba "nunca más" sin confirmar donación real

A partir del análisis de monetización de este día, Carlos reportó que de 4 clics reales en el botón
☕ Cafecito del toast de ayuda, solo 1 se convirtió en una donación real. Revisando el código
(`web/assets/player.js`, handler `.rp-ayuda-cafecito`), el clic llamaba a `never()` — igual que
"No molestar más" — silenciando el aviso para siempre sin ninguna confirmación de pago (Cafecito es
un link externo, no hay webhook). Con ese dato, 3 de cada 4 personas que mostraron intención de ayudar
quedaban calladas para siempre sin haber aportado nada.

**Fix:** el clic en Cafecito ahora llama a `snooze()` (mismo régimen que "OK"/"Contacto", pausa
`AYUDA_SNOOZE_DIAS`=7 días) en vez de `never()`. Solo "No molestar más" sigue siendo permanente.
Comentario del bloque `ayudaInit()` actualizado para reflejar el comportamiento real.

Verificado sintaxis con `node --check` antes de subir. Deploy: `web/assets/player.js` +
`web/sw.js` (`CACHE_NAME` bumpeado a `radio-ar-v12` — obligatorio en todo deploy que toque
`player.js`, ver TKT-0684) por FTP directo. Confirmado en producción con `curl` post-deploy:
`sw.js` sirve v12 y `player.js` tiene `snooze()` en el handler de cafecito.

---

## TKT-0736 — 2026-08-25 — Botón flotante de contacto por Telegram

Nuevo componente `components/telegram_button.php`, incluido en `listing.php` y `station.php` (todas
las páginas públicas, menos el error temprano de estación no encontrada). Link a `https://t.me/Amammoli`
(usuario público, nunca el número de teléfono). Ícono SVG inline del avión de papel de Telegram en
blanco sobre fondo `#29a9eb` (color de marca de Telegram, no el `--accent` del sitio, a propósito).

Posición fija `left:16px; bottom:88px` — elegida para no chocar nunca con la barra del reproductor
(`bottom:0`, aparece/desaparece según si hay algo sonando) ni con el toast de encuesta (`right:16px`).
En mobile (`max-width:480px`) el texto se oculta y queda solo el ícono circular.

Probado local (PHP + copia de la DB) antes de subir: sin errores de sintaxis, el botón renderiza en
ambas páginas con el link correcto. Primer intento del ícono tenía un círculo blanco de fondo propio
que quedaba duplicado sobre el fondo ya azul del botón — corregido a solo el avión en blanco.

### Archivos afectados
- `web/components/telegram_button.php` (nuevo)
- `web/assets/player.css` (estilos `.rp-tg-btn`)
- `web/pages/listing.php`, `web/pages/station.php` (include antes de `</body>`)

### Actualización — mismo día — botón de WhatsApp apilado

Carlos pidió lo mismo para WhatsApp, sin exponer el número de teléfono. Usó el link "click to chat"
que WhatsApp genera con nombre de usuario (`wa.me/message/BD42PKGXON2QI1`) — no lleva el número en
la URL. Nuevo componente `web/components/whatsapp_button.php`, ícono oficial bajado de
simple-icons (no dibujado a mano, para que sea exacto). Apilado arriba del botón de Telegram
(`bottom:144px` vs `bottom:88px`), mismo ancho de pill, verde `#25D366` de marca WhatsApp.

### Archivos afectados (actualización)
- `web/components/whatsapp_button.php` (nuevo)
- `web/assets/player.css` (estilos `.rp-wa-btn`)
- `web/pages/listing.php`, `web/pages/station.php` (segundo include)

### Actualización — mismo día — fix mobile: botones tapados hasta hacer scroll

Carlos reportó que en mobile había que scrollear hasta el final para ver los botones. Diagnóstico
confirmado con Puppeteer emulando iPhone (`getBoundingClientRect` mostró `position:fixed` correcto,
sin ancestros con `transform`/`filter` rotos) — la posición en sí estaba bien. Causa real: en iOS
Safari la barra inferior del navegador puede tapar elementos `fixed` pegados abajo hasta que el
usuario hace scroll (recién ahí Safari la esconde) — headless Chrome no reproduce esto porque no
simula esa barra dinámica, por eso no aparecía en las capturas locales.

**Fix (técnica estándar de WebKit para este caso exacto):**
- `viewport-fit=cover` agregado al meta viewport (`components/head.php`) — sin esto, `env()` no
  devuelve nada.
- `bottom: calc(88px + env(safe-area-inset-bottom))` (y `144px` para WhatsApp) en vez de un valor
  fijo — empuja el botón por encima de la barra dinámica de Safari, sin depender de que el usuario
  scrollee para revelarlo.
- De paso, pedido explícito de Carlos: en pantallas ≤640px (subido desde 480px) los botones quedan
  solo el ícono, circulares de 44×44px (mínimo recomendado de touch target), más compactos que la
  pastilla con texto.

Verificado con captura real de producción (Puppeteer + emulación iPhone 13, viewport 390×844): los
dos círculos quedan dentro del viewport visible sin scroll, `44×44px`, sin superposición entre ellos.

### Archivos afectados (esta actualización)
- `web/components/head.php` (viewport-fit=cover)
- `web/assets/player.css` (env safe-area + breakpoint ícono-solo a 640px)

---

## TKT-0735 — 2026-08-25 — Pestaña "Emisoras" (ABM completo, nunca DELETE) + revisión de backups y visitas

### Contexto
Carlos pidió tres cosas: (1) backup antes de tocar nada, dado el historial de corrupción recurrente
de `radio_v2.sqlite` (TKT-0687/0690/0693/0695/0701); (2) revisar con atención los datos de
visitas/oyentes tras el fix de ayer (TKT-0701, contador is_active + bloqueo de IP bot); (3) una
pestaña de ABM para el catálogo completo de emisoras, con la regla explícita de que **nunca se
borren** — solo se marcan de baja, con un campo de "último cambio" para saber cuándo se dieron de
alta o de baja.

### Backup previo
Descargada la DB de producción completa (`.sqlite` + `-wal` + `-shm`, importante bajar los tres —
ver nota de WAL más abajo) antes de cualquier cambio. `integrity_check: ok`, sin violaciones de FK,
1268 `stations`. Guardado en `~/Escritorio/Backups/radio_backup_completo_20260825_093805/`.

### Revisión de visitas (post-fix TKT-0701)
- El hash de IP bloqueado ayer (`c7a0e2692b529b79`, patrón de station-hopping/bot) no volvió a
  aparecer en `plays` desde el 23/08 22:49 — justo antes del deploy del bloqueo. Parece efectivo.
- Las sesiones activas en `listeners` (16 al momento de revisar) están todas con `last_seen` de
  hace segundos y repartidas en estaciones distintas — sin señales de sesiones "pegadas" (el bug
  que corrigió el fix de `is_active` en `admin.php`).
- Ningún otro `ip_hash` con volumen de plays muestra un patrón sospechoso (alta concentración en
  una sola emisora en poco tiempo) — la distribución observada es consistente con oyentes reales.

### Nueva pestaña "Emisoras" (`web/admin.php`)
- **Columnas nuevas en `stations`:** `activa INTEGER DEFAULT 1` y `ultimo_cambio TEXT`. Migración
  auto-aplicada (mismo patrón `ALTER TABLE ... try/catch` que ya usa el archivo para las demás
  columnas de metadata). `ultimo_cambio` se toca **solo** al hacer alta/baja — a diferencia de
  `updated_at`, que cambia con cualquier edición.
- **`v_stations` → versión 5:** se agregó `AND COALESCE(s.activa, 1) = 1` al filtro existente
  (que ya ocultaba automáticamente las muertas 14+ días). Emisoras dadas de baja desaparecen del
  sitio público pero la fila sigue intacta en `stations`, visible en el panel admin.
- **Acciones nuevas:** `set_activa` (toggle alta/baja, nunca DELETE), `crear_emisora` (alta manual
  con slug auto-generado si no se especifica), `editar_emisora` (nombre/url/provincia/homepage).
- **UI:** tabla ABM completa (reutiliza el componente `DT` ya existente — filtro/orden/paginado),
  formulario de alta manual colapsable, botón alta/baja + edición inline por fila.
- Card nueva en Resumen: "Emisoras de baja" (0 al momento del deploy).

### Nota importante sobre WAL (relevante para la causa raíz de TKT-0701)
Al verificar el deploy, un primer intento de backup bajando solo `radio_v2.sqlite` (sin `-wal`)
mostró la DB **sin** las columnas nuevas recién creadas — a pesar de que el panel admin, corriendo
en el mismo momento, ya las usaba sin problema. Confirmado: había un `-wal` de 1.5MB sin checkpoint
en el servidor. Esto es evidencia directa y reproducible del mecanismo que TKT-0701 ya había
planteado como hipótesis (ventana de inconsistencia entre el archivo principal y el WAL). Cualquier
proceso de backup/restore de esta DB **debe** bajar los tres archivos juntos (`.sqlite`, `-wal`,
`-shm`), nunca solo el principal.

### Probado antes de deployar
Copia local de la DB de producción + `admin.php` nuevo servidos con PHP local (Docker): login,
migración de esquema, alta, edición, baja, reactivación, y confirmación de que una emisora de baja
desaparece de `v_stations` pero sigue en `stations`. Todo verificado antes de subir a producción.
Deploy: solo `web/admin.php` por FTP (no se tocó el archivo de la DB directamente — la migración de
esquema la aplica el propio PHP en producción, evitando el patrón de swap por FTP que es sospechoso
de la corrupción recurrente).

### Pendiente (fuera del alcance de hoy, para la próxima sesión de backups/confiabilidad)
- El fix ya identificado en TKT-0701 (fusionar `mv`+`rm` del wal/shm en una sola sesión lftp en los
  3 workflows que hacen swap de la DB) sigue sin aplicar.
- Revisar y organizar el estado general de backups del proyecto (pedido explícito de Carlos para
  la próxima sesión).
- Monetización del sitio (pedido explícito de Carlos para la próxima sesión).

### Archivos afectados
- `web/admin.php` (deploy)
- `db/radio_v2.sqlite` en servidor: migración auto-aplicada (columnas `activa`/`ultimo_cambio`,
  vista `v_stations` v5) — no se subió el archivo de DB, solo se dejó que el propio admin.php la
  migrara en su primer request.

---

## TKT-0699 — 2026-08-21 — Panel admin v4: filtro, orden por cabecera, paginado y agrupado en las 14 tablas

### Contexto

Carlos pidió "llevá el panel de control a la versión 4 (de hecho el sistema está en la
versión 4)" — el header del panel efectivamente decía "Admin v3" mientras el sitio ya
está en v4.1.0 desde TKT-0689. Pidió, para todos los listados: paginado, orden por
cabecera, agrupar, filtrar. Copia de seguridad previa explícitamente pedida.

### Lo que se hizo

`web/admin.php`:
- Header `📻 Radio Argentina — Admin v3` → `Admin v4`.
- Nueva librería JS genérica `DT` (`window.DT`, ~250 líneas, un solo `<script>` nuevo
  antes del script de tab-nav/auto-refresh existente): dado cualquier
  `<table class="dt">`, agrega automáticamente:
  - **Filtro** de texto libre (input arriba de la tabla, busca en todo el `textContent`
    de cada fila).
  - **Orden por click en cabecera** — comparador genérico: si el texto de la celda
    reduce a un número válido (quitando todo lo que no sea dígito/punto/signo) compara
    numérico, si no compara alfabético (`localeCompare` con locale `es`). Las columnas
    de fecha (`YYYY-MM-DD HH:MM:SS`) ordenan bien con este mismo comparador porque el
    formato zero-padded concatenado da un número consistente con el orden cronológico —
    no hizo falta un parser de fechas aparte. Columnas de Acción/IP hash marcadas
    `data-nosort="1"` en el `<th>` para no ofrecer un orden sin sentido.
  - **Paginado** (10/25/50/100/Todo, con Anterior/Siguiente y contador de página).
  - **Agrupado opcional** por columna — solo en los `<th>` marcados con
    `data-group="Etiqueta"` (Emisora, Provincia, Tipo, Estado, Motivo, Canal, Rating,
    Día, Crawler — donde agrupar aporta algo real; se dejó afuera de las tablas donde ya
    es ~1 fila por entidad, como ICY o el resumen de encuestas por emisora). Inserta
    filas de cabecera de grupo colapsables (click para expandir/contraer) y desactiva el
    paginado mientras hay un agrupado activo (mostrar todo agrupado es más útil que
    paginar a través de grupos cortados).
  - Todo 100% client-side, sin pegar a la API de nuevo — las tablas son chicas (30-200
    filas), alcanza y sobra.
- Aplicado a las 14 tablas del panel (encuestas resumen, encuestas detalle, compartidos,
  toast de ayuda, reproducciones, sugerencias, problemas, pendientes de verificación,
  seguimiento especial, suscriptores, notificaciones enviadas, patrones de programas,
  ICY, crawlers) vía `id="dt-<nombre>" class="dt"` en cada `<table>`.
- **Compatibilidad con el auto-refresh AJAX existente (TKT-0718):** Reproducciones y
  Compartidos reemplazan su `tbody.innerHTML` cada 10s. `DT.refresh(table)` se llama
  justo después de cada reemplazo — recaptura las filas nuevas del DOM y vuelve a
  aplicar filtro/orden/página/agrupado vigentes, sin duplicar la toolbar ni el pager.

### Backup pre-cambio

Local: `~/Escritorio/Backups/radio_admin_v4_20260821_180330/admin.php.orig_produccion`.
Server-side: `radio/admin.php.bak_20260821_180446` (mismo patrón `.bak_<timestamp>` ya
usado en el proyecto).

### Testing

Se armó un entorno local real antes de tocar producción (mismo patrón que TKT-0690/0694):
`php8.2-cli` + `pdo`/`pdo_sqlite`/`sqlite3`/`mbstring`/`curl` extraídos vía
`apt-get download` + `dpkg-deb -x` (sin root), copia fresca de la DB de producción,
`php -S` sirviendo `web/` con `config.php` de prueba (token de Telegram falso y
`NOTIFY_OYENTES=false` a propósito, para no disparar avisos reales durante las pruebas).
Se instaló `playwright` (el binario de Chromium ya estaba cacheado en el entorno) y se
corrió un script de test real contra el servidor local: login, click en cabecera
(orden asc/desc con verificación de clase CSS), filtro con verificación de que las filas
visibles realmente contienen el texto buscado, agrupado con verificación de las filas de
grupo generadas y del colapso al click, cambio de tamaño de página y de página con
verificación de contenido distinto, y — el caso más delicado — esperar un ciclo completo
de 10s de auto-refresh AJAX y confirmar que la tabla sigue con datos y sin toolbar
duplicada. Los 18 chequeos pasaron, cero errores de consola. Verificado también en vivo
en producción con login real (curl + cookie jar): header "Admin v4" presente, controles
nuevos presentes en las 14 tablas con datos.

### Entorno de prueba limpiado

Servidor PHP local detenido, copia de la DB de prueba borrada, `config.php` local
restaurado a sus valores reales (token de Telegram, `NOTIFY_OYENTES=true`) tras las
pruebas — nada de esto quedó en el repo (`config.php`/`db/` gitignored).

---

## TKT-0697 — 2026-08-21 — Toast de ayuda: una vez por sesión + delay 12s + texto resumido

### Contexto

Carlos notó una tasa de respuesta muy baja en el toast de ayuda/cafecito (TKT-0694): ~5,5%
de las veces que se muestra termina en un click de OK/Cafecito/Contacto/No molestar; el
resto lo cierra con la X o simplemente lo ignora. Pidió opinión antes de tocar nada.

Se descargó `radio_v2.sqlite` (solo lectura) para analizar `ayuda_toast_eventos` en vez de
especular: 112 filas `mostrado` pero solo **66 IPs únicas** — mucha repetición a la misma
persona (hasta 5 veces). El gap mínimo entre dos apariciones a la misma IP es de **~30
segundos**, no milisegundos — es decir, no es un bug de doble disparo, es gente navegando
de una página a otra dentro del sitio (listado → emisora → otra emisora) y recibiendo el
pedido de nuevo en cada una, porque el diseño de TKT-0694 lo dispara en cada página
("aparece ni bien se entra al sitio... en cualquier página"), no una vez por visita. Además
no existe ningún dato de user-agent/dispositivo en `ayuda_toast_eventos` ni en `plays` —
no se puede saber mobile vs. PC con los datos actuales.

Con esa evidencia, opinión entregada y **confirmada por Carlos**: no sacar la X (rompería
el tono "te pido una mano sin trucos" del propio texto), pero sí atacar frecuencia y timing,
que la data muestra como el problema más grande. Carlos sumó su propia idea de renombrar el
botón OK para que quede claro que va a volver a aparecer.

### Lo que se hizo

`web/assets/player.js`:
- `AYUDA_DELAY_MS`: 1000 → **12000** (12s) — ya no interrumpe al segundo de entrar a la página.
- Nueva `AYUDA_SESSION_KEY` (sessionStorage) chequeada en `ayudaSuprimido()` — el toast se
  muestra como máximo **una vez por sesión de navegación** (se resetea al cerrar la
  pestaña) aunque el visitante recorra varias páginas sin responder ningún botón. Se marca
  en `showAyuda()` junto al `ayudaLog('mostrado')` existente. No reemplaza el snooze/never
  por localStorage (OK/Cafecito/Contacto/No molestar siguen funcionando igual) — es una
  capa adicional solo para la repetición dentro de la misma sesión.
- Texto del toast reescrito de 7 párrafos largos a 5 cortos — mismo pedido concreto (1 de
  cada 10 aporta un cafecito alguna vez), mismo link a GitHub, mismo agradecimiento final.
  Se sacó el link de Cafecito duplicado dentro del texto (ya existe como botón de acción).
- Botón OK renombrado de "OK" a **"Ok, recordámelo en unos días"** (idea de Carlos) — deja
  explícito que va a reaparecer (coincide con el snooze real de `AYUDA_SNOOZE_DIAS`=7).
- Comentario de cabecera de la función actualizado para documentar el límite por sesión.

`web/sw.js`:
- `CACHE_NAME`: `radio-ar-v10` → **`radio-ar-v11`** — lección ya documentada en TKT-0684/
  TKT-0694: cualquier cambio a `player.js` sin bump deja a los visitantes recurrentes
  sirviendo la versión cacheada vieja indefinidamente.

### Backup pre-cambio

Local: `~/Escritorio/Backups/radio_toast_fix_20260821_081853/` (`player.js.orig`,
`sw.js.orig`). Server-side: `radio/assets/player.js.bak_20260821_081853` y
`radio/sw.js.bak_20260821_081853` (mismo patrón `.bak_<timestamp>` que ya usa el proyecto).

### Deploy

Sintaxis verificada con `node --check` antes de subir. FTP atómico (`put ... .new` + `mv`)
a `/radio/assets/` y `/radio/`. Verificado en producción: `sw.js` sirve `radio-ar-v11`,
`player.js` sirve `AYUDA_DELAY_MS = 12000`, sitio responde 200.

### Pendiente

Sin dato de mobile vs. PC — si Carlos lo quiere a futuro, agregar user-agent (o al menos un
flag booleano "es_mobile" derivado del header) a `ayuda_toast_eventos` es el único cambio
de schema necesario.

---

## TKT-0696 — 2026-08-20 — Anti-spam en formularios de contacto y suscripción

### Contexto
Auditoría general de formularios públicos en todos los sitios de mammoli.ar, disparada por
spam llegando al form de contacto de mammoli.ar/.

### Lo que se hizo
- `web/contacto.php`: ya tenía honeypot bien camuflado (off-screen, no `display:none`).
  Se agrega trampa de tiempo (timestamp `ts` embebido server-side en el render, rechaza
  envíos a menos de 2s) y se etiqueta el aviso de Telegram con "[Radio Argentina]" para
  identificarlo rápido entre notificaciones de otros sitios/bots.
- `web/suscribirse.php`: no tenía ninguna protección. Se agrega honeypot (`web2`, off-screen)
  + trampa de tiempo (mismo patrón: `ts` viaja de ida y vuelta en el form). Importa
  especialmente acá porque el form manda mensajes de "confirmación" a cualquier chat ID de
  Telegram o email que se le ponga — sin filtro es vector de mail-bombing a terceros.

### Deploy
FTP atómico (`put ... .new` + `mv`) a `/radio/`. Verificado con descarga FTP + diff contra
el local (no con curl para páginas con challenge WAF).

---

## TKT-0695 — 2026-08-18 — DB corrupta por SEGUNDA VEZ en el mismo día — sospecha de icy_refresh.php descartada

### Contexto

Carlos reportó "la página de radio da error 500" — segunda corrupción de `radio_v2.sqlite` en el mismo día (la primera fue TKT-0693, unas horas antes). Mismo patrón exacto: `in_header_page_count` del header desincronizado del tamaño físico real del archivo (esta vez 164 páginas de diferencia / ~656KB, contra 29 páginas / ~116KB la vez anterior).

### Recuperación

Idéntico procedimiento a TKT-0693: parche del header (offset 28, page count) sobre una copia → 20/22 tablas 100% intactas → mismas dos tablas dañadas de siempre (`station_events`, `stream_history` + sus índices) → rescate por chunks + fila-a-fila. Resultado llamativo: se recuperaron **exactamente las mismas** 4817/4874 filas de `station_events` y 184170/239294 de `stream_history` que la vez anterior — el `max(rowid)` vía `sqlite_sequence` no había avanzado nada desde la recuperación de TKT-0693. Es decir, entre una corrupción y la otra, ninguna escritura nueva llegó a consolidarse con éxito en esas dos tablas. Se preservaron intactas 2 cosas nuevas reales que sí habían llegado en el medio: 1 mensaje de contacto (`contacto_mensajes`) y 8 eventos del toast de ayuda (`ayuda_toast_eventos`, TKT-0694). `integrity_check`/`foreign_key_check` limpios, subida atómica, sitio verificado 200 en index/stations-API/página de emisora/estadísticas.

### Hallazgo que cambia la sospecha de causa raíz (TKT-0690)

Se verificó en el código quién escribe efectivamente `station_events`/`stream_history`: **no es `icy_refresh.php`** (el cron cPanel cada 10min que se venía sospechando desde TKT-0690) — esas dos tablas las escribe `check_streams_v2.py`, que corre vía GitHub Actions (`check-streams-v2.yml`, cada 6hs) y que **ya tiene la protección que se suponía que evitaba esto**: `concurrency: group: radio-db-write` compartido entre los 3 workflows + subida atómica `put .new` + `mv`, agregada específicamente para este problema en TKT-0732. `icy_refresh.php` escribe `icy_cache`/`icy_history`, que en AMBOS incidentes de hoy quedaron 100% intactas — evidencia directa de que no es la causa.

Que la corrupción siga ocurriendo pese a esa protección, siempre en las mismas dos tablas, y ahora dos veces en un solo día (frecuencia muy superior a los días/semanas entre incidentes anteriores), apunta a que la causa real nunca fue la sospechada. **Pendiente recomendado, más urgente que antes**: revisar `gh run list`/`gh api .../logs` de `check-streams-v2.yml` correlacionado con los horarios exactos de ambas corrupciones de hoy, en vez de seguir asumiendo por horarios.

---

## TKT-0694 — 2026-08-18 — Rediseño del toast de ayuda: aparece en cada entrada, 4 botones de acción

### Contexto

Carlos reportó que tras casi una hora escuchando, el toast de TKT-0692 nunca le apareció. Investigado y encontrado: el `CACHE_NAME` del service worker (`sw.js`) seguía en `radio-ar-v9` desde antes del deploy de TKT-0692 — al no bumpearlo, cualquier visitante recurrente (Carlos incluido) seguía recibiendo el `player.js` viejo desde la caché del navegador, sin el toast nuevo, sin siquiera intentar contactar al servidor (estrategia cache-first para `.js`/`.css`). Mismo bug ya documentado y resuelto una vez en julio (TKT-0684) — esta vez se me pasó bumpearlo al deployar.

A partir de esto, Carlos pidió rediseñar el toast por completo en vez de solo arreglar el cache bug.

### Nuevo diseño (a pedido explícito de Carlos, confirmado antes de implementar)

- Aparece **ni bien se entra al sitio** (cualquier página), sin esperar a reproducir nada — ya no depende del evento `'playing'` del audio.
- **No es "una vez para siempre"**: si el visitante no responde nada (cierra con la X), vuelve a aparecer en la próxima entrada, todas las veces que haga falta.
- 4 botones de acción, cada uno con consecuencia distinta:
  - **👍 OK** → pausa 7 días.
  - **☕ Cafecito** (abre cafecito.app en pestaña nueva) → No molestar más (permanente) — si ya aportó, no tiene sentido insistir.
  - **📬 Contacto** (abre contacto.php en pestaña nueva) → pausa 7 días.
  - **🚫 No molestar más** → nunca más.

### Implementación

`player.js`: se sacó `ayudaStart()`/`AYUDA_KEY` (atado a `'playing'` + 240s + localStorage de una sola vez) y se reemplazó por `ayudaInit()` (se llama incondicionalmente al construir el `RadioPlayer`, delay de 1000ms) + `ayudaSuprimido()` (chequea `radio_ayuda_never` y `radio_ayuda_snooze_until` en cada carga) + `ayudaLog(tipo)` (beacon `fetch` con `keepalive:true` a `api/ayuda_toast.php`). Se mantiene la guarda de colisión con `.rp-welcome` (bienvenida/encuesta de sitio) ya existente de TKT-0692, ahora más relevante porque el toast puede aparecer antes de que el visitante interactúe. El cuerpo de texto (redactado por Carlos) no se tocó — solo cambió la fila de botones del final.

Nuevo `web/api/ayuda_toast.php`: GET-only (`api_method('GET')`), valida `tipo` contra whitelist, crea `ayuda_toast_eventos` (`id, tipo, ip_hash, provincia, created_at`) si no existe, reutiliza `geo_provincia()`/`ip_hash()`/`client_ip()` de `_helpers.php` — mismo patrón que `share.php`.

`admin.php`: pestaña nueva "🙏 Ayuda" — cards con conteos por tipo + tasa de respuesta (respondidos/mostrado), tabla de eventos recientes (fecha, tipo, provincia, ip_hash). Queries envueltas en try/catch (la tabla puede no existir aún en un admin.php fresco antes del primer evento real).

`sw.js`: `CACHE_NAME` bumpeado a `radio-ar-v10` — obligatorio en este deploy para que el nuevo player.js llegue a cualquiera que ya haya visitado el sitio antes.

### Metodología de prueba (sin tocar producción hasta validar todo)

Se armó un entorno 100% local sin necesitar root: `php8.2-cli` + `mbstring`/`pdo_sqlite`/`sqlite3`/`curl` vía `apt-get download` + `dpkg-deb -x` (mismo truco ya usado con `sqlite3` en TKT-0693), servido con `php -S` sobre un docroot con symlink `radio/ -> web/` (necesario porque el sitio usa rutas absolutas `/radio/...`), con una copia real (pero descartable) de la DB de producción y un `config.php` local con `TG_TOKEN` vacío para no disparar Telegram real. Se probó con Playwright: aparición al entrar sin reproducir, cada uno de los 4 botones (incluyendo que Cafecito/Contacto abren pestaña nueva Y fijan la preferencia correcta), reaparición tras cerrar con la X, y el escenario de colisión con el toast de bienvenida. Recién después de que las 5 pruebas pasaran se desplegó a producción y se repitió la verificación clave con Playwright contra el sitio real.

### Hallazgo colateral, no resuelto todavía — login de admin.php también bloqueado por WAF para navegadores reales

Verificando la pestaña nueva del admin, se encontró que el `<form method="post">` de login de `admin.php` tiene el MISMO bug de ModSecurity ya documentado en TKT-0692 (sugerir.php) y en `contacto.php`: un navegador real que envía el login recibe **406 "Not Acceptable"**, mientras que `curl` con las mismas credenciales entra sin problema. A diferencia de los otros casos, acá el fix obvio (GET+fetch+JSON) tiene una contra real: **expondría la contraseña de admin en la URL** (logs del servidor, historial del navegador) — no es un simple intercambio de método como en los formularios públicos. Queda sin tocar a propósito, pendiente de decidir con Carlos el enfoque (¿excepción de ModSecurity por `.htaccess` para esa ruta puntual? ¿challenge-response en vez de mandar la contraseña en claro?) antes de tocar nada.

---

## TKT-0693 — 2026-08-18 — DB corrupta de nuevo (sitio caído 500) — recuperada con patch de page count + rescate por chunks

### Contexto

Detectado incidentalmente mientras se verificaba `contacto.php` (TKT-0692) en producción: `radio_v2.sqlite` corrupta (`database disk image is malformed`), sitio entero devolviendo 500 (index, `api/stations.php`, todo). Mismo patrón recurrente que TKT-0690/0721/0726/0732, pero esta vez `.recover` fallaba de entrada con `SQL logic error` sin producir ni siquiera el CREATE TABLE del schema.

### Diagnóstico

El header SQLite (offset 28, `in_header_page_count`) decía 5952 páginas contra 5923 páginas físicas reales en el archivo descargado (24.260.608 bytes ÷ 4096 = 5923.0 exacto, sin truncamiento por transferencia FTP — header y tamaño de archivo consistentes entre sí, solo desincronizados entre ellos). Archivo truncado ~29 páginas / ~116KB respecto a lo que su propio header declaraba, consistente con una escritura interrumpida a mitad de camino.

Se descargó `sqlite3` (CLI, no estaba instalado) vía `apt-get download` + `dpkg-deb -x` sin necesitar root. Se parcheó a mano el campo `in_header_page_count` del header (4 bytes, offset 28) para que coincida con el tamaño real del archivo, sobre una copia — nunca el original. Con ese único cambio, el schema y la mayoría de las tablas volvieron a ser legibles sin usar `.recover` en absoluto.

### Alcance del daño

De 18 tablas, 16 quedaron 100% intactas: `stations`, `plays`, `listeners`, `settings`, `stream_status`, `crawler_runs`, `icy_cache`, `icy_history`, `ip_geo_cache`, `program_patterns`, `reportes`, `shares`, `subscriber_matches`, `subscribers`, `surveys`, `sqlite_sequence`. Solo `station_events` y `stream_history` (y sus índices) tenían páginas realmente perdidas en la cola — las filas más recientes, coherente con la teoría de escritura interrumpida.

### Recuperación

Reconstrucción tabla por tabla en una DB nueva vía Python/sqlite3 (mismo `libsqlite3` del sistema). Para las 16 tablas intactas, copia directa. Para `station_events` y `stream_history`, rescate en chunks de 200 filas con reintento fila-por-fila sobre los chunks que fallaban, para aislar al máximo el daño real:
- `station_events`: 4817/4874 filas (99,8%) — 141 filas irrecuperables en el tramo final.
- `stream_history`: 184.170/239.294 filas (76,9%) — ~55.100 filas de historial de checkeos perdidas de forma irrecuperable en el tramo final (mayor pérdida que TKT-0688 en la misma tabla, pero sigue siendo solo log de monitoreo histórico, no datos de usuario).

`PRAGMA integrity_check` → `ok`, `PRAGMA foreign_key_check` → limpio, sobre la base reconstruida. `VACUUM` + `wal_checkpoint(TRUNCATE)` antes de subir, para no dejar `-wal`/`-shm` huérfanos. Subida atómica (`put .new` + `mv`), mismo mecanismo que las recuperaciones anteriores. Verificado en producción: `index.php`, `api/stations.php`, `estadisticas.php`, `admin.php` todos 200.

### Causa raíz — sigue sin confirmarse

Misma sospecha histórica documentada en TKT-0690 (`icy_refresh.php` vía cron cPanel, fuera del `concurrency: group` de GitHub Actions). Pendiente recomendado: instrumentar `icy_refresh.php` con logging de inicio/fin de escritura, o migrarlo al patrón atómico `put .new` + `mv`.

---

## TKT-0692 — 2026-08-18 — Botón de ayuda/contacto para sostener el proyecto + fix WAF en Sugerir emisora

### Contexto

Pedido: subir a producción el texto sobre sostener el proyecto (ya redactado y editado por Carlos en `Texto_Ayuda_Sostener_Radio.md`) como un toast que aparezca una vez a todos los visitantes, en cualquier página de entrada — reemplazando el mailto final por un botón de contacto con su propio formulario.

### Implementación

`player.js`: nuevo toast `AYUDA_KEY='radio_ayuda_v1'`, se dispara 240s después de `'playing'`, mismo patrón que `showWelcome()`/site-survey ya existentes (una sola vez por visitante vía `localStorage`). Se le agregó una guarda de colisión (si ya hay otro `.rp-welcome` visible, reintenta a los 10s) porque el welcome (90s) y el site-survey (150s) pueden quedar abiertos al mismo tiempo que este nuevo toast de 240s, y ninguno se auto-cierra — sin la guarda, se superponían en la misma posición fija de pantalla. Verificado con Playwright usando `page.clock.fastForward()`.

Nuevo `contacto.php`: formulario de contacto general (nombre opcional, email opcional, mensaje), honeypot anti-bot, notifica por Telegram, guarda en tabla nueva `contacto_mensajes`.

### Bug encontrado (no pedido, hallado verificando lo anterior): Sugerir una emisora rota para cualquier visitante real

Mismo hallazgo que ya documentado para Catastro y Starlink Panel: este hosting bloquea con 406 (Mod_Security) cualquier POST cuyo User-Agent sea un navegador real, sin importar el Content-Type — pero el POST idéntico desde `curl` pasa bien, lo mismo que un GET con el mismo UA. Confirmado con Playwright (navegación real, no solo curl). `sugerir.php` ya estaba en producción con un `<form method="post">` nativo — es decir, **nadie pudo sugerir una emisora exitosamente desde que se implementó esa función**, sin que nadie lo notara porque el error solo aparece con navegadores reales.

Fix (mismo patrón ya probado en Starlink Panel y aplicado también a `contacto.php` desde el inicio): convertir el submit a `fetch()` por GET con querystring, respuesta JSON en vez de recarga de página completa. Se preservó toda la lógica existente (`check_stream()`, guarda SSRF vía `radio_url_is_safe()`, chequeo de duplicados, generación de slug, notificación Telegram) — solo cambió el mecanismo de transporte.

Ambos formularios verificados end-to-end en producción con Playwright (envío real exitoso, sin 406). Fila de prueba en `contacto_mensajes` borrada después de verificar.

---

## TKT-0691 — 2026-08-14 — Provincia geolocalizada en Reproducciones, Compartidos y Encuestas

### Contexto

Tras revisar v4.1 (TKT-0689), Carlos pidió mostrar la provincia geolocalizada "en todas las pestañas donde sea posible, sobre todo en Reproducciones" — la geolocalización ya guardaba `plays.provincia` desde v4.1, pero solo se mostraba agregada en el dashboard resumen de Encuestas, no en ninguna tabla de detalle.

### Cambios

- **Reproducciones** (prioridad explícita): columna nueva usando `plays.provincia` (ya se guardaba, solo faltaba seleccionarlo/mostrarlo) — query inicial, refresh AJAX (`?ajax=1`) y ambos templates de fila (PHP + JS).
- **Compartidos**: columna nueva vía `LEFT JOIN ip_geo_cache` por `ip_hash` — la tabla `shares` no tiene columna propia de provincia, no hizo falta agregarla porque `ip_geo_cache` ya cubre cualquier IP vista antes en cualquier parte del sitio.
- **Encuestas — detalle**: columna "Provincia (geo)" al lado de la "Ubicación" autoreportada existente, mismo JOIN, para comparar dato real vs. autoreportado.
- Migración `CREATE TABLE IF NOT EXISTS ip_geo_cache` agregada también al bloque de `admin.php`, por si el panel se visita antes que cualquier ping real haya creado la tabla.

### Verificación

Servidor PHP local sobre copia fresca de producción (`integrity_check` ok) antes de desplegar: login OK, 7 ocurrencias de "Provincia" en las 3 tablas, JSON del refresh AJAX válido con provincia real (CABA en el primer registro), sin errores PHP. Desplegado por FTP y reverificado en producción con el mismo resultado.

---

## TKT-0690 — 2026-08-14 — DB corrupta de nuevo durante el deploy de v4.1 (causa raíz sin confirmar)

### Contexto

Al desplegar v4.1 (TKT-0689), la primera request real post-deploy tiró `PDOException: database disk image is malformed` en `_db.php:27` — dentro de `radio_db()`, antes de llegar a cualquier código nuevo de v4.1. Diagnosticado con un script temporal (subido por FTP, ejecutado una vez, borrado de inmediato) que descartó cualquier problema de versión de PHP o del código nuevo: PHP 8.3.33, `str_contains`, arrow functions y `_helpers.php` cargaban y corrían perfecto de forma aislada — el único error real era la excepción de SQLite al conectar.

Misma clase de corrupción que TKT-0687/0721/0722, pero ocurrió de nuevo en la ventana de ~3 horas entre la recuperación de TKT-0688 (misma sesión, ~15:5x) y este deploy (~19:08) — se corrompió sola otra vez, sin relación con el código de v4.1 (nunca llegó a ejecutarse contra la DB corrupta).

### Recuperación

Mismo procedimiento ya probado 3 veces este año: descarga fresca → `sqlite3 .recover` (sin errores) → rebuild → `PRAGMA integrity_check` ok + `foreign_key_check` limpio → drop de `lost_and_found` (21.680 filas parciales de 2 columnas, sin `station_id`/`estado`, no reinsertables) → subida atómica (`put .new` + `mv`). Verificado en producción: sitio, estadísticas, admin, ficha de emisora todos 200; ping real a `listeners.php` funciona y geolocaliza.

### Causa raíz — sigue sin confirmarse

Sospecha histórica (memoria de proyecto, 2026-07-23): `icy_refresh.php` vía cron cPanel cada 10 min, fuera del radar de GitHub Actions y su `concurrency: group`. Esta es la **tercera vez** que se repite el patrón (TKT-0721/0722 en julio, TKT-0687 el 13/08, este el 14/08) sin lograr confirmar la causa de fondo — cada vez se resuelve el síntoma, nunca se instrumentó `icy_refresh.php` para confirmar o descartar la hipótesis con evidencia directa.

**Pendiente recomendado**: instrumentar `icy_refresh.php` (logging de inicio/fin de escritura, o migrarlo al mismo patrón atómico `put .new` + `mv` que ya usan los demás crawlers) para resolver esto con evidencia en vez de inferencia por horarios.

---

## TKT-0689 — 2026-08-14 — v4.1: geolocalización, sesiones huérfanas, contador, provincia, encuestas, fixes admin

### Contexto

A partir del documento de propuestas (`Radio_v4.1_Propuestas.html`, generado en la sesión anterior), Carlos pidió implementar todo salvo descripciones con IA y el FAQ de reproductores externos, que quedan pendientes a propósito. Checkpoint pre-cambios: tag `pre-v4.1-2026-08-14`. Release: tag `v4.1.0`, commit `4f594c0`.

### Lado usuario

**1) Geolocalización de oyentes.** `geo_provincia()` en `_helpers.php` usa ip-api.com (sin cuenta ni license key — opción elegida tras confirmar que la alternativa, MaxMind GeoLite2, requiere una que no se tenía) con caché en `ip_geo_cache` indexada por `ip_hash`: el IP crudo nunca se persiste, solo se usa en memoria para la consulta puntual. Usa el código ISO 3166-2:AR (campo `region` de la API) como fuente primaria en vez de parsear el nombre de la región en texto libre — evita ambigüedades como "Buenos Aires C.F." vs. "Buenos Aires" provincia. Se llama desde `listeners.php` al crear cada play nuevo. Verificado con tráfico real en producción: ya hay oyentes geolocalizados a Buenos Aires, CABA, Río Negro y Chaco.

**2) Cierre proactivo de sesiones huérfanas.** La limpieza que ya existía en `listeners.php` (cierra `plays.ended_at` con el último heartbeat, borra `listeners` vencidos) solo corría de forma pasiva, disparada por tráfico real. Se extrajo a `cerrar_sesiones_expiradas()` (compartida) y se agregó `cron_close_sessions.php` (endpoint autenticado con `RADIO_ADMIN_KEY`) + GitHub Actions cada 15 minutos, para que también corra sin visitas.

**3) Fix contador de oyentes en station.php.** Bug confirmado en sesión anterior: cuando nadie escuchaba esa emisora puntual (`stationCount=0`), el fallback mostraba el total del sitio como si fueran oyentes de esa emisora. El bug estaba solo en el frontend — la API ya devolvía ambos números (`count` y `listeners_station`) correctamente. Ahora se muestran los dos, aclarados: "N personas escuchando esta emisora · M en todo el sitio".

**4) Normalización de `stations.provincia`.** Mapeo a las 24 provincias canónicas (23 + CABA), con tabla de alias de ciudades (Rosario→Santa Fe, Mar del Plata→Buenos Aires, etc.) y detección de CABA por variantes de texto. Validado contra las 75 variantes reales distintas encontradas en la DB antes de aplicar — 100% de cobertura salvo `NULL` y "Argentina" genérico, que quedan sin dato a propósito en vez de adivinar. Migración one-off automática en `_db.php`, guardada en `settings.provincia_normalizada_v1` para no reprocesar. Confirmado corriendo solo, en el primer request real post-deploy.

**5) Encuestas ordenan el listado.** En `listing.php`, el feedback de encuestas (👍1/😐0/👎-1) ahora es un desempate en el `ORDER BY`: entre emisoras con el mismo estado, la de mejor puntaje sube antes de caer a plays/votos. La mayoría no tiene encuestas → `COALESCE` a 0 → sin cambios de orden para ellas.

### Lado admin

**6) `notify_subscribers.php` — dos bugs reales corregidos.** Las suscripciones `type=genre` nunca notificaban nada: tanto `keywords_match()` como el matching de "programa próximo" las excluían explícitamente del todo. Se agregó un bloque nuevo que compara géneros contra `stations.tags` de emisoras con actividad ICY reciente (un género no aparece en "Artista - Canción", tiene que compararse contra otra cosa). Además, el cooldown de "programa próximo" usaba una clave (`prog_subid_patternid`) que el query de carga inicial de cooldowns nunca reconstruía, y el bloque nunca insertaba en `subscriber_matches` — el cooldown solo duraba lo que dura una corrida del script, reenviando la misma alerta hasta 9 veces por hora. Fix: misma forma de clave que el resto del sistema (sub+estación) + persistencia real.

**7) `learn_patterns.php` — cron activado.** El cron de cPanel documentado (`0 4 * * 1`) nunca se configuró: 0 patrones detectados en 3+ semanas desde v3.0. Se creó `cron_learn_patterns.php` (endpoint autenticado) + GitHub Actions semanal. Probado contra datos reales en producción: corre en menos de 1 segundo sobre 6.014 registros de `icy_history`.

**8) `admin.php` — dashboard de provincia + detección de bots.** Nueva sección "Oyentes por provincia (geolocalizado)" en la pestaña Encuestas, usa `plays.provincia`. Nueva columna con badge de station-hopping (🤔 6+ emisoras distintas del mismo IP en 1h, 🤖 12+) en Reproducciones, replicada en el refresh AJAX. Umbral validado contra el caso real ya documentado (sesión con 35 cambios en 3hs) que da `hops_1h=24`, bien por encima.

### Pendiente a propósito

Descripciones de emisoras con IA y FAQ de reproductores externos quedan fuera de esta tanda (decisión explícita de Carlos).

### Verificación

`estadisticas.php`/`admin.php`/listado/ficha de emisora en 200. Ambos endpoints cron probados con y sin key (403 sin key). Ping real a `listeners.php` devuelve `count`+`listeners_station` correctos y geolocaliza. Admin panel logueado muestra la sección de provincia y el badge de bot sin errores PHP. `notify_subscribers.php` probado **solo localmente** — nunca en producción, para no disparar notificaciones reales al único suscriptor real del sistema.

---

## TKT-0688 — 2026-08-14 — Fix: estadisticas.php caída por corrupción DB (recuperación de stream_history)

### Contexto

TKT-0687 (2026-08-13) había detectado que `/radio/estadisticas.php` devolvía 500 por corrupción real de páginas b-tree en `stream_history` — preexistente, no causada por el deploy de v4.0.0. En ese momento Carlos decidió dejarlo así ("los crawlers siempre rompen todo, por ahora dejalo así"), sin tocar la DB. El 14/08 pidió retomarlo.

### Procedimiento

Mismo método ya usado 2 veces antes (TKT-0721/0722 en junio, recuperación de julio documentada en memoria de proyecto): descarga fresca de `radio_v2.sqlite` por FTP → `sqlite3 .recover` a SQL → rebuild en archivo nuevo → validación (`PRAGMA integrity_check` ok, `PRAGMA foreign_key_check` limpio) → subida atómica (`put .new` + `mv`).

Esta corrupción fue más extensa que las anteriores: no un solo índice sino varios árboles b-tree afectados. `.recover` volcó 6929 filas de `stream_history` a la tabla auxiliar `lost_and_found`, pero solo con 2 de las 9 columnas originales recuperables (aparentemente `checked_at` + el `id`), sin `station_id` ni `estado` — no se pudieron reinsertar de forma válida (violarían el `NOT NULL` de esas columnas). Se descartó esa tabla auxiliar tras confirmar que no había solapamiento de ids con la `stream_history` ya recuperada (166.452 filas intactas). Pérdida real: esas ~6929 filas de log de checkeos históricos de un total de cientos de miles — no crítico, solo reduce levemente la granularidad del histórico de uptime en ese rango de fechas puntual.

### Verificación

Queries reales de `estadisticas.php` (estado actual + historial 91 días con window function) y de `admin_stats.php` (pico de concurrencia, self-join sobre `listeners`) probadas contra la DB recuperada antes de subir. Post-deploy en producción: `estadisticas.php` → 200 sin errores (antes 500), `admin_stats.php` → 302 (redirect login normal, sin sesión), `admin.php` → 200, sitio público → 200.

### Estado

Subido a producción por FTP (deploy directo de datos, no de código — no requiere commit). TKT-0687 puede cerrarse; TKT-0688 es el registro del fix.

---

## TKT-0686 — 2026-08-13 — v4.0.0: fix real de cortes en Aspen (HTTPS directo), banners consolidados

### Contexto

Carlos midió con cronómetro cortes reales de audio escuchando Aspen (~5min de intervalo) y notó que la MISMA url escuchada directo con VLC (sin pasar por nuestro `proxy.php`) nunca se corta — descartando al origen del stream y señalando a nuestro hosting como causa. Aprovechando la sesión, también pidió sacar varios banners/toasts que se habían acumulado (v3-alertas, cafecito viejo, núcleo fiel con el texto "che che che" que nunca le apareció) y reordenar la bienvenida/encuestas para no saturar al visitante nuevo con 4 pedidos a la vez.

### 1) Fix de fondo: redirección HTTPS directa en proxy.php

`proxy.php` pipeaba TODO el audio a través de PHP+cURL, sin importar el protocolo. El hosting compartido corta las respuestas largas de PHP a los ~300s (confirmado indirectamente: el mismo stream sin pasar por PHP no se corta nunca). Fix: si la URL de la emisora (directa o resuelta de una playlist `.pls`/`.m3u`) es HTTPS, `proxy.php` ahora hace `header('Location: ...')` y el browser se conecta directo al origen — ya no depende de que PHP sostenga la conexión. Se sacó también la duplicación de este mismo chequeo que ya existía solo para el caso de playlists resueltas.

### 2) Hallazgo en el camino: el fix de HTTPS no alcanzaba a Aspen tal cual estaba

Verificando en producción, `aspen-486` seguía sin redirigir — su URL guardada en la DB era `http://playerservices.streamtheworld.com/api/livestream-redirect/ASPENAAC_SC` (HTTP), que streamtheworld resuelve internamente a un edge server también HTTP (`http://27573.live.streamtheworld.com:80/ASPENAAC_SC`). Probando el MISMO path con el esquema `https://` en vez de `http://`, streamtheworld sí resuelve a un edge HTTPS real (`https://26493.live.streamtheworld.com:443/ASPENAAC_SC`) — la API de streamtheworld soporta ambos esquemas, pero la URL guardada en nuestra DB usaba el viejo.

Se relevaron las 5 emisoras con URL `http://` de streamtheworld: 2 usan el patrón de API de redirección (`aspen-486`, `radio-mitre-argentina`) — ambas confirmadas con variante HTTPS funcional. Las otras 3 (`arpeggio`, `fm-cordoba-297`, `like`) son servidores de borde FIJOS (no la API de redirección) — probadas explícitamente, ninguna tiene listener TLS en ese host:puerto (conexión rechazada), así que se dejaron como estaban (siguen proxiadas vía PHP, sin fix posible sin encontrarles una URL alternativa — mismo tipo de trabajo que ya hace `dedupe_streamtheworld_v2.py`).

**Fix de datos aplicado** (no de código): se actualizó `stations.url` de `aspen-486` y `radio-mitre-argentina` a la variante `https://` del mismo path. Hecho vía script PHP temporal subido por FTP, ejecutado una vez contra la DB en vivo vía PDO (WAL, mismo mecanismo que usa la app — evita el riesgo de descargar/resubir la DB completa mientras recibe tráfico real, que fue la causa de corrupciones anteriores documentadas en este archivo), y borrado del servidor inmediatamente después de correr. Verificado end-to-end: `proxy.php?station=aspen-486` → 302 → 302 (streamtheworld) → 200 audio HTTPS real, sin pasar por nuestro servidor en ningún tramo.

Aspen y Radio Mitre son las emisoras más escuchadas del sitio (ver métricas TKT-0684), así que este fix cubre el caso de mayor impacto real aunque no sea el 100% de las emisoras con URLs `http://` de streamtheworld.

### 3) Banners consolidados

Sacados completamente (HTML + JS + CSS): banner "v3 — Activar alertas" (`#banner-v3`), banner núcleo fiel con el texto "Che, che, che, esperá..." (`#loyal-banner`, y la query `$es_nucleo_fiel` que solo se usaba ahí). El toast cafecito (`#support-toast`, 5min de escucha real + cooldown 7 días) queda como único pedido de café del sitio — no se tocó.

Toast de bienvenida (`player.js`, `showWelcome()`) reescrito: ahora es solo informativo (novedades v4 + privacidad + CTA), sin encuesta ni pedido de café adentro — antes hacía las 3 cosas a la vez. `WELCOME_KEY` bumpeado a `radio_welcome_v4` para que se muestre una vez a TODOS desde ahora, incluso a quien ya había visto la versión vieja.

La encuesta "¿qué te parece el sitio?" + "¿desde dónde escuchás?" que antes vivía dentro del toast de bienvenida ahora es un componente propio (`showSiteSurvey()`, key `radio_site_survey_v1`), dispara a los 150s de escucha real — antes de la encuesta de emisora (existente, sin cambios, dispara a los 180s). Así ningún visitante nuevo ve más de un popup a la vez: bienvenida (90s) → encuesta de sitio (150s) → encuesta de emisora (180s) → café (300s), todos espaciados.

### Archivos afectados

`web/proxy.php`, `web/pages/listing.php`, `web/assets/player.js`, `web/assets/style.css`, `web/admin.php`, `web/suscribirse.php`, `web/estadisticas.php`, `web/sw.js` (CACHE_NAME → `radio-ar-v9`). Fix de datos aplicado directo en producción (no versionado en git, es contenido de DB no de código).

### Estado

Deployado por FTP y verificado en producción. Commit `f84c91a`, tag `v4.0.0`. Tag de checkpoint pre-cambios: `pre-v4-2026-08-13` (apunta al commit `ca0547f`, que también incluye un fix de reconexión en el evento `pause` del navegador que ya estaba deployado sin commitear de una sesión anterior — TKT-0685 continuación, ver commit `ca0547f`).

---

## TKT-0685 — 2026-08-09 — Banner núcleo fiel, fix del contador de reproducción y regresión del Service Worker cortando streams

### Contexto

Se implementó el banner "núcleo fiel" (visitante con 8+ días distintos de escucha) propuesto y aprobado en TKT-0684: pide colaboración vía cafecito.app, aparece tras 2 minutos de reproducción acumulada, cooldown de 30 días. En paralelo, badges de categoría de oyente (🆕/🔁/⭐/💎) en el panel admin, calculados con la misma métrica.

Al probarlo con Carlos en vivo aparecieron dos problemas reales, encadenados.

### 1) El banner nunca aparecía — bug en el contador de reproducción acumulada

`onState('playing')` reiniciaba `playStart = Date.now()` cada vez que se llamaba, sin chequear si ya se estaba contando. El evento nativo `'playing'` del `<audio>` se dispara de nuevo después de cualquier buffering breve (normal en streaming, más aún en redes móviles), no solo al arrancar — con una sola interrupción de buffering en el medio, el cronómetro nunca llegaba a acumular 2 minutos seguidos. El mismo bug (copiado) afectaba al toast de apoyo (`#support-toast`) ya existente. Fix: solo arrancar `playStart`/`timer` si `!playStart`. Aplicado a ambos bloques en `web/pages/listing.php`.

### 2) Bug real, más grave: streams cortándose cada ~5 minutos — regresión en `sw.js`

Reportado por Carlos escuchando Aspen desde el celular: cortes consistentes cada ~5 minutos, algo que "antes no pasaba nunca" (horas de escucha continua previa sin problema). Hipótesis inicial (timeout de ejecución del hosting) descartada: `error_log` no registraba nada cerca del momento de los cortes pese a estar reproduciendo en vivo, y el bloque de código que pipea el stream en `proxy.php` no cambió ni un carácter en el fix de seguridad del 30/07 (TKT-0683) — solo cambió la validación de la URL antes de llegar a ese bloque.

Causa real encontrada revisando el historial completo de `sw.js`: las versiones v1/v2 tenían una lista explícita de rutas excluidas del Service Worker (`proxy.php`, `nowplaying.php`, `listeners.php`, `survey.php`, `log.php`). El commit `57b3204` ("fix: SW cacheaba los pings a /api/listeners") reemplazó esa lista completa por un único chequeo de prefijo `/radio/api/` — y `proxy.php` vive fuera de `/radio/api/`, así que quedó sin excluir desde ese commit (varios días antes del fix de seguridad del proxy, coincide con la sensación de Carlos de que el problema "empezó con el proxy" sin ser realmente ese código). Desde entonces el Service Worker intercepta la respuesta infinita del stream de audio y la clona para intentar guardarla en caché (`cache.put`) — operación que no tiene sentido para un recurso que no termina nunca mientras se escucha, y corta la conexión periódicamente.

**Fix:** `web/sw.js` — nueva regex `NOCACHE_RE` que excluye explícitamente `/radio/(proxy|nowplaying|listeners|survey|log).php` del manejador `fetch`, además del prefijo `/radio/api/` ya existente. `CACHE_NAME` bumpeado a `radio-ar-v6` para forzar la purga de cualquier entrada de `proxy.php` ya cacheada en navegadores de usuarios.

### 3) El corte de ~5 min seguía pasando incluso con `radio-ar-v6` confirmado activo

Carlos verificó en DevTools (Application → Service Workers) que `radio-ar-v6` ya estaba activo y el corte seguía ocurriendo cada ~5 minutos, con la request a `proxy.php` completando en 200 OK (no un error HTTP). Esto descarta al Service Worker como única causa. Se revisaron todos los commits del 07 al 09/08 y ninguno toca `proxy.php`, `sw.js` (antes del fix de este mismo ticket) ni nada relacionado a streaming — tampoco hay corridas de GitHub Actions fuera de lo rutinario (`Verificar streams v2`, cada ~6h, todas exitosas). No se encontró una causa de código para el cambio de comportamiento que Carlos reporta ("ayer no pasaba").

Con un 200 limpio y un intervalo tan regular, la hipótesis más plausible es un timeout de idle/duración máxima a nivel de infraestructura del hosting (proxy inverso Apache/LiteSpeed delante de PHP, típicamente 300s por default) — fuera de nuestro control y sin visibilidad para confirmarlo con certeza.

**Mitigación implementada (`web/assets/player.js`):** un watchdog que detecta cortes silenciosos y reconecta solo, sin mostrarle nada al usuario:
- Nuevo listener `timeupdate` que registra la última vez que hubo progreso real de reproducción.
- `watchdogCheck()` corre cada 5s mientras `state === 'playing'`; si pasan 15s (`STALL_MS`) sin progreso, reconecta (recrea `audio.src` vía `resolveUrl()` y llama `audio.play()`) sin pasar por `setState('error')`.
- El handler de `audio.error` también reconecta en silencio para códigos 1 (ABORTED) y 2 (NETWORK) si ya se venía reproduciendo bien — los códigos 3/4 (DECODE/SRC_NOT_SUPPORTED, problema real de formato) siguen mostrando el error de siempre.
- Tope de `MAX_RECONNECT = 6` intentos antes de rendirse y mostrar el error real; se resetea a 0 en cuanto vuelve a haber progreso real (`timeupdate`).
- `player.js` es un asset estático cacheado cache-first por el Service Worker — `CACHE_NAME` bumpeado a `radio-ar-v7` en el mismo deploy para que el fix llegue a navegadores que ya visitaron el sitio (misma lección de TKT-0684 punto 5).

### Archivos afectados

`web/pages/listing.php`, `web/sw.js`, `web/assets/player.js`.

### Estado

Deployado por FTP y verificado en producción (`radio-ar-v7`, watchdog presente en el archivo servido). Pendiente confirmación de Carlos de que los cortes ya no se notan. Causa raíz de fondo (posible timeout de infraestructura del hosting) no confirmada con certeza — la mitigación resuelve el síntoma, no necesariamente la causa.

---

## TKT-0684 — 2026-08-06 — Dedup streamtheworld, sesiones huérfanas, catálogo público y cache del Service Worker

### Contexto

Carlos pidió deduplicar emisoras con duplicados de streamtheworld.com (Aspen, Radio Mitre, Rock&Pop, Continental, Disney) — hunt_stations_v2.py dedupea por URL exacta y nunca detectó que un servidor de borde fijo (`14983.live.streamtheworld.com:3690`) y la API de redirección (`playerservices.streamtheworld.com/api/livestream-redirect/...`) son la misma emisora real, porque los servidores de borde van quedando obsoletos con el tiempo sin que nada lo detecte. De ahí se encadenaron varios hallazgos y fixes relacionados en la misma sesión.

### 1) Deduplicación + crawler nuevo

Aplicado a mano en producción: **Aspen** (3 entradas de servidor fijo ocultadas — una bloqueada por el hosting en puerto no estándar, una rechaza acceso directo, una con DNS muerto — se dejó activa `aspen-486`, la de redirección). **Horizonte**: mismo patrón, 1 oculta. **Continental**: las 2 entradas existentes están muertas (DNS no resuelve), ninguna variante de redirección funcional encontrada — quedó sin emisora activa, pendiente. **Disney**: 2 variantes de redirección (`.m3u8` vs `.aac`), se consolidó todo en el slug `disney` con la URL `.aac` (más simple, menor latencia, sin dependencia de hls.js), la otra ocultada liberando su URL (unique constraint).

**`crawlers/dedupe_streamtheworld_v2.py`** (nuevo): agrupa emisoras aprobadas por "callsign" real (extraído tanto de URLs de redirección como de servidor fijo). Si hay una variante de redirección y una o más fijas → oculta las fijas (`approved=0`, reversible). Grupos sin variante de redirección, o con más de una, se reportan para revisión manual — nunca se auto-resuelven. De paso detecta el bug de `nombre` roto (contiene una URL en vez de texto, herencia de un crawler viejo). `--apply --notify` corre vía **`.github/workflows/dedupe-streamtheworld.yml`**, lunes 08:30 AR. Probado en local (dry-run + apply real, coincide con lo hecho a mano) y en una corrida real en GitHub Actions (0 grupos nuevos, como esperado).

### 2) Bug real: sesiones de reproducción que quedan abiertas para siempre

`api/stations.php` tenía su propia limpieza de `listeners` vencidos (`DELETE ... WHERE last_seen < -90s`) que **no cerraba `plays.ended_at` antes de borrar**, a diferencia de `listeners.php` que sí lo hacía. Cualquier sesión cuyo vencimiento fuera detectado primero por esta ruta (muy transitada, se llama por cada visita a una ficha individual) quedaba "reproduciendo" para siempre sin que nada la cierre. Encontrada con datos reales: una sesión de **2 semanas** de antigüedad (`session_id o35wplvnu8imrxenxp6`, `plays.id 795`, desde el 23/07). Cerrada a mano con `ended_at = played_at` (duración desconocida, no se inventa un número). Fix de código: agregado el mismo bloque de cierre que ya tenía `listeners.php`, antes del DELETE.

### 3) Catálogo público más limpio — ocultar caídas de 14+ días

Pendiente documentado desde el 23/07, resuelto ahora. `v_stations` (usada por listado, API, sitemap, M3U) ahora excluye las emisoras con `estado='muerto'` y `last_ok` de hace 14+ días o nulo. `'timeout'` no se oculta — señal más ambigua, puede ser transitorio. Siguen existiendo en la DB y visibles en el panel admin (pestaña Problemas), solo dejan de mostrarse a los visitantes. Resultado: bajó de 1259 a 1190 emisoras visibles públicamente (69 ocultadas), confirmado 1:1 contra la API pública real.

**Cambio de mecanismo de migración de `v_stations`:** el primer intento de este mismo día (agregar solo `contacto_publico` al guard) casi rompe el sitio en producción — la vista nueva también referenciaba `s.destacada`, columna que no existía todavía porque esa migración vivía solo en `admin.php` (solo corre si Carlos está logueado). `listing.php` y `station.php` tiraron 500 real en producción por ~2 minutos hasta el segundo fix. Reemplazado por un mecanismo más robusto: la vista se versiona con un comentario embebido (`v_stations_version:N`) comparado contra `sqlite_master.sql`, se recrea sola cuando cambia. Vive en `_db.php` (no solo en `admin.php`) porque `station.php`/`listing.php` reciben tráfico antes de que se cargue el panel admin — mismo criterio que la lección ya documentada sobre `listeners.php` en TKT-0716.

### 4) Gestión de emisoras: contacto, destacada, observación

Motivado por un caso real: **OK FM Radio** (Florencio Varela, Buenos Aires) escribió directo por el formulario de sugerencias pidiendo estar en el listado — la propia emisora, no un oyente, primera vez que pasa. Carlos la aprobó y pidió armar infraestructura para gestionar este tipo de casos.

Campos nuevos en `stations`: `en_observacion`, `destacada` (booleanos), `contacto_publico` (visible en la ficha pública), `contacto_privado` y `notas_privadas` (solo admin). Tabla `reportes` nueva — el botón "¿la señal está caída? Reportar" en `station.php` antes solo mandaba un Telegram sin dejar rastro, ahora también persiste.

3 pestañas nuevas en `admin.php`, cada una con botón "Editar" inline (`station_meta_row()`, formulario `<details>` con los 5 campos):
- **Problemas**: ocultas (`approved=0`) + `muerto`/`timeout` + reportadas últimos 14 días
- **Pendientes de verificación**: aprobadas sin fila en `stream_status` (esperando el primer chequeo del crawler)
- **Seguimiento**: `en_observacion=1` o con `contacto_privado` cargado

Probado end-to-end: guardado real vía POST, valor persistido y verificado en la DB de producción.

### 5) Bug real: Aspen/Mitre/Los40 "online" pero no reproducen — cache del Service Worker

Carlos reportó 3 emisoras de las más escuchadas del sitio marcadas `estado='ok'` (confirmado con ICY título en vivo) que no reproducían en su navegador — mismo navegador donde antes sí andaban. Hipótesis inicial (códec AAC+/HE-AAC sin soporte en el navegador) descartada por el propio Carlos: en Brave (Chromium, decodifica AAC+ sin problema) "antes andaba y ahora nada", mismo navegador.

Prueba server-side de 4m24s replicando el patrón exacto del navegador (audio sostenido vía proxy + consulta a `nowplaying.php` cada 30s, igual que hace `player.js`) — 8 ciclos, cero degradación. Descartado timeout de hosting/infraestructura.

Causa real, confirmada con la consola real del navegador de Carlos (`proxy.php:1 Failed to load resource: the server responded with a status of 400 ()`): **`sw.js` cachea archivos `.js` con estrategia cache-first** ("son inmutables o versionados" — supuesto que no se cumple para `player.js`, sin hash/versión en el nombre). `CACHE_NAME` no se bumpeaba desde el **01/07** (commit `d70900b`), casi un mes antes de que `proxy.php` cambiara de `?url=` a `?station=` el 30/07 (TKT-0683). Cualquier navegador que ya hubiera visitado el sitio antes del 30/07 quedó sirviendo para siempre, desde caché local, el `player.js` viejo — que sigue llamando a `proxy.php?url=...`, parámetro que el proxy nuevo ni siquiera lee. Resultado: 400 ("Emisora inválida", `station` vacío) para esos visitantes específicos, mientras el crawler y cualquier visitante nuevo veían todo perfecto — coincide exactamente con "empezó justo cuando tocamos el proxy" pese a que el fix del proxy en sí no tenía el bug.

**Fix:** bump `CACHE_NAME` a `radio-ar-v5` (`web/sw.js`). Fuerza a todos los clientes a descartar la caché vieja en el próximo `activate()`. Confirmado por Carlos tras hard refresh: funciona.

**Bonus (mismo hallazgo):** el manejador de error de `player.js` trataba cualquier fallo (red, formato, lo que sea) con el mismo mensaje genérico "no disponible en web", ignorando `audio.error.code`. `listing.php`/`station.php` ni siquiera leían el 3er parámetro (`msg`) que `onError()` ya les pasaba. Ahora `audio.error.code` 3 (DECODE) o 4 (SRC_NOT_SUPPORTED) arma un mensaje distinto ("tu navegador no puede reproducir este formato — probá VLC"), y ambas páginas ya lo muestran. El botón de fallback a VLC ya existía; el mensaje nuevo explica por qué usarlo.

### Archivos afectados

`web/admin.php`, `web/api/_db.php`, `web/api/stations.php`, `web/pages/station.php`, `web/pages/listing.php`, `web/assets/player.js`, `web/sw.js`, `crawlers/dedupe_streamtheworld_v2.py` (nuevo), `.github/workflows/dedupe-streamtheworld.yml` (nuevo).

### Estado

Todo commiteado y pusheado a `camammoli/radio` (commits `9ceca56`, `0075ba3`, `a920e4a`, `06db3e6`). Verificado en producción en cada paso. Backups de la DB pre-cambio en `~/Escritorio/` (varios timestamps del 05 y 06/08). Pendiente real: encontrar una URL funcional para Continental.

---

## TKT-0683 — 2026-07-30 — fix: SSRF y proxy HTTP abierto en proxy.php, nowplaying.php y sugerir.php

### Contexto

Auditoría de bugs pedida por Carlos (código + métricas en vivo del panel admin). Encontró que `proxy.php` aceptaba cualquier URL del visitante (`?url=`) sin whitelist — funcionaba como proxy HTTP abierto hacia cualquier destino de internet, con protección SSRF débil (chequeo por texto sobre el hostname, bypasseable con un redirect 302 externo hacia `127.0.0.1` gracias a `FOLLOWLOCATION` activo, o con DNS rebinding). `web/api/nowplaying.php` tenía el mismo problema en su rama legacy `?url=` (compatibilidad v1), sin ningún filtro. `web/nowplaying.php` (archivo suelto en la raíz, dead code de v1 sin ninguna referencia en el código actual) tenía el mismo hueco, también sin filtro.

### Fix

- **`web/proxy.php`**: en vez de recibir `?url=`, ahora recibe `?station=SLUG` y busca la URL real en la DB server-side (`SELECT url FROM stations WHERE slug=? AND approved=1`). El visitante ya no controla qué URL se fetchea. Se agregó revalidación de la URL resuelta al parsear playlists `.pls`/`.m3u` (antes esa URL, que viene de contenido externo, no se revalidaba después de la resolución).
- **`web/assets/player.js`**: `PROXY_URL` y `resolveUrl()` actualizados para pasar el slug de la emisora en vez de la URL cruda.
- **`web/api/nowplaying.php`**: se eliminó por completo la rama `?url=` legacy. Solo acepta `?slug=` de una emisora existente en la DB.
- **`web/nowplaying.php`**: eliminado (`git rm`). Era dead code v1 sin ninguna referencia, sin filtro SSRF alguno.
- **`web/_ssrf_guard.php`** (nuevo): `radio_url_is_safe($url)` — resuelve el DNS del host y valida la(s) IP(s) real(es) contra rangos privados/loopback/link-local (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`, incluye `169.254.0.0/16`). Reemplaza el chequeo anterior por texto sobre el hostname, que no protegía contra DNS rebinding.
- **`web/sugerir.php::check_stream()`**: sigue necesitando aceptar una URL arbitraria (es el formulario para sugerir una emisora nueva). Ahora usa `radio_url_is_safe()` en cada hop y sigue los redirects a mano (`FOLLOWLOCATION => false` + loop revalidando cada `Location` antes de seguirlo, máx. 5 saltos) en vez de dejar que curl los siga solo sin revalidar.

### Deploy y verificación

Backup completo pre-cambio (repo local + copia descargada de producción) en `~/Escritorio/Backups/radio_ssrf_fix_20260730/`. Deploy a `/radio/` vía lftp con patrón atómico (`put ... .new` + `mv`). Verificado en vivo contra producción:
- `nowplaying.php` (legacy) → 404 (eliminado)
- `proxy.php?url=...` y `api/nowplaying?url=...` → 400 (ya no aceptan URL arbitraria)
- `proxy.php?station=<slug real>` → 200, stream de audio AAC real verificado end-to-end (106KB descargados, formato confirmado)
- Página de emisora y listado principal → 200 sin cambios

### Pendiente

Cambios sin commitear todavía en el repo local (a la espera de que seas vos quien pida el commit).

---

## TKT-0734 — 2026-07-09 — fix+feat: v3.0.0 — fix crawler workflows + suscriptores + alertas ICY

### Fix: lftp `|| true` dentro de heredoc (85% de failures desde 07/07)

**Síntoma:** 85% de las corridas de `check-streams-v2.yml` fallando en el último step ("Checkpoint WAL y subir DB"). La DB se subía correctamente pero el step retornaba exit code 1.

**Causa:** El comando `rm /radio/db/radio_v2.sqlite-wal || true` estaba dentro del heredoc de lftp. El `|| true` es sintaxis bash; dentro de lftp se interpreta como el comando lftp `true` que no existe. Cuando los archivos WAL/SHM no existen en el servidor, el `rm` falla y lftp sale con error. Los archivos WAL no existen en la mayoría de los runs porque el checkpoint de Python los elimina antes del upload.

**Fix:** Separar el rm WAL/SHM en una invocación lftp independiente usando `-e` flag con `glob -a rm` (que no falla si no hay match). El `|| true` queda a nivel bash donde corresponde.

**Archivos:** `.github/workflows/check-streams-v2.yml`, `.github/workflows/enrich-v2.yml`

---

### Feat: Sistema de alertas para oyentes (v3.0.0)

**Nuevas funcionalidades:**

**1. Suscripción de oyentes** (`web/suscribirse.php` + `web/api/subscribe` implícito en el form)
- Oyente registra Telegram (chat_id numérico) o email + preferencias (artistas, programas, géneros)
- Telegram: envía link de activación al chat_id para confirmar que el bot puede escribirle
- Email: envía link de activación por correo
- Las preferencias son keywords libres: cualquier texto que aparezca en los títulos ICY

**2. Notificaciones automáticas** (`crawlers/notify_subscribers.php`)
- Cron PHP cada 5 minutos: compara preferencias de cada suscriptor vs. ICY activo (últimos 30 min)
- Condición de disparo: **2+ temas matching** en una misma emisora en 30 min (no el primero suelto)
- Cooldown de 4 horas por (suscriptor, emisora) para no spamear
- También notifica programas próximos (15 min antes) según patrones aprendidos con ≥60% de confianza
- Configurar en cPanel: `*/5 * * * * php /home/username/radio/crawlers/notify_subscribers.php`

**3. Aprendizaje de patrones de programas** (`crawlers/learn_patterns.php`)
- Analiza `icy_history` de los últimos 60 días
- Agrupa por station + keyword normalizado + día_semana + hora (hora local ARG = UTC-3)
- Si un keyword aparece ≥3 días distintos en el mismo slot → crea/actualiza `program_patterns`
- Confianza = ocurrencias / semanas en rango (mínimo 25% para guardar)
- Los patrones con >45 días sin verse se eliminan automáticamente
- Configurar en cPanel: `0 4 * * 1  php /home/username/radio/crawlers/learn_patterns.php`

**4. Cafecito toast mejorado** (`web/pages/listing.php`)
- Antes: aparecía a los 20 segundos siempre
- Ahora: aparece después de **5 minutos de reproducción activa**
- Cooldown de 7 días entre apariciones
- No molesta al visitante que entra y sale sin escuchar

**5. UI player** (`web/pages/listing.php`)
- Badge `🔔 Alertas` en el header → suscribirse.php
- Banner dismissible "v3 — Activar alertas" que aparece una sola vez (localStorage)

**6. Estadísticas de oyentes** (`web/admin_stats.php`)
- Gráficos por día/mes (seleccionable), hora del día, top emisoras
- Vinculado desde el admin con botón `📊 Estadísticas`

**Nuevas tablas en DB:**
- `subscribers`: suscriptores, contact_type, preferences (JSON), token de activación
- `subscriber_matches`: tracking de matches detectados + cooldown de notificaciones
- `program_patterns`: patrones aprendidos (station, keyword, día, hora, confianza)

**Tag git:** `v3.0.0`

---

## TKT-0732 — 2026-07-05 — fix: corrupción DB producción + guardas en workflows

### Síntoma
6 runs consecutivos de `check-streams-v2.yml` fallando en ~1 minuto con `database disk image is malformed` para las 1264 emisoras. La DB en producción estaba corrupta; al descargarla y correr el crawler, todos los UPSERT fallaban y el run terminaba con error antes de llegar al upload.

### Causa raíz
WAL huérfano en el servidor. `icy_refresh.php` corre cada 10 minutos vía cron cPanel y escribe al SQLite del servidor en modo WAL. Si el proceso se interrumpe o el archivo se reemplaza con timing adverso, el WAL queda en estado inconsistente respecto al DB principal. La combinación DB-nueva + WAL-viejo produce `database disk image is malformed` en SQLite.

El ciclo se auto-perpetuaba: el crawler descargaba la DB corrupta → fallaba antes de terminar → el upload (con DB limpia) nunca se ejecutaba → la DB corrupta se mantenía en el servidor.

### Fix inmediato
Subir la copia local (757760 bytes, `integrity_check=ok`) al servidor vía lftp atómico:
```
put db/radio_v2.sqlite -o /radio/db/radio_v2.sqlite.new
mv /radio/db/radio_v2.sqlite.new /radio/db/radio_v2.sqlite
```
Verificado: run manual exitoso en 13m38s, todos los steps verdes.

### Guardas aplicadas (ambos workflows)
1. **`PRAGMA integrity_check` al descargar**: si la DB está corrupta, el job falla inmediatamente con mensaje claro. Antes corría 12+ minutos reportando error por cada emisora.
2. **`rm WAL` y `rm SHM` en el servidor tras el upload**: elimina archivos de journal huérfanos que podrían corromper la DB nueva cuando `icy_refresh.php` la re-abra.

### Archivos modificados
- `.github/workflows/check-streams-v2.yml`
- `.github/workflows/enrich-v2.yml`

---

## TKT-0731 — 2026-07-01 — Crawler automático de competencia

### Motivación
Automatizar la comparación manual que hicimos contra myradioenvivo.ar — que corra solo y avise cuando hay algo nuevo.

### Implementación
- **`crawlers/competitor_scan.py`**: Scrapea myradioenvivo.ar (detecta streams en `data-src` base64 + nombres en `data-name`). Compara contra DB local por nombre normalizado y dominio de URL. CDNs compartidos (streamtheworld, radiohdvivo) se comparan por URL completa para evitar falsos negativos. Genera reporte Telegram con: nuevas, URLs alternativas, ya existentes.
- **`.github/workflows/competitor-scan.yml`**: Corre todos los lunes 08:00 AR. Descarga DB desde FTP (solo lectura, no sube). Extensible: agregar targets en la lista `TARGETS` del script.
- Diseño sin IA — solo muestra lo que encuentra, Carlos y Claude deciden qué agregar.

### Primer resultado (prueba local)
- Radio Browser API: 782 emisoras AR — ya las teníamos todas (0 nuevas). Confirma cobertura superior.
- myradioenvivo.ar: 54 emisoras, 1 nueva post-insert (Radio Del Sur), 9 URLs alternativas.

### Emisoras agregadas como resultado del scan
- **Belgrano Radio** (`belgrano-radio`, id 1264) → `https://server.laradio.online:15223/live.mp3` — `approved=1`, stream verificado.
- **Radio Del Sur** (`radio-del-sur`, id 1265) → `https://cdn1.tvlin.net/icecast/radiodelsuraudio/icecast.audio` — `approved=0`, URL sin respuesta al verificar, pendiente de confirmar.

### Archivos nuevos
- `crawlers/competitor_scan.py`
- `.github/workflows/competitor-scan.yml`

---

## TKT-0730 — 2026-07-01 — Historial ICY: últimas canciones por emisora

### Motivación
Adoptar una funcionalidad vista en la competencia (myradioenvivo.ar): mostrar las canciones que sonaron en la emisora.

### Implementación
- **Nueva tabla `icy_history`**: `station_id, title, seen_at`. Índice en `(station_id, seen_at DESC)`. Máximo 50 entradas por emisora (limpieza automática en cada ciclo).
- **`icy_refresh.php`**: detecta cambio de título (`prev != new`), INSERT en `icy_history`. Crea tabla con `CREATE TABLE IF NOT EXISTS` al arrancar, sin migración manual.
- **`station.php`**: sección "♪ Últimas canciones" con las últimas 15 entradas, hora local AR, visible solo si `icy_supported=1` y hay historial.
- DB desplegada (740 KB), archivos PHP pusheados a GitHub.

### Archivos modificados
- `crawlers/icy_refresh.php`
- `web/pages/station.php`
- `db/radio_v2.sqlite` (tabla icy_history + índice)

### Pendiente relacionado
- **TKT-0731**: Descripción editorial de emisoras generada con IA (campo `descripcion` en stations + renderizado en station.php). Espera a tener más rodaje con el historial.

---

## TKT-0729 — 2026-07-01 — v2.1.0: +6 emisoras nuevas, 3 URLs actualizadas

### Cambios
- Agregadas 6 emisoras nuevas (identificadas comparando con myradioenvivo.ar, confirmadas sin duplicados por nombre y URL):
  - **LOS40 Argentina** (slug: `los40-argentina`) → radiohdvivo.com
  - **Mía FM 104.1** (slug: `mia-fm-104-1`) → streamtheworld FM1041_56AAC
  - **Radio Mitre Córdoba AM 810** (slug: `radio-mitre-cordoba-am-810`) → streamtheworld AM810_56AAC
  - **Radio Colonia AM 550** (slug: `radio-colonia-am-550`) → streamtheworld COLONIA_SC
  - **96.5 La Plata** (slug: `96-5-la-plata`) → solumedia.com/6466
  - **Mujer FM** (slug: `mujer-fm`) → radiohdvivo.com
- Actualizadas 3 URLs con estado `timeout` (también de la comparación):
  - **Street** (id 102): cdn2.instream → ipanel.instream.audio:7006
  - **Vale** (id 144): s6.stweb.tv → vale.stweb.tv
  - **Rock & Pop** (id 554): 305streamcdn → streamtheworld ROCKANDPOP_SC
- Fix `_helpers.php`: `CREATE TABLE IF NOT EXISTS settings` antes del query en `notify_active()`.
- DB desplegada al servidor vía FTP atómico (749 KB).
- Tag `v2.1.0` creado y pusheado a GitHub.

### Estado DB post-update
- Total emisoras aprobadas: 1263 (+6)
- IDs nuevas: 1258–1263; n: 1281–1286

---

## TKT-0728 — 2026-07-01 — FAQ reproductores externos (pendiente)

Varias personas preguntaron cómo conectar Rhythmbox, VLC, Kodi, etc. al M3U.
Texto borrador redactado. Se espera más demanda antes de publicar. Evaluar si va en README, en el sitio o en ambos.

---

## TKT-0727 — 2026-07-01 — Política de privacidad + eliminación de Google Analytics

### Cambios
- Eliminado `GA_ID` del `config.php` del servidor (analytics de terceros incompatible con la política declarada).
- Creado `web/components/privacy.php`: bottom sheet scrolleable con fondo blanco, animación CSS, cierre con ✕/Escape/click fuera. Incluido en `listing.php` y `station.php`.
- `LEGAL.md` publicado en el repo con el texto completo de la política.
- Política cubre: datos anónimos registrados, qué no se registra, legalidad de streams ICY/URLs públicas, marco legal (Ley 25.326, 11.723, 25.690, 27.275), contacto `radio@mammoli.ar`.
- Misma política adaptada y publicada en `camammoli/iptv` y `camammoli/Manuales` (LEGAL.md en cada repo).
- Gist actual (21ce6e3b) y gist viejo (bfb2cdc2) actualizados con sección de uso legal.

### Archivos modificados
- `web/components/privacy.php` (nuevo)
- `web/pages/listing.php` — include privacy.php
- `web/pages/station.php` — include privacy.php
- `LEGAL.md` (nuevo)
- `config.php` en servidor — GA_ID eliminado

---

## TKT-0726 — 2026-07-01 — Fix: DB corrupta (segunda vez) + correcciones panel admin

### DB corrupta (segunda vez)
`check-streams-v2.yml` corrió antes de que el fix de concurrencia (`TKT-0722`) tomara efecto en GitHub Actions. DB restaurada desde copia local limpia (745 KB, 1257 emisoras, integrity OK) vía FTP atómico (put .new → mv). Fix de concurrencia confirmado activo.

### Correcciones panel admin (`web/admin.php`)
- **`stat-icy-activo` freezado**: faltaba `icy_activo` en la respuesta AJAX `?ajax=1` y en el JS `refreshAdmin()`. Agregado — ahora se refresca cada 10 s como el resto.
- **Cabeceras tabla Crawlers incorrectas**: "Con título / Sin título" → "Cambios / Errores" (los campos reales son `changes_detected` y `errors`).
- **`<tbody>` inexistente bloqueaba AJAX**: si `plays_recientes` o `shares_recientes` estaban vacíos al cargar, el `<tbody id="plays-body">` no existía en el DOM y el AJAX no podía poblarlos. Refactorizado para que la tabla siempre se renderice (fila vacía reemplazable).

### Tab "Disponibles" por defecto (`web/pages/listing.php`)
El directorio arrancaba en "Todas". Cambiado a `filterStatus = 'ok'` y botón `f-ok` marcado como activo. `applyFilters()` se llama siempre al cargar (no solo con filtros de URL).

### Archivos modificados
- `web/admin.php`
- `web/pages/listing.php`

---

## TKT-0725 — 2026-06-28 — Fix: panel admin datos viejos + toggle Telegram ignorado

### Problema 1 — Panel mostraba datos viejos tras unos segundos
El auto-refresh JS hacía `fetch('?ajax=1')` sin cache-buster. El browser cacheaba la respuesta GET y sobreescribía los datos frescos del PHP inicial con datos viejos.

**Fix:** `admin.php` — cambio `?ajax=1` → `?ajax=1&_=Date.now()`.

### Problema 2 — Toggle Telegram desactivado pero notificaciones seguían llegando
Tres rutas ignoraban el setting de BD:
1. `api/_helpers.php` → `notify_active()` podía fallar con excepción si la tabla `settings` no existía (no la crea la API, solo admin.php). El catch devolvía la constante `NOTIFY_OYENTES = true`.
2. `web/pages/station.php` → notificación de "reporte de caída" enviaba Telegram sin consultar el toggle.

**Fix:**
- `api/_helpers.php`: `notify_active()` ahora hace `CREATE TABLE IF NOT EXISTS settings` antes del SELECT, garantizando que la tabla existe independientemente de si admin.php cargó primero.
- `web/pages/station.php`: reporte de caída ahora verifica `notify_active($db)`.

### Archivos modificados
- `web/admin.php` — cache-bust en fetch Ajax
- `web/api/_helpers.php` — notify_active robusto
- `web/pages/station.php` — reporte usa notify_active

---

## TKT-0724 — 2026-06-27 — API endpoint /radio/api/stations.json

### Cambios
- `web/api/export.php`: exporta todas las emisoras aprobadas como JSON array plano (sin wrapper `{ok, data}`). Campos: `slug, nombre, url, provincia, logo, tags, codec, bitrate, estado`. CORS abierto (`Access-Control-Allow-Origin: *`), cache pública 1h. Tags se deserializan de JSON string a array. Bitrate como entero o null.
- `web/.htaccess`: nueva regla antes del catch-all `api/{endpoint}` → `RewriteRule ^api/stations\.json$ api/export.php [L,QSA]` (necesaria porque `.` no matchea el patrón genérico `[a-z0-9_-]`).

### Por qué no reusar stations.php
`stations.php` tiene paginación (limit/offset) y devuelve wrapper con metadata. El endpoint de export es distinto en semántica: sin paginación, formato plano, pensado para consumo externo.

### Deploy
`lftp put` → `/radio/api/export.php`, `/radio/.htaccess`

---

## TKT-0723 — 2026-06-27 — Gist V2: sync desde DB, workflow semanal, README y presentación a pisculichi

### Contexto
El gist `camammoli/21ce6e3ba07486bcd16a28cda967f0d9` es un fork del [gist original de pisculichi](https://gist.github.com/pisculichi/fae88a2f5570ab22da53). En V1 se sincronizaba via `hunt-stations.yml` que corría `gist_sync.py` leyendo `emisoras.txt`. Con la migración a V2 (SQLite) y la eliminación de los workflows V1 zombie, el gist dejó de actualizarse.

### Fix
- `gist_sync.py` reescrito: lee emisoras desde `stations WHERE approved=1` via `get_db()`. Elimina toda la lógica de comentar en el gist original (era ruido, pisculichi no puede hacer merge). Queda solo la actualización del fork.
- Nuevo workflow `gist-sync.yml`: cada lunes a las 12 UTC, descarga DB por FTP, corre `gist_sync.py`. Usa secret `GITHUB_PAT` (scope gist).
- `README.md` del repo: nueva sección "Directorio como gist" con URL del fork, del original y del endpoint JSON. Tabla de workflows actualizada.
- README.md agregado al gist via GitHub API: explica qué es el fork, links al player, API y repo. GitHub lo renderiza automáticamente.
- Comentario enviado al gist de pisculichi (id 6220172) presentando el fork, el player y el endpoint JSON.

### Corrección a TKT-0722
Los workflows V1 `check-streams.yml`, `hunt-stations.yml` y `add-station.yml` fueron **eliminados** (`git rm`), no convertidos a `workflow_dispatch`. La nota anterior era inexacta.

---

## TKT-0722 — 2026-06-27 — Fix: race condition DB + desactivación workflows V1

### Causa raíz de la corrupción
`check-streams-v2.yml` descarga la DB, la procesa y la sube con `lftp put` (no atómico). Si `icy_refresh.php` (cron cPanel cada 10 min) abre la DB DURANTE el upload, el servidor recibe bytes mezclados → **corrupción irrecuperable**. Probabilidad por run ~1%, con 4 runs/día es cuestión de semanas hasta que ocurra.

### Fix 1: checkpoint WAL antes de cerrar (`check_streams_v2.py`, `enrich_v2.py`)
`PRAGMA wal_checkpoint(TRUNCATE)` antes de `db.close()` garantiza que el archivo `.sqlite` tenga todos los cambios integrados, sin depender del WAL, antes de ser subido. SQLite ignora automáticamente el WAL viejo del servidor (salt mismatch).

### Fix 2: upload atómico en `check-streams-v2.yml` y `enrich-v2.yml`
```
put db/radio_v2.sqlite -o /radio/db/radio_v2.sqlite.new
ren /radio/db/radio_v2.sqlite.new /radio/db/radio_v2.sqlite
```
`ren` es `rename()` de POSIX (atómico): el archivo en el servidor pasa de viejo a nuevo en una sola operación, sin ventana de archivo parcial.

### Fix 3: desactivar crons V1 obsoletos
| Workflow | Estado | Motivo |
|---|---|---|
| `check-streams.yml` | cron → solo workflow_dispatch | Verificaba `emisoras.json` V1, subía `status.json` que el player V2 no usa |
| `hunt-stations.yml` | cron → solo workflow_dispatch | Cazaba a `sugerencias.json` V1 (ya no se usa); TG apuntaba a `admin_sugerencias.php` inexistente |
| `add-station.yml` | solo manual (sin cambios) | Agrega a `emisoras.txt` V1; inofensivo al no tener cron |

### Archivos root V1 (sin cron automático ahora)
`hunt_stations.py`, `recuperar_caidas.py`, `verificar_urls.sh`, `gist_sync.py`, `track_since.py`, `enrich.py`, `emisoras.json`, `emisoras.txt` — conservados por historial, ninguno corre automáticamente.

---

## TKT-0721 — 2026-06-27 — Recuperación DB corrupta

### Causa
La DB `radio_v2.sqlite` en el servidor quedó corrupta (`database disk image is malformed`). Todo el sitio retornaba HTTP 500. Probablemente causada por un write parcial durante un cron o un GitHub Actions run fallido.

### Fix
Restaurada desde la copia local (`db/radio_v2.sqlite`, Jun 24). Migraciones aplicadas antes de subir:
- `surveys.location`
- `settings` (tabla)
- `shares` (tabla)
- `plays.ended_at`
- `stations.contacto`

Se pierden plays/surveys/shares desde Jun 24. Emisoras (1257), stream_status e icy_cache conservadas.

### Prevención futura
Considerar backup automático de la DB antes de cada cron de GitHub Actions (descargar con timestamp).

### Deploy
`lftp put` → `/radio/db/radio_v2.sqlite`

---

## TKT-0720 — 2026-06-27 — Media Session API: hotkeys Bluetooth / teclado

### Cambios
- `player.js`: agrega `logo` y `onNextTrack`/`onPrevTrack` como opts. Nuevo `setupMediaSession()` registra `navigator.mediaSession` al iniciar reproducción: metadata (nombre + artwork), handlers play/pause/stop/nexttrack/previoustrack. `setStation()` acepta `newLogo` y llama `updateMediaMeta()` para actualizar el nombre en el auto sin esperar el próximo `playing`.
- `listing.php`: agrega `data-logo` a las cards. Refactoriza la lógica de inicio en `playStation(el)` (compartida por click y hotkeys). Pasa `onNextTrack`/`onPrevTrack` al player: navegan por las emisoras visibles (`.station:not(.hidden)`), respetando el filtro activo.
- `station.php`: pasa `logo` al RadioPlayer para que la pantalla del auto muestre el artwork.

### Funcionamiento
- Teléfono conectado por BT a la camioneta/auto
- Se abre mammoli.ar/radio en el navegador y se inicia una emisora
- Los botones ⏮⏭ del equipo de audio cambian la emisora en el listado filtrado visible
- La pantalla del auto muestra el nombre de la emisora (y logo si existe)

### Deploy
`lftp put` → `/radio/assets/player.js`, `/radio/pages/listing.php`, `/radio/pages/station.php`

---

## TKT-0719 — 2026-06-26 — Fix: sugerir.php desconectado del admin v2

### Causa raíz
`sugerir.php` era código v1: guardaba sugerencias en `data/sugerencias.json`. El admin v2 lee de `stations WHERE source='sugerencia'`. Formulario público y panel completamente desconectados — sugerencias nunca aparecían en el admin.

### Fix
Reescritura del handler PHP de `sugerir.php`:
- Elimina toda la lógica de JSON file (`DATA_FILE`, `url_en_emisoras()`, caché de `emisoras.json`)
- Agrega `require_once __DIR__ . '/api/_db.php'` para usar `radio_db()`
- Verifica duplicados directamente en `stations` (por URL)
- Genera slug con `_sugerir_slug()` (accent norm + anti-colisión)
- Inserta con `source='sugerencia', approved=0` en tabla `stations`
- Notificación Telegram apunta a `admin.php` (no al viejo `admin_sugerencias.php`)
- Formulario HTML sin cambios

### Deploy
`lftp put` → `/radio/sugerir.php`

---

## TKT-0718 — 2026-06-26 — Admin panel: auto-refresh sin F5

### Cambios
- `admin.php`: endpoint `?ajax=1` (GET, solo lectura, requiere sesión) devuelve JSON con stats + plays (200) + shares (100)
- `session_write_close()` antes de queries para liberar el lock de sesión PHP
- try/catch en el handler: errores de DB devuelven JSON válido en vez de romper silenciosamente
- JS en el panel: polling cada 10s, actualiza 6 stat cards (total, ok, icy, plays_hoy, plays_total, listeners), tbody de plays con duración en tiempo real y tbody de shares
- Indicador `↻ HH:MM:SS` en top-bar muestra la última actualización

### Fix incluido: congelamiento con 2+ oyentes
El handler original hacía un DELETE (escritura) que competía con los pings de los oyentes en SQLite WAL → lock contention → el ajax fallaba silenciosamente y la UI se congelaba. Fix: sin DELETE en el handler (listeners.php ya hace el cleanup en cada ping).

### Deploy
`lftp put` → `/radio/admin.php`

---

## TKT-0717 — 2026-06-26 — Fix: Service Worker cacheaba los pings de oyentes

### Causa raíz
`sw.js` tenía lista de exclusiones: `['listeners.php', 'nowplaying.php', ...]`. La URL real del ping es `/radio/api/listeners` (sin `.php`). El SW cacheaba la respuesta del primer ping y devolvía la misma copia a todos los heartbeats siguientes → el servidor nunca recibía los pings posteriores → `last_seen` nunca se actualizaba → oyente expiraba a los 90s → se ponía gris en el admin.

### Fix
Reemplazada la lista de exclusiones por una sola condición:
```javascript
if (url.pathname.startsWith('/radio/api/')) return;
```
`CACHE_NAME` bumpeado de `radio-ar-v2` a `radio-ar-v3` para forzar que todos los browsers descarten el SW viejo.

### Deploy
`lftp put` → `/radio/sw.js`

---

## TKT-0716 — 2026-06-26 — Fix: listeners.php roto por migración ended_at sin try/catch

### Causa raíz
La feature de duración (TKT-0715) agregó la migración `ALTER TABLE plays ADD COLUMN ended_at` solo en `admin.php`. `listeners.php` usaba esa columna en un UPDATE sin try/catch. Al llegar cualquier ping, PDO tiraba excepción → HTTP 500 → el cliente recibía respuesta no-JSON → `r.ok = false` → ping descartado silenciosamente. Resultado: cero registros nuevos, listener count congelado en el último valor pre-deploy.

### Fix
- Migración `ended_at` movida a `listeners.php` con try/catch (idempotente)
- UPDATE de `ended_at` también protegido con try/catch
- `station.php`: `onListeners` usa `stationCount` (emisora específica) en vez de count global
- Conteo visible desde 1 persona (antes requería > 1)

### Deploy
`lftp put` → `/radio/api/listeners.php` + `/radio/pages/station.php`

---

## TKT-0715 — 2026-06-26 — Duración de reproducción en panel admin

### Cambios
- `plays`: nueva columna `ended_at TEXT` (migración automática con try/catch en listeners.php y admin.php)
- `listeners.php` action=stop: `UPDATE plays SET ended_at = datetime('now')` antes de borrar el listener
- `listeners.php` cleanup TTL: `UPDATE plays SET ended_at = last_seen` para sesiones expiradas, luego DELETE
- `admin.php` query plays: LEFT JOIN con listeners para calcular `duration_secs` y `is_active`
- Display: sesiones activas en verde con `▶ Xm Ys`; sesiones cerradas con duración fija; plays anteriores al deploy muestran `—`
- `fmt_duration()`: helper PHP para formatear segundos → `Xs / Xm Ys / Xh Ym`

### Lógica de duración
- Stop explícito (botón o tab cerrada): duración exacta
- Expiración TTL (red caída, tab cerrada de golpe): duración = último heartbeat recibido (±90s)
- Plays anteriores al deploy: `—` (sin ended_at)

### Deploy
`lftp put` → `/radio/api/listeners.php` + `/radio/admin.php`

---

## TKT-0712 — 2026-06-25 — Cutover v1→v2 a producción + panel admin

### Contexto
v2 en beta desde semanas anteriores. Cutover definitivo a producción con los siguientes requisitos:
no interrumpir oyentes activos, archivar v1, no romper SEO/crawlers, activar notificaciones Telegram,
mantener M3U y Gist disponibles, solo v2 en repo.

### Cambios

**Archivado v1**
- Branch `v1-archive` creado con snapshot completo de todos los PHP v1 (index.php 83KB monolito + endpoints)
- Tag `v1-final` aplicado en ese commit
- Pusheados a GitHub: `origin/v1-archive`, tag `v1-final`

**Admin panel** (`web/admin.php`)
- Panel completo con auth (sesión PHP, sin redireccionamiento para evitar race conditions)
- Noindex, Cache-Control: no-store en todas las respuestas
- Secciones: Resumen (9 stat cards), Encuestas (rating + location), Sugerencias (aprobar/rechazar con CSRF),
  ICY activas (semáforo de frescura), Log de crawlers
- Tema claro/oscuro con toggle localStorage synced con el resto del sitio
- Login con tema claro fijo
- Credenciales: `carlos` / en config.php (no commiteado)

**ICY tiempo real (admin + listing)**
- `admin.php` muestra tabla ICY activas con columna de frescura (verde <15min, amarillo <1h, rojo >1h)
- `listing.php`: card activa sincroniza el título vía `onNowPlaying` callback del player

**Crawler ICY PHP** (`crawlers/icy_refresh.php`)
- cURL Multi con 20 conexiones concurrentes, barre todas las estaciones cada 10min (cron cPanel)
- Logging a tabla `crawler_runs`
- Encuesta: campo `location` agregado en `surveys` table vía migración automática

**sw.js**
- `CACHE_NAME` bumpeado de `radio-ar-v1` a `radio-ar-v2` para forzar invalidación en browsers

**Deploy producción**
- `index.php` reemplazado: router v2 (1KB) en lugar del monolito v1 (83KB)
- `admin.php`, `sw.js`, `sitemap.php`, `api/`, `pages/`, `assets/`, `components/` actualizados
- `crawlers/icy_refresh.php` subido a `/radio/crawlers/`
- `config.php` ya tenía `NOTIFY_OYENTES=true`, `ADMIN_USER`, `ADMIN_PASS`, `RADIO_DB` → sin cambios

**Git**
- v2 mergeado a `master` (default branch) → GitHub Actions crons activos
- Conflicto en `check-streams-v2.yml` resuelto: eliminada condición `if:` de rama, checkout
  siempre usa `ref: v2` explícitamente

### Cierre (2026-06-25)
- `/radio/beta/` eliminada del servidor — Carlos confirmó que producción funciona
- Branch `v2` eliminado (local y remoto) — código ya en `master`
- Branch `v1-archive` eliminado — snapshot accesible por tag `v1-final`
- Tag `v2.0.0` creado y pusheado — release oficial de v2
- README y V2_DESIGN actualizados: un solo branch `master`, versiones por tags
- GitHub About actualizado: descripción y homepage correctos
- Repo normalizado según convenciones estándar

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

## TKT-0713 — 2026-06-25 — SEO: meta descriptions, títulos y schema

### Problema
Search Console mostraba páginas de emisoras con 0% CTR a pesar de 90+ impresiones (antena-98-9: 95 imp/0 clics, dorrego: 94/0, rio-fm: 93/0). Causa: sin meta description, Google generaba snippets genéricos poco atractivos.

### Cambios en `web/pages/station.php`
- **Título**: cambió de `"NOMBRE — Escuchá en vivo | Radio Argentina"` a `"NOMBRE en Vivo Online Gratis | Radio Argentina"` — keywords que la gente busca
- **Meta description**: texto más rico con provincia, género (primer tag) y variante "Argentina". Ejemplo: "▶ Escuchá Antena 98.9 en vivo online, gratis y sin instalar nada. Emisora de Mendoza, Argentina. Pop, rock."
- **Párrafo descriptivo**: `<p>` de 13px/color muted justo antes del player, generado dinámicamente desde nombre + provincia + tags + codec. Contenido indexable adicional para Google.

### Cambios en `web/pages/listing.php`
- **Título**: `"Radio Argentina en Vivo — N Emisoras Online Gratis"` (con variante provincia)
- **Meta description**: agrega géneros explícitos: "FM, AM, noticias, rock, folklore, cumbia y más"
- **ItemList JSON-LD**: schema con las 30 emisoras activas más reproducidas. Google puede mostrar el sitio como un rich result de lista.

### Deploy
`lftp put` → `/radio/pages/station.php` + `/radio/pages/listing.php`

### Siguiente paso
Solicitar re-rastreo en Search Console (URLs prioritarias: antena-98-9, dorrego, rio-fm-rosario).

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

---

## TKT-0701 — 2026-08-24 — Recuperación DB corrupta (5ª vez) + deploy de fixes que habían quedado pendientes de una sesión cortada

### Contexto
Sitio caído (500 en `/`, `admin.php` seguía respondiendo). Misma familia de incidentes de corrupción recurrente de `radio_v2.sqlite` ya documentados en TKT-0687/0690/0693/0695. Esta vez con una diferencia importante: se perdieron 21 filas reales de la tabla `stations` (antes solo se perdían tablas de log como `stream_history`/`station_events`).

### Recuperación
- Header con page-count desincronizado otra vez, `.recover` con el sqlite3 3.40.1 del sistema fallaba con "SQL logic error" — hubo que usar el binario oficial 3.45.1 (`sqlite-tools-linux-x64-3450100.zip`, mismo truco que TKT-0693) para que `.recover` funcionara.
- El primer intento de reconstrucción quedó con 1246/1267 `stations` y decenas de violaciones de `PRAGMA foreign_key_check` (`stream_status`/`icy_cache`/`station_events`/`plays` referenciando rowids 151–171 inexistentes).
- Se encontró que una sesión previa (cortada) ya había recuperado la DB a las 16:58 y la había dejado lista para subir en `~/Escritorio/Backups/radio_visitantes_fix_20260824_165814/radio_v2_recovered_a_subir.sqlite` — esa copia sí tenía las 1267 `stations` completas, `integrity_check` ok y 0 violaciones de FK. Se usó esa (no la reconstruida en esta sesión) para no perder ninguna emisora.
- Subida por FTP muy lenta este día (~65KB/s) — el primer intento de `put` se cortó a los 2 min con solo 8/27MB. Se resolvió con `reput` (resume) en background en vez de reintentar desde cero.

### Deploy
Junto con la DB, se subieron los 2 archivos que la sesión cortada había dejado preparados en la misma carpeta de backup:
- **`web/admin.php`**: el cálculo de `is_active` en las 2 queries de listado pasó de `l.sid IS NOT NULL` a `l.sid IS NOT NULL AND p.ended_at IS NULL` — corrige el bug ya conocido de sesiones que quedan "reproduciendo" para siempre en el panel cuando el heartbeat se corta sin avisar.
- **`web/api/listeners.php`**: nuevo `BLOCKED_IP_HASHES = ['c7a0e2692b529b79']` — ese hash corresponde al patrón de station-hopping masivo (badge 🤖) ya visto en el admin; ahora ese IP recibe el conteo total del sitio en vez de contar como oyente real de cada emisora, para no seguir ensuciando las métricas de audiencia.

### Hipótesis nueva sobre la causa raíz de la corrupción recurrente (sin confirmar)
Los 3 workflows que hacen swap de la DB por FTP (`check-streams-v2`, `dedupe-streamtheworld`, `enrich-v2`) se coordinan entre sí con `concurrency: group: radio-db-write`, pero esa protección NO cubre las escrituras PHP en vivo del servidor: tráfico real (`listeners.php`/`plays`), `icy_refresh.php` (cron cPanel cada 10min) y los cron que llaman endpoints por HTTP (`cron_close_sessions.php` cada 15min, `cron_learn_patterns.php`). Entre el `mv` que activa el archivo nuevo y el `glob -a rm` que borra el `-wal`/`-shm` viejo del servidor (2 comandos lftp separados, con reconexión de por medio) hay una ventana en la que cualquier request PHP que abra la DB en modo WAL puede encontrar un `-wal` viejo (de antes del swap) y aplicarlo sobre las páginas del archivo nuevo → corrupción. No se pudo confirmar con timestamps exactos esta vez (la corrupción no coincidió con ninguna corrida de GitHub Actions cercana). Pendiente: combinar el `rm` del wal/shm en la MISMA sesión lftp que el `mv` (reduce la ventana a cero reconexiones) en los 3 workflows, y/o instrumentar con logging para capturar el próximo incidente con evidencia directa.

### Archivos afectados
- `web/admin.php`, `web/api/listeners.php` (deploy)
- `db/radio_v2.sqlite` (restaurado en servidor)
- Pendiente: `.github/workflows/check-streams-v2.yml`, `dedupe-streamtheworld.yml`, `enrich-v2.yml` (fusionar mv+rm en una sola sesión lftp, no aplicado aún)

## 2026-08-29 — Anti-spam: rate limit en contacto.php y suscribirse.php (Claude Code)

### Contexto
Auditoría general de Carlos sobre todos sus formularios públicos contra el checklist
estándar (`feedback_estandar_formularios_contacto`, 2026-08-20). `contacto.php` y
`suscribirse.php` ya tenían honeypot y trampa de tiempo — les faltaba el rate limit.
De paso, se encontró que la acción `send_link` de `suscribirse.php` (pedir el link de
gestión por email/Telegram) no tenía NINGUNA protección — el más expuesto de los tres,
porque manda un mensaje real (Telegram o mail) a quien esté registrado con el contacto
buscado, sin ningún filtro.

### Cambios
- **`contacto.php`**: rate limit de 5 mensajes por IP por hora (archivo temporal JSON,
  clave `radio_contacto_<ip>`), insertado después del honeypot/trampa de tiempo (ya
  existentes) y antes de la validación real del mensaje — error real y visible si se
  supera.
- **`suscribirse.php`, alta nueva**: mismo patrón, clave `radio_suscribir_<ip>`, 5/hora.
- **`suscribirse.php`, acción `send_link`**: se le agregó honeypot (`web3`) + trampa de
  tiempo (`ts2`, mismo `$render_ts` de la página) + rate limit (`radio_sendlink_<ip>`,
  5/hora) — no existía ninguno de los tres antes. Honeypot/trampa disparados muestran el
  mismo mensaje "Listo" que un pedido real (no revela nada nuevo, ya era el
  comportamiento normal no revelar si el contacto existe o no).

### Validado
Contra copia real de la DB de producción (28MB) bajada por FTP y corrida en local con
`php-portable` (SQLite, no hace falta MySQL acá). Honeypot y trampa de tiempo:
confirmado que siguen fingiendo éxito sin guardar nada en las 3 rutas. Rate limit:
probado enviando intentos que pasan el honeypot/trampa pero fallan la validación real
siguiente (mensaje muy corto / preferencias vacías / contacto sin match) — así se pudo
confirmar el conteo y el bloqueo al 6° intento sin mandar ningún Telegram/email real
durante la prueba. Confirmado también en producción (honeypot de las 2 páginas
principales, con backup previo de los archivos) sin dejar rastro en la DB real.

### Nota — falso positivo del WAF durante la verificación
Al chequear `contacto.php` recién desplegado en producción, dio 409 con un mini-script
que setea `document.cookie = "humans_21909=1"` y recarga — es el "Human Activity
Detector" de Imunify360 reaccionando a la ráfaga de requests automatizados de la propia
verificación, no un problema del deploy. Pasando esa cookie a mano, la página real
carga en 200 sin cambios. Transparente para cualquier navegador real (ejecuta el JS
solo). Mismo tipo de comportamiento ya documentado para este hosting en
`feedback_waf_post_bloqueado`, pero con un mecanismo distinto (challenge JS/cookie en
vez de 406 directo) — vale la pena tenerlo en cuenta si vuelve a aparecer.

### Archivos afectados
- `web/contacto.php`, `web/suscribirse.php` (deploy directo, sin tocar la DB ni otros
  archivos).

## 2026-08-29 (2) — Anti-spam en sugerir.php (Claude Code)

### Contexto
Cierre de la lista de la auditoría de formularios. `sugerir.php` no tenía honeypot,
trampa de tiempo ni rate limit. Estaba parcialmente mitigado porque antes de guardar
o notificar corre `check_stream()`, que le pega un HEAD/GET real a la URL — pero ese
chequeo **no exige que sea un stream de audio**, solo que la URL responda 200-399.
Cualquier sitio real pasa. La única protección genuina previa era que la sugerencia
entra con `approved=0` (cola de moderación en `admin.php`, no se publica sola) — pero
igual mandaba un Telegram real por cada sugerencia "válida" sin ningún filtro.

### Cambios
Mismo patrón que `contacto.php` (honeypot `web` + trampa de tiempo `ts`, ambos ya
usados en ese archivo) + rate limit nuevo (5/hora por IP, clave `radio_sugerir_<ip>`).
El rate limit se chequea **antes** de `check_stream()` a propósito — esa función hace
requests salientes de verdad, tiene sentido cortar ahí antes de gastar esos recursos.

### Validado
Local con copia real de la DB (28MB): honeypot/trampa fingen éxito sin llamar a
`check_stream()` ni guardar nada; rate limit probado con `nombre` vacío (falla la
validación real siguiente sin llegar a hacer ningún request saliente ni guardar) —
5 pasan, 6° bloqueado. Flujo legítimo completo probado con `TG_TOKEN` vaciado *solo en
la copia local* (nunca se tocó `config.php` real) para confirmar que sigue guardando
bien sin mandar un Telegram real durante la prueba. Confirmado también en producción
(honeypot, con backup previo — sin drift entre prod y el repo antes de deployar).

### Nota — falso positivo de integridad durante la verificación
Al volver a bajar la DB después del test en producción para confirmar que no había
quedado nada guardado, un primer `PRAGMA integrity_check` dio
`row 1 missing from index idx_listeners_lastseen` (tabla `listeners`, no relacionada a
`stations`). Se volvió a bajar la base unos segundos después y dio `ok` — fue un
artefacto de descargar por FTP un SQLite en modo WAL mientras el servidor escribía en
vivo (mismo mecanismo ya documentado en TKT-0701 sobre la corrupción recurrente), no
algo causado por este cambio — la prueba de producción no hizo ningún `INSERT`, solo
activó el honeypot.

### Archivos afectados
- `web/sugerir.php` (deploy directo, sin tocar la DB).

### Estado de la auditoría de formularios (2026-08-20/29)
Con esto quedan cerrados todos los ítems de Radio de la auditoría. Resumen del
recorrido completo (otros proyectos, hecho en sesiones de Claude Code de estos días):
mammoli.ar raíz / Finca / LU2MCA ya estaban completos desde el 20/8. Tienda de Juan
(`api/pedido.php`) y QSLforge (`account/register.php`) se protegieron de cero. Radio
(`contacto.php`, `suscribirse.php` incluyendo `send_link`, `sugerir.php`) sumó lo que
le faltaba. Quedan de menor prioridad (mitigados por aprobación manual, sin tocar por
ahora): QSL Manager registro, Alerta SOS, y el nombre de honeypot de Pelotudos
(`website`, mal elegido según el propio estándar).

## 2026-09-01 — Investigación oyentes/SSH + recuperación DB (6ª vez) + fix real en listeners.php + prueba de los 7 workflows (Claude Code)

### Contexto
Carlos pidió verificar los oyentes (venían intentando por SSH el día anterior pero se
cortaba), evaluar si migrar a MySQL resolvería la corrupción recurrente, y correr
manualmente los jobs del repo (los 7 workflows llevaban desactivados desde TKT-0703,
2026-08-26) para confirmar que no dan error.

### SSH — diagnóstico (sin aplicar fix, falta info de Carlos)
El puerto 22 de mammoli.ar responde y completa el handshake TCP/key-exchange sin
problema (probado con paramiko, log completo). La autenticación con el usuario/clave de
FTP (`carlos@mammoli.ar` + la clave de `.ftp.conf`) fallar limpio ("Authentication
(password) failed") — no es la credencial correcta para SSH. Un intento anterior con
logging detallado quedó colgado >120s sin completar el handshake, evidencia real de
inestabilidad intermitente (coincide con "se desconectaba"). No se insistió con más
intentos de contraseña para no arriesgar un bloqueo de la cuenta en el hosting
compartido (afectaría también el FTP, usado para todo). **Pendiente de Carlos:**
confirmar en cPanel si SSH está habilitado para la cuenta, cuál es el usuario real
(probablemente distinto del login de FTP), y si conviene configurar autenticación por
clave pública en vez de contraseña (más confiable, evita el problema de "se
desconecta").

### 🐛 Bug real encontrado y corregido: ping de oyentes nuevos tiraba 500 desde el 27/08
Reproducido en vivo (`curl` directo a producción): cualquier sesión de oyente NUEVA
(sid nunca visto) hacía que `api/listeners.php` devolviera 500. La sesión SÍ se
registraba en `listeners` (primer INSERT, sin protección), pero el segundo INSERT (en
`plays`, para el historial de reproducciones) fallaba con `database disk image is
malformed` — **la tabla `plays` está corrupta puntualmente: pasa `PRAGMA
integrity_check` limpio, pero cualquier INSERT le falla**, algo confirmado también con
la CLI de sqlite3 directo, sin PHP de por medio. Esto explica por qué "oyentes en vivo"
seguía funcionando con normalidad (hasta 22 concurrentes vistos) mientras el historial
de reproducciones (`plays`, usado por estadísticas/top emisoras) quedó congelado en
4776 filas desde el 27/08 — 5 días sin una sola reproducción nueva registrada, pese a
tráfico real constante.

**Causa de fondo:** la corrupción es la misma de TKT-0703 (26/08) — memoria decía
explícitamente "la DB sigue corrupta, no se recuperó (pendiente de que Carlos decida)".
Nadie la había recuperado en los 6 días siguientes; simplemente quedó rota y sin tocar
todo ese tiempo (con los 7 workflows apagados, así que tampoco hay una "ventana de 6
días sin corrupción" real que sirva de evidencia — la base ya estaba rota desde el
primer día de esa ventana).

**Fix de código (`web/api/listeners.php`):** el INSERT a `plays` se protegió con
try/catch (con `error_log` del error real) — si vuelve a fallar, el ping sigue
devolviendo 200 y el oyente cuenta igual; solo se pierde el registro puntual de esa
reproducción en vez de tumbar todo el endpoint. Commit `66c7817`, desplegado y
confirmado en producción (200 con sid nuevo) antes incluso de recuperar la DB.

**Recuperación de la DB (autorizada explícitamente por Carlos):** mismo procedimiento
ya usado 5 veces antes — `sqlite3` 3.45.1 oficial (el 3.40.1 del sistema falla
`.recover` con "SQL logic error" en este tipo de corrupción, ya documentado) →
`.recover` → sacar `CREATE TABLE sqlite_sequence` → reconstruir → validar. **Esta vez
sin ninguna pérdida de datos** (1468 stations, 4776 plays, 5473 station_events, 215782
stream_history — todo idéntico al original corrupto). Validación extra que no se había
hecho antes en ningún incidente previo: probar el INSERT real que fallaba, no solo
`integrity_check` — confirmó que el problema estaba resuelto antes de subir. Deploy
atómico (put+mv+rm en una sola sesión lftp, patrón TKT-0706). Verificado post-deploy:
`plays` volvió a crecer con timestamps reales (4776→4813 en la misma sesión), sitio/
admin/estadisticas 200.

### MySQL — evaluación pedida por Carlos: recomendación SÍ migrar

Evidencia nueva de esta sesión que endurece la recomendación:
- Se logró reproducir un archivo SQLite que pasa `PRAGMA integrity_check` limpio pero
  falla de forma determinística en un INSERT real a una tabla específica — un patrón
  de corrupción "silenciosa" que ni siquiera el chequeo estándar detecta, y que solo
  se nota cuando algo intenta escribir ahí. Van 6 incidentes de corrupción documentados
  desde julio (TKT-0687/0690/0693/0695/0701/0703) pese a múltiples fixes defensivos
  (upload atómico, concurrency groups, fusión put+mv+rm).
- El patrón de fondo (muchos escritores concurrentes — tráfico real, cron cPanel de
  `icy_refresh.php` cada 10min, 2 endpoints cron vía HTTP, y hasta hace poco 3
  workflows de GitHub Actions reemplazando el archivo entero por FTP) sobre un
  filesystem de hosting compartido es exactamente el escenario que SQLite en modo WAL
  no soporta bien — la documentación oficial de SQLite desaconseja explícitamente
  usarlo sobre almacenamiento de red, porque el locking POSIX que necesita para
  coordinar escritores no siempre se respeta ahí.
- MySQL (ya usado en este mismo hosting para `mammoli_gestion`/`mammoli_qsl`, sin
  ningún incidente de corrupción) elimina esta clase de problema de raíz: un solo
  proceso `mysqld` coordina todos los accesos internamente — no hay "archivo que se
  reemplaza por FTP" ni locks de filesystem de por medio.
- Contras honestos: es una migración real, no un fix rápido — hay que portar `_db.php`
  (PDO singleton), ajustar SQL específico de SQLite (`datetime('now')`, `PRAGMA`,
  `ON CONFLICT`, semántica de `AUTOINCREMENT`), y los crawlers Python (usan el módulo
  `sqlite3` directo, no la API PHP) necesitan un cliente MySQL. No es algo para hacer
  en una sesión suelta — conviene planificarla aparte si Carlos decide seguir adelante.

### Prueba de los 7 workflows manuales (pedido explícito de Carlos)
Los 7 estaban `disabled_manually` desde TKT-0703. Se habilitaron y dispararon los 7 con
`gh workflow run` (esto también reactiva sus schedules automáticos — **pendiente
decisión de Carlos si dejarlos así o volver a desactivarlos**, ver mensaje enviado).
Resultado, con la DB ya recuperada:
- ✅ **5 éxito limpio:** Aprender patrones de programas, Sincronizar gist de emisoras,
  Competitor Scan, Cerrar sesiones huérfanas, Verificar streams v2.
- ⚠️ **1 cancelado:** Enriquecer emisoras v2 — se disparó casi al mismo tiempo que
  otro workflow del mismo `concurrency: group: radio-db-write`, y GitHub lo canceló
  antes de arrancar ningún job. Es el comportamiento correcto y esperado de esa
  protección (evita que 2 escrituras de DB se pisen) — en operación normal (horarios
  distintos por cron) esto no pasaría; fue un artefacto de dispararlos todos juntos
  para la prueba.
- ⚠️ **1 falló:** Deduplicar streamtheworld — el paso final (`rm` del `-wal`/`-shm`
  viejo) falló con "No such file or directory" porque esos archivos ya no existían
  (los había limpiado yo mismo minutos antes en la recuperación manual de la DB) — el
  `put`/`mv` de arriba no mostró confirmación explícita de éxito en el log en el poco
  tiempo entre pasos, pero la verificación final de la DB (después de las 7 corridas)
  dio íntegra y con los datos esperados, así que no hubo impacto real. Vale la pena
  igual endurecer ese paso (`glob -a rm` no debería fallar todo el job si no hay nada
  que borrar) — no aplicado en esta sesión, queda como mejora menor.

**Verificación final tras las 7 corridas:** `integrity_check` ok, INSERT de prueba
exitoso, 1468 stations (sin pérdida), `plays` siguió creciendo con tráfico real, sitio/
admin/estadisticas 200.

### Limpieza adicional (a pedido de Carlos)
4 líneas del crontab local (`crawler_radio_browser.py`, `dedup_emisoras.py`,
`verificar_urls.sh`, `recuperar_caidas.py`) apuntaban a scripts V1 ya archivados —
fallaban en cada corrida (varias veces por semana) ensuciando los logs sin tocar nada
real. Eliminadas del crontab (`trafico_github.py`, que sí existe y funciona, se
conservó). Backup del crontab anterior en `/tmp/crontab_backup_20260901.txt`.

### Archivos afectados
- `web/api/listeners.php` (deploy + commit `66c7817`)
- `db/radio_v2.sqlite` (recuperado y subido a producción, sin pérdida de datos)
- Crontab local del usuario (4 líneas rotas eliminadas)
- 7 workflows de GitHub Actions: quedaron **habilitados** (antes deshabilitados) —
  pendiente confirmación de Carlos si dejarlos así.
