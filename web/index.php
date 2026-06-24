<?php
/**
 * Radio Argentina — Player web
 * Lee emisoras.txt desde el repo de GitHub, con caché local de 1 hora.
 * ?m3u=1  → devuelve la lista completa como M3U (para VLC, apps IPTV)
 * ?buscar=texto → filtra por nombre (server-side, útil para bots/curl)
 */

require_once __DIR__ . '/log.php';
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (!defined('TG_TOKEN'))   define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID')) define('TG_CHAT_ID', '');

define('EMISORAS_JSON_URL', 'https://raw.githubusercontent.com/camammoli/radio/master/emisoras.json');
define('EMISORAS_TXT_URL',  'https://raw.githubusercontent.com/camammoli/radio/master/emisoras.txt');
define('CACHE_JSON', sys_get_temp_dir() . '/radio_emisoras_cache.json');
define('CACHE_TXT',  sys_get_temp_dir() . '/radio_emisoras_cache.txt');
define('CACHE_TTL',  3600);

function cargar_emisoras_json(): ?array {
    if (file_exists(CACHE_JSON) && (time() - filemtime(CACHE_JSON)) < CACHE_TTL) {
        $data = json_decode(file_get_contents(CACHE_JSON), true);
        if (is_array($data)) return $data;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = @file_get_contents(EMISORAS_JSON_URL, false, $ctx);
    if ($raw !== false) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            file_put_contents(CACHE_JSON, $raw);
            return $data;
        }
    }
    if (file_exists(CACHE_JSON)) {
        $data = json_decode(file_get_contents(CACHE_JSON), true);
        if (is_array($data)) return $data;
    }
    return null;
}

function cargar_emisoras_txt(): array {
    if (file_exists(CACHE_TXT) && (time() - filemtime(CACHE_TXT)) < CACHE_TTL) {
        $raw = file_get_contents(CACHE_TXT);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $raw = @file_get_contents(EMISORAS_TXT_URL, false, $ctx);
        if ($raw !== false) file_put_contents(CACHE_TXT, $raw);
        else $raw = file_exists(CACHE_TXT) ? file_get_contents(CACHE_TXT) : '';
    }
    $lineas = explode("\n", $raw); $total = count($lineas); $stations = [];
    for ($i = 0; $i < $total; $i++) {
        $linea = trim($lineas[$i]);
        if (!preg_match('/^\[#?(\d+)\]\s+(.+)/', $linea, $m)) continue;
        $numero = (int)$m[1]; $nombre = trim($m[2]); $url = '';
        for ($j = $i+1; $j < min($i+3,$total); $j++) {
            $sig = trim($lineas[$j]);
            if ($sig !== '' && preg_match('#^https?://#', $sig)) { $url = $sig; break; }
        }
        if (!$url) continue;
        $provincia = '';
        if (preg_match('/^(.+?)\s*\*\s*(.+)$/', $nombre, $pm)) {
            $nombre = trim($pm[1]); $provincia = trim($pm[2]);
        }
        $stations[] = ['n'=>$numero,'nombre'=>$nombre,'provincia'=>$provincia,'url'=>$url,
                        'logo'=>null,'tags'=>[],'homepage'=>null,'codec'=>null,'bitrate'=>null];
    }
    return $stations;
}

function _radio_slug(array $s): string {
    $text = $s['nombre'];
    if (!empty($s['provincia'])) {
        $text .= ' ' . trim(explode(',', $s['provincia'])[0]);
    }
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, [
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ñ'=>'n','ç'=>'c',
    ]);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

$stations = cargar_emisoras_json() ?? cargar_emisoras_txt();
$total    = count($stations);

// Índice de slugs: slug base → n del primer dueño (para resolver colisiones)
$_slug_idx = [];
foreach ($stations as $_s) {
    $_b = _radio_slug($_s);
    if (!isset($_slug_idx[$_b])) $_slug_idx[$_b] = $_s['n'];
}
// Slug real de cada emisora: base si es el dueño, base-n si colisiona
function _radio_full_slug(array $s, array $idx): string {
    $b = _radio_slug($s);
    return ($idx[$b] === $s['n']) ? $b : $b . '-' . $s['n'];
}

// ── Página individual de emisora (/radio/{slug}/) ─────────────────────────────
if (!empty($_GET['station'])) {
    $req = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_GET['station'])));
    $found = null;
    foreach ($stations as $_s) {
        if (_radio_full_slug($_s, $_slug_idx) === $req) { $found = $_s; break; }
    }
    if (!$found) { header('Location: /radio/'); exit; }

    // Reporte de caída (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reportar') {
        if (TG_TOKEN && TG_CHAT_ID) {
            $msg = "⚠️ Reporte de caída\n" . $found['nombre'] . "\n" . $found['url'];
            $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
            curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['chat_id'=>TG_CHAT_ID,'text'=>$msg],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
            curl_exec($ch); curl_close($ch);
        }
        header('Location: /radio/' . $req . '/?reportado=1');
        exit;
    }
    $reportado = !empty($_GET['reportado']);

    $st_data = [];
    $st_file = __DIR__ . '/status.json';
    if (file_exists($st_file)) {
        $sd = json_decode(file_get_contents($st_file), true);
        $st_data = $sd['streams'] ?? [];
    }
    $estado  = $st_data[$found['url']]['estado'] ?? 'unknown';
    $is_dead = ($estado === 'muerto');

    radio_log('station', $found['nombre']);

    $pg_url = 'https://mammoli.ar/radio/' . $req . '/';
    $prov   = !empty($found['provincia']) ? trim(explode(',', $found['provincia'])[0]) : '';
    $tags_s = !empty($found['tags']) ? implode(', ', array_slice($found['tags'], 0, 3)) : '';
    $codec_info = '';
    if (!empty($found['codec'])) {
        $codec_info = ' Formato: ' . $found['codec'];
        if (!empty($found['bitrate'])) $codec_info .= ' ' . $found['bitrate'] . 'kbps.';
        else $codec_info .= '.';
    }
    $meta_d = '▶ Escuchá ' . $found['nombre'] . ' en vivo por internet, gratis y sin instalar nada.'
        . ($prov   ? ' ' . $prov . ', Argentina.' : '')
        . ($tags_s ? ' Géneros: ' . $tags_s . '.' : '')
        . $codec_info
        . ' Directorio de ' . $total . ' radios argentinas en streaming.';

    // Emisoras relacionadas (misma provincia, hasta 5)
    $relacionadas = [];
    if ($prov) {
        foreach ($stations as $_rel) {
            if ($_rel['n'] === $found['n']) continue;
            $rel_prov = !empty($_rel['provincia']) ? trim(explode(',', $_rel['provincia'])[0]) : '';
            if (strcasecmp($rel_prov, $prov) !== 0) continue;
            $relacionadas[] = $_rel;
            if (count($relacionadas) >= 5) break;
        }
    }

    $ld = ['@context'=>'https://schema.org','@type'=>'RadioStation',
           'name'=>$found['nombre'],'inLanguage'=>'es-AR'];
    if ($prov)                    $ld['areaServed']         = $found['provincia'];
    if (!empty($found['homepage'])) $ld['url']              = $found['homepage'];
    if (!empty($found['logo']))     $ld['logo']             = $found['logo'];

    $ld2 = ['@context'=>'https://schema.org','@type'=>'RadioBroadcastService',
             'name'=>$found['nombre'],'broadcastDisplayName'=>$found['nombre'],'inLanguage'=>'es-AR'];
    if ($prov) $ld2['areaServed'] = $found['provincia'];
    if (preg_match('/(\d+\.?\d*)\s*(FM|AM|MHz|KHz)/i', $found['nombre'], $fm)
        || preg_match('/(FM|AM)\s*(\d+\.?\d*)/i', $found['nombre'], $fm2)) {
        if (isset($fm[1])) { $freq_val = $fm[1]; $freq_mod = strtoupper($fm[2]); }
        else { $freq_val = $fm2[2]; $freq_mod = strtoupper($fm2[1]); }
        if (in_array($freq_mod, ['FM','AM','MHZ','KHZ'])) {
            $freq_mod = ($freq_mod === 'MHZ') ? 'FM' : (($freq_mod === 'KHZ') ? 'AM' : $freq_mod);
            $ld2['broadcastFrequency'] = ['@type'=>'BroadcastFrequencySpecification','broadcastFrequencyValue'=>$freq_val,'broadcastSignalModulation'=>$freq_mod];
        }
    }

    $st_label = $estado === 'ok' ? '✓ Activa' : ($estado === 'muerto' ? '✗ Caída' : '⏱ Sin respuesta');
    $st_color = $estado === 'ok' ? '#22c55e'  : ($estado === 'muerto' ? '#ef4444' : '#f59e0b');
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($found['nombre']) ?> — Escuchá en vivo | Radio Argentina</title>
  <meta name="description" content="<?= htmlspecialchars($meta_d) ?>">
  <link rel="canonical" href="<?= $pg_url ?>">
  <?php if ($is_dead): ?><meta name="robots" content="noindex"><?php endif; ?>
  <meta property="og:type"        content="website">
  <meta property="og:url"         content="<?= $pg_url ?>">
  <meta property="og:title"       content="<?= htmlspecialchars($found['nombre']) ?> en vivo | Radio Argentina">
  <meta property="og:description" content="<?= htmlspecialchars($meta_d) ?>">
  <?php if (!empty($found['logo'])): ?>
  <meta property="og:image" content="<?= htmlspecialchars($found['logo']) ?>">
  <?php endif; ?>
  <meta property="og:audio" content="<?= htmlspecialchars($found['url']) ?>">
  <link rel="manifest" href="/radio/manifest.json">
  <meta name="theme-color" content="#111827">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Radio AR">
  <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>
  <script type="application/ld+json"><?= json_encode($ld2, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>
  <script type="application/ld+json"><?= json_encode([
    '@context'=>'https://schema.org','@type'=>'BreadcrumbList',
    'itemListElement'=>[
      ['@type'=>'ListItem','position'=>1,'name'=>'Radio Argentina','item'=>'https://mammoli.ar/radio/'],
      ['@type'=>'ListItem','position'=>2,'name'=>$found['nombre'],'item'=>$pg_url],
    ]
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>
  <style>
    :root{--bg:#111827;--surface:#1f2937;--border:#374151;--text:#f9fafb;--muted:#9ca3af;--accent:#3b82f6;--green:#22c55e;--red:#ef4444}
    body.light{--bg:#f3f4f6;--surface:#fff;--border:#d1d5db;--text:#111827;--muted:#6b7280;--accent:#2563eb;--green:#16a34a;--red:#dc2626}
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh}
    a{color:var(--accent);text-decoration:none}
    a:hover{color:#93c5fd}
    .hdr{background:linear-gradient(135deg,#1e3a5f 0%,#111827 70%);padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .hdr a{color:var(--muted);font-size:13px}
    .hdr a:hover{color:var(--text)}
    #theme-btn-st{background:none;border:1px solid var(--border);border-radius:16px;color:var(--muted);font-size:11px;padding:3px 9px;cursor:pointer;font-family:inherit;white-space:nowrap}
    #theme-btn-st:hover{color:var(--text)}
    .wrap{max-width:640px;margin:0 auto;padding:32px 20px}
    h1{font-size:28px;font-weight:700;margin-bottom:6px;line-height:1.2;color:var(--text)}
    .prov{color:var(--muted);font-size:14px;margin-bottom:14px}
    .tags{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:28px}
    .tag{background:rgba(167,139,250,.12);color:#a78bfa;border-radius:20px;padding:3px 10px;font-size:12px}
    .box{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:24px}
    .box-label{font-size:12px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px}
    #btn-play{display:flex;align-items:center;justify-content:center;gap:10px;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:14px 28px;font-size:16px;font-weight:600;cursor:pointer;width:100%;margin-bottom:14px;transition:background .15s}
    #btn-play:hover{background:#2563eb}
    #btn-play.playing{background:var(--green)}
    #btn-play.loading{background:#f59e0b;cursor:wait}
    #btn-play.error{background:var(--red)}
    audio{width:100%}
    #st-msg{font-size:12px;color:var(--muted);margin-top:8px;min-height:16px}
    #now-playing{font-size:12px;color:var(--muted);margin-top:6px;min-height:16px}
    .info{display:grid;gap:10px;font-size:13px;margin-bottom:28px}
    .info-row{display:flex;gap:8px;color:var(--muted)}
    .info-lbl{min-width:80px;flex-shrink:0}
    .info-val{color:var(--text)}
    hr{border:none;border-top:1px solid var(--border);margin:24px 0}
    .ft{display:flex;gap:16px;flex-wrap:wrap;font-size:14px;align-items:center}
    .btn-report{background:none;border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:12px;padding:5px 10px;cursor:pointer;margin-top:10px}
    .btn-report:hover{border-color:var(--red);color:var(--red)}
    .btn-share{background:none;border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:12px;padding:5px 10px;cursor:pointer;font-family:inherit}
    .btn-share:hover{border-color:var(--accent);color:var(--accent)}
    .reportado-ok{font-size:12px;color:var(--green);margin-top:8px}
    .rel-section{margin-top:28px}
    .rel-title{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;font-weight:500}
    .rel-list{display:flex;flex-direction:column;gap:6px}
    .rel-item{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:10px 12px;text-decoration:none;color:var(--text);font-size:13px;transition:background .15s}
    .rel-item:hover{background:var(--border);color:var(--text)}
    .rel-item img{width:28px;height:28px;border-radius:4px;object-fit:cover;flex-shrink:0}
    .rel-item-info{flex:1;min-width:0}
    .rel-item-name{font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .rel-item-tags{font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .suggest-prov{color:var(--muted);font-size:13px}
    .suggest-prov:hover{color:var(--text)}
    #survey-toast{position:fixed;bottom:20px;right:16px;max-width:260px;background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--accent);border-radius:8px;padding:14px 40px 14px 16px;font-size:14px;color:var(--text);line-height:1.5;z-index:200;box-shadow:0 4px 20px rgba(0,0,0,.3);transform:translateY(0);transition:opacity .5s,transform .5s}
    #survey-toast.hide{opacity:0;transform:translateY(8px);pointer-events:none}
    #survey-toast .survey-q{font-size:13px;color:var(--muted);margin-bottom:10px}
    #survey-toast .survey-btns{display:flex;gap:8px}
    #survey-toast .survey-btn{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:6px 12px;font-size:18px;cursor:pointer;transition:background .15s}
    #survey-toast .survey-btn:hover{background:var(--border)}
    #survey-toast .survey-dismiss{position:absolute;top:6px;right:8px;background:none;border:none;color:var(--muted);font-size:13px;cursor:pointer;padding:2px}
    #survey-toast .survey-dismiss:hover{color:var(--text)}
    #survey-toast .survey-later{font-size:11px;color:var(--muted);margin-top:8px;cursor:pointer;background:none;border:none;padding:0;font-family:inherit;text-decoration:underline}
    /* Header estandarizado */
    .site-header{background:linear-gradient(135deg,#1e3a5f 0%,#111827 70%);padding:20px 20px 16px;text-align:center;border-bottom:1px solid var(--border)}
    body.light .site-header{background:linear-gradient(135deg,#dbeafe 0%,#f3f4f6 70%)}
    .site-header-inner{max-width:640px;margin:0 auto}
    .site-brand{font-size:20px;font-weight:700;color:#f9fafb;margin-bottom:3px}
    .site-sub{font-size:12px;color:#9ca3af;margin-bottom:12px}
    .badges{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
    .badge{background:rgba(255,255,255,.08);border:1px solid var(--border);border-radius:20px;padding:4px 12px;font-size:12px;color:var(--muted);text-decoration:none;cursor:pointer;font-family:inherit;white-space:nowrap}
    .badge:hover{background:rgba(255,255,255,.14);color:var(--text)}
    .badge-cafe{border-color:#92400e;color:#fbbf24}
    .badge-cafe:hover{background:rgba(251,191,36,.12);color:#fde68a}
    body.light .badge{background:rgba(0,0,0,.06);color:#374151}
    body.light .badge:hover{background:rgba(0,0,0,.11);color:#111827}
    body.light .badge-cafe{border-color:#b45309;color:#92400e}
    body.light .badge-cafe:hover{background:rgba(180,83,9,.10);color:#78350f}
    /* Share row en station page */
    .share-row-st{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
    .sbtn{background:rgba(255,255,255,.07);border:1px solid var(--border);border-radius:6px;color:var(--muted);font-size:12px;padding:6px 12px;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .15s;font-family:inherit}
    .sbtn:hover{background:rgba(255,255,255,.13);color:var(--text);border-color:#6b7280}
    body.light .sbtn{background:rgba(0,0,0,.05);color:#374151}
    body.light .sbtn:hover{background:rgba(0,0,0,.10);color:#111827;border-color:#9ca3af}
    #sbtn-copy.copied{border-color:var(--green);color:#86efac}
    /* QR modal en station page */
    #qr-modal-st{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:300;align-items:center;justify-content:center}
    #qr-modal-st.visible{display:flex}
    #qr-box-st{background:#fff;border-radius:12px;padding:20px;text-align:center;max-width:260px;width:90%}
    #qr-box-st img{width:200px;height:200px;display:block;margin:0 auto 12px}
    .qr-name{font-size:14px;color:#111;font-weight:600;margin-bottom:4px}
    .qr-url{font-size:11px;color:#6b7280;word-break:break-all;margin-bottom:12px}
    #qr-close-st{background:#f3f4f6;border:none;border-radius:6px;padding:7px 18px;font-size:13px;cursor:pointer;color:#374151}
    #qr-close-st:hover{background:#e5e7eb}
  </style>
  <?php if (defined('GA_ID') && GA_ID): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= GA_ID ?>"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= GA_ID ?>');</script>
  <?php endif; ?>
</head>
<body>
<header class="site-header">
  <div class="site-header-inner">
    <div class="site-brand">📻 Radio Argentina</div>
    <div class="site-sub"><?= htmlspecialchars($found['nombre']) ?></div>
    <div class="badges">
      <a class="badge" href="/radio/">← Todas las emisoras</a>
      <a class="badge" href="/radio/sugerir.php">+ Sugerir emisora</a>
      <a class="badge badge-cafe" href="https://cafecito.app/mammoli" rel="noopener" target="_blank">☕ Café</a>
      <button id="theme-btn-st" class="badge">☀️ Modo claro</button>
    </div>
  </div>
</header>
<div class="wrap">
  <?php if (!empty($found['logo'])): ?>
  <img src="<?= htmlspecialchars($found['logo']) ?>" alt="" style="width:64px;height:64px;border-radius:8px;object-fit:cover;margin-bottom:16px" onerror="this.style.display='none'">
  <?php endif; ?>
  <h1><?= htmlspecialchars($found['nombre']) ?></h1>
  <?php if ($prov): ?><div class="prov">📍 <?= htmlspecialchars($found['provincia']) ?></div><?php endif; ?>
  <?php if (!empty($found['tags'])): ?>
  <div class="tags"><?php foreach ($found['tags'] as $t): ?><span class="tag"><?= htmlspecialchars($t) ?></span><?php endforeach; ?></div>
  <?php endif; ?>

  <div class="box">
    <div class="box-label">🎙 Reproducción en vivo</div>
    <?php if ($is_dead): ?>
    <p style="color:#f59e0b;font-size:13px;margin-bottom:14px">⚠ Esta emisora no respondió en la última verificación automática.</p>
    <?php endif; ?>
    <button id="btn-play">▶ Escuchar en vivo</button>
    <audio id="audio" preload="none"></audio>
    <div id="st-msg"></div>
    <div id="now-playing"></div>
  </div>

  <div class="info">
    <div class="info-row"><span class="info-lbl">Estado</span><span class="info-val" style="color:<?= $st_color ?>"><?= $st_label ?></span></div>
    <?php if (!empty($found['codec']) || !empty($found['bitrate'])): ?>
    <div class="info-row"><span class="info-lbl">Formato</span><span class="info-val"><?= htmlspecialchars(trim(($found['codec']??'').' '.($found['bitrate'] ? $found['bitrate'].'kbps' : ''))) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($found['homepage'])): ?>
    <div class="info-row"><span class="info-lbl">Sitio web</span><span class="info-val"><a href="<?= htmlspecialchars($found['homepage']) ?>" target="_blank" rel="noopener">🌐 <?= htmlspecialchars($found['homepage']) ?></a></span></div>
    <?php endif; ?>
  </div>

  <form method="post" style="display:inline">
    <input type="hidden" name="accion" value="reportar">
    <button type="submit" class="btn-report">⚠ Reportar caída</button>
  </form>
  <?php if ($reportado): ?>
    <div class="reportado-ok">✓ Gracias por el reporte. Lo revisaremos pronto.</div>
  <?php endif; ?>

  <?php if ($relacionadas): ?>
  <div class="rel-section">
    <div class="rel-title">Otras radios de <?= htmlspecialchars($prov) ?></div>
    <div class="rel-list">
    <?php foreach ($relacionadas as $_rel):
      $_slug = _radio_full_slug($_rel, $_slug_idx);
      $_rtags = !empty($_rel['tags']) ? implode(' · ', array_slice($_rel['tags'], 0, 2)) : '';
    ?>
      <a class="rel-item" href="/radio/<?= htmlspecialchars($_slug) ?>/">
        <?php if (!empty($_rel['logo'])): ?>
        <img src="<?= htmlspecialchars($_rel['logo']) ?>" alt="" onerror="this.style.display='none'">
        <?php else: ?>
        <span style="width:28px;height:28px;border-radius:4px;background:#374151;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px">📻</span>
        <?php endif; ?>
        <div class="rel-item-info">
          <div class="rel-item-name"><?= htmlspecialchars($_rel['nombre']) ?></div>
          <?php if ($_rtags): ?><div class="rel-item-tags"><?= htmlspecialchars($_rtags) ?></div><?php endif; ?>
        </div>
        <span style="color:#6b7280;font-size:14px">›</span>
      </a>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <hr>
  <div class="share-row-st">
    <button class="sbtn" id="sbtn-copy">🔗 Copiar link</button>
    <a class="sbtn" id="sbtn-wa" href="https://wa.me/?text=<?= urlencode('📻 Escuchá ' . $found['nombre'] . ' en vivo 👉 ' . $pg_url) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
    <button class="sbtn" id="sbtn-qr">⬛ QR</button>
  </div>
  <?php if ($prov): ?>
  <div style="margin-top:14px;font-size:13px">
    <a href="/radio/?provincia=<?= urlencode($found['provincia']) ?>" style="color:var(--muted)">
      📻 Ver todas las radios de <?= htmlspecialchars($prov) ?> →
    </a>
    &nbsp;·&nbsp;
    <a class="suggest-prov" href="/radio/sugerir.php?provincia=<?= urlencode($found['provincia']) ?>">¿Conocés otra? Sugerila →</a>
  </div>
  <?php endif; ?>
</div>

<div id="qr-modal-st">
  <div id="qr-box-st">
    <img id="qr-img-st" src="" alt="QR">
    <div class="qr-name"><?= htmlspecialchars($found['nombre']) ?></div>
    <div class="qr-url"><?= htmlspecialchars($pg_url) ?></div>
    <button id="qr-close-st">Cerrar</button>
  </div>
</div>
<script>
(function(){
  var audio=document.getElementById('audio'),btn=document.getElementById('btn-play'),msg=document.getElementById('st-msg');
  var npDiv=document.getElementById('now-playing');
  var rawUrl=<?= json_encode($found['url']) ?>,isHttps=location.protocol==='https:',playing=false;
  var PROXY='/radio/proxy.php?url=';
  function resolve(u){
    if(/\.pls(\?|$)/i.test(u)||(/\.m3u(\?|$)/i.test(u)&&!/\.m3u8/i.test(u))) return PROXY+encodeURIComponent(u);
    if(isHttps&&u.startsWith('http://')) return u.replace('http://','https://');
    return u;
  }
  var npTimer=0;
  function fetchNP(){
    fetch('/radio/nowplaying.php?url='+encodeURIComponent(rawUrl))
      .then(function(r){return r.json();})
      .then(function(d){if(d.ok&&d.title)npDiv.textContent='♪ '+d.title;else npDiv.textContent='';})
      .catch(function(){npDiv.textContent='';});
  }
  btn.addEventListener('click',function(){
    if(playing){audio.pause();audio.src='';btn.textContent='▶ Escuchar en vivo';btn.className='';msg.textContent='';npDiv.textContent='';clearInterval(npTimer);playing=false;return;}
    btn.textContent='⏳ Conectando...';btn.className='loading';msg.textContent='';
    audio.src=resolve(rawUrl);
    audio.play().catch(function(){btn.textContent='▶ Escuchar en vivo';btn.className='error';msg.textContent='No disponible en este momento.';});
  });
  audio.addEventListener('playing',function(){btn.textContent='⏸ Detener';btn.className='playing';msg.textContent='● En vivo';playing=true;clearInterval(npTimer);fetchNP();npTimer=setInterval(fetchNP,30000);});
  audio.addEventListener('error',function(){btn.textContent='▶ Escuchar en vivo';btn.className='error';msg.textContent='La señal se cortó. Intentá de nuevo.';playing=false;npDiv.textContent='';clearInterval(npTimer);});
  audio.addEventListener('waiting',function(){if(playing){btn.textContent='⏳ Buffering...';btn.className='loading';}});

  // Survey
  var slug=<?= json_encode($req) ?>;
  var surveyKey='survey_v1_'+slug;
  var playSeconds=0;var playTimer=0;var surveyShown=false;
  function showSurvey(){
    if(surveyShown)return;
    var stored=localStorage.getItem(surveyKey);
    if(stored){var days=(Date.now()-parseInt(stored))/86400000;if(days<30)return;}
    surveyShown=true;
    var toast=document.createElement('div');
    toast.id='survey-toast';
    toast.innerHTML='<div class="survey-q">¿Encontraste lo que buscabas?</div>'+
      '<div class="survey-btns">'+
      '<button class="survey-btn" data-r="1">👍</button>'+
      '<button class="survey-btn" data-r="0">😐</button>'+
      '<button class="survey-btn" data-r="-1">👎</button>'+
      '</div>'+
      '<button class="survey-later">Ahora no</button>'+
      '<button class="survey-dismiss">✕</button>';
    document.body.appendChild(toast);
    function dismiss(days){
      localStorage.setItem(surveyKey,(Date.now()-(30-days)*86400000).toString());
      toast.classList.add('hide');
      setTimeout(function(){if(toast.parentNode)toast.parentNode.removeChild(toast);},600);
    }
    toast.querySelectorAll('.survey-btn').forEach(function(b){
      b.addEventListener('click',function(){
        fetch('/radio/survey.php',{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({station:<?= json_encode($found['nombre']) ?>,rating:parseInt(b.dataset.r)})
        }).catch(function(){});
        dismiss(30);
      });
    });
    toast.querySelector('.survey-later').addEventListener('click',function(){dismiss(7);});
    toast.querySelector('.survey-dismiss').addEventListener('click',function(){dismiss(7);});
  }
  audio.addEventListener('playing',function(){
    clearInterval(playTimer);
    playTimer=setInterval(function(){playSeconds+=5;if(playSeconds>=180){clearInterval(playTimer);showSurvey();}},5000);
  });
  audio.addEventListener('pause',function(){clearInterval(playTimer);});
  audio.addEventListener('error',function(){clearInterval(playTimer);playSeconds=0;});

  // Compartir — Link, WhatsApp, QR
  var pgUrl=<?= json_encode($pg_url) ?>;
  var pgNombre=<?= json_encode($found['nombre']) ?>;
  var btnCopySt=document.getElementById('sbtn-copy');
  var btnQrSt=document.getElementById('sbtn-qr');
  var qrModalSt=document.getElementById('qr-modal-st');
  if(btnCopySt){btnCopySt.addEventListener('click',function(){
    navigator.clipboard.writeText(pgUrl).then(function(){
      btnCopySt.textContent='✓ Copiado';btnCopySt.id='sbtn-copy';btnCopySt.classList.add('copied');
      setTimeout(function(){btnCopySt.textContent='🔗 Copiar link';btnCopySt.classList.remove('copied');},2000);
    }).catch(function(){if(navigator.share)navigator.share({title:pgNombre,url:pgUrl}).catch(function(){});});
  });}
  if(btnQrSt){btnQrSt.addEventListener('click',function(){
    document.getElementById('qr-img-st').src='https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='+encodeURIComponent(pgUrl);
    qrModalSt.classList.add('visible');
  });}
  if(qrModalSt){
    document.getElementById('qr-close-st').addEventListener('click',function(){qrModalSt.classList.remove('visible');});
    qrModalSt.addEventListener('click',function(e){if(e.target===qrModalSt)qrModalSt.classList.remove('visible');});
  }

  // Tema claro/oscuro
  var themeBtn=document.getElementById('theme-btn-st');
  function setThemeBtnSt(isLight){themeBtn.textContent=isLight?'🌙 Modo oscuro':'☀️ Modo claro';}
  var savedTheme=localStorage.getItem('radio_theme');
  if(savedTheme==='light'){document.body.classList.add('light');setThemeBtnSt(true);}
  themeBtn.addEventListener('click',function(){
    var isLight=document.body.classList.toggle('light');
    setThemeBtnSt(isLight);
    localStorage.setItem('radio_theme',isLight?'light':'dark');
  });

  // Service Worker
  if('serviceWorker' in navigator){navigator.serviceWorker.register('/radio/sw.js').catch(function(){});}
})();
</script>
</body>
</html>
<?php
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// Géneros para filtro (top 12 tags con al menos 3 emisoras)
$tag_counts = [];
foreach ($stations as $s) {
    foreach (($s['tags'] ?? []) as $t) $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1;
}
arsort($tag_counts);
$genre_tags = array_keys(array_filter(array_slice($tag_counts, 0, 12, true), fn($c) => $c >= 3));

// Provincias para filtro — normalización de variantes
$province_terms = [
    'Buenos Aires'        => ['provincia de buenos aires', 'buenos aires'],
    'CABA'                => ['caba', 'ciudad autonoma', 'ciudad de buenos aires', 'capital federal'],
    'Córdoba'             => ['córdoba', 'cordoba'],
    'Santa Fe'            => ['santa fe', 'rosario'],
    'Mendoza'             => ['mendoza'],
    'La Pampa'            => ['la pampa'],
    'Corrientes'          => ['corrientes'],
    'Salta'               => ['salta'],
    'Misiones'            => ['misiones', 'posadas'],
    'Jujuy'               => ['jujuy'],
    'Entre Ríos'          => ['entre ríos', 'entre rios'],
    'Río Negro'           => ['río negro', 'rio negro', 'bariloche'],
    'Neuquén'             => ['neuquén', 'neuquen'],
    'San Juan'            => ['san juan'],
    'Tucumán'             => ['tucumán', 'tucuman'],
    'Chaco'               => ['chaco', 'resistencia'],
    'Chubut'              => ['chubut'],
    'Santa Cruz'          => ['santa cruz'],
    'Tierra del Fuego'    => ['tierra del fuego'],
    'San Luis'            => ['san luis'],
    'Santiago del Estero' => ['santiago del estero'],
    'Catamarca'           => ['catamarca'],
    'La Rioja'            => ['la rioja'],
    'Formosa'             => ['formosa'],
];
$province_counts = [];
foreach ($stations as $s) {
    $prov_lower = strtolower($s['provincia']);
    foreach ($province_terms as $display => $terms) {
        foreach ($terms as $term) {
            if (str_contains($prov_lower, $term)) {
                $province_counts[$display] = ($province_counts[$display] ?? 0) + 1;
                break 2;
            }
        }
    }
}
arsort($province_counts);
$province_list = array_filter($province_counts, fn($c) => $c >= 4);

// Escribe el conteo para que mammoli.ar/index.php lo lea sin HTTP request
@file_put_contents(__DIR__ . '/count.json', json_encode(['total' => $total, 'ts' => time()]));

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

// ── SEO por provincia ─────────────────────────────────────────────────────────
$filtro_prov_seo = trim($_GET['provincia'] ?? '');
$page_title = 'Radio Argentina en vivo · ' . $total . ' emisoras online';
$page_desc  = 'Escuchá ' . $total . ' radios argentinas en streaming desde el navegador — sin instalar nada. Noticias, música, deportes, folklore y más.';
$page_canon = 'https://mammoli.ar/radio/';
if ($filtro_prov_seo) {
    $terms_match = [];
    foreach ($province_terms as $display => $terms) {
        if (strcasecmp($display, $filtro_prov_seo) === 0) { $terms_match = $terms; break; }
    }
    if ($terms_match) {
        $stations = array_values(array_filter($stations, function($s) use ($terms_match) {
            $pl = strtolower($s['provincia']);
            foreach ($terms_match as $t) { if (str_contains($pl, $t)) return true; }
            return false;
        }));
    }
    $cnt = count($stations);
    $page_title = 'Radios de ' . $filtro_prov_seo . ' en vivo · ' . $cnt . ' emisoras online';
    $page_desc  = '▶ Escuchá ' . $cnt . ' radios de ' . $filtro_prov_seo . ' en vivo desde el navegador, sin instalar nada. Noticias, música, folklore y más de Argentina.';
    $page_canon = 'https://mammoli.ar/radio/?provincia=' . urlencode($filtro_prov_seo);
}

// ── Filtros server-side (para curl/bots/M3U) ─────────────────────────────────
$filtro = trim($_GET['buscar'] ?? '');
if ($filtro !== '') {
    radio_log('search', $filtro);
    $stations = array_values(array_filter($stations, fn($s) =>
        stripos($s['nombre'],   $filtro) !== false ||
        stripos($s['provincia'],$filtro) !== false ||
        in_array(strtolower($filtro), array_map('strtolower', $s['tags'] ?? []))
    ));
}

$genero = strtolower(trim($_GET['genero'] ?? ''));
if ($genero !== '') {
    radio_log('search', 'genero:' . $genero);
    $stations = array_values(array_filter($stations, fn($s) =>
        in_array($genero, array_map('strtolower', $s['tags'] ?? []))
    ));
}

radio_log('visit', '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?></title>
  <meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
  <link rel="canonical" href="<?= htmlspecialchars($page_canon) ?>">
  <meta property="og:type"        content="website">
  <meta property="og:url"         content="<?= htmlspecialchars($page_canon) ?>">
  <meta property="og:site_name"   content="Radio Argentina">
  <meta property="og:title"       content="<?= htmlspecialchars($page_title) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($page_desc) ?>">
  <meta name="twitter:card"        content="summary">
  <meta name="twitter:title"       content="<?= htmlspecialchars($page_title) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($page_desc) ?>"><?php
  // Canonical por emisora compartida (?n=) para mejorar indexación de páginas compartidas
  if (!empty($_GET['n']) && ctype_digit($_GET['n'])) {
    echo "\n  <link rel=\"canonical\" href=\"https://mammoli.ar/radio/?n=" . $_GET['n'] . "\">";
  }
  ?>
  <link rel="manifest" href="/radio/manifest.json">
  <meta name="theme-color" content="#111827">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Radio AR">
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
    body.light {
      --bg:      #f3f4f6;
      --surface: #ffffff;
      --border:  #d1d5db;
      --text:    #111827;
      --muted:   #6b7280;
      --accent:  #2563eb;
      --green:   #16a34a;
      --red:     #dc2626;
      --playing-bg: #dbeafe;
    }
    body.light header {
      background: linear-gradient(135deg, #dbeafe 0%, #f3f4f6 70%);
    }
    /* Botones de filtro en tema claro */
    body.light .filter-btn {
      background: rgba(0,0,0,.04);
      color: #374151;
    }
    body.light .filter-btn:hover { background: rgba(0,0,0,.09); color: #111827; }
    body.light .filter-btn.active { background: rgba(37,99,235,.12); color: #1e40af; }
    body.light .filter-btn.f-ok.active      { background: rgba(22,163,74,.12); border-color: #16a34a; color: #15803d; }
    body.light .filter-btn.f-timeout.active { background: rgba(217,119,6,.12); border-color: #d97706; color: #b45309; }
    body.light .filter-btn.f-muerto.active  { background: rgba(220,38,38,.12); border-color: #dc2626; color: #b91c1c; }
    body.light .filter-btn.f-top.active     { background: rgba(180,83,9,.12);  border-color: #d97706; color: #92400e; }
    body.light .filter-btn.f-genre.active   { background: rgba(109,40,217,.12); border-color: #7c3aed; color: #5b21b6; }
    body.light .filter-btn.f-cat.has-genre  { border-color: #7c3aed; color: #5b21b6; }
    body.light .filter-btn.f-prov.active    { background: rgba(5,150,105,.12); border-color: #059669; color: #065f46; }
    body.light .filter-btn.f-provcat.has-prov { border-color: #059669; color: #065f46; border-style: solid; }
    /* Panel de géneros y provincias */
    body.light #genre-panel    { background: rgba(0,0,0,.03); }
    body.light #province-panel { background: rgba(0,0,0,.03); }
    /* Tags y badges */
    body.light .station-tag { background: rgba(109,40,217,.10); color: #6d28d9; }
    /* Hover de emisora */
    body.light .station:hover { background: #e5e7eb; border-color: #9ca3af; }
    /* Player bar */
    body.light #player-bar { background: #ffffff; border-top-color: #d1d5db; }
    /* Badges del header */
    body.light .badge { background: rgba(0,0,0,.06); color: #374151; }
    body.light .badge:hover { background: rgba(0,0,0,.11); color: #111827; }
    body.light .badge-cafe { border-color: #b45309; color: #92400e; }
    body.light .badge-cafe:hover { background: rgba(180,83,9,.10); color: #78350f; }
    /* Botones de compartir */
    body.light .share-btn { background: rgba(0,0,0,.05); color: #374151; }
    body.light .share-btn:hover { background: rgba(0,0,0,.10); color: #111827; border-color: #9ca3af; }
    body.light #btn-copy.copied { color: #15803d; border-color: #16a34a; }
    /* Toast */
    body.light #support-toast {
      background: #ffffffee;
      color: #374151;
      border-color: #d1d5db;
    }
    body.light #support-toast button { color: #9ca3af; }
    body.light #support-toast button:hover { color: #374151; }
    #theme-btn { cursor: pointer; font-size: 12px; }
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
    .filter-btn.f-top.active     { background: rgba(251,191,36,.15); border-color: #fbbf24; color: #fde68a; }
    .filter-btn.f-genre.active   { background: rgba(167,139,250,.15); border-color: #a78bfa; color: #ddd6fe; }
    .filter-btn.f-cat            { border-style: dashed; }
    .filter-btn.f-cat.has-genre  { border-color: #a78bfa; color: #ddd6fe; border-style: solid; }
    .filter-btn.f-prov.active    { background: rgba(16,185,129,.15); border-color: #10b981; color: #6ee7b7; }
    .filter-btn.f-provcat        { border-style: dashed; }
    .filter-btn.f-provcat.has-prov { border-color: #10b981; color: #6ee7b7; border-style: solid; }
    #genre-panel, #province-panel {
      display: none;
      gap: 6px;
      flex-wrap: wrap;
      margin-top: 6px;
      padding: 8px 10px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: rgba(255,255,255,.03);
    }
    #genre-panel.open { display: flex; }
    #province-panel.open { display: flex; }

    .station-logo {
      width: 36px; height: 36px; border-radius: 6px; object-fit: cover;
      flex-shrink: 0; background: var(--border);
    }
    .station-tags { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 3px; }
    .station-tag  {
      font-size: 10px; padding: 1px 5px; border-radius: 10px;
      background: rgba(167,139,250,.12); color: #a78bfa; white-space: nowrap;
    }
    .codec-badge {
      font-size: 10px; color: var(--muted); white-space: nowrap;
      align-self: flex-start; padding-top: 2px; flex-shrink: 0;
    }
    .icy-badge {
      font-size: 10px; color: #a78bfa; padding: 1px 5px; border-radius: 10px;
      background: rgba(167,139,250,.12); white-space: nowrap;
      align-self: flex-start; padding-top: 2px; flex-shrink: 0; cursor: default;
    }

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
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .station-pg-link {
      color: transparent;
      font-size: 11px;
      flex-shrink: 0;
      text-decoration: none;
      transition: color .15s;
      line-height: 1;
    }
    .station:hover .station-pg-link { color: var(--muted); }
    .station-pg-link:hover { color: var(--text) !important; }
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
    #btn-vlc {
      font-size: 12px; color: #93c5fd; white-space: nowrap; text-decoration: none;
      padding: 4px 8px; border: 1px solid #374151; border-radius: 6px;
    }
    body.light #btn-vlc { color: #2563eb; border-color: #93c5fd; }
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

    /* ── Share row ── */
    #share-row {
      display: none;
      gap: 8px;
      align-items: center;
    }
    #share-row.visible { display: flex; }
    .share-btn {
      background: rgba(255,255,255,.07);
      border: 1px solid var(--border);
      border-radius: 6px;
      color: var(--muted);
      font-size: 12px;
      padding: 5px 10px;
      cursor: pointer;
      white-space: nowrap;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all .15s;
    }
    .share-btn:hover { background: rgba(255,255,255,.13); color: var(--text); border-color: #6b7280; }
    #btn-copy.copied { border-color: var(--green); color: #86efac; }

    /* ── QR modal ── */
    #qr-modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.65);
      z-index: 300;
      align-items: center;
      justify-content: center;
    }
    #qr-modal.visible { display: flex; }
    #qr-box {
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      text-align: center;
      max-width: 260px;
      width: 90%;
    }
    #qr-box img { width: 200px; height: 200px; display: block; margin: 0 auto 12px; }
    #qr-name { font-size: 14px; color: #111; font-weight: 600; margin-bottom: 4px; }
    #qr-url  { font-size: 11px; color: #6b7280; word-break: break-all; margin-bottom: 12px; }
    #qr-close {
      background: #f3f4f6; border: none; border-radius: 6px;
      padding: 7px 18px; font-size: 13px; cursor: pointer; color: #374151;
    }
    #qr-close:hover { background: #e5e7eb; }

    /* ── Link compartido ── */
    @keyframes pulse-border {
      0%, 100% { border-color: var(--accent); }
      50%       { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59,130,246,.25); }
    }
    .station.shared-highlight {
      border-color: var(--accent);
      animation: pulse-border 1.4s ease-in-out infinite;
    }
    #shared-banner {
      position: fixed;
      top: 0; left: 0; right: 0;
      background: var(--accent);
      color: #fff;
      text-align: center;
      padding: 10px 40px 10px 16px;
      font-size: 14px;
      z-index: 200;
      transition: opacity .6s ease;
    }
    #shared-banner.hide { opacity: 0; pointer-events: none; }
    #shared-banner button {
      position: absolute;
      right: 10px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      color: rgba(255,255,255,.8);
      font-size: 15px; cursor: pointer;
      padding: 4px 6px;
    }

    /* ── Toast de apoyo ── */
    #support-toast {
      position: fixed;
      bottom: 140px; right: 16px;
      max-width: 260px;
      background: #1f2937ee;
      border: 1px solid #374151;
      border-left: 3px solid #fbbf24;
      border-radius: 8px;
      padding: 13px 40px 13px 17px;
      font-size: 14px;
      color: #d1d5db;
      line-height: 1.5;
      z-index: 200;
      opacity: 1;
      transition: opacity .6s ease;
    }
    #support-toast.hide { opacity: 0; pointer-events: none; }
    #support-toast a { color: #fbbf24; text-decoration: none; }
    #support-toast a:hover { text-decoration: underline; }
    #support-toast button {
      position: absolute;
      top: 6px; right: 8px;
      background: none; border: none;
      color: #6b7280; font-size: 13px;
      cursor: pointer; padding: 2px;
    }
    #support-toast button:hover { color: #d1d5db; }
  </style>
  <?php if (defined('GA_ID') && GA_ID): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= GA_ID ?>"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= GA_ID ?>');</script>
  <?php endif; ?>
</head>
<body>

<header>
  <h1>📻 Radio Argentina</h1>
  <p class="sub"><?= $total ?> emisoras en streaming · escuchá sin instalar nada<?php if ($total < 1500): ?> · <a href="sugerir.php" style="color:#f59e0b;text-decoration:none">ayudanos a llegar a 1500 →</a><?php endif; ?></p>
  <div class="badges">
    <a class="badge" href="?m3u=1">⬇ Bajar M3U</a>
    <a class="badge" href="sugerir.php">+ Sugerir emisora</a>
    <a class="badge" href="estadisticas.php">📊 Estadísticas</a>
    <a class="badge" href="https://github.com/camammoli/radio" target="_blank">GitHub</a>
    <a class="badge" href="https://mammoli.ar">mammoli.ar</a>
    <a class="badge badge-cafe" href="https://cafecito.app/mammoli" rel="noopener" target="_blank">☕ Invitame un café</a>
    <button id="theme-btn" class="badge" title="Cambiar tema" style="margin-left:10px">☀️ Modo claro</button>
  </div>
</header>

<div class="search-wrap">
  <input id="buscador" type="search" placeholder="Buscar por nombre, provincia o género..." autocomplete="off" autofocus>
  <div class="filtros" id="filtros"></div>
  <div id="genre-panel"></div>
  <div id="province-panel"></div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
    <div style="font-size:11px;color:#6b7280" id="status-gen"></div>
    <div style="display:flex;align-items:center;gap:12px">
      <span id="listeners-badge" style="display:none;font-size:11px;color:#22c55e">● <span id="listeners-count"></span> escuchando</span>
      <div class="result-count" id="result-count"><?= $total ?> emisoras</div>
    </div>
  </div>
  <div id="no-results-hint" style="display:none;font-size:13px;color:#9ca3af;padding:6px 0;text-align:right">
    ¿No encontrás tu radio? <a href="sugerir.php" style="color:#3b82f6">Sugerila →</a>
  </div>
</div>

<div class="lista" id="lista">
<?php foreach ($stations as $s): ?>
  <?php
    $tags     = $s['tags']    ?? [];
    $codec    = $s['codec']   ?? null;
    $bitrate  = $s['bitrate'] ?? null;
    $logo     = $s['logo']    ?? null;
    $homepage = $s['homepage']?? null;
    $codec_str = $codec ? ($codec . ($bitrate ? " {$bitrate}k" : '')) : '';
    $search_str = strtolower($s['nombre'] . ' ' . $s['provincia'] . ' ' . implode(' ', $tags));
    $tags_str   = implode(',', $tags);
  ?>
  <div class="station"
       data-url="<?= htmlspecialchars($s['url']) ?>"
       data-n="<?= $s['n'] ?>"
       data-nombre="<?= htmlspecialchars($s['nombre']) ?>"
       data-prov="<?= htmlspecialchars($s['provincia']) ?>"
       data-search="<?= htmlspecialchars($search_str) ?>"
       data-tags="<?= htmlspecialchars($tags_str) ?>">
    <span class="dot" title="sin verificar"></span>
    <?php if ($logo): ?>
      <img class="station-logo" src="<?= htmlspecialchars($logo) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
    <?php endif; ?>
    <span class="station-num"><?= $s['n'] ?></span>
    <div class="station-info">
      <div class="station-name">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($s['nombre']) ?></span>
        <a class="station-pg-link"
           href="/radio/<?= htmlspecialchars(_radio_full_slug($s, $_slug_idx)) ?>/"
           onclick="event.stopPropagation()"
           target="_blank" rel="noopener"
           title="Página de <?= htmlspecialchars($s['nombre']) ?>">⬈</a>
      </div>
      <?php if ($s['provincia']): ?>
        <div class="station-prov"><?= htmlspecialchars($s['provincia']) ?></div>
      <?php endif; ?>
      <?php if ($tags): ?>
        <div class="station-tags">
          <?php foreach (array_slice($tags, 0, 3) as $t): ?>
            <span class="station-tag"><?= htmlspecialchars($t) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($codec_str): ?><span class="codec-badge"><?= htmlspecialchars($codec_str) ?></span><?php endif; ?>
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
  <a id="btn-vlc" style="display:none" target="_blank">▶ VLC</a>
  <div id="share-row">
    <button class="share-btn" id="btn-copy">🔗 Link</button>
    <a class="share-btn" id="btn-wa" href="#" target="_blank" rel="noopener">💬 WhatsApp</a>
    <button class="share-btn" id="btn-qr">⬛ QR</button>
  </div>
  <audio id="audio-elem" controls preload="none"></audio>
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
  const shareRow    = document.getElementById('share-row');
  const btnCopy     = document.getElementById('btn-copy');
  const btnWa       = document.getElementById('btn-wa');
  const btnQr       = document.getElementById('btn-qr');
  const qrModal     = document.getElementById('qr-modal');

  // ── Oyentes en tiempo real ───────────────────────────────────────────────
  var listenerSid = sessionStorage.getItem('radio_sid');
  if (!listenerSid) { listenerSid = Math.random().toString(36).slice(2); sessionStorage.setItem('radio_sid', listenerSid); }
  var heartbeatTID  = 0;
  var currentStation = null;
  var listenerBadge = document.getElementById('listeners-badge');
  var listenerCount = document.getElementById('listeners-count');

  function updateListenerBadge(n) {
    if (n > 0) { listenerCount.textContent = n === 1 ? '1 persona' : n + ' personas'; listenerBadge.style.display = ''; }
    else { listenerBadge.style.display = 'none'; }
  }

  function pingListeners(station) {
    fetch('/radio/listeners.php?action=ping&sid=' + listenerSid + '&station=' + encodeURIComponent(station))
      .then(function(r) { return r.json(); }).then(function(d) { updateListenerBadge(d.count); }).catch(function() {});
  }

  function stopListeners() {
    currentStation = null;
    clearInterval(heartbeatTID); heartbeatTID = 0;
    navigator.sendBeacon('/radio/listeners.php?action=stop&sid=' + listenerSid);
  }

  function startListeners(station) {
    currentStation = station;
    clearInterval(heartbeatTID);
    pingListeners(station);
    heartbeatTID = setInterval(function() { pingListeners(station); }, 30000);
  }

  // Page Visibility API: ping inmediato al ocultar/mostrar la pestaña
  // Cubre el caso de móvil donde setInterval se pausa en background
  document.addEventListener('visibilitychange', function() {
    if (!currentStation) return;
    pingListeners(currentStation);
  });

  // Poll pasivo (ver si otros están escuchando aunque vos no estés reproduciendo)
  function pollListeners() {
    fetch('/radio/listeners.php').then(function(r) { return r.json(); }).then(function(d) { updateListenerBadge(d.count); }).catch(function() {});
  }
  pollListeners();
  setInterval(function() { if (!heartbeatTID) pollListeners(); }, 30000);

  window.addEventListener('beforeunload', stopListeners);
  const total       = <?= $total ?>;
  const isHttps     = location.protocol === 'https:';
  const PROXY       = '/radio/proxy.php?url=';
  const TIMEOUT_MS  = 12000;

  let activeEl    = null;
  let hlsInstance = null;
  let loadTimer   = null;
  let currentN    = null;

  // ── Share ─────────────────────────────────────────────────────────────────────
  function shareUrl(n) {
    return 'https://mammoli.ar/radio/?n=' + n;
  }

  function updateShare(n, nombre) {
    currentN = n;
    var url  = shareUrl(n);
    shareRow.classList.add('visible');

    btnCopy.onclick = function() {
      navigator.clipboard.writeText(url).then(function() {
        btnCopy.textContent = '✓ Copiado';
        btnCopy.classList.add('copied');
        setTimeout(function() { btnCopy.textContent = '🔗 Link'; btnCopy.classList.remove('copied'); }, 2000);
      });
    };

    var waText = encodeURIComponent('📻 Estoy escuchando ' + nombre + '\n👉 ' + url);
    btnWa.href = 'https://wa.me/?text=' + waText;

    btnQr.onclick = function() {
      document.getElementById('qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url);
      document.getElementById('qr-name').textContent = nombre;
      document.getElementById('qr-url').textContent  = url;
      qrModal.classList.add('visible');
    };
  }

  document.getElementById('qr-close').addEventListener('click', function() {
    qrModal.classList.remove('visible');
  });
  qrModal.addEventListener('click', function(e) {
    if (e.target === qrModal) qrModal.classList.remove('visible');
  });

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

    // Limpiar highlight de link compartido al reproducir cualquier emisora
    if (window._sharedTarget) { window._sharedTarget.classList.remove('shared-highlight'); window._sharedTarget = null; }

    // Activar nueva emisora
    activeEl = el;
    markEl(el, 'loading');
    playerTitle.textContent = nombre;
    playerProv.textContent  = el.dataset.prov || '';
    playerBar.classList.add('visible');

    updateShare(el.dataset.n, nombre);
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
    stopListeners();
  }

  btnStop.addEventListener('click', function() {
    clearTimeout(loadTimer);
    if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
    if (activeEl) { markEl(activeEl, null); activeEl = null; }
    audio.pause();
    audio.src = '';
    btnVlc.style.display = 'none';
    shareRow.classList.remove('visible');
    playerBar.classList.remove('visible');
    stopListeners();
  });

  audio.addEventListener('playing', function() {
    if (activeEl) { clearTimeout(loadTimer); markEl(activeEl, 'active'); btnVlc.style.display = 'none'; startListeners(activeEl.dataset.nombre); }
  });
  audio.addEventListener('error', function() {
    if (!activeEl) return;
    clearTimeout(loadTimer);
    showError(activeEl, activeEl.dataset.url, activeEl.dataset.nombre, 'no disponible en web');
  });
  audio.addEventListener('waiting', function() {
    if (activeEl && activeEl.classList.contains('active')) markEl(activeEl, 'loading');
  });

  var genreTags     = <?= json_encode(array_values($genre_tags)) ?>;
  var provinceList  = <?= json_encode($province_list) ?>;
  var provinceTerms = <?= json_encode($province_terms) ?>;
  var urlParams     = new URLSearchParams(location.search);
  var initGenre     = urlParams.get('genero') ? urlParams.get('genero').toLowerCase() : null;
  var initStatus    = urlParams.get('estado') || null; // all|ok|timeout|muerto
  var initProv      = urlParams.get('provincia') || null;

  // ── Filtros ───────────────────────────────────────────────────────────────────
  var currentStatus = 'all';
  var currentGenre  = null;
  var currentProv   = null;
  var urlN = new URLSearchParams(location.search).get('n');

  function matchesProv(el) {
    if (!currentProv) return true;
    var provLower = (el.dataset.prov || '').toLowerCase();
    var terms = provinceTerms[currentProv] || [];
    return terms.some(function(t) { return provLower.indexOf(t) !== -1; });
  }

  function applyFilters() {
    var q   = buscador.value.toLowerCase().trim();
    var vis = 0;
    document.querySelectorAll('.station').forEach(function(el) {
      var textMatch   = !q || el.dataset.search.includes(q);
      var statusMatch = currentStatus === 'all'
          || (currentStatus === 'top' ? (el.dataset.top === '1' && el.dataset.status === 'ok')
          : el.dataset.status === currentStatus);
      var genreMatch  = currentStatus === 'top' || !currentGenre
          || (el.dataset.tags || '').split(',').includes(currentGenre);
      var provMatch   = matchesProv(el);
      var show = textMatch && statusMatch && genreMatch && provMatch;
      el.classList.toggle('hidden', !show);
      if (show) vis++;
    });
    counter.textContent = (q || currentStatus !== 'all' || currentGenre || currentProv)
      ? vis + ' de ' + total + ' emisoras' : total + ' emisoras';
    var noHint = document.getElementById('no-results-hint');
    if (noHint) noHint.style.display = vis === 0 ? 'block' : 'none';
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
        btn.className = 'filter-btn ' + b.cls;
        btn.textContent = b.label + ' ' + b.n;
        btn.addEventListener('click', function() {
          currentStatus = b.f;
          document.querySelectorAll('.filter-btn:not(.f-genre)').forEach(function(x) { x.classList.remove('active'); });
          btn.classList.add('active');
          applyFilters();
        });
        filtrosEl.appendChild(btn);
      });
      filtrosEl.classList.add('visible');

      // Top emisoras — fetch arranca ya, botón va en la fila de estado
      fetch('/radio/listeners.php?action=top&limit=10')
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.top || d.top.length < 1) return;
          d.top.forEach(function(name) {
            document.querySelectorAll('.station').forEach(function(el) {
              if (el.dataset.nombre === name) el.dataset.top = '1';
            });
          });
          var topBtn = document.createElement('button');
          topBtn.className = 'filter-btn f-top';
          topBtn.textContent = '★ Más escuchadas';
          topBtn.addEventListener('click', function() {
            currentStatus = 'top';
            document.querySelectorAll('.filter-btn:not(.f-genre)').forEach(function(x) { x.classList.remove('active'); });
            topBtn.classList.add('active');
            // Limpiar género al activar ranking global
            if (currentGenre) {
              currentGenre = null;
              document.querySelectorAll('.filter-btn.f-genre').forEach(function(x) { x.classList.remove('active'); });
              allGenreBtn.classList.add('active');
              genrePanel.classList.remove('open');
              updateCatBtn();
            }
            applyFilters();
          });
          // Insertar antes del separador de género para quedar en la fila de estado
          var genreSep = filtrosEl.querySelector('.genre-sep');
          if (genreSep) filtrosEl.insertBefore(topBtn, genreSep);
          else filtrosEl.appendChild(topBtn);
        })
        .catch(function() {});

      // Estado inicial: 'all' si viene por link compartido, sino URL param o 'ok'
      var defaultStatus = urlN ? 'all' : (initStatus || 'ok');
      currentStatus = defaultStatus;
      var okBtn = filtrosEl.querySelector('.f-ok');
      var activeStatusBtn = filtrosEl.querySelector('.f-' + defaultStatus);
      if (activeStatusBtn) activeStatusBtn.classList.add('active');
      else if (okBtn) { okBtn.classList.add('active'); currentStatus = 'ok'; }
      applyFilters();

      // Botón "Categorías" — despliega panel de géneros
      if (genreTags.length > 0) {
        var genrePanel  = document.getElementById('genre-panel');
        var catBtn = document.createElement('button');
        catBtn.className = 'filter-btn f-cat';
        catBtn.textContent = 'Categorías ▾';
        catBtn.addEventListener('click', function() {
          if (currentGenre) {
            clearGenre();
          } else {
            genrePanel.classList.toggle('open');
          }
        });
        filtrosEl.appendChild(catBtn);

        function updateCatBtn() {
          if (currentGenre) {
            catBtn.textContent = 'Categorías: ' + currentGenre + ' ✕';
            catBtn.classList.add('has-genre');
          } else {
            catBtn.textContent = 'Categorías ▾';
            catBtn.classList.remove('has-genre');
          }
        }

        function activarOk() {
          currentStatus = 'ok';
          document.querySelectorAll('.filter-btn:not(.f-genre):not(.f-cat)').forEach(function(x) { x.classList.remove('active'); });
          var okB = filtrosEl.querySelector('.f-ok');
          if (okB) okB.classList.add('active');
        }

        function clearGenre() {
          currentGenre = null;
          document.querySelectorAll('.filter-btn.f-genre').forEach(function(x) { x.classList.remove('active'); });
          allGenreBtn.classList.add('active');
          updateCatBtn();
          applyFilters();
        }

        // Botón "Todas" dentro del panel
        var allGenreBtn = document.createElement('button');
        allGenreBtn.className = 'filter-btn f-genre active';
        allGenreBtn.textContent = 'Todas';
        allGenreBtn.addEventListener('click', clearGenre);
        genrePanel.appendChild(allGenreBtn);

        genreTags.forEach(function(tag) {
          var btn = document.createElement('button');
          btn.className = 'filter-btn f-genre';
          btn.textContent = tag;
          btn.addEventListener('click', function() {
            if (currentGenre === tag) {
              clearGenre();
            } else {
              currentGenre = tag;
              document.querySelectorAll('.filter-btn.f-genre').forEach(function(x) { x.classList.remove('active'); });
              btn.classList.add('active');
              if (currentStatus === 'top') activarOk();
              updateCatBtn();
              applyFilters();
            }
          });
          if (initGenre && initGenre === tag) {
            currentGenre = tag;
            document.querySelectorAll('.filter-btn.f-genre').forEach(function(x) { x.classList.remove('active'); });
            btn.classList.add('active');
            genrePanel.classList.add('open');
            updateCatBtn();
            applyFilters();
          }
          genrePanel.appendChild(btn);
        });
      }

      // ── Panel de Provincias ────────────────────────────────────────────────────
      var provKeys = Object.keys(provinceList);
      if (provKeys.length > 0) {
        var provincePanel = document.getElementById('province-panel');
        var provBtn = document.createElement('button');
        provBtn.className = 'filter-btn f-provcat';
        provBtn.textContent = 'Provincias ▾';
        provBtn.addEventListener('click', function() {
          if (currentProv) {
            clearProv();
          } else {
            provincePanel.classList.toggle('open');
          }
        });
        filtrosEl.appendChild(provBtn);

        function updateProvBtn() {
          if (currentProv) {
            provBtn.textContent = currentProv + ' ✕';
            provBtn.classList.add('has-prov');
          } else {
            provBtn.textContent = 'Provincias ▾';
            provBtn.classList.remove('has-prov');
          }
        }

        function clearProv() {
          currentProv = null;
          document.querySelectorAll('.filter-btn.f-prov').forEach(function(x) { x.classList.remove('active'); });
          allProvBtn.classList.add('active');
          updateProvBtn();
          applyFilters();
        }

        var allProvBtn = document.createElement('button');
        allProvBtn.className = 'filter-btn f-prov active';
        allProvBtn.textContent = 'Todas';
        allProvBtn.addEventListener('click', clearProv);
        provincePanel.appendChild(allProvBtn);

        provKeys.forEach(function(prov) {
          var btn = document.createElement('button');
          btn.className = 'filter-btn f-prov';
          btn.textContent = prov + ' (' + provinceList[prov] + ')';
          btn.addEventListener('click', function() {
            if (currentProv === prov) {
              clearProv();
            } else {
              currentProv = prov;
              document.querySelectorAll('.filter-btn.f-prov').forEach(function(x) { x.classList.remove('active'); });
              btn.classList.add('active');
              updateProvBtn();
              applyFilters();
            }
          });
          if (initProv && initProv === prov) {
            currentProv = prov;
            document.querySelectorAll('.filter-btn.f-prov').forEach(function(x) { x.classList.remove('active'); });
            btn.classList.add('active');
            provincePanel.classList.add('open');
            updateProvBtn();
            applyFilters();
          }
          provincePanel.appendChild(btn);
        });
      }

      // Scroll a emisora compartida — después de applyFilters para que el layout esté correcto
      if (urlN) {
        var st = document.querySelector('.station[data-n="' + urlN + '"]');
        if (st) requestAnimationFrame(function() { st.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
      }
    })
    .catch(function() {
      // Sin status.json: hacer scroll igual como fallback
      if (urlN) {
        var st = document.querySelector('.station[data-n="' + urlN + '"]');
        if (st) st.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });

  // ── Buscador ─────────────────────────────────────────────────────────────────
  buscador.addEventListener('input', applyFilters);

  // ── Abrir desde link compartido (?n=) ────────────────────────────────────────
  // urlN ya declarado arriba. Banner inmediato; scroll ocurre dentro del fetch status.json
  if (urlN) {
    var sharedTarget = document.querySelector('.station[data-n="' + urlN + '"]');
    if (sharedTarget) {
      sharedTarget.classList.add('shared-highlight');

      var banner = document.createElement('div');
      banner.id = 'shared-banner';
      banner.innerHTML =
        '▶ Tocá para escuchar <strong>' + sharedTarget.dataset.nombre + '</strong>' +
        '<button onclick="document.getElementById(\'shared-banner\').remove()">✕</button>';
      document.body.appendChild(banner);

      window._sharedTarget = sharedTarget;
      function hideBanner() {
        banner.classList.add('hide');
        setTimeout(function() { banner.remove(); }, 650);
      }
      setTimeout(hideBanner, 6000);
    }
  }

  // ── Tema claro / oscuro ───────────────────────────────────────────────────────
  var themeBtn = document.getElementById('theme-btn');
  var savedTheme = localStorage.getItem('radio_theme');
  function setThemeBtn(isLight) {
    themeBtn.textContent = isLight ? '🌙 Modo oscuro' : '☀️ Modo claro';
  }
  if (savedTheme === 'light') { document.body.classList.add('light'); setThemeBtn(true); }
  themeBtn.addEventListener('click', function() {
    var isLight = document.body.classList.toggle('light');
    setThemeBtn(isLight);
    localStorage.setItem('radio_theme', isLight ? 'light' : 'dark');
  });

  // ── Toast de apoyo (una vez por día) ─────────────────────────────────────────
  var toastTs = parseInt(localStorage.getItem('toast_ts_v2') || '0');
  if (Date.now() - toastTs > 86400000) {
    setTimeout(function() {
      var toast = document.createElement('div');
      toast.id = 'support-toast';
      function dismissToast() {
        localStorage.setItem('toast_ts_v2', Date.now().toString());
        toast.classList.add('hide');
        setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 700);
      }
      toast.innerHTML =
        'Si esta herramienta te resultó útil, considerá <a href="https://cafecito.app/mammoli" target="_blank" rel="noopener">invitar un café</a> —' +
        ' ayuda a mantener esta y otras herramientas online. ☕' +
        '<button id="toast-close" title="Cerrar">✕</button>';
      document.body.appendChild(toast);
      document.getElementById('toast-close').addEventListener('click', dismissToast);
      setTimeout(dismissToast, 12000);
    }, 20000);
  }

  // ICY badges — emisoras que soportan metadata de canción
  fetch('/radio/icy_stations.json')
    .then(function(r) { return r.ok ? r.json() : null; })
    .then(function(icy) {
      if (!icy) return;
      var icySet = new Set(icy);
      document.querySelectorAll('.station').forEach(function(el) {
        if (!icySet.has(el.dataset.url)) return;
        var badge = document.createElement('span');
        badge.className = 'icy-badge';
        badge.title = 'Muestra la canción que suena';
        badge.textContent = '♪';
        var ref = el.querySelector('.codec-badge') || el.querySelector('.btn-play');
        if (ref) ref.before(badge);
      });
    })
    .catch(function() {});

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/radio/sw.js').catch(function(){});
  }
})();
</script>
<?php
$count_file = __DIR__ . '/count.json';
$count_data = file_exists($count_file) ? json_decode(file_get_contents($count_file), true) : null;
$last_update = ($count_data && isset($count_data['ts']))
    ? (new DateTime('@' . $count_data['ts']))->setTimezone(new DateTimeZone('America/Argentina/Mendoza'))->format('d/m/Y H:i')
    : null;
?>
<footer style="text-align:center;padding:24px 16px 32px;font-size:11px;color:#4b5563;border-top:1px solid #1f2937;margin-top:16px">
  <?php if ($last_update): ?>Directorio actualizado el <?= $last_update ?> · <?php endif; ?>
  <a href="https://mammoli.ar" style="color:#4b5563;text-decoration:none">mammoli.ar</a>
</footer>
</body>
</html>
