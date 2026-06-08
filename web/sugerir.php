<?php
/**
 * sugerir.php — formulario público para sugerir una emisora nueva
 */

define('DATA_FILE',   __DIR__ . '/data/sugerencias.json');
define('EMISORAS_JSON_URL', 'https://raw.githubusercontent.com/camammoli/radio/master/emisoras.json');
define('CACHE_JSON',  sys_get_temp_dir() . '/radio_emisoras_cache.json');
define('CACHE_TTL',   3600);

function url_en_emisoras(string $url): ?string {
    $data = null;
    if (file_exists(CACHE_JSON) && (time() - filemtime(CACHE_JSON)) < CACHE_TTL) {
        $data = json_decode(file_get_contents(CACHE_JSON), true);
    }
    if (!is_array($data)) {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw = @file_get_contents(EMISORAS_JSON_URL, false, $ctx);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data)) @file_put_contents(CACHE_JSON, $raw);
        }
    }
    if (!is_array($data)) return null;
    $url_norm = rtrim(strtolower($url), '/');
    foreach ($data as $s) {
        if (rtrim(strtolower($s['url'] ?? ''), '/') === $url_norm) {
            return $s['nombre'] ?? 'una emisora existente';
        }
    }
    return null;
}

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (!defined('TG_TOKEN'))  define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID') ) define('TG_CHAT_ID', '');

function tg_send(string $text): void {
    if (!TG_TOKEN || !TG_CHAT_ID) return;
    $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => TG_CHAT_ID, 'text' => $text],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function check_stream(string $url): array {
    // Bloquear SSRF
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || preg_match('#^(localhost|127\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|::1$|0\.0\.0\.0)#i', $host)) {
        return ['ok' => false, 'msg' => 'URL no permitida'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY          => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_CONNECTTIMEOUT  => 7,
        CURLOPT_TIMEOUT         => 10,
        CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; radio-check/1.0)',
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_HTTPHEADER      => ['Icy-MetaData: 0'],
    ]);
    $code = 0;
    $content_type = '';
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = strtolower(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
    $err = curl_error($ch);
    curl_close($ch);

    if ($code === 0 || $err) {
        // Podría ser un stream que no responde a HEAD — intentar GET parcial
        $ch2 = curl_init($url);
        curl_setopt_array($ch2, [
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_CONNECTTIMEOUT  => 7,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_USERAGENT       => 'Mozilla/5.0',
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_RANGE           => '0-1023',
            CURLOPT_WRITEFUNCTION   => function($ch, $d){ return strlen($d); },
        ]);
        curl_exec($ch2);
        $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $content_type = strtolower(curl_getinfo($ch2, CURLINFO_CONTENT_TYPE) ?? '');
        curl_close($ch2);
    }

    $ok = ($code >= 200 && $code < 400);
    $is_audio = str_contains($content_type, 'audio') || str_contains($content_type, 'ogg') || str_contains($content_type, 'mpegurl');
    return ['ok' => $ok, 'code' => $code, 'audio' => $is_audio, 'msg' => $ok ? 'OK' : "HTTP $code"];
}

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim(strip_tags($_POST['nombre']   ?? ''));
    $url      = trim($_POST['url']      ?? '');
    $provincia = trim(strip_tags($_POST['provincia'] ?? ''));
    $contacto = trim(strip_tags($_POST['contacto'] ?? ''));

    // Validaciones
    if (strlen($nombre) < 2) {
        $error = 'El nombre de la radio es obligatorio.';
    } elseif (!preg_match('#^https?://#i', $url)) {
        $error = 'La URL debe empezar con http:// o https://';
    } elseif (strlen($url) > 500) {
        $error = 'URL demasiado larga.';
    } else {
        // Chequear duplicado en emisoras existentes
        $existente = url_en_emisoras($url);
        if ($existente !== null) {
            $error = "Esta URL ya está en el directorio como \"" . htmlspecialchars($existente) . "\". ¡Gracias de todas formas!";
        }
    }

    if (!$error) {
        // Verificar stream
        $check = check_stream($url);
        if (!$check['ok']) {
            $error = "No se pudo conectar al stream: {$check['msg']}. Verificá que la URL sea correcta y el stream esté activo.";
        } else {
            // Guardar sugerencia
            $sugerencias = [];
            if (file_exists(DATA_FILE)) {
                $raw = file_get_contents(DATA_FILE);
                $sugerencias = json_decode($raw, true) ?? [];
            }
            $nueva = [
                'id'       => uniqid('sug_', true),
                'ts'       => gmdate('Y-m-d H:i:s'),
                'nombre'   => $nombre,
                'url'      => $url,
                'provincia'=> $provincia,
                'contacto' => $contacto,
                'estado'   => 'pendiente',
                'http_code'=> $check['code'],
                'is_audio' => $check['audio'],
            ];
            $sugerencias[] = $nueva;
            @file_put_contents(DATA_FILE, json_encode($sugerencias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

            // Notificar por Telegram
            $prov_str = $provincia ? " * $provincia" : '';
            $msg = "Nueva sugerencia de emisora para Radio Argentina\n\n"
                 . "Nombre: {$nombre}{$prov_str}\n"
                 . "URL: {$url}\n"
                 . "Stream: HTTP {$check['code']}" . ($check['audio'] ? ' (audio)' : '') . "\n"
                 . ($contacto ? "Contacto: {$contacto}\n" : '')
                 . "\nRevisala en mammoli.ar/radio/admin_sugerencias.php";
            tg_send($msg);

            $result = ['nombre' => $nombre, 'url' => $url];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sugerir emisora · Radio Argentina</title>
<style>
:root{--bg:#111827;--surface:#1f2937;--border:#374151;--text:#f9fafb;--muted:#9ca3af;--accent:#3b82f6;--green:#22c55e;--red:#ef4444}
body.light{--bg:#f3f4f6;--surface:#fff;--border:#d1d5db;--text:#111827;--muted:#6b7280;--accent:#2563eb}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:0 0 48px}
header{background:linear-gradient(135deg,#1e3a5f 0%,#111827 70%);padding:24px 20px 20px;text-align:center;border-bottom:1px solid var(--border)}
body.light header{background:linear-gradient(135deg,#dbeafe 0%,#f3f4f6 70%)}
header h1{font-size:1.4rem;font-weight:700;margin-bottom:4px}
header .sub{font-size:.85rem;color:var(--muted)}
.nav{display:flex;gap:8px;justify-content:center;padding:14px 16px;flex-wrap:wrap;border-bottom:1px solid var(--border)}
.nav a,.nav button{color:var(--muted);text-decoration:none;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:transparent;cursor:pointer;transition:background .15s}
.nav a:hover,.nav button:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.container{max-width:540px;margin:0 auto;padding:28px 16px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px}
.card h2{font-size:1rem;font-weight:600;margin-bottom:6px}
.card p{font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.6}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:5px;margin-top:14px}
label:first-of-type{margin-top:0}
input,select{width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:14px;outline:none;transition:border-color .15s}
input:focus{border-color:var(--accent)}
body.light input,body.light select{background:#f9fafb}
.hint{font-size:11px;color:var(--muted);margin-top:3px}
.btn{width:100%;padding:11px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:15px;font-weight:600;cursor:pointer;margin-top:20px;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn:disabled{opacity:.5;cursor:not-allowed}
.error{background:#2a0a0a;border:1px solid var(--red);border-radius:8px;padding:12px 14px;color:#ff8a8a;font-size:13px;margin-bottom:16px;line-height:1.5}
.success{background:#0a2e12;border:1px solid var(--green);border-radius:8px;padding:16px;color:#7dd49f;font-size:14px;line-height:1.6;text-align:center}
.success strong{display:block;font-size:1.1rem;margin-bottom:6px}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<header>
  <h1>📻 Sugerir una emisora</h1>
  <p class="sub">Ayudá a completar el directorio de Radio Argentina</p>
</header>
<div class="nav">
  <a href="index.php">← Volver al player</a>
  <button id="theme-btn">☀️ Modo claro</button>
</div>
<div class="container">
<div class="card">
  <h2>Sugerir una emisora</h2>
  <p>Si conocés una radio argentina que no está en el listado, completá el formulario. El stream se verifica automáticamente y si funciona queda en revisión para ser agregada.</p>

  <?php if ($result): ?>
    <div class="success">
      <strong>¡Gracias por la sugerencia!</strong>
      <strong><?= htmlspecialchars($result['nombre']) ?></strong> quedó en revisión y será agregada pronto si cumple los criterios del directorio.
    </div>
    <a href="index.php" style="display:block;text-align:center;margin-top:16px;color:var(--accent);font-size:14px">← Volver al player</a>
  <?php else: ?>
    <?php if ($error): ?><div class="error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" id="form-sug">
      <label for="nombre">Nombre de la radio *</label>
      <input type="text" id="nombre" name="nombre" required maxlength="100" placeholder="Ej: FM La Nacional" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">

      <label for="url">URL del stream *</label>
      <input type="url" id="url" name="url" required maxlength="500" placeholder="https://..." value="<?= htmlspecialchars($_POST['url'] ?? '') ?>">
      <p class="hint">URL directa del stream de audio (mp3, aac, ogg). No la página web de la radio.</p>

      <label for="provincia">Provincia / País</label>
      <input type="text" id="provincia" name="provincia" maxlength="60" placeholder="Ej: Mendoza" value="<?= htmlspecialchars($_POST['provincia'] ?? '') ?>">

      <label for="contacto">Tu email o contacto <span style="font-weight:normal">(opcional, para avisarte cuando se agregue)</span></label>
      <input type="text" id="contacto" name="contacto" maxlength="100" placeholder="Ej: tu@email.com" value="<?= htmlspecialchars($_POST['contacto'] ?? '') ?>">

      <button type="submit" class="btn" id="btn-sug">Verificar y sugerir</button>
      <div class="spinner" id="spinner"></div>
    </form>
  <?php endif; ?>
</div>
</div>

<script>
(function(){
  var theme = localStorage.getItem('radio_theme');
  if (theme === 'light') document.body.classList.add('light');
  var btn = document.getElementById('theme-btn');
  function syncBtn(){ if(btn) btn.textContent = document.body.classList.contains('light') ? '🌙 Modo oscuro' : '☀️ Modo claro'; }
  syncBtn();
  if(btn) btn.addEventListener('click', function(){
    var isLight = document.body.classList.toggle('light');
    localStorage.setItem('radio_theme', isLight ? 'light' : 'dark');
    syncBtn();
  });
  var form = document.getElementById('form-sug');
  if (form) form.addEventListener('submit', function(){
    var btnEl = document.getElementById('btn-sug');
    var sp = document.getElementById('spinner');
    if(btnEl){ btnEl.disabled = true; btnEl.textContent = 'Verificando stream...'; }
    if(sp){ sp.style.display = 'block'; }
  });
})();
</script>
</body>
</html>
