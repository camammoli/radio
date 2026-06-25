<?php
/**
 * admin.php — Panel de administración Radio Argentina v2.
 * Autenticación por sesión. No indexado.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

session_start();

$ADMIN_USER = defined('ADMIN_USER') ? ADMIN_USER : 'admin';
$ADMIN_PASS = defined('ADMIN_PASS') ? ADMIN_PASS : (defined('RADIO_ADMIN_KEY') ? RADIO_ADMIN_KEY : 'mammoli_radio_2026');

// ── Auth ──────────────────────────────────────────────────────────────────────

$act = $_POST['action'] ?? '';

if ($act === 'login') {
    if (($_POST['u'] ?? '') === $ADMIN_USER && ($_POST['p'] ?? '') === $ADMIN_PASS) {
        $_SESSION['radio_admin'] = true;
        $_SESSION['csrf']        = bin2hex(random_bytes(16));
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $login_err = true;
}
if ($act === 'logout') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (empty($_SESSION['radio_admin'])) {
    login_page($login_err ?? false);
    exit;
}

$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$db   = radio_db();

// ── Acciones sobre sugerencias ────────────────────────────────────────────────

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

// Encuesta bienvenida (station_id IS NULL = slug _welcome_v2)
$welcome = $db->query(
    "SELECT rating, COUNT(*) AS cnt FROM surveys WHERE station_id IS NULL GROUP BY rating ORDER BY rating DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$welcome_map = [-1 => 0, 0 => 0, 1 => 0];
foreach ($welcome as $r) $welcome_map[(int)$r['rating']] = (int)$r['cnt'];

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
$sugerencias = $db->query(
    "SELECT id, nombre, url, provincia, homepage, created_at
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

// ICY cache — resumen
$icy = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN stream_title IS NOT NULL AND stream_title!='' THEN 1 ELSE 0 END) AS con_titulo,
            MAX(last_checked) AS ultima,
            MIN(last_checked) AS primera_sin_refresh
     FROM icy_cache WHERE supported=1"
)->fetch(PDO::FETCH_ASSOC);

// ICY activas (con título reciente < 30 min)
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
    if ($diff < 60)   return 'hace ' . $diff . 's';
    if ($diff < 3600) return 'hace ' . floor($diff/60) . 'min';
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
:root{--bg:#0f172a;--card:#1e293b;--border:#334155;--text:#e2e8f0;--muted:#94a3b8;--accent:#3b82f6;--green:#22c55e;--red:#ef4444;--yellow:#f59e0b}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif;padding:16px}
h1{font-size:20px;margin-bottom:16px}
h2{font-size:15px;color:var(--accent);margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.cards{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px}
.card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 18px;min-width:120px}
.card .v{font-size:26px;font-weight:700;color:var(--accent)}
.card .l{font-size:11px;color:var(--muted);margin-top:2px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px}
th{text-align:left;padding:7px 10px;background:var(--card);color:var(--muted);font-weight:600;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid var(--border);vertical-align:top}
tr:hover td{background:rgba(255,255,255,.02)}
.pos{color:var(--green)} .neu{color:var(--yellow)} .neg{color:var(--red)}
.badge-ok{color:var(--green)} .badge-err{color:var(--red)} .badge-warn{color:var(--yellow)}
.url{font-size:11px;color:var(--muted);word-break:break-all}
form.inline{display:inline}
button{cursor:pointer;border:none;border-radius:4px;padding:4px 10px;font-size:12px;font-weight:600}
.btn-ok{background:#16a34a;color:#fff} .btn-ok:hover{background:#15803d}
.btn-del{background:#b91c1c;color:#fff} .btn-del:hover{background:#991b1b}
.btn-out{background:transparent;border:1px solid var(--border);color:var(--muted);padding:4px 12px;font-size:13px}
.btn-out:hover{color:var(--text)}
.top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.mins-ok{color:var(--green)} .mins-warn{color:var(--yellow)} .mins-old{color:var(--red)}
a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
.empty{color:var(--muted);font-style:italic;padding:10px 0}
.note{font-size:12px;color:var(--muted);margin-top:6px}
</style>
</head>
<body>

<div class="top-bar">
  <h1>📻 Radio Argentina — Admin v2</h1>
  <form method="post">
    <input type="hidden" name="action" value="logout">
    <button class="btn-out" type="submit">Cerrar sesión</button>
  </form>
</div>

<!-- ── Resumen ─────────────────────────────────────────────────────────────── -->
<h2 id="resumen">Resumen</h2>
<div class="cards">
  <div class="card"><div class="v"><?= $stats['total'] ?></div><div class="l">Emisoras activas</div></div>
  <div class="card"><div class="v badge-ok"><?= $stats['ok'] ?></div><div class="l">Streams OK</div></div>
  <div class="card"><div class="v"><?= $stats['icy'] ?></div><div class="l">Con ICY</div></div>
  <div class="card"><div class="v pos"><?= $stats['icy_activo'] ?></div><div class="l">ICY con título ahora</div></div>
  <div class="card"><div class="v"><?= $stats['plays_hoy'] ?></div><div class="l">Plays hoy</div></div>
  <div class="card"><div class="v"><?= $stats['plays_total'] ?></div><div class="l">Plays totales</div></div>
  <div class="card"><div class="v pos"><?= $stats['listeners'] ?></div><div class="l">Oyentes ahora</div></div>
  <div class="card"><div class="v"><?= $stats['surveys'] ?></div><div class="l">Encuestas recibidas</div></div>
  <div class="card"><div class="v <?= $stats['suger_pend'] > 0 ? 'neg' : '' ?>"><?= $stats['suger_pend'] ?></div><div class="l">Sugerencias pendientes</div></div>
</div>

<!-- ── Encuestas ───────────────────────────────────────────────────────────── -->
<h2 id="encuestas">Encuestas</h2>

<p style="margin-bottom:10px;color:var(--muted);font-size:13px">
  <strong style="color:var(--text)">Toast bienvenida v2</strong> —
  <span class="pos">👍 <?= $welcome_map[1] ?></span> &nbsp;
  <span class="neu">😐 <?= $welcome_map[0] ?></span> &nbsp;
  <span class="neg">👎 <?= $welcome_map[-1] ?></span>
  &nbsp; (total: <?= array_sum($welcome_map) ?>)
</p>

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

<!-- ── Sugerencias ─────────────────────────────────────────────────────────── -->
<h2 id="sugerencias">Sugerencias pendientes (<?= count($sugerencias) ?>)</h2>

<?php if ($sugerencias): ?>
<table>
  <thead><tr>
    <th>Nombre</th><th>URL</th><th>Provincia</th><th>Recibida</th><th>Acción</th>
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
    <td class="<?= $cls ?>" style="font-size:12px;white-space:nowrap">
      <?= ago($ic['last_checked']) ?>
    </td>
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
      <?php if ($cr['secs'] !== null): ?>
        <?= $cr['secs'] >= 60
            ? floor($cr['secs']/60) . 'min ' . ($cr['secs']%60) . 's'
            : $cr['secs'] . 's' ?>
      <?php else: echo '—'; endif; ?>
    </td>
    <td><?= $cr['stations_checked'] ?: '—' ?></td>
    <td class="<?= $cr['changes_detected'] > 0 ? 'pos' : '' ?>"><?= $cr['changes_detected'] ?: '—' ?></td>
    <td class="<?= $cr['errors'] > 0 ? 'neg' : '' ?>"><?= $cr['errors'] ?: '—' ?></td>
    <td style="font-size:12px;color:var(--muted)"><?= h($cr['notes'] ?? '') ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p class="empty">Sin ejecuciones registradas todavía.</p>
<?php endif; ?>

<p class="note">
  Los crawlers Python (check-streams, hunt-stations) registran aquí sus runs.
  El cron PHP (icy_refresh) muestra sus datos en la sección ICY de arriba.
</p>

<p style="margin-top:32px;font-size:11px;color:var(--border);text-align:center">
  Radio Argentina Admin · <?= date('Y-m-d H:i') ?> UTC
</p>

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
body{background:#0f172a;color:#e2e8f0;font:14px/1.5 system-ui,sans-serif;
     display:flex;align-items:center;justify-content:center;min-height:100vh}
.box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px;width:320px}
h1{font-size:18px;margin-bottom:20px;text-align:center}
label{display:block;font-size:12px;color:#94a3b8;margin-bottom:4px}
input{width:100%;background:#0f172a;border:1px solid #334155;border-radius:6px;
      padding:9px 12px;color:#e2e8f0;font-size:14px;margin-bottom:14px;outline:none}
input:focus{border-color:#3b82f6}
button{width:100%;background:#3b82f6;color:#fff;border:none;border-radius:6px;
       padding:10px;font-size:14px;font-weight:600;cursor:pointer;margin-top:4px}
button:hover{background:#2563eb}
.err{color:#ef4444;font-size:13px;text-align:center;margin-bottom:12px}
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
