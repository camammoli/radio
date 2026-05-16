<?php
/**
 * Radio Argentina — Player web
 * Lee emisoras.txt desde el repo de GitHub, con caché local de 1 hora.
 * ?m3u=1  → devuelve la lista completa como M3U (para VLC, apps IPTV)
 * ?buscar=texto → filtra por nombre (server-side, útil para bots/curl)
 */

require_once __DIR__ . '/log.php';

define('EMISORAS_URL', 'https://raw.githubusercontent.com/camammoli/radio/master/emisoras.txt');
define('CACHE_FILE',   sys_get_temp_dir() . '/radio_emisoras_cache.txt');
define('CACHE_TTL',    3600); // 1 hora

function cargar_emisoras(): string {
    if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
        return file_get_contents(CACHE_FILE);
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = @file_get_contents(EMISORAS_URL, false, $ctx);
    if ($raw !== false) {
        file_put_contents(CACHE_FILE, $raw);
        return $raw;
    }
    // Fallback: usar caché aunque esté vencido
    return file_exists(CACHE_FILE) ? file_get_contents(CACHE_FILE) : '';
}

function parsear_emisoras(string $texto): array {
    $lineas   = explode("\n", $texto);
    $total    = count($lineas);
    $stations = [];

    for ($i = 0; $i < $total; $i++) {
        $linea = trim($lineas[$i]);

        // Líneas activas: [NNN] o [#NNN] — el # dentro de [] NO es comentario
        if (!preg_match('/^\[#?(\d+)\]\s+(.+)/', $linea, $m)) continue;

        $numero = (int)$m[1];
        $nombre = trim($m[2]);

        // Buscar URL en la siguiente línea no vacía
        $url = '';
        for ($j = $i + 1; $j < min($i + 3, $total); $j++) {
            $sig = trim($lineas[$j]);
            if ($sig !== '' && preg_match('#^https?://#', $sig)) {
                $url = $sig;
                break;
            }
        }
        if ($url === '') continue;

        // Separar nombre de "* Provincia, País"
        $provincia = '';
        if (preg_match('/^(.+?)\s*\*\s*(.+)$/', $nombre, $pm)) {
            $nombre    = trim($pm[1]);
            $provincia = trim($pm[2]);
        }

        $stations[] = [
            'n'         => $numero,
            'nombre'    => $nombre,
            'provincia' => $provincia,
            'url'       => $url,
        ];
    }

    return $stations;
}

$raw      = cargar_emisoras();
$stations = parsear_emisoras($raw);
$total    = count($stations);

// ── Modo M3U ─────────────────────────────────────────────────────────────────
if (isset($_GET['m3u'])) {
    radio_log('m3u', '');
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="radio-argentina.m3u"');
    echo "#EXTM3U\n";
    foreach ($stations as $s) {
        $tag = $s['nombre'] . ($s['provincia'] ? ' · ' . $s['provincia'] : '');
        echo "#EXTINF:-1,{$tag}\n{$s['url']}\n";
    }
    exit;
}

// ── Filtro server-side (para curl/bots) ──────────────────────────────────────
$filtro = trim($_GET['buscar'] ?? '');
if ($filtro !== '') {
    radio_log('search', $filtro);
    $stations = array_values(array_filter($stations, fn($s) =>
        stripos($s['nombre'], $filtro) !== false ||
        stripos($s['provincia'], $filtro) !== false
    ));
}

radio_log('visit', '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Radio Argentina · <?= $total ?> emisoras</title>
  <meta name="description" content="<?= $total ?> radios argentinas en streaming — escuchá desde el navegador sin instalar nada.">
  <style>
    :root {
      --bg:      #111827;
      --surface: #1f2937;
      --border:  #374151;
      --text:    #f9fafb;
      --muted:   #9ca3af;
      --accent:  #3b82f6;
      --green:   #22c55e;
      --red:     #ef4444;
      --playing-bg: #1e3a5f;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
      min-height: 100vh;
      padding-bottom: 120px;
    }

    /* ── Header ── */
    header {
      background: linear-gradient(135deg, #1e3a5f 0%, #111827 70%);
      padding: 28px 20px 24px;
      text-align: center;
      border-bottom: 1px solid var(--border);
    }
    header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    header .sub { color: var(--muted); font-size: 13px; margin-bottom: 16px; }
    .badges { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
    .badge {
      background: rgba(255,255,255,.08);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 4px 12px;
      font-size: 12px;
      color: var(--muted);
      text-decoration: none;
    }
    .badge:hover { background: rgba(255,255,255,.14); color: var(--text); }
    .badge-cafe { border-color: #92400e; color: #fbbf24; }
    .badge-cafe:hover { background: rgba(251,191,36,.12); color: #fde68a; }

    /* ── Filtros de estado ── */
    .filtros {
      display: none;
      gap: 6px;
      flex-wrap: wrap;
      margin-top: 8px;
    }
    .filtros.visible { display: flex; }
    .filter-btn {
      border: 1px solid var(--border);
      background: rgba(255,255,255,.05);
      color: var(--muted);
      border-radius: 20px;
      padding: 4px 11px;
      font-size: 12px;
      cursor: pointer;
      transition: all .15s;
      white-space: nowrap;
    }
    .filter-btn:hover { background: rgba(255,255,255,.10); color: var(--text); }
    .filter-btn.active { background: rgba(59,130,246,.18); border-color: var(--accent); color: var(--text); }
    .filter-btn.f-ok.active      { background: rgba(34,197,94,.15); border-color: #22c55e; color: #86efac; }
    .filter-btn.f-timeout.active { background: rgba(245,158,11,.15); border-color: #f59e0b; color: #fcd34d; }
    .filter-btn.f-muerto.active  { background: rgba(239,68,68,.15);  border-color: #ef4444; color: #fca5a5; }

    /* ── Buscador ── */
    .search-wrap {
      position: sticky;
      top: 0;
      z-index: 10;
      background: var(--bg);
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
    }
    #buscador {
      width: 100%;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 15px;
      color: var(--text);
      outline: none;
    }
    #buscador:focus { border-color: var(--accent); }
    #buscador::placeholder { color: var(--muted); }
    .result-count { font-size: 12px; color: var(--muted); text-align: right; margin-top: 6px; }

    /* ── Lista ── */
    .lista {
      max-width: 720px;
      margin: 0 auto;
      padding: 8px 12px;
    }
    .station {
      display: flex;
      align-items: center;
      gap: 12px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 8px;
      cursor: pointer;
      transition: background .15s, border-color .15s;
    }
    .station:hover       { background: #2d3748; border-color: #4b5563; }
    .station.active      { background: var(--playing-bg); border-color: var(--accent); }
    .station.hidden      { display: none; }

    .dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: #374151; flex-shrink: 0; margin-top: 2px;
      title: attr(title);
    }
    .dot-ok      { background: #22c55e; }
    .dot-muerto  { background: #ef4444; }
    .dot-timeout { background: #f59e0b; }

    .station-num {
      font-size: 11px;
      color: var(--muted);
      min-width: 34px;
      text-align: right;
      font-variant-numeric: tabular-nums;
    }
    .station-info { flex: 1; min-width: 0; }
    .station-name {
      font-size: 14px;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .station-prov {
      font-size: 12px;
      color: var(--muted);
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .btn-play {
      width: 38px; height: 38px;
      border-radius: 50%;
      border: 2px solid var(--border);
      background: transparent;
      color: var(--muted);
      font-size: 16px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      cursor: pointer;
      transition: all .15s;
    }
    .station:hover .btn-play    { border-color: var(--accent); color: var(--accent); }
    .station.active .btn-play   { border-color: var(--green); color: var(--green); background: rgba(34,197,94,.1); }
    .station.loading .btn-play  { border-color: #f59e0b; color: #f59e0b; }
    .station.error .btn-play    { border-color: var(--red); color: var(--red); }

    /* ── Player sticky ── */
    #player-bar {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      background: #0f172a;
      border-top: 1px solid var(--border);
      padding: 10px 16px 14px;
      display: none;
      flex-direction: column;
      gap: 8px;
      z-index: 100;
    }
    #player-bar.visible { display: flex; }
    #player-title {
      font-size: 13px;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    #player-prov { font-size: 11px; color: var(--muted); }
    #audio-elem  { width: 100%; height: 36px; }
    audio::-webkit-media-controls-panel { background: var(--surface); }

    #btn-stop {
      position: absolute;
      right: 14px; top: 12px;
      background: transparent;
      border: none;
      color: var(--muted);
      font-size: 18px;
      cursor: pointer;
      padding: 4px;
    }
    #btn-stop:hover { color: var(--red); }
  </style>
</head>
<body>

<header>
  <h1>📻 Radio Argentina</h1>
  <p class="sub"><?= $total ?> emisoras en streaming · escuchá sin instalar nada</p>
  <div class="badges">
    <a class="badge" href="?m3u=1">⬇ Bajar M3U</a>
    <a class="badge" href="https://github.com/camammoli/radio" target="_blank">GitHub</a>
    <a class="badge" href="https://mammoli.ar">mammoli.ar</a>
    <a class="badge badge-cafe" href="https://cafecito.app/mammoli" rel="noopener" target="_blank">☕ Invitame un café</a>
  </div>
</header>

<div class="search-wrap">
  <input id="buscador" type="search" placeholder="Buscar por nombre o provincia..." autocomplete="off" autofocus>
  <div class="filtros" id="filtros"></div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
    <div style="font-size:11px;color:#6b7280" id="status-gen"></div>
    <div class="result-count" id="result-count"><?= $total ?> emisoras</div>
  </div>
</div>

<div class="lista" id="lista">
<?php foreach ($stations as $s): ?>
  <div class="station"
       data-url="<?= htmlspecialchars($s['url']) ?>"
       data-nombre="<?= htmlspecialchars($s['nombre']) ?>"
       data-prov="<?= htmlspecialchars($s['provincia']) ?>"
       data-search="<?= strtolower($s['nombre'] . ' ' . $s['provincia']) ?>">
    <span class="dot" title="sin verificar"></span>
    <span class="station-num"><?= $s['n'] ?></span>
    <div class="station-info">
      <div class="station-name"><?= htmlspecialchars($s['nombre']) ?></div>
      <?php if ($s['provincia']): ?>
        <div class="station-prov"><?= htmlspecialchars($s['provincia']) ?></div>
      <?php endif; ?>
    </div>
    <button class="btn-play" aria-label="Reproducir <?= htmlspecialchars($s['nombre']) ?>">▶</button>
  </div>
<?php endforeach; ?>
</div>

<!-- Player fijo -->
<div id="player-bar">
  <button id="btn-stop" title="Detener">✕</button>
  <div style="flex:1;min-width:0">
    <div id="player-title">—</div>
    <div id="player-prov"></div>
  </div>
  <a id="btn-vlc" style="display:none;font-size:12px;color:#93c5fd;white-space:nowrap;text-decoration:none;padding:4px 8px;border:1px solid #374151;border-radius:6px" target="_blank">▶ VLC</a>
  <audio id="audio-elem" controls preload="none"></audio>
</div>

<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
<script>
(function() {
  const audio       = document.getElementById('audio-elem');
  const playerBar   = document.getElementById('player-bar');
  const playerTitle = document.getElementById('player-title');
  const playerProv  = document.getElementById('player-prov');
  const buscador    = document.getElementById('buscador');
  const counter     = document.getElementById('result-count');
  const btnStop     = document.getElementById('btn-stop');
  const btnVlc      = document.getElementById('btn-vlc');
  const total       = <?= $total ?>;
  const isHttps     = location.protocol === 'https:';
  const PROXY       = '/radio/proxy.php?url=';
  const TIMEOUT_MS  = 12000;

  let activeEl    = null;
  let hlsInstance = null;
  let loadTimer   = null;

  // ── Determina la URL a reproducir ──────────────────────────────────────────
  function resolveUrl(raw) {
    if (/\.pls(\?|$)/i.test(raw)) return PROXY + encodeURIComponent(raw);
    if (/\.m3u(\?|$)/i.test(raw) && !/\.m3u8(\?|$)/i.test(raw)) return PROXY + encodeURIComponent(raw);
    // HTTP en HTTPS → auto-upgrade (muchos servidores AR soportan ambos)
    if (isHttps && raw.startsWith('http://')) return raw.replace('http://', 'https://');
    return raw;
  }

  function markEl(el, estado) {
    el.classList.remove('active', 'loading', 'error');
    if (estado) el.classList.add(estado);
    el.querySelector('.btn-play').textContent =
      estado === 'active'  ? '⏸' :
      estado === 'loading' ? '⏳' :
      estado === 'error'   ? '✕' : '▶';
  }

  document.getElementById('lista').addEventListener('click', function(e) {
    const el = e.target.closest('.station');
    if (!el) return;

    const rawUrl = el.dataset.url;
    const nombre = el.dataset.nombre;

    // Toggle pause
    if (activeEl === el && !audio.paused) {
      audio.pause();
      markEl(el, null);
      activeEl = null;
      return;
    }

    // Limpiar estado anterior (null ANTES de tocar audio para que error-handler lo ignore)
    clearTimeout(loadTimer);
    if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
    if (activeEl) { markEl(activeEl, null); }
    activeEl = null;

    // Pausar audio viejo
    audio.pause();

    // Activar nueva emisora
    activeEl = el;
    markEl(el, 'loading');
    playerTitle.textContent = nombre;
    playerProv.textContent  = el.dataset.prov || '';
    playerBar.classList.add('visible');

    btnVlc.style.display = 'none';

    // Timeout 12s
    loadTimer = setTimeout(function() {
      if (activeEl === el) {
        showError(el, rawUrl, nombre, 'sin señal (timeout)');
        audio.pause();
      }
    }, TIMEOUT_MS);

    const url   = resolveUrl(rawUrl);
    const isHls = /\.m3u8(\?|$)/i.test(rawUrl);

    if (isHls && typeof Hls !== 'undefined' && Hls.isSupported()) {
      hlsInstance = new Hls({ maxBufferLength: 20 });
      hlsInstance.loadSource(url);
      hlsInstance.attachMedia(audio);
      hlsInstance.on(Hls.Events.MANIFEST_PARSED, function() {
        audio.play()
          .then(function() { clearTimeout(loadTimer); if (activeEl === el) markEl(el, 'active'); })
          .catch(function() { clearTimeout(loadTimer); if (activeEl === el) showError(el, rawUrl, nombre, 'no disponible'); });
      });
      hlsInstance.on(Hls.Events.ERROR, function(_, d) {
        if (d.fatal && activeEl === el) { clearTimeout(loadTimer); showError(el, rawUrl, nombre, 'no disponible'); }
      });
    } else {
      audio.src = url;
      audio.play()
        .then(function() { clearTimeout(loadTimer); if (activeEl === el) markEl(el, 'active'); })
        .catch(function() { clearTimeout(loadTimer); if (activeEl === el) showError(el, rawUrl, nombre, 'no disponible en web'); });
    }
  });

  function showError(el, rawUrl, nombre, msg) {
    markEl(el, 'error');
    playerTitle.textContent = nombre + ' — ' + msg;
    btnVlc.href = 'vlc://' + rawUrl;
    btnVlc.style.display = 'inline-block';
  }

  btnStop.addEventListener('click', function() {
    clearTimeout(loadTimer);
    if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
    if (activeEl) { markEl(activeEl, null); activeEl = null; }
    audio.pause();
    audio.src = '';
    btnVlc.style.display = 'none';
    playerBar.classList.remove('visible');
  });

  audio.addEventListener('playing', function() {
    if (activeEl) { clearTimeout(loadTimer); markEl(activeEl, 'active'); btnVlc.style.display = 'none'; }
  });
  audio.addEventListener('error', function() {
    if (!activeEl) return;
    clearTimeout(loadTimer);
    showError(activeEl, activeEl.dataset.url, activeEl.dataset.nombre, 'no disponible en web');
  });
  audio.addEventListener('waiting', function() {
    if (activeEl && activeEl.classList.contains('active')) markEl(activeEl, 'loading');
  });

  // ── Filtros de estado ─────────────────────────────────────────────────────────
  var currentFilter = 'all';

  function applyFilters() {
    var q     = buscador.value.toLowerCase().trim();
    var items = document.querySelectorAll('.station');
    var vis   = 0;
    items.forEach(function(el) {
      var textMatch   = !q || el.dataset.search.includes(q);
      var statusMatch = currentFilter === 'all' || el.dataset.status === currentFilter;
      var show        = textMatch && statusMatch;
      el.classList.toggle('hidden', !show);
      if (show) vis++;
    });
    var base = currentFilter === 'all' ? total : document.querySelectorAll('.station[data-status="' + currentFilter + '"]').length;
    counter.textContent = (q || currentFilter !== 'all') ? vis + ' de ' + total + ' emisoras' : total + ' emisoras';
  }

  // ── Estado de streams (status.json generado por verificar_urls.sh) ──────────
  fetch('/radio/status.json')
    .then(function(r) { return r.ok ? r.json() : null; })
    .then(function(data) {
      if (!data || !data.streams) return;

      document.querySelectorAll('.station').forEach(function(el) {
        var s = data.streams[el.dataset.url];
        if (!s) return;
        el.dataset.status = s.estado;
        var dot = el.querySelector('.dot');
        if (!dot) return;
        dot.classList.add('dot-' + s.estado);
        dot.title = s.estado === 'ok'      ? '✓ activa (' + (s.ms || '?') + ' ms)' :
                    s.estado === 'timeout'  ? '⏱ sin respuesta' :
                    '✗ caída' + (s.codigo ? ' (HTTP ' + s.codigo + ')' : '');
      });

      // Mostrar línea de verificación
      var genEl = document.getElementById('status-gen');
      if (genEl) genEl.textContent = 'Verificado: ' + data.generado;

      // Construir botones de filtro
      var filtrosEl = document.getElementById('filtros');
      var btns = [
        { f: 'all',     cls: '',           label: 'Todas',       n: total },
        { f: 'ok',      cls: 'f-ok',       label: '✓ Activas',   n: data.ok },
        { f: 'timeout', cls: 'f-timeout',  label: '⏱ Dudosas',   n: data.timeout },
        { f: 'muerto',  cls: 'f-muerto',   label: '✗ Caídas',    n: data.muertos },
      ];
      btns.forEach(function(b) {
        var btn = document.createElement('button');
        btn.className = 'filter-btn ' + b.cls + (b.f === 'all' ? ' active' : '');
        btn.textContent = b.label + ' ' + b.n;
        btn.addEventListener('click', function() {
          currentFilter = b.f;
          document.querySelectorAll('.filter-btn').forEach(function(x) { x.classList.remove('active'); });
          btn.classList.add('active');
          applyFilters();
        });
        filtrosEl.appendChild(btn);
      });
      filtrosEl.classList.add('visible');
    })
    .catch(function() {}); // sin status.json todavía — silencioso

  // ── Buscador ─────────────────────────────────────────────────────────────────
  buscador.addEventListener('input', applyFilters);
})();
</script>
</body>
</html>
