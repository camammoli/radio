<?php
/**
 * listing.php — Directorio principal de emisoras.
 * Lee de v_stations (SQLite). Filtrado client-side en JS.
 */

require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/../api/_helpers.php';

$db = radio_db();

// ── Cargar emisoras ───────────────────────────────────────────────────────────

$stations = $db->query(
    "SELECT id, n, slug, nombre, url, provincia, tags, codec, bitrate,
            logo, estado, icy_supported, total_plays, rb_votes, last_checked
     FROM v_stations
     ORDER BY
       CASE estado WHEN 'ok' THEN 0 WHEN 'timeout' THEN 1 ELSE 2 END,
       total_plays DESC, rb_votes DESC, n ASC"
)->fetchAll();

$total = count($stations);

// ── Géneros (top 14 tags con ≥3 emisoras) ────────────────────────────────────

$tag_counts = [];
foreach ($stations as $s) {
    foreach ((json_decode($s['tags'] ?? '[]', true) ?: []) as $t) {
        $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1;
    }
}
arsort($tag_counts);
$genre_tags = array_keys(array_filter(array_slice($tag_counts, 0, 14, true), fn($c) => $c >= 3));

// ── Provincias con ≥4 emisoras ────────────────────────────────────────────────

$prov_counts = [];
foreach ($stations as $s) {
    if (!$s['provincia']) continue;
    $p = trim(explode(',', $s['provincia'])[0]);
    $prov_counts[$p] = ($prov_counts[$p] ?? 0) + 1;
}
arsort($prov_counts);
$province_list = array_keys(array_filter($prov_counts, fn($c) => $c >= 4));

// ── SEO por provincia ─────────────────────────────────────────────────────────

$filtro_prov_seo = trim($_GET['provincia'] ?? '');
if ($filtro_prov_seo !== '') {
    $page_title = 'Radios de ' . ucwords($filtro_prov_seo) . ' en Vivo Online Gratis | Radio Argentina';
    $page_desc  = 'Escuchá radios de ' . ucwords($filtro_prov_seo) . ' en vivo, gratis y sin instalar nada. '
                . 'Todas las emisoras de ' . ucwords($filtro_prov_seo) . ', Argentina.';
    $page_canon = 'https://mammoli.ar/radio/?provincia=' . urlencode($filtro_prov_seo);
} else {
    $page_title = 'Radio Argentina en Vivo — ' . $total . ' Emisoras Online Gratis';
    $page_desc  = 'Escuchá radio argentina en vivo, gratis y sin instalar nada. '
                . $total . ' emisoras de todo el país: FM, AM, noticias, rock, folklore, cumbia y más.';
    $page_canon = 'https://mammoli.ar/radio/';
}

// ItemList JSON-LD para Google
$ld_stations_top = array_filter($stations, fn($s) => $s['estado'] === 'ok');
$ld_list_items   = [];
$pos = 1;
foreach (array_slice(array_values($ld_stations_top), 0, 30) as $s) {
    $ld_list_items[] = [
        '@type'    => 'ListItem',
        'position' => $pos++,
        'url'      => 'https://mammoli.ar/radio/' . $s['slug'] . '/',
        'name'     => $s['nombre'],
    ];
}
$ld_itemlist = [
    '@context'       => 'https://schema.org',
    '@type'          => 'ItemList',
    'name'           => 'Radios Argentinas en Vivo',
    'description'    => 'Directorio de emisoras de radio de Argentina para escuchar en vivo por internet, gratis.',
    'numberOfItems'  => $total,
    'itemListElement' => $ld_list_items,
];
?>
<!DOCTYPE html>
<html lang="es">
<?php require __DIR__ . '/../components/head.php'; ?>
<body>
<script>
  if (localStorage.getItem('radio_theme') === 'light') document.body.classList.add('light');
</script>
<script type="application/ld+json"><?= json_encode($ld_itemlist, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>

<!-- Header -->
<header class="site-header">
  <h1>📻 Radio Argentina</h1>
  <p class="sub">
    <?= $total ?> emisoras en streaming · escuchá sin instalar nada
    <?php if ($total < 1500): ?> · <a href="/radio/sugerir.php" style="color:#f59e0b;text-decoration:none">ayudanos a llegar a 1500 →</a><?php endif; ?>
  </p>
  <div class="badges">
    <a  class="badge" href="/radio/api/playlist.m3u">⬇ Bajar M3U</a>
    <a  class="badge" href="/radio/sugerir.php">+ Sugerir emisora</a>
    <a  class="badge" href="/radio/estadisticas.php">📊 Estadísticas</a>
    <a  class="badge" href="https://github.com/camammoli/radio" target="_blank" rel="noopener">GitHub</a>
    <a  class="badge badge-cafe" href="https://cafecito.app/mammoli" rel="noopener" target="_blank">☕ Café</a>
    <button id="theme-btn" class="badge">☀️ Modo claro</button>
  </div>
</header>

<!-- Buscador y filtros -->
<div class="search-wrap">
  <input id="buscador" type="search" placeholder="Buscar por nombre, provincia o género..." autocomplete="off" autofocus>
  <div class="filtros" id="filtros"></div>
  <div id="genre-panel"></div>
  <div id="province-panel"></div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
    <div id="listeners-badge" style="font-size:11px;color:#22c55e;display:none">
      ● <span id="listeners-count"></span> escuchando
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <div class="result-count" id="result-count"><?= $total ?> emisoras</div>
    </div>
  </div>
  <div id="no-results-hint" style="display:none;font-size:13px;color:#9ca3af;padding:6px 0;text-align:right">
    ¿No encontrás tu radio? <a href="/radio/sugerir.php" style="color:#3b82f6">Sugerila →</a>
  </div>
</div>

<!-- Lista de emisoras -->
<div class="lista" id="lista">
<?php foreach ($stations as $s):
    $tags  = json_decode($s['tags'] ?? '[]', true) ?: [];
    $prov1 = $s['provincia'] ? trim(explode(',', $s['provincia'])[0]) : '';
    $codec = trim(($s['codec'] ?? '') . ($s['bitrate'] ? ' ' . $s['bitrate'] . 'k' : ''));
    $dot   = $s['estado'] === 'ok' ? 'dot-ok' : ($s['estado'] === 'muerto' ? 'dot-muerto' : 'dot-timeout');
?>
<div class="station"
     data-slug="<?= htmlspecialchars($s['slug']) ?>"
     data-url="<?= htmlspecialchars($s['url']) ?>"
     data-nombre="<?= htmlspecialchars($s['nombre']) ?>"
     data-n="<?= (int)$s['n'] ?>"
     data-prov="<?= htmlspecialchars($prov1) ?>"
     data-logo="<?= htmlspecialchars($s['logo'] ?? '') ?>"
     data-estado="<?= htmlspecialchars($s['estado']) ?>"
     data-tags="<?= htmlspecialchars(implode(' ', $tags)) ?>"
     data-plays="<?= (int)$s['total_plays'] ?>"
     data-icy="<?= $s['icy_supported'] ? '1' : '0' ?>">

  <span class="dot <?= $dot ?>" title="<?= $s['estado'] ?>"></span>

  <?php if ($s['logo']): ?>
  <img class="station-logo" src="<?= htmlspecialchars($s['logo']) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
  <?php endif; ?>

  <div class="station-info">
    <div class="station-name">
      <?= htmlspecialchars($s['nombre']) ?>
      <a class="station-pg-link"
         href="/radio/<?= htmlspecialchars($s['slug']) ?>/"
         title="Página de <?= htmlspecialchars($s['nombre']) ?>"
         onclick="event.stopPropagation()">⎋</a>
    </div>
    <?php if ($prov1): ?>
    <div class="station-prov"><?= htmlspecialchars($prov1) ?></div>
    <?php endif; ?>
    <?php if ($tags): ?>
    <div class="station-tags">
      <?php foreach (array_slice($tags, 0, 3) as $t): ?>
      <span class="station-tag"><?= htmlspecialchars($t) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($s['last_checked']): ?>
    <div style="font-size:10px;color:var(--muted);margin-top:3px">✓ <?= htmlspecialchars(substr(str_replace('T', ' ', $s['last_checked']), 0, 16)) ?></div>
    <?php endif; ?>
  </div>

  <?php if ($s['icy_supported']): ?>
  <span class="icy-badge" title="Esta emisora muestra la canción que está sonando">♪</span>
  <?php endif; ?>

  <?php if ($codec): ?>
  <span class="codec-badge"><?= htmlspecialchars($codec) ?></span>
  <?php endif; ?>

  <button class="btn-play" aria-label="Reproducir <?= htmlspecialchars($s['nombre']) ?>">▶</button>
</div>
<?php endforeach; ?>
</div><!-- /#lista -->

<!-- Player bar (sticky bottom) -->
<div id="player-bar">
  <button id="btn-stop" aria-label="Detener">✕</button>
  <div>
    <div id="player-title">—</div>
    <div id="player-prov"></div>
    <div id="player-np"></div>
  </div>
  <div id="share-row">
    <button class="share-btn" id="btn-copy">🔗 Link</button>
    <a class="share-btn" id="btn-wa" href="#" target="_blank" rel="noopener">💬 WhatsApp</a>
    <button class="share-btn" id="btn-qr">⬛ QR</button>
    <a id="btn-vlc" href="#" class="rp-vlc">📡 VLC</a>
  </div>
  <div id="bar-vol" style="display:flex;align-items:center;gap:6px;flex-shrink:0">
    <span id="bar-vol-icon" style="font-size:13px">🔊</span>
    <input type="range" id="bar-vol-slider" min="0" max="1" step="0.05" value="1"
           style="width:80px;cursor:pointer;accent-color:#3b82f6">
  </div>
</div>

<!-- QR modal -->
<div id="qr-modal">
  <div id="qr-box">
    <img id="qr-img" src="" alt="QR">
    <div id="qr-name"></div>
    <div id="qr-url"></div>
    <button id="qr-close">Cerrar</button>
  </div>
</div>

<!-- Banner "emisora compartida" (?n= o ?station= directo) -->
<div id="shared-banner" style="display:none">
  <span id="shared-name"></span> — ¡empezá a escuchar!
  <button onclick="document.getElementById('shared-banner').classList.add('hide')">✕</button>
</div>

<!-- Toast cafecito -->
<div id="support-toast" style="display:none">
  ¿Te gusta Radio Argentina? <a href="https://cafecito.app/mammoli" target="_blank" rel="noopener">☕ Invitame un café</a>
  <button onclick="this.closest('#support-toast').classList.add('hide')">✕</button>
</div>

<?php $__base = defined('RADIO_BASE') ? RADIO_BASE : '/radio'; ?>
<script src="<?= $__base ?>/assets/theme.js"></script>
<script src="<?= $__base ?>/assets/player.js"></script>
<script>
(function () {
'use strict';

// ── Tema ──────────────────────────────────────────────────────────────────────
RadioTheme.init(document.getElementById('theme-btn'));

// ── Refs UI ───────────────────────────────────────────────────────────────────
var lista       = document.getElementById('lista');
var playerBar   = document.getElementById('player-bar');
var playerTitle = document.getElementById('player-title');
var playerProv  = document.getElementById('player-prov');
var playerNp    = document.getElementById('player-np');
var btnStop     = document.getElementById('btn-stop');
var btnVlc      = document.getElementById('btn-vlc');
var shareRow    = document.getElementById('share-row');
var btnCopy     = document.getElementById('btn-copy');
var btnWa       = document.getElementById('btn-wa');
var btnQr       = document.getElementById('btn-qr');
var qrModal     = document.getElementById('qr-modal');
var lBadge      = document.getElementById('listeners-badge');
var lCount      = document.getElementById('listeners-count');
var counter     = document.getElementById('result-count');
var buscador    = document.getElementById('buscador');
var noResults   = document.getElementById('no-results-hint');

// ── Elemento activo en el listado ─────────────────────────────────────────────
var activeEl = null;

function markEl(el, css) {
  if (activeEl && activeEl !== el) {
    activeEl.classList.remove('rp-active', 'rp-loading', 'rp-error');
    activeEl.querySelector('.btn-play').textContent = '▶';
  }
  if (!el) { activeEl = null; return; }
  el.classList.remove('rp-active', 'rp-loading', 'rp-error');
  if (css) el.classList.add(css);
  var icon = { 'rp-active': '⏸', 'rp-loading': '⏳', 'rp-error': '✕' };
  el.querySelector('.btn-play').textContent = icon[css] || '▶';
  activeEl = css ? el : null;
}

// ── Iniciar emisora (compartido por click y hotkeys) ──────────────────────────
function playStation(el) {
  if (!el) return;
  var slug   = el.dataset.slug;
  var url    = el.dataset.url;
  var nombre = el.dataset.nombre;
  var prov   = el.dataset.prov;
  var logo   = el.dataset.logo || '';
  markEl(el, 'rp-loading');
  playerTitle.textContent = nombre;
  playerProv.textContent  = prov || '';
  playerNp.textContent    = '';
  playerBar.classList.add('visible');
  btnVlc.style.display = 'none';
  updateShare(slug, nombre);
  player.setStation(slug, url, nombre, logo);
}

// ── Player ────────────────────────────────────────────────────────────────────
var player = RadioPlayer({
  slug:   '',
  url:    '',
  nombre: '',
  source: 'web-listing',

  onState: function (state) {
    if (!activeEl) return;
    if (state === 'playing')    markEl(activeEl, 'rp-active');
    else if (state === 'connecting' || state === 'buffering') markEl(activeEl, 'rp-loading');
    else if (state === 'error') markEl(activeEl, 'rp-error');
    else if (state === 'idle' || state === 'stopped') markEl(null);
  },

  onNowPlaying: function (title) {
    playerNp.textContent = title ? '♪ en el aire — ' + title : '';
    if (activeEl) {
      var icyEl = activeEl.querySelector('.station-icy-passive');
      if (title) {
        if (!icyEl) {
          icyEl = document.createElement('div');
          icyEl.className = 'station-icy-passive';
          var info = activeEl.querySelector('.station-info');
          if (info) info.appendChild(icyEl);
        }
        icyEl.textContent = '♪ en el aire — ' + title;
      } else if (icyEl) {
        icyEl.textContent = '';
      }
    }
  },

  onListeners: function (total) {
    if (total > 0) {
      lCount.textContent  = total === 1 ? '1 persona' : total + ' personas';
      lBadge.style.display = '';
    } else {
      lBadge.style.display = 'none';
    }
  },

  onError: function (rawUrl) {
    if (activeEl) markEl(activeEl, 'rp-error');
    playerTitle.textContent += ' — no disponible';
    btnVlc.href = 'vlc://' + rawUrl;
    btnVlc.style.display = 'inline';
  },

  onNextTrack: function () {
    var visible = Array.from(lista.querySelectorAll('.station:not(.hidden)'));
    var idx = visible.indexOf(activeEl);
    if (idx >= 0 && idx < visible.length - 1) playStation(visible[idx + 1]);
  },

  onPrevTrack: function () {
    var visible = Array.from(lista.querySelectorAll('.station:not(.hidden)'));
    var idx = visible.indexOf(activeEl);
    if (idx > 0) playStation(visible[idx - 1]);
  },
});

// Volumen en la barra del player
var barVolSlider = document.getElementById('bar-vol-slider');
var barVolIcon   = document.getElementById('bar-vol-icon');
var barAudio     = player.getAudio();
barVolSlider.addEventListener('input', function () {
  var v = parseFloat(barVolSlider.value);
  barAudio.volume = v;
  barVolIcon.textContent = v === 0 ? '🔇' : v < 0.5 ? '🔉' : '🔊';
});

// ── Click en emisora ──────────────────────────────────────────────────────────
lista.addEventListener('click', function (e) {
  var el = e.target.closest('.station');
  if (!el) return;
  if (e.target.closest('.station-pg-link')) return; // link ⎋ → no reproducir

  // Toggle pausa si es la misma emisora
  if (activeEl === el && player.getState() === 'playing') {
    player.stop();
    playerBar.classList.remove('visible');
    shareRow.classList.remove('visible');
    btnVlc.style.display = 'none';
    return;
  }

  playStation(el);
});

// ── Detener ───────────────────────────────────────────────────────────────────
btnStop.addEventListener('click', function () {
  player.stop();
  markEl(null);
  playerBar.classList.remove('visible');
  shareRow.classList.remove('visible');
  btnVlc.style.display = 'none';
});

// ── Compartir ─────────────────────────────────────────────────────────────────
function stationUrl(slug) { return 'https://mammoli.ar/radio/' + slug + '/'; }

function pingShare(slug, channel) {
  fetch('/radio/api/share?slug=' + encodeURIComponent(slug) + '&channel=' + channel)
    .catch(function(){});
}

function updateShare(slug, nombre) {
  shareRow.classList.add('visible');
  var url = stationUrl(slug);

  btnCopy.onclick = function () {
    navigator.clipboard.writeText(url).then(function () {
      btnCopy.textContent = '✓ Copiado';
      btnCopy.classList.add('copied');
      setTimeout(function () { btnCopy.textContent = '🔗 Link'; btnCopy.classList.remove('copied'); }, 2000);
      pingShare(slug, 'copy');
    });
  };

  btnWa.href = 'https://wa.me/?text=' + encodeURIComponent('📻 Estoy escuchando ' + nombre + '\n👉 ' + url);
  btnWa.onclick = function () { pingShare(slug, 'wa'); };

  btnQr.onclick = function () {
    document.getElementById('qr-img').src  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url);
    document.getElementById('qr-name').textContent = nombre;
    document.getElementById('qr-url').textContent  = url;
    qrModal.classList.add('visible');
    pingShare(slug, 'qr');
  };
}

document.getElementById('qr-close').addEventListener('click', function () { qrModal.classList.remove('visible'); });
qrModal.addEventListener('click', function (e) { if (e.target === qrModal) qrModal.classList.remove('visible'); });

// ── Toast cafecito ────────────────────────────────────────────────────────────
(function () {
  var key = 'cafecito_shown';
  if (localStorage.getItem(key)) return;
  setTimeout(function () {
    var t = document.getElementById('support-toast');
    if (!t) return;
    t.style.display = 'block';
    localStorage.setItem(key, '1');
  }, 20000);
}());

// ── Filtros y búsqueda ────────────────────────────────────────────────────────
var genreTags    = <?= json_encode(array_values($genre_tags)) ?>;
var provinceList = <?= json_encode(array_values($province_list)) ?>;
var urlParams    = new URLSearchParams(location.search);
var filterStatus = 'all';
var filterGenre  = urlParams.get('genero')   ? urlParams.get('genero').toLowerCase()   : null;
var filterProv   = urlParams.get('provincia') ? urlParams.get('provincia').toLowerCase() : null;
var filterTop    = false;
var total        = <?= $total ?>;

// Construir filtros de estado
var filtrosEl = document.getElementById('filtros');
filtrosEl.className = 'filtros visible';
[
  { id:'f-all',     label:'Todas ('     + total + ')',   cls:'',         val:'all'     },
  { id:'f-ok',      label:'✓ En línea', cls:'f-ok',      val:'ok'       },
  { id:'f-timeout', label:'~ Inestables',cls:'f-timeout', val:'timeout' },
  { id:'f-muerto',  label:'✕ Caídas',   cls:'f-muerto',  val:'muerto'  },
  { id:'f-top',     label:'★ Más escuchadas', cls:'f-top', val:'top'   },
].forEach(function (f) {
  var btn = document.createElement('button');
  btn.id        = f.id;
  btn.className = 'filter-btn ' + f.cls + (f.val === 'all' ? ' active' : '');
  btn.textContent = f.label;
  btn.addEventListener('click', function () {
    filterTop    = (f.val === 'top');
    filterStatus = filterTop ? 'all' : f.val;
    document.querySelectorAll('.filter-btn:not(.f-cat):not(.f-genre):not(.f-prov):not(.f-provcat)').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    applyFilters();
  });
  filtrosEl.appendChild(btn);
});

// Botón Categorías
var catBtn = document.createElement('button');
catBtn.className   = 'filter-btn f-cat';
catBtn.textContent = 'Categorías ▾';
catBtn.addEventListener('click', function () {
  document.getElementById('genre-panel').classList.toggle('open');
  document.getElementById('province-panel').classList.remove('open');
});
filtrosEl.appendChild(catBtn);

// Panel de géneros
var genrePanel = document.getElementById('genre-panel');
genreTags.forEach(function (tag) {
  var btn = document.createElement('button');
  btn.className   = 'filter-btn f-genre';
  btn.textContent = tag;
  btn.addEventListener('click', function () {
    filterGenre = filterGenre === tag.toLowerCase() ? null : tag.toLowerCase();
    document.querySelectorAll('.f-genre').forEach(function (b) { b.classList.remove('active'); });
    if (filterGenre) { btn.classList.add('active'); catBtn.classList.add('has-genre'); }
    else catBtn.classList.remove('has-genre');
    applyFilters();
  });
  if (filterGenre === tag.toLowerCase()) { btn.classList.add('active'); catBtn.classList.add('has-genre'); }
  genrePanel.appendChild(btn);
});

// Botón Provincias
var provCatBtn = document.createElement('button');
provCatBtn.className   = 'filter-btn f-provcat';
provCatBtn.textContent = 'Provincias ▾';
provCatBtn.addEventListener('click', function () {
  document.getElementById('province-panel').classList.toggle('open');
  document.getElementById('genre-panel').classList.remove('open');
});
filtrosEl.appendChild(provCatBtn);

// Panel de provincias
var provPanel = document.getElementById('province-panel');
provinceList.forEach(function (prov) {
  var btn = document.createElement('button');
  btn.className   = 'filter-btn f-prov';
  btn.textContent = prov;
  btn.addEventListener('click', function () {
    filterProv = filterProv === prov.toLowerCase() ? null : prov.toLowerCase();
    document.querySelectorAll('.f-prov').forEach(function (b) { b.classList.remove('active'); });
    if (filterProv) { btn.classList.add('active'); provCatBtn.classList.add('has-prov'); }
    else provCatBtn.classList.remove('has-prov');
    applyFilters();
  });
  if (filterProv && prov.toLowerCase() === filterProv) {
    btn.classList.add('active'); provCatBtn.classList.add('has-prov');
  }
  provPanel.appendChild(btn);
});

// ── Aplicar filtros ───────────────────────────────────────────────────────────
function applyFilters() {
  var q       = buscador.value.trim().toLowerCase();
  var cards   = lista.querySelectorAll('.station');
  var visible = 0;
  var sorted  = filterTop ? Array.from(cards).sort(function (a, b) {
    return parseInt(b.dataset.plays, 10) - parseInt(a.dataset.plays, 10);
  }) : null;

  if (sorted) {
    sorted.forEach(function (c) { lista.appendChild(c); });
    cards = lista.querySelectorAll('.station');
  }

  cards.forEach(function (el) {
    var matchQ  = !q || el.dataset.nombre.toLowerCase().includes(q)
                     || (el.dataset.prov  || '').toLowerCase().includes(q)
                     || (el.dataset.tags  || '').toLowerCase().includes(q);
    var matchS  = filterStatus === 'all' || el.dataset.estado === filterStatus;
    var matchG  = !filterGenre || (el.dataset.tags || '').toLowerCase().includes(filterGenre);
    var matchP  = !filterProv  || (el.dataset.prov || '').toLowerCase().includes(filterProv);
    var show    = matchQ && matchS && matchG && matchP;
    el.classList.toggle('hidden', !show);
    if (show) visible++;
  });

  counter.textContent = visible === total ? total + ' emisoras' : visible + ' de ' + total;
  noResults.style.display = visible === 0 ? '' : 'none';
}

buscador.addEventListener('input', applyFilters);
if (filterGenre || filterProv) applyFilters();

// ── ICY titles pasivos ────────────────────────────────────────────────────────
fetch('/radio/api/nowplaying?batch=1')
  .then(function (r) { return r.ok ? r.json() : null; })
  .then(function (d) {
    if (!d || !d.ok) return;
    var titles = d.data;
    Object.keys(titles).forEach(function (slug) {
      var card = lista.querySelector('.station[data-slug="' + slug + '"]');
      if (!card || !titles[slug]) return;
      var info = card.querySelector('.station-info');
      if (!info) return;
      var el = document.createElement('div');
      el.className = 'station-icy-passive';
      el.textContent = '♪ en el aire — ' + titles[slug];
      info.appendChild(el);
    });
  }).catch(function () {});

// ── Destacar emisora compartida (desde ?n=) ───────────────────────────────────
// El router ya resuelve ?n= → redirect al slug, así que aquí solo manejamos
// el banner si llegamos desde esa redirección con ?shared=1
(function () {
  var p = new URLSearchParams(location.search);
  if (!p.has('shared')) return;
  // Scroll y banner serían opcionales — se puede agregar después
}());

// ── Service Worker ────────────────────────────────────────────────────────────
if ('serviceWorker' in navigator) navigator.serviceWorker.register(<?= json_encode($__base . '/sw.js') ?>).catch(function(){});

}());
</script>
<?php require __DIR__ . '/../components/privacy.php'; ?>
</body>
</html>
