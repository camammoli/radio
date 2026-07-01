<?php
/**
 * admin.php — Panel de administración Radio Argentina v2.
 * Autenticación por sesión. No indexado.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

session_start();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$ADMIN_USER = defined('ADMIN_USER') ? ADMIN_USER : 'admin';
$ADMIN_PASS = defined('ADMIN_PASS') ? ADMIN_PASS : (defined('RADIO_ADMIN_KEY') ? RADIO_ADMIN_KEY : 'mammoli_radio_2026');

// ── Auth ──────────────────────────────────────────────────────────────────────

$act = $_POST['action'] ?? '';

if ($act === 'login') {
    if (($_POST['u'] ?? '') === $ADMIN_USER && ($_POST['p'] ?? '') === $ADMIN_PASS) {
        $_SESSION['radio_admin'] = true;
        $_SESSION['csrf']        = bin2hex(random_bytes(16));
        // Sin redirect: renderizar el dashboard directamente evita cualquier flash intermedio
    } else {
        $login_err = true;
    }
}
if ($act === 'logout') {
    session_destroy();
    session_start(); // sesión limpia para la página de login
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    login_page(false);
    exit;
}

if (empty($_SESSION['radio_admin'])) {
    login_page($login_err ?? false);
    exit;
}

$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$db   = radio_db();

// Migraciones
try { $db->exec('ALTER TABLE surveys ADD COLUMN location TEXT'); } catch (Exception $e) {}
try { $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)'); } catch (Exception $e) {}
try { $db->exec('CREATE TABLE IF NOT EXISTS shares (id INTEGER PRIMARY KEY AUTOINCREMENT, station_id INTEGER, slug TEXT, channel TEXT, ip_hash TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'); } catch (Exception $e) {}
try { $db->exec('ALTER TABLE plays ADD COLUMN ended_at TEXT'); } catch (Exception $e) {}

// ── Acciones sobre sugerencias ────────────────────────────────────────────────

if ($act === 'toggle_notify' && ($_POST['csrf'] ?? '') === $csrf) {
    $current = $db->query("SELECT value FROM settings WHERE key='notify_oyentes' LIMIT 1")->fetchColumn();
    if ($current === false) {
        $current = defined('NOTIFY_OYENTES') && NOTIFY_OYENTES ? '1' : '0';
    }
    $new = $current === '1' ? '0' : '1';
    $db->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('notify_oyentes', ?, datetime('now'))")
       ->execute([$new]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#telegram');
    exit;
}
if ($act === 'approve' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare('UPDATE stations SET approved=1, updated_at=datetime("now") WHERE id=? AND source="sugerencia" AND approved=0')
       ->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#sugerencias');
    exit;
}
if ($act === 'reject' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare('DELETE FROM stations WHERE id=? AND source="sugerencia" AND approved=0')
       ->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#sugerencias');
    exit;
}

// ── Ajax: auto-refresh ───────────────────────────────────────────────────────

if (isset($_GET['ajax'])) {
    session_write_close(); // liberar lock de sesión antes de las queries
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        // Sin DELETE: listeners.php ya hace el cleanup en cada ping
        $out = [
            'stats' => [
                'total'       => (int)$db->query('SELECT COUNT(*) FROM stations WHERE approved=1')->fetchColumn(),
                'ok'          => (int)$db->query("SELECT COUNT(*) FROM v_stations WHERE estado='ok'")->fetchColumn(),
                'icy'         => (int)$db->query('SELECT COUNT(*) FROM icy_cache WHERE supported=1')->fetchColumn(),
                'icy_activo'  => (int)$db->query("SELECT COUNT(*) FROM icy_cache WHERE supported=1 AND stream_title IS NOT NULL AND stream_title!=''")->fetchColumn(),
                'plays_hoy'   => (int)$db->query("SELECT COUNT(*) FROM plays WHERE played_at>=date('now')")->fetchColumn(),
                'plays_total' => (int)$db->query('SELECT COUNT(*) FROM plays')->fetchColumn(),
                'listeners'   => (int)$db->query("SELECT COUNT(*) FROM listeners WHERE last_seen>=datetime('now','-90 seconds')")->fetchColumn(),
            ],
            'plays' => $db->query(
                "SELECT p.played_at, p.ip_hash, p.source, p.session_id, s.nombre, s.slug,
                        CASE WHEN p.ended_at IS NOT NULL THEN ROUND((julianday(p.ended_at)-julianday(p.played_at))*86400)
                             WHEN l.sid IS NOT NULL      THEN ROUND((julianday('now')-julianday(p.played_at))*86400)
                             ELSE NULL END AS duration_secs,
                        CASE WHEN l.sid IS NOT NULL THEN 1 ELSE 0 END AS is_active
                 FROM plays p
                 LEFT JOIN stations s ON s.id=p.station_id
                 LEFT JOIN listeners l ON l.sid=p.session_id
                 ORDER BY p.played_at DESC LIMIT 200"
            )->fetchAll(),
            'shares' => $db->query(
                "SELECT sh.created_at, sh.channel, sh.ip_hash, sh.slug, s.nombre
                 FROM shares sh LEFT JOIN stations s ON s.id=sh.station_id
                 ORDER BY sh.created_at DESC LIMIT 100"
            )->fetchAll(),
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Consultas ─────────────────────────────────────────────────────────────────

$stats = [
    'total'      => (int)$db->query('SELECT COUNT(*) FROM stations WHERE approved=1')->fetchColumn(),
    'ok'         => (int)$db->query("SELECT COUNT(*) FROM v_stations WHERE estado='ok'")->fetchColumn(),
    'icy'        => (int)$db->query('SELECT COUNT(*) FROM icy_cache WHERE supported=1')->fetchColumn(),
    'icy_activo' => (int)$db->query("SELECT COUNT(*) FROM icy_cache WHERE supported=1 AND stream_title IS NOT NULL AND stream_title!=''")->fetchColumn(),
    'plays_hoy'  => (int)$db->query("SELECT COUNT(*) FROM plays WHERE played_at>=date('now')")->fetchColumn(),
    'plays_total'=> (int)$db->query('SELECT COUNT(*) FROM plays')->fetchColumn(),
    'listeners'  => (int)$db->query("SELECT COUNT(*) FROM listeners WHERE last_seen>=datetime('now','-90 seconds')")->fetchColumn(),
    'surveys'    => (int)$db->query('SELECT COUNT(*) FROM surveys')->fetchColumn(),
    'suger_pend' => (int)$db->query("SELECT COUNT(*) FROM stations WHERE source='sugerencia' AND approved=0")->fetchColumn(),
];

// Encuesta bienvenida — rating
$welcome_rating = $db->query(
    "SELECT rating, COUNT(*) AS cnt FROM surveys WHERE station_id IS NULL GROUP BY rating ORDER BY rating DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$wrating = [-1 => 0, 0 => 0, 1 => 0];
foreach ($welcome_rating as $r) $wrating[(int)$r['rating']] = (int)$r['cnt'];

// Encuesta bienvenida — location (¿desde dónde escuchás?)
$welcome_loc = $db->query(
    "SELECT location, COUNT(*) AS cnt FROM surveys
     WHERE station_id IS NULL AND location IS NOT NULL AND location != ''
     GROUP BY location ORDER BY cnt DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$loc_icons = ['casa' => '🏠', 'trabajo' => '💼', 'viaje' => '🚗', 'caminando' => '📱'];

// Encuestas por emisora (top 40)
$station_surveys = $db->query(
    "SELECT s.nombre, s.slug,
            SUM(CASE WHEN sv.rating=1  THEN 1 ELSE 0 END) AS pos,
            SUM(CASE WHEN sv.rating=0  THEN 1 ELSE 0 END) AS neu,
            SUM(CASE WHEN sv.rating=-1 THEN 1 ELSE 0 END) AS neg,
            COUNT(*) AS total,
            MAX(sv.created_at) AS ultima
     FROM surveys sv
     JOIN stations s ON s.id = sv.station_id
     WHERE sv.station_id IS NOT NULL
     GROUP BY sv.station_id
     ORDER BY total DESC
     LIMIT 40"
)->fetchAll(PDO::FETCH_ASSOC);

// Sugerencias pendientes
try { $db->exec('ALTER TABLE stations ADD COLUMN contacto TEXT'); } catch (Exception $e) {}
$sugerencias = $db->query(
    "SELECT id, nombre, url, provincia, homepage, contacto, created_at
     FROM stations WHERE source='sugerencia' AND approved=0
     ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Últimas ejecuciones de crawlers
$crawler_runs = $db->query(
    "SELECT crawler, started_at, finished_at,
            ROUND((julianday(finished_at)-julianday(started_at))*86400) AS secs,
            stations_checked, changes_detected, errors, notes
     FROM crawler_runs ORDER BY started_at DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

// Estado Telegram
$notify_db  = $db->query("SELECT value FROM settings WHERE key='notify_oyentes' LIMIT 1")->fetchColumn();
$notify_val = $notify_db !== false ? $notify_db === '1' : (defined('NOTIFY_OYENTES') && NOTIFY_OYENTES);

// Shares recientes (últimas 100)
$shares_recientes = $db->query(
    "SELECT sh.created_at, sh.channel, sh.ip_hash, sh.slug,
            s.nombre
     FROM shares sh
     LEFT JOIN stations s ON s.id = sh.station_id
     ORDER BY sh.created_at DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

// Detalle de encuestas con ip_hash (últimas 100)
$surveys_detalle = $db->query(
    "SELECT sv.rating, sv.location, sv.ip_hash, sv.created_at,
            s.nombre, s.slug
     FROM surveys sv
     LEFT JOIN stations s ON s.id = sv.station_id
     ORDER BY sv.created_at DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

// Plays recientes (últimas 200)
$plays_recientes = $db->query(
    "SELECT p.played_at, p.ended_at, p.ip_hash, p.source, p.session_id,
            s.nombre, s.slug,
            CASE
              WHEN p.ended_at IS NOT NULL
                THEN ROUND((julianday(p.ended_at) - julianday(p.played_at)) * 86400)
              WHEN l.sid IS NOT NULL
                THEN ROUND((julianday('now')       - julianday(p.played_at)) * 86400)
              ELSE NULL
            END AS duration_secs,
            CASE WHEN l.sid IS NOT NULL THEN 1 ELSE 0 END AS is_active
     FROM plays p
     LEFT JOIN stations s ON s.id = p.station_id
     LEFT JOIN listeners l ON l.sid = p.session_id
     ORDER BY p.played_at DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

// ICY cache — resumen
$icy = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN stream_title IS NOT NULL AND stream_title!='' THEN 1 ELSE 0 END) AS con_titulo,
            MAX(last_checked) AS ultima
     FROM icy_cache WHERE supported=1"
)->fetch(PDO::FETCH_ASSOC);

// ICY activas (con título, las más recientes)
$icy_activas = $db->query(
    "SELECT s.nombre, s.slug, ic.stream_title, ic.last_checked,
            ROUND((julianday('now')-julianday(ic.last_checked))*1440) AS mins_ago
     FROM icy_cache ic
     JOIN stations s ON s.id = ic.station_id
     WHERE ic.supported=1 AND ic.stream_title IS NOT NULL AND ic.stream_title!=''
     ORDER BY ic.last_checked DESC
     LIMIT 60"
)->fetchAll(PDO::FETCH_ASSOC);

// ── HTML ──────────────────────────────────────────────────────────────────────

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ago(?string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt . ' UTC');
    if ($diff < 60)    return 'hace ' . $diff . 's';
    if ($diff < 3600)  return 'hace ' . floor($diff/60) . 'min';
    if ($diff < 86400) return 'hace ' . floor($diff/3600) . 'h';
    return 'hace ' . floor($diff/86400) . 'd';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin — Radio Argentina</title>
<style>
:root{--bg:#0f172a;--card:#1e293b;--card2:#263249;--border:#334155;--text:#e2e8f0;--muted:#94a3b8;--accent:#3b82f6;--green:#22c55e;--red:#ef4444;--yellow:#f59e0b}
body.light{--bg:#f1f5f9;--card:#ffffff;--card2:#f8fafc;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--accent:#2563eb;--green:#16a34a;--red:#dc2626;--yellow:#d97706}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif;padding:16px;transition:background .2s,color .2s}
h1{font-size:20px}
h2{font-size:15px;color:var(--accent);margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.cards{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px}
.card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 18px;min-width:120px}
.card .v{font-size:26px;font-weight:700;color:var(--accent)}
.card .l{font-size:11px;color:var(--muted);margin-top:2px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px}
th{text-align:left;padding:7px 10px;background:var(--card2);color:var(--muted);font-weight:600;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid var(--border);vertical-align:top}
tr:hover td{background:var(--card2)}
.pos{color:var(--green)} .neu{color:var(--yellow)} .neg{color:var(--red)}
.badge-ok{color:var(--green)} .badge-err{color:var(--red)} .badge-warn{color:var(--yellow)}
.url{font-size:11px;color:var(--muted);word-break:break-all}
form.inline{display:inline}
button{cursor:pointer;border:none;border-radius:4px;padding:4px 10px;font-size:12px;font-weight:600}
.btn-ok{background:#16a34a;color:#fff} .btn-ok:hover{background:#15803d}
.btn-del{background:#b91c1c;color:#fff} .btn-del:hover{background:#991b1b}
.btn-out{background:var(--card);border:1px solid var(--border);color:var(--muted);padding:5px 12px;font-size:13px;border-radius:6px}
.btn-out:hover{color:var(--text)}
.top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:10px;flex-wrap:wrap}
.top-actions{display:flex;gap:8px;align-items:center}
.mins-ok{color:var(--green)} .mins-warn{color:var(--yellow)} .mins-old{color:var(--red)}
a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
.empty{color:var(--muted);font-style:italic;padding:10px 0}
.note{font-size:12px;color:var(--muted);margin-top:6px}
.welcome-row{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin-bottom:12px}
.welcome-block{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px 18px;min-width:180px}
.welcome-block h3{font-size:12px;color:var(--muted);font-weight:600;margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em}
.loc-bar{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px}
.loc-bar-fill{height:8px;border-radius:4px;background:var(--accent);min-width:4px;transition:width .3s}
</style>
</head>
<body>
<script>
if(localStorage.getItem('radio_theme')==='light')document.body.classList.add('light');
</script>

<div class="top-bar">
  <h1>📻 Radio Argentina — Admin v2</h1>
  <div class="top-actions">
    <span id="refresh-ind" style="font-size:11px;color:var(--muted)"></span>
    <button class="btn-out" id="theme-btn" onclick="toggleTheme()">☀️ Claro</button>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="logout">
      <button class="btn-out" type="submit">Cerrar sesión</button>
    </form>
  </div>
</div>
<script>
var themeBtn = document.getElementById('theme-btn');
function toggleTheme() {
  var light = document.body.classList.toggle('light');
  localStorage.setItem('radio_theme', light ? 'light' : 'dark');
  themeBtn.textContent = light ? '🌙 Oscuro' : '☀️ Claro';
}
// Sincronizar texto del botón con estado actual
if (document.body.classList.contains('light')) themeBtn.textContent = '🌙 Oscuro';
</script>

<!-- ── Telegram ────────────────────────────────────────────────────────────── -->
<h2 id="telegram">Notificaciones Telegram</h2>
<div style="display:flex;align-items:center;gap:16px;margin-bottom:8px">
  <span style="font-size:14px">
    Estado actual:
    <strong style="color:<?= $notify_val ? 'var(--green)' : 'var(--muted)' ?>">
      <?= $notify_val ? '● Activas' : '● Inactivas' ?>
    </strong>
    <span style="font-size:12px;color:var(--muted)">(oyentes nuevos + compartidos)</span>
  </span>
  <form method="post" style="margin:0">
    <input type="hidden" name="action" value="toggle_notify">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <button class="<?= $notify_val ? 'btn-del' : 'btn-ok' ?>" type="submit">
      <?= $notify_val ? '⏸ Desactivar' : '▶ Activar' ?>
    </button>
  </form>
</div>

<!-- ── Resumen ─────────────────────────────────────────────────────────────── -->
<h2 id="resumen">Resumen</h2>
<div class="cards">
  <div class="card"><div class="v" id="stat-total"><?= $stats['total'] ?></div><div class="l">Emisoras activas</div></div>
  <div class="card"><div class="v badge-ok" id="stat-ok"><?= $stats['ok'] ?></div><div class="l">Streams OK</div></div>
  <div class="card"><div class="v" id="stat-icy"><?= $stats['icy'] ?></div><div class="l">Con ICY</div></div>
  <div class="card"><div class="v pos" id="stat-icy-activo"><?= $stats['icy_activo'] ?></div><div class="l">ICY con título ahora</div></div>
  <div class="card"><div class="v" id="stat-plays-hoy"><?= $stats['plays_hoy'] ?></div><div class="l">Plays hoy</div></div>
  <div class="card"><div class="v" id="stat-plays-total"><?= $stats['plays_total'] ?></div><div class="l">Plays totales</div></div>
  <div class="card"><div class="v pos" id="stat-listeners"><?= $stats['listeners'] ?></div><div class="l">Oyentes ahora</div></div>
  <div class="card"><div class="v"><?= $stats['surveys'] ?></div><div class="l">Encuestas recibidas</div></div>
  <div class="card"><div class="v <?= $stats['suger_pend'] > 0 ? 'neg' : '' ?>"><?= $stats['suger_pend'] ?></div><div class="l">Sugerencias pendientes</div></div>
</div>

<!-- ── Encuestas ───────────────────────────────────────────────────────────── -->
<h2 id="encuestas">Encuestas</h2>

<?php
$w_total = array_sum($wrating);
$loc_total = array_sum(array_column($welcome_loc, 'cnt'));
?>
<div class="welcome-row">
  <div class="welcome-block">
    <h3>¿Qué te parece el sitio? (<?= $w_total ?>)</h3>
    <?php if ($w_total > 0): ?>
      <?php foreach ([1=>'👍 Me gusta', 0=>'😐 Regular', -1=>'👎 No me convence'] as $r => $lbl): ?>
      <div class="loc-bar">
        <span style="min-width:130px"><?= $lbl ?></span>
        <div class="loc-bar-fill" style="width:<?= $w_total > 0 ? round($wrating[$r]/$w_total*120) : 0 ?>px"></div>
        <span style="color:var(--muted)"><?= $wrating[$r] ?></span>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="empty" style="padding:6px 0">Sin respuestas aún.</p>
    <?php endif; ?>
  </div>

  <div class="welcome-block">
    <h3>¿Desde dónde escuchás? (<?= $loc_total ?>)</h3>
    <?php if ($welcome_loc): ?>
      <?php foreach ($welcome_loc as $loc): ?>
      <div class="loc-bar">
        <span style="min-width:130px"><?= ($loc_icons[$loc['location']] ?? '📍') . ' ' . h(ucfirst($loc['location'])) ?></span>
        <div class="loc-bar-fill" style="width:<?= $loc_total > 0 ? round($loc['cnt']/$loc_total*120) : 0 ?>px"></div>
        <span style="color:var(--muted)"><?= (int)$loc['cnt'] ?></span>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="empty" style="padding:6px 0">Sin respuestas aún.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($station_surveys): ?>
<table>
  <thead><tr>
    <th>Emisora</th><th>👍</th><th>😐</th><th>👎</th><th>Total</th><th>Última</th>
  </tr></thead>
  <tbody>
  <?php foreach ($station_surveys as $sv): ?>
  <tr>
    <td><a href="/radio/<?= h($sv['slug']) ?>/" target="_blank"><?= h($sv['nombre']) ?></a></td>
    <td class="pos"><?= $sv['pos'] ?></td>
    <td class="neu"><?= $sv['neu'] ?></td>
    <td class="neg"><?= $sv['neg'] ?></td>
    <td><?= $sv['total'] ?></td>
    <td style="color:var(--muted);font-size:12px"><?= ago($sv['ultima']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p class="empty">Sin encuestas de emisoras todavía.</p>
<?php endif; ?>

<!-- ── Compartidos ─────────────────────────────────────────────────────────── -->
<h2 id="shares">Compartidos recientes (últimas 100)</h2>
<?php $ch_labels = ['copy' => '🔗 Link', 'wa' => '💬 WhatsApp', 'qr' => '⬛ QR']; ?>
<table>
  <thead><tr>
    <th>Fecha / Hora</th><th>Emisora</th><th>Canal</th><th>IP hash</th>
  </tr></thead>
  <tbody id="shares-body">
  <?php if ($shares_recientes): foreach ($shares_recientes as $sh): ?>
  <tr>
    <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($sh['created_at'],0,19))) ?></td>
    <td><?php if ($sh['slug']): ?><a href="/radio/<?= h($sh['slug']) ?>/" target="_blank"><?= h($sh['nombre'] ?? $sh['slug']) ?></a><?php else: ?>—<?php endif; ?></td>
    <td><?= $ch_labels[$sh['channel']] ?? h($sh['channel']) ?></td>
    <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($sh['ip_hash'] ?? '', 0, 16)) ?>…</td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="4" class="empty">Sin compartidos registrados todavía.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<!-- ── Detalle encuestas ───────────────────────────────────────────────────── -->
<h2 id="encuestas-detalle">Encuestas — detalle (últimas 100)</h2>
<p class="note" style="margin-bottom:10px">IP hasheada: identificador anónimo consistente (misma IP = mismo hash).</p>
<?php if ($surveys_detalle): ?>
<table>
  <thead><tr>
    <th>Fecha</th><th>Rating</th><th>Emisora</th><th>Ubicación</th><th>IP hash</th>
  </tr></thead>
  <tbody>
  <?php foreach ($surveys_detalle as $sv):
    $rlbl = $sv['rating'] ==  1 ? '<span class="pos">👍</span>'
          : ($sv['rating'] == -1 ? '<span class="neg">👎</span>'
                                 : '<span class="neu">😐</span>');
  ?>
  <tr>
    <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($sv['created_at'],0,19))) ?></td>
    <td><?= $rlbl ?></td>
    <td><?php if ($sv['slug']): ?><a href="/radio/<?= h($sv['slug']) ?>/" target="_blank"><?= h($sv['nombre'] ?? '—') ?></a><?php else: ?><span style="color:var(--muted)">bienvenida</span><?php endif; ?></td>
    <td style="color:var(--muted)"><?= $sv['location'] ? h(ucfirst($sv['location'])) : '—' ?></td>
    <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($sv['ip_hash'] ?? '', 0, 16)) ?>…</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p class="empty">Sin encuestas todavía.</p>
<?php endif; ?>

<!-- ── Reproducciones ──────────────────────────────────────────────────────── -->
<h2 id="plays">Reproducciones recientes (últimas 200)</h2>
<?php
function fmt_duration(?int $secs): string {
    if ($secs === null) return '—';
    if ($secs < 60)   return $secs . 's';
    if ($secs < 3600) return floor($secs/60) . 'm ' . ($secs%60) . 's';
    return floor($secs/3600) . 'h ' . floor(($secs%3600)/60) . 'm';
}
?>
<table>
  <thead><tr>
    <th>Fecha / Hora</th><th>Emisora</th><th>Duración</th><th>Origen</th><th>IP hash</th><th>Sesión</th>
  </tr></thead>
  <tbody id="plays-body">
  <?php if ($plays_recientes): foreach ($plays_recientes as $pl): ?>
  <tr>
    <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($pl['played_at'],0,19))) ?></td>
    <td><?php if ($pl['slug']): ?><a href="/radio/<?= h($pl['slug']) ?>/" target="_blank"><?= h($pl['nombre'] ?? '—') ?></a><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
    <td style="font-size:12px;white-space:nowrap">
      <?php if ($pl['is_active']): ?>
        <span style="color:#22c55e">▶ <?= fmt_duration((int)$pl['duration_secs']) ?></span>
      <?php else: ?>
        <?= fmt_duration(isset($pl['duration_secs']) ? (int)$pl['duration_secs'] : null) ?>
      <?php endif; ?>
    </td>
    <td style="font-size:12px;color:var(--muted)"><?= h($pl['source'] ?? '—') ?></td>
    <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($pl['ip_hash'] ?? '', 0, 16)) ?>…</td>
    <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($pl['session_id'] ?? '', 0, 12)) ?>…</td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="6" class="empty" id="plays-empty">Sin reproducciones registradas todavía.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<!-- ── Sugerencias ─────────────────────────────────────────────────────────── -->
<h2 id="sugerencias">Sugerencias pendientes (<?= count($sugerencias) ?>)</h2>

<?php if ($sugerencias): ?>
<table>
  <thead><tr>
    <th>Nombre</th><th>URL</th><th>Provincia</th><th>Contacto</th><th>Recibida</th><th>Acción</th>
  </tr></thead>
  <tbody>
  <?php foreach ($sugerencias as $sg): ?>
  <tr>
    <td>
      <?= h($sg['nombre']) ?>
      <?php if ($sg['homepage']): ?>
        <br><a href="<?= h($sg['homepage']) ?>" target="_blank" rel="noopener" style="font-size:11px">homepage ↗</a>
      <?php endif; ?>
    </td>
    <td class="url"><a href="<?= h($sg['url']) ?>" target="_blank" rel="noopener"><?= h($sg['url']) ?></a></td>
    <td><?= h($sg['provincia'] ?? '—') ?></td>
    <td style="font-size:12px"><?= $sg['contacto'] ? h($sg['contacto']) : '<span style="color:var(--muted)">—</span>' ?></td>
    <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= ago($sg['created_at']) ?></td>
    <td style="white-space:nowrap">
      <form class="inline" method="post">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="id" value="<?= (int)$sg['id'] ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="btn-ok" type="submit" onclick="return confirm('¿Aprobar <?= h(addslashes($sg['nombre'])) ?>?')">✓ Aprobar</button>
      </form>
      &nbsp;
      <form class="inline" method="post">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="id" value="<?= (int)$sg['id'] ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="btn-del" type="submit" onclick="return confirm('¿Eliminar esta sugerencia?')">✕ Rechazar</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p class="empty">No hay sugerencias pendientes.</p>
<?php endif; ?>

<!-- ── ICY activas ─────────────────────────────────────────────────────────── -->
<h2 id="icy">ICY — títulos en tiempo real</h2>
<p class="note" style="margin-bottom:10px">
  <?= $icy['total'] ?> emisoras con soporte ICY &nbsp;·&nbsp;
  <?= $icy['con_titulo'] ?> con título activo &nbsp;·&nbsp;
  Última actualización: <strong><?= ago($icy['ultima'] ?? null) ?></strong>
</p>

<?php if ($icy_activas): ?>
<table>
  <thead><tr><th>Emisora</th><th>Sonando ahora</th><th>Actualizado</th></tr></thead>
  <tbody>
  <?php foreach ($icy_activas as $ic):
    $mins = (int)($ic['mins_ago'] ?? 0);
    $cls  = $mins <= 15 ? 'mins-ok' : ($mins <= 60 ? 'mins-warn' : 'mins-old');
  ?>
  <tr>
    <td><a href="/radio/<?= h($ic['slug']) ?>/" target="_blank"><?= h($ic['nombre']) ?></a></td>
    <td><?= h($ic['stream_title']) ?></td>
    <td class="<?= $cls ?>" style="font-size:12px;white-space:nowrap"><?= ago($ic['last_checked']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p class="empty">Sin títulos ICY activos.</p>
<?php endif; ?>

<!-- ── Crawlers ────────────────────────────────────────────────────────────── -->
<h2 id="crawlers">Crawlers — últimas ejecuciones</h2>

<?php if ($crawler_runs): ?>
<table>
  <thead><tr>
    <th>Crawler</th><th>Inicio</th><th>Duración</th>
    <th>Chequeadas</th><th>Cambios</th><th>Errores</th><th>Notas</th>
  </tr></thead>
  <tbody>
  <?php foreach ($crawler_runs as $cr): ?>
  <tr>
    <td><strong><?= h($cr['crawler']) ?></strong></td>
    <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= ago($cr['started_at']) ?></td>
    <td style="white-space:nowrap">
      <?php if ($cr['secs'] !== null):
        $s = (int)$cr['secs'];
        echo $s >= 60 ? floor($s/60).'min '.($s%60).'s' : $s.'s';
      else: echo '—'; endif; ?>
    </td>
    <td><?= $cr['stations_checked'] ?: '—' ?></td>
    <td class="<?= $cr['changes_detected'] > 0 ? 'pos' : '' ?>"><?= $cr['changes_detected'] ?: '—' ?></td>
    <td style="color:var(--muted)"><?= $cr['errors'] ?: '—' ?></td>
    <td style="font-size:12px;color:var(--muted)"><?= h($cr['notes'] ?? '') ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p class="empty">Sin ejecuciones registradas todavía.</p>
<?php endif; ?>

<p class="note" style="margin-top:8px">
  "Sin título" = emisoras que en ese momento no devolvieron StreamTitle (offline, silencio, ICY vacío). No son errores del cron.
</p>

<p style="margin-top:32px;font-size:11px;color:var(--border);text-align:center">
  Radio Argentina Admin · <?= gmdate('Y-m-d H:i') ?> UTC
</p>

<script>
(function () {
  function esc(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function fmtDur(s) {
    if (s == null) return '—';
    s = parseInt(s, 10);
    if (s < 60)   return s + 's';
    if (s < 3600) return Math.floor(s/60) + 'm ' + (s%60) + 's';
    return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
  }

  var CH = {copy: '🔗 Link', wa: '💬 WhatsApp', qr: '⬛ QR'};

  function upd(id, val) {
    var el = document.getElementById(id);
    if (el && val !== undefined) el.textContent = val;
  }

  function refreshAdmin() {
    fetch(location.pathname + '?ajax=1&_=' + Date.now())
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;

        // Stats
        var s = d.stats || {};
        upd('stat-total',      s.total);
        upd('stat-ok',         s.ok);
        upd('stat-icy',        s.icy);
        upd('stat-icy-activo', s.icy_activo);
        upd('stat-plays-hoy',  s.plays_hoy);
        upd('stat-plays-total',s.plays_total);
        upd('stat-listeners',  s.listeners);

        // Plays
        var pb = document.getElementById('plays-body');
        if (pb && d.plays) {
          pb.innerHTML = d.plays.map(function (p) {
            var dur = p.is_active
              ? '<span style="color:#22c55e">▶ ' + esc(fmtDur(p.duration_secs)) + '</span>'
              : esc(fmtDur(p.duration_secs != null ? p.duration_secs : null));
            var nom = p.slug
              ? '<a href="/radio/' + esc(p.slug) + '/" target="_blank">' + esc(p.nombre || '—') + '</a>'
              : '<span style="color:var(--muted)">—</span>';
            var dt = (p.played_at || '').replace('T', ' ').substring(0, 19);
            return '<tr>'
              + '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' + esc(dt) + '</td>'
              + '<td>' + nom + '</td>'
              + '<td style="font-size:12px;white-space:nowrap">' + dur + '</td>'
              + '<td style="font-size:12px;color:var(--muted)">' + esc(p.source || '—') + '</td>'
              + '<td style="font-size:11px;color:var(--muted);font-family:monospace">' + esc((p.ip_hash || '').substring(0,16)) + '…</td>'
              + '<td style="font-size:11px;color:var(--muted);font-family:monospace">' + esc((p.session_id || '').substring(0,12)) + '…</td>'
              + '</tr>';
          }).join('');
        }

        // Shares
        var sb = document.getElementById('shares-body');
        if (sb && d.shares) {
          sb.innerHTML = d.shares.map(function (sh) {
            var nom = sh.slug
              ? '<a href="/radio/' + esc(sh.slug) + '/" target="_blank">' + esc(sh.nombre || sh.slug) + '</a>'
              : '—';
            var dt = (sh.created_at || '').replace('T', ' ').substring(0, 19);
            return '<tr>'
              + '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' + esc(dt) + '</td>'
              + '<td>' + nom + '</td>'
              + '<td>' + esc(CH[sh.channel] || sh.channel) + '</td>'
              + '<td style="font-size:11px;color:var(--muted);font-family:monospace">' + esc((sh.ip_hash || '').substring(0,16)) + '…</td>'
              + '</tr>';
          }).join('');
        }

        // Indicador
        var ind = document.getElementById('refresh-ind');
        if (ind) ind.textContent = '↻ ' + new Date().toLocaleTimeString('es-AR');
      })
      .catch(function () {});
  }

  setInterval(refreshAdmin, 10000);
}());
</script>
</body>
</html>
<?php

// ── Login page ────────────────────────────────────────────────────────────────

function login_page(bool $err): void {
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin — Radio Argentina</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f1f5f9;color:#1e293b;font:14px/1.5 system-ui,sans-serif;
     display:flex;align-items:center;justify-content:center;min-height:100vh}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;width:320px;
     box-shadow:0 4px 24px rgba(0,0,0,.07)}
h1{font-size:18px;margin-bottom:20px;text-align:center}
label{display:block;font-size:12px;color:#64748b;margin-bottom:4px}
input{width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;
      padding:9px 12px;color:#1e293b;font-size:14px;margin-bottom:14px;outline:none}
input:focus{border-color:#3b82f6;background:#fff}
button{width:100%;background:#3b82f6;color:#fff;border:none;border-radius:6px;
       padding:10px;font-size:14px;font-weight:600;cursor:pointer;margin-top:4px}
button:hover{background:#2563eb}
.err{color:#dc2626;font-size:13px;text-align:center;margin-bottom:12px}
</style>
</head>
<body>
<div class="box">
  <h1>📻 Admin</h1>
  <?php if ($err): ?><p class="err">Usuario o contraseña incorrectos</p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <label>Usuario</label>
    <input type="text" name="u" autocomplete="username" autofocus>
    <label>Contraseña</label>
    <input type="password" name="p" autocomplete="current-password">
    <button type="submit">Entrar</button>
  </form>
</div>
</body>
</html>
<?php
}
