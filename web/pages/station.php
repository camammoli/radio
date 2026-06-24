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
$page_title = htmlspecialchars($s['nombre']) . ' — Escuchá en vivo | Radio Argentina';
$page_desc  = '▶ Escuchá ' . $s['nombre'] . ' en vivo por internet, gratis y sin instalar nada.'
            . ($prov    ? ' Emisora de ' . $prov . '.' : '')
            . ($tag_s   ? ' ' . $tag_s . '.' : '')
            . ($codec_s ? ' Formato: ' . $codec_s . '.' : '');
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

  <!-- Player -->
  <div class="player-wrap">
    <button id="btn-play" class="rp-btn">▶ Escuchar en vivo</button>
    <div class="st-status" id="st-status"></div>
    <div class="st-np"     id="st-np"></div>
    <div class="st-listeners" id="st-listeners"></div>
  </div>

  <!-- Info técnica -->
  <div style="text-align:left;margin-bottom:20px">
    <?php if ($codec_s): ?>
    <div class="info-row"><span class="info-lbl">Formato</span><span class="info-val"><?= htmlspecialchars($codec_s) ?></span></div>
    <?php endif; ?>
    <?php if ($s['homepage']): ?>
    <div class="info-row"><span class="info-lbl">Sitio web</span><span class="info-val"><a href="<?= htmlspecialchars($s['homepage']) ?>" target="_blank" rel="noopener">🌐 <?= htmlspecialchars($s['homepage']) ?></a></span></div>
    <?php endif; ?>
    <div class="info-row">
      <span class="info-lbl">Estado</span>
      <span class="info-val" style="color:<?= $s['estado']==='ok' ? '#22c55e' : ($s['estado']==='muerto' ? '#ef4444' : '#f59e0b') ?>">
        <?= $s['estado'] === 'ok' ? '● En línea' : ($s['estado'] === 'muerto' ? '● Caída' : '● Inestable') ?>
      </span>
    </div>
    <?php if ($s['icy_supported']): ?>
    <div class="info-row"><span class="info-lbl">Metadata</span><span class="info-val"><span class="icy-badge">♪ ahora suena</span></span></div>
    <?php endif; ?>
  </div>

  <!-- Compartir -->
  <div class="share-row-st">
    <button class="sbtn" id="sbtn-copy">🔗 Copiar link</button>
    <a class="sbtn" id="sbtn-wa" href="https://wa.me/?text=<?= urlencode('📻 Escuchá ' . $s['nombre'] . ' en vivo 👉 ' . $pg_url) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
    <button class="sbtn" id="sbtn-qr">⬛ QR</button>
  </div>

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
    if (defined('TG_TOKEN') && TG_TOKEN) {
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

<script src="/radio/assets/theme.js"></script>
<script src="/radio/assets/player.js"></script>
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
    },

    onNowPlaying: function (title) {
      stNp.textContent = title ? '♪ ' + title : '';
    },

    onListeners: function (total) {
      stList.textContent = total > 1 ? total + ' personas escuchando ahora' : '';
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

  // Compartir
  var pgUrl  = <?= json_encode($pg_url) ?>;
  var pgNom  = <?= json_encode($s['nombre']) ?>;

  document.getElementById('sbtn-copy').addEventListener('click', function () {
    navigator.clipboard.writeText(pgUrl).then(function () {
      var b = document.getElementById('sbtn-copy');
      b.textContent = '✓ Copiado'; b.classList.add('copied');
      setTimeout(function () { b.textContent = '🔗 Copiar link'; b.classList.remove('copied'); }, 2000);
    }).catch(function () {
      if (navigator.share) navigator.share({ title: pgNom, url: pgUrl }).catch(function(){});
    });
  });

  var qrModal = document.getElementById('qr-modal-st');
  document.getElementById('sbtn-qr').addEventListener('click', function () {
    document.getElementById('qr-img-st').src =
      'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(pgUrl);
    qrModal.classList.add('visible');
  });
  document.getElementById('qr-close-st').addEventListener('click', function () { qrModal.classList.remove('visible'); });
  qrModal.addEventListener('click', function (e) { if (e.target === qrModal) qrModal.classList.remove('visible'); });

  // Service Worker
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/radio/sw.js').catch(function(){});
}());
</script>
</body>
</html>
