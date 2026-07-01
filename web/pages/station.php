<?php
/**
 * station.php — Página individual de emisora.
 * Contexto: $req (slug), config.php ya incluido por el router.
 */

require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/../api/_helpers.php';

$db   = radio_db();
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($req)));

$stmt = $db->prepare('SELECT * FROM v_stations WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$s = $stmt->fetch();

// 404 si no existe
if (!$s) {
    http_response_code(404);
    $page_title = 'Emisora no encontrada | Radio Argentina';
    $page_desc  = '';
    $page_canon = 'https://mammoli.ar/radio/';
    require __DIR__ . '/../components/head.php';
    echo '<body><div style="text-align:center;padding:60px 20px">';
    echo '<h1 style="font-size:20px;color:#f9fafb">Emisora no encontrada</h1>';
    echo '<p style="color:#9ca3af;margin:12px 0"><a href="/radio/" style="color:#3b82f6">← Volver al directorio</a></p>';
    echo '</div></body></html>';
    exit;
}

// ── Datos ─────────────────────────────────────────────────────────────────────

$tags    = json_decode($s['tags'] ?? '[]', true) ?: [];
$prov    = $s['provincia'] ? trim(explode(',', $s['provincia'])[0]) : '';
$codec_s = trim(($s['codec'] ?? '') . ' ' . ($s['bitrate'] ? $s['bitrate'] . 'kbps' : ''));
$pg_url  = 'https://mammoli.ar/radio/' . $slug . '/';

// ── SEO ───────────────────────────────────────────────────────────────────────

$tag_s      = $tags ? implode(', ', array_slice($tags, 0, 3)) : '';
$page_title = $s['nombre'] . ' en Vivo Online Gratis | Radio Argentina';
$page_desc  = '▶ Escuchá ' . $s['nombre'] . ' en vivo online, gratis y sin instalar nada.'
            . ($prov  ? ' Emisora de ' . $prov . ', Argentina.' : ' Emisora argentina.')
            . ($tag_s ? ' ' . ucfirst($tag_s) . '.' : '');
$page_canon    = $pg_url;
$page_og_image = $s['logo'] ?: '';
$page_og_audio = $s['url'];

// ── Schema JSON-LD ────────────────────────────────────────────────────────────

$ld_station = ['@context'=>'https://schema.org','@type'=>'RadioStation',
    'name'=>$s['nombre'],'url'=>$pg_url,'inLanguage'=>'es-AR'];
if ($prov)        $ld_station['areaServed']  = $s['provincia'];
if ($s['logo'])   $ld_station['logo']        = $s['logo'];
if ($s['homepage']) $ld_station['sameAs']    = $s['homepage'];

$ld_svc = ['@context'=>'https://schema.org','@type'=>'RadioBroadcastService',
    'name'=>$s['nombre'],'broadcastDisplayName'=>$s['nombre'],'inLanguage'=>'es-AR',
    'potentialAction'=>['@type'=>'ListenAction','target'=>$s['url']]];
if ($prov) $ld_svc['areaServed'] = $s['provincia'];
if (preg_match('/(\d+\.?\d*)\s*(FM|AM)/i', $s['nombre'], $m)
 || preg_match('/(FM|AM)\s*(\d+\.?\d*)/i', $s['nombre'], $m2)) {
    $freq = isset($m[1]) ? $m[1] . ' ' . strtoupper($m[2]) : $m2[2] . ' ' . strtoupper($m2[1]);
    $ld_svc['broadcastFrequency'] = $freq;
}

$ld_breadcrumb = ['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[
    ['@type'=>'ListItem','position'=>1,'name'=>'Radio Argentina','item'=>'https://mammoli.ar/radio/'],
    ['@type'=>'ListItem','position'=>2,'name'=>$s['nombre'],'item'=>$pg_url],
]];

// ── Relacionadas ──────────────────────────────────────────────────────────────

$related = [];
if ($prov) {
    $rel = $db->prepare(
        "SELECT slug, nombre, estado, icy_supported
         FROM v_stations
         WHERE provincia LIKE ? AND slug != ? AND estado != 'muerto'
         ORDER BY total_plays DESC, rb_votes DESC LIMIT 6"
    );
    $rel->execute(['%' . $prov . '%', $slug]);
    $related = $rel->fetchAll();
}

// ── HTML ──────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<?php require __DIR__ . '/../components/head.php'; ?>
<body>
<script>
  // Aplicar tema guardado antes del primer paint
  if (localStorage.getItem('radio_theme') === 'light') document.body.classList.add('light');
</script>

<!-- Schema JSON-LD -->
<script type="application/ld+json"><?= json_encode($ld_station,    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json"><?= json_encode($ld_svc,        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json"><?= json_encode($ld_breadcrumb, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>

<!-- Header -->
<header class="site-header">
  <div class="site-brand">📻 Radio Argentina</div>
  <div class="site-sub"><?= htmlspecialchars($s['nombre']) ?></div>
  <div class="badges">
    <a class="badge" href="/radio/">← Todas las emisoras</a>
    <a class="badge" href="/radio/sugerir.php">+ Sugerir emisora</a>
    <a class="badge badge-cafe" href="https://cafecito.app/mammoli" rel="noopener" target="_blank">☕ Café</a>
    <button id="theme-btn" class="badge">☀️ Modo claro</button>
  </div>
</header>

<!-- Contenido -->
<div class="station-page">

  <?php if ($s['logo']): ?>
  <img src="<?= htmlspecialchars($s['logo']) ?>" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;margin-bottom:16px" onerror="this.style.display='none'">
  <?php endif; ?>

  <h1><?= htmlspecialchars($s['nombre']) ?></h1>

  <?php if ($prov): ?>
  <div class="prov">📍 <?= htmlspecialchars($s['provincia']) ?></div>
  <?php endif; ?>

  <?php if ($tags): ?>
  <div class="tags">
    <?php foreach ($tags as $t): ?><span class="tag"><?= htmlspecialchars($t) ?></span><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
  $desc_parraf = $s['nombre'] . ' es una emisora de radio';
  if ($prov)   $desc_parraf .= ' de ' . $prov;
  $desc_parraf .= ', Argentina.';
  if ($tags)   $desc_parraf .= ' Transmite ' . strtolower($tags[0]) . (count($tags) > 1 ? ' y más géneros' : '') . '.';
  if ($codec_s) $desc_parraf .= ' Disponible en ' . $codec_s . '.';
  $desc_parraf .= ' Escuchala gratis desde tu navegador, sin descargar ninguna app.';
  ?>
  <p style="color:var(--muted);font-size:13px;line-height:1.6;margin:0 0 18px;text-align:left"><?= htmlspecialchars($desc_parraf) ?></p>

  <!-- Player -->
  <div class="player-wrap">
    <button id="btn-play" class="rp-btn">▶ Escuchar en vivo</button>
    <div class="st-status" id="st-status"></div>
    <div class="st-np"     id="st-np"></div>
    <div class="st-listeners" id="st-listeners"></div>
    <div id="volume-ctrl" style="display:none;margin-top:14px;align-items:center;gap:8px;justify-content:center">
      <span style="font-size:13px" id="vol-icon">🔊</span>
      <input type="range" id="volume-slider" min="0" max="1" step="0.05" value="1"
             style="width:120px;cursor:pointer;accent-color:var(--accent)">
    </div>
  </div>

  <!-- Info técnica -->
  <div style="text-align:left;margin-bottom:20px">
    <?php if ($codec_s): ?>
    <div class="info-row"><span class="info-lbl">Formato</span><span class="info-val"><?= htmlspecialchars($codec_s) ?></span></div>
    <?php endif; ?>
    <div class="info-row">
      <span class="info-lbl">Estado</span>
      <span class="info-val" style="color:<?= $s['estado']==='ok' ? '#22c55e' : ($s['estado']==='muerto' ? '#ef4444' : '#f59e0b') ?>">
        <?= $s['estado'] === 'ok' ? '● En línea' : ($s['estado'] === 'muerto' ? '● Caída' : '● Inestable') ?>
      </span>
    </div>
    <?php if ($s['icy_supported']): ?>
    <div class="info-row"><span class="info-lbl">Metadata</span><span class="info-val"><span class="icy-badge">♪</span> muestra canción en tiempo real</span></div>
    <?php endif; ?>
    <?php if ($s['last_checked']): ?>
    <div class="info-row"><span class="info-lbl">Verificado</span><span class="info-val" style="color:var(--muted)"><?= htmlspecialchars(str_replace('T', ' ', substr($s['last_checked'], 0, 19))) ?></span></div>
    <?php endif; ?>
  </div>

  <!-- Compartir -->
  <div class="share-row-st">
    <button class="sbtn" id="sbtn-copy">🔗 Copiar link</button>
    <a class="sbtn" id="sbtn-wa" href="https://wa.me/?text=<?= urlencode('📻 Escuchá ' . $s['nombre'] . ' en vivo 👉 ' . $pg_url) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
    <button class="sbtn" id="sbtn-qr">⬛ QR</button>
  </div>

  <?php if ($s['homepage']): ?>
  <div style="margin-bottom:8px;font-size:11px;color:var(--muted)">
    <a href="<?= htmlspecialchars($s['homepage']) ?>" target="_blank" rel="noopener" style="color:var(--muted)">sitio oficial de la emisora ↗</a>
  </div>
  <?php endif; ?>

  <!-- Reportar caída -->
  <?php
  $reportado = isset($_GET['reportado']);
  if (!$reportado): ?>
  <div style="margin-top:8px">
    <a href="?station=<?= $slug ?>&reportar=1" style="font-size:12px;color:var(--muted);text-decoration:underline">
      ¿La señal está caída? Reportar →
    </a>
  </div>
  <?php elseif (isset($_GET['reportar'])): ?>
  <?php
    // Notificar a Telegram y redirigir
    if (notify_active($db) && defined('TG_TOKEN') && TG_TOKEN) {
        $msg = '⚠️ Reporte de caída: ' . $s['nombre'] . "\n" . $s['url'];
        $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,
            CURLOPT_POSTFIELDS=>['chat_id'=>TG_CHAT_ID,'text'=>$msg]]);
        curl_exec($ch); curl_close($ch);
    }
    header('Location: /radio/' . $slug . '/?reportado=1');
    exit;
  ?>
  <?php else: ?>
  <div style="margin-top:8px;font-size:12px;color:var(--green)">✓ Reporte enviado, gracias.</div>
  <?php endif; ?>

  <!-- Otras radios de la provincia -->
  <?php if ($related && $prov): ?>
  <div class="rel-section">
    <h2>Otras radios de <?= htmlspecialchars($prov) ?></h2>
    <?php foreach ($related as $r): ?>
    <a class="rel-item" href="/radio/<?= htmlspecialchars($r['slug']) ?>/">
      <span class="rel-dot <?= $r['estado']==='ok' ? 'ok' : '' ?>"></span>
      <span style="flex:1;font-size:13px"><?= htmlspecialchars($r['nombre']) ?></span>
      <?php if ($r['icy_supported']): ?><span class="rel-icy">♪</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php if ($prov): ?>
    <div style="margin-top:10px;font-size:12px;color:var(--muted)">
      <a href="/radio/sugerir.php?provincia=<?= urlencode($s['provincia']) ?>" style="color:var(--accent)">¿Conocés otra radio de <?= htmlspecialchars($prov) ?>? Sugerila →</a>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- CTA directorio -->
  <div style="margin-top:32px;padding-top:24px;border-top:1px solid var(--border);text-align:center">
    <a href="/radio/" style="display:inline-block;padding:14px 28px;background:var(--accent);color:#fff;border-radius:10px;font-size:15px;font-weight:600;text-decoration:none;letter-spacing:.01em">
      Explorá las 1200+ emisoras argentinas
    </a>
  </div>

</div><!-- /.station-page -->

<!-- QR modal -->
<div id="qr-modal-st">
  <div id="qr-box-st">
    <img id="qr-img-st" src="" alt="QR">
    <div class="qr-name"><?= htmlspecialchars($s['nombre']) ?></div>
    <div class="qr-url"><?= htmlspecialchars($pg_url) ?></div>
    <button id="qr-close-st">Cerrar</button>
  </div>
</div>

<?php $__base = defined('RADIO_BASE') ? RADIO_BASE : '/radio'; ?>
<script src="<?= $__base ?>/assets/theme.js"></script>
<script src="<?= $__base ?>/assets/player.js"></script>
<script>
(function () {
  // Tema
  RadioTheme.init(document.getElementById('theme-btn'));

  // Elementos de UI
  var btnPlay   = document.getElementById('btn-play');
  var stStatus  = document.getElementById('st-status');
  var stNp      = document.getElementById('st-np');
  var stList    = document.getElementById('st-listeners');

  // Player
  var p = RadioPlayer({
    slug:   <?= json_encode($slug) ?>,
    url:    <?= json_encode($s['url']) ?>,
    nombre: <?= json_encode($s['nombre']) ?>,
    logo:   <?= json_encode($s['logo'] ?? '') ?>,
    source: 'web-station',

    onState: function (state) {
      var labels = {
        idle:       '▶ Escuchar en vivo',
        connecting: '⏳ Conectando...',
        playing:    '⏸ Detener',
        buffering:  '⏳ Buffering...',
        stopped:    '▶ Escuchar en vivo',
        error:      '▶ Reintentar',
      };
      btnPlay.textContent = labels[state] || labels.idle;
      btnPlay.className   = 'rp-btn rp-btn--' + state;
      stStatus.innerHTML  = state === 'playing'
        ? '<span class="st-live">● En vivo</span>'
        : state === 'error'
          ? 'La señal se cortó. Intentá de nuevo.'
          : '';
      var showVol = (state === 'playing' || state === 'buffering');
      document.getElementById('volume-ctrl').style.display = showVol ? 'flex' : 'none';
    },

    onNowPlaying: function (title) {
      stNp.innerHTML = '';
      if (!title) return;
      var dot = document.createElement('span');
      dot.className = 'np-dot';
      var txt = document.createTextNode(' en el aire — ' + title);
      stNp.appendChild(dot);
      stNp.appendChild(txt);
    },

    onListeners: function (total, stationCount) {
      var n = stationCount > 0 ? stationCount : total;
      stList.textContent = n > 0
        ? (n === 1 ? '1 persona escuchando ahora' : n + ' personas escuchando ahora')
        : '';
    },

    onError: function (rawUrl) {
      // VLC fallback
      var vlc = document.getElementById('vlc-link');
      if (!vlc) {
        vlc = document.createElement('a');
        vlc.id = 'vlc-link';
        vlc.className = 'rp-vlc';
        vlc.textContent = '📡 Abrir en VLC';
        document.querySelector('.player-wrap').appendChild(vlc);
      }
      vlc.href = 'vlc://' + rawUrl;
      vlc.style.display = 'inline-block';
    },
  });

  btnPlay.addEventListener('click', function () { p.toggle(); });

  // Volumen
  var volCtrl   = document.getElementById('volume-ctrl');
  var volSlider = document.getElementById('volume-slider');
  var volIcon   = document.getElementById('vol-icon');
  var audioEl   = p.getAudio();

  volSlider.addEventListener('input', function () {
    var v = parseFloat(volSlider.value);
    audioEl.volume = v;
    volIcon.textContent = v === 0 ? '🔇' : v < 0.5 ? '🔉' : '🔊';
  });

  // Compartir
  var pgUrl  = <?= json_encode($pg_url) ?>;
  var pgNom  = <?= json_encode($s['nombre']) ?>;
  var stSlug = <?= json_encode($slug) ?>;

  function pingShare(channel) {
    fetch('/radio/api/share?slug=' + encodeURIComponent(stSlug) + '&channel=' + channel)
      .catch(function(){});
  }

  document.getElementById('sbtn-copy').addEventListener('click', function () {
    navigator.clipboard.writeText(pgUrl).then(function () {
      var b = document.getElementById('sbtn-copy');
      b.textContent = '✓ Copiado'; b.classList.add('copied');
      setTimeout(function () { b.textContent = '🔗 Copiar link'; b.classList.remove('copied'); }, 2000);
      pingShare('copy');
    }).catch(function () {
      if (navigator.share) navigator.share({ title: pgNom, url: pgUrl }).catch(function(){});
    });
  });

  document.getElementById('sbtn-wa').addEventListener('click', function () {
    pingShare('wa');
  });

  var qrModal = document.getElementById('qr-modal-st');
  document.getElementById('sbtn-qr').addEventListener('click', function () {
    document.getElementById('qr-img-st').src =
      'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(pgUrl);
    qrModal.classList.add('visible');
    pingShare('qr');
  });
  document.getElementById('qr-close-st').addEventListener('click', function () { qrModal.classList.remove('visible'); });
  qrModal.addEventListener('click', function (e) { if (e.target === qrModal) qrModal.classList.remove('visible'); });

  // Service Worker
  var _swBase = <?= json_encode(defined('RADIO_BASE') ? RADIO_BASE : '/radio') ?>;
  if ('serviceWorker' in navigator) navigator.serviceWorker.register(_swBase + '/sw.js').catch(function(){});
}());
</script>
<?php require __DIR__ . '/../components/privacy.php'; ?>
</body>
</html>
