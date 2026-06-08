<?php
/**
 * admin_sugerencias.php — panel de administración de sugerencias de emisoras
 * Acceso: ?key=RADIO_ADMIN_KEY
 */

define('DATA_FILE', __DIR__ . '/data/sugerencias.json');
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (!defined('RADIO_ADMIN_KEY')) define('RADIO_ADMIN_KEY', '');
if (!defined('TG_TOKEN'))        define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID'))      define('TG_CHAT_ID', '');

$key = trim($_GET['key'] ?? $_POST['key'] ?? '');
if (!RADIO_ADMIN_KEY || $key !== RADIO_ADMIN_KEY) {
    http_response_code(403);
    exit('Acceso denegado');
}

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

function cargar(): array {
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?? [];
}

function guardar(array $data): void {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function sig_numero(): int {
    // Leer emisoras.txt para encontrar el número más alto
    $txt = __DIR__ . '/../emisoras.txt';
    if (!file_exists($txt)) return 900;
    $max = 0;
    foreach (file($txt) as $linea) {
        if (preg_match('/^\[#?(\d+)\]/', trim($linea), $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max + 1;
}

function formato_emisoras_txt(string $nombre, string $url, string $provincia, int $num): string {
    $linea_nombre = sprintf('[#%03d] %s', $num, $nombre);
    if ($provincia) $linea_nombre .= " * $provincia";
    return "$linea_nombre\n$url";
}

$flash = '';

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $id     = $_POST['id'] ?? '';
    $sug    = cargar();
    $idx    = null;
    foreach ($sug as $i => $s) { if ($s['id'] === $id) { $idx = $i; break; } }

    if ($idx !== null) {
        if ($accion === 'rechazar') {
            $sug[$idx]['estado'] = 'rechazada';
            $sug[$idx]['rechazado_at'] = gmdate('Y-m-d H:i:s');
            guardar($sug);
            $flash = 'ok|Sugerencia rechazada.';

        } elseif ($accion === 'aprobar') {
            $num = sig_numero();
            $entrada = formato_emisoras_txt(
                $sug[$idx]['nombre'],
                $sug[$idx]['url'],
                $sug[$idx]['provincia'] ?? '',
                $num
            );
            $sug[$idx]['estado'] = 'aprobada';
            $sug[$idx]['aprobado_at'] = gmdate('Y-m-d H:i:s');
            $sug[$idx]['numero_asignado'] = $num;
            $sug[$idx]['entrada_emisoras_txt'] = $entrada;
            guardar($sug);

            // Notificar por Telegram con la línea lista para pegar
            $msg = "Sugerencia APROBADA — Agregar a emisoras.txt:\n\n$entrada\n\nLuego: git commit + deploy";
            tg_send($msg);

            $flash = "entrada|$entrada";
        }
    }
    // Redirect PRG para evitar reenvío
    $redir_flash = urlencode($flash);
    header("Location: admin_sugerencias.php?key={$key}&flash={$redir_flash}");
    exit;
}

// Leer flash de redirect
if (!$flash && !empty($_GET['flash'])) $flash = urldecode($_GET['flash']);

$sug = cargar();
$pendientes = array_filter($sug, fn($s) => $s['estado'] === 'pendiente');
$aprobadas  = array_filter($sug, fn($s) => $s['estado'] === 'aprobada');
$rechazadas = array_filter($sug, fn($s) => $s['estado'] === 'rechazada');

$tab = $_GET['tab'] ?? 'pendientes';
if (!in_array($tab, ['pendientes','aprobadas','rechazadas'])) $tab = 'pendientes';

$tab_data = ['pendientes'=>$pendientes,'aprobadas'=>$aprobadas,'rechazadas'=>$rechazadas][$tab];
$tab_data = array_values(array_reverse($tab_data));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin sugerencias · Radio Argentina</title>
<style>
:root{--bg:#111827;--surface:#1f2937;--border:#374151;--text:#f9fafb;--muted:#9ca3af;--accent:#3b82f6;--green:#22c55e;--red:#ef4444;--yellow:#f59e0b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:0 0 48px}
header{background:linear-gradient(135deg,#1e3a5f 0%,#111827 70%);padding:20px;border-bottom:1px solid var(--border)}
header h1{font-size:1.3rem;font-weight:700}
header p{font-size:13px;color:var(--muted);margin-top:4px}
.nav{display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.nav a{color:var(--muted);text-decoration:none;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--border)}
.nav a:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.container{max-width:800px;margin:0 auto;padding:20px 16px}
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:16px}
.tab{padding:9px 16px;font-size:13px;cursor:pointer;border:none;background:none;color:var(--muted);border-bottom:2px solid transparent;text-decoration:none}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.badge{display:inline-block;background:var(--border);color:var(--muted);border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px}
.badge.red{background:#2a0a0a;color:#ff8a8a}
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:12px}
.card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:8px}
.card-nombre{font-weight:600;font-size:15px}
.card-prov{font-size:12px;color:var(--muted);margin-top:2px}
.card-url{font-size:12px;color:var(--accent);word-break:break-all;margin-bottom:8px}
.card-meta{font-size:11px;color:var(--muted);display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.badge-ok{color:#22c55e} .badge-audio{color:#3b82f6}
.btns{display:flex;gap:8px}
.btn{padding:7px 14px;border-radius:7px;border:none;font-size:13px;font-weight:600;cursor:pointer}
.btn-aprobar{background:#155724;color:#7dd49f;border:1px solid #28a745}
.btn-rechazar{background:#2a0a0a;color:#ff8a8a;border:1px solid #dc3545}
.btn-aprobar:hover{background:#28a745;color:#fff}
.btn-rechazar:hover{background:#dc3545;color:#fff}
.entrada-txt{background:#0d0d0d;border:1px solid var(--border);border-radius:8px;padding:12px;font-family:monospace;font-size:13px;white-space:pre;margin-top:10px;cursor:pointer;user-select:all}
.entrada-txt:hover{border-color:var(--accent)}
.flash-ok{background:#0a2e12;border:1px solid #28a745;border-radius:8px;padding:12px 14px;color:#7dd49f;font-size:13px;margin-bottom:16px}
.flash-entrada{background:#0a1a2e;border:1px solid var(--accent);border-radius:8px;padding:14px;font-size:13px;margin-bottom:16px;color:#7db4ff;line-height:1.6}
.empty{text-align:center;padding:36px;color:var(--muted);font-size:14px}
.estado-badge{font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600}
.estado-aprobada{background:#155724;color:#7dd49f}
.estado-rechazada{background:#2a0a0a;color:#ff8a8a}
</style>
</head>
<body>
<header>
  <h1>Admin · Sugerencias de emisoras</h1>
  <p>Radio Argentina — <?= count($sug) ?> sugerencias en total</p>
</header>
<div class="nav">
  <a href="index.php">← Player</a>
  <a href="estadisticas.php?key=<?= urlencode($key) ?>">Estadísticas</a>
</div>
<div class="container">

<?php if ($flash): ?>
  <?php [$flash_type, $flash_val] = explode('|', $flash, 2); ?>
  <?php if ($flash_type === 'ok'): ?>
    <div class="flash-ok"><?= htmlspecialchars($flash_val) ?></div>
  <?php elseif ($flash_type === 'entrada'): ?>
    <div class="flash-entrada">
      <strong>Sugerencia aprobada.</strong> Copiá esta línea y pegala al final de <code>emisoras.txt</code>, luego hacé commit y deploy:<br><br>
      <div class="entrada-txt" title="Click para copiar" id="entrada-copy"><?= htmlspecialchars($flash_val) ?></div>
      <button onclick="copyEntrada()" style="margin-top:8px;padding:5px 12px;border-radius:6px;border:1px solid var(--accent);background:transparent;color:var(--accent);cursor:pointer;font-size:12px">Copiar</button>
      <span id="copy-ok" style="display:none;color:#22c55e;margin-left:8px;font-size:12px">¡Copiado!</span>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="tabs">
  <a class="tab <?= $tab==='pendientes'?'active':'' ?>" href="?key=<?= urlencode($key) ?>&tab=pendientes">
    Pendientes <span class="badge <?= count($pendientes)?'red':'' ?>"><?= count($pendientes) ?></span>
  </a>
  <a class="tab <?= $tab==='aprobadas'?'active':'' ?>" href="?key=<?= urlencode($key) ?>&tab=aprobadas">
    Aprobadas <span class="badge"><?= count($aprobadas) ?></span>
  </a>
  <a class="tab <?= $tab==='rechazadas'?'active':'' ?>" href="?key=<?= urlencode($key) ?>&tab=rechazadas">
    Rechazadas <span class="badge"><?= count($rechazadas) ?></span>
  </a>
</div>

<?php if (!$tab_data): ?>
  <div class="empty">No hay sugerencias <?= $tab ?>.</div>
<?php else: ?>
  <?php foreach ($tab_data as $s):
    $dt = (new DateTime($s['ts'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/Argentina/Mendoza'))
        ->format('d/m/Y H:i');
  ?>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-nombre"><?= htmlspecialchars($s['nombre']) ?></div>
        <?php if ($s['provincia'] ?? ''): ?>
          <div class="card-prov">📍 <?= htmlspecialchars($s['provincia']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($s['estado'] !== 'pendiente'): ?>
        <span class="estado-badge estado-<?= $s['estado'] ?>"><?= $s['estado'] ?></span>
      <?php endif; ?>
    </div>
    <div class="card-url">
      <a href="<?= htmlspecialchars($s['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($s['url']) ?></a>
    </div>
    <div class="card-meta">
      <span>📅 <?= $dt ?></span>
      <span class="badge-ok">✓ HTTP <?= $s['http_code'] ?? '?' ?></span>
      <?php if ($s['is_audio'] ?? false): ?><span class="badge-audio">🎵 audio</span><?php endif; ?>
      <?php if ($s['contacto'] ?? ''): ?><span>📧 <?= htmlspecialchars($s['contacto']) ?></span><?php endif; ?>
    </div>
    <?php if ($s['estado'] === 'pendiente'): ?>
    <form method="post">
      <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
      <input type="hidden" name="id"  value="<?= htmlspecialchars($s['id']) ?>">
      <div class="btns">
        <button type="submit" name="accion" value="aprobar"   class="btn btn-aprobar">✓ Aprobar</button>
        <button type="submit" name="accion" value="rechazar"  class="btn btn-rechazar">✕ Rechazar</button>
      </div>
    </form>
    <?php elseif ($s['estado'] === 'aprobada' && isset($s['entrada_emisoras_txt'])): ?>
      <div class="entrada-txt"><?= htmlspecialchars($s['entrada_emisoras_txt']) ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<script>
function copyEntrada() {
    var el = document.getElementById('entrada-copy');
    if (!el) return;
    navigator.clipboard.writeText(el.textContent.trim()).then(function(){
        document.getElementById('copy-ok').style.display = 'inline';
    });
}
document.querySelectorAll('.entrada-txt').forEach(function(el){
    el.addEventListener('click', function(){
        navigator.clipboard.writeText(el.textContent.trim()).catch(function(){});
    });
});
</script>
</body>
</html>
