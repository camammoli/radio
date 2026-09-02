<?php
/**
 * admin_stats.php — Estadísticas de oyentes · Radio Argentina Admin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

session_start();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (empty($_SESSION['radio_admin'])) {
    header('Location: admin.php');
    exit;
}

$db = radio_db();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Parámetros
$modo  = in_array($_GET['modo'] ?? '', ['dia', 'mes']) ? $_GET['modo'] : 'dia';
$rango = (int)($_GET['rango'] ?? ($modo === 'dia' ? 30 : 12));
if ($modo === 'dia') $rango = in_array($rango, [7, 30, 60, 90]) ? $rango : 30;
if ($modo === 'mes') $rango = in_array($rango, [6, 12, 24]) ? $rango : 12;

// ─── Queries ──────────────────────────────────────────────────────────────────

// "Hoy" en hora local Argentina (UTC-3), como expresión SQL reutilizable.
$hoyExpr = sql_date_local(db_engine() === 'mysql' ? 'NOW()' : "'now'", -3);
$diaExpr = sql_date_local('played_at', -3);

// Totales
$total_plays   = (int)$db->query('SELECT COUNT(*) FROM plays')->fetchColumn();
$total_oyentes = (int)$db->query('SELECT COUNT(DISTINCT ip_hash) FROM plays')->fetchColumn();
$plays_hoy     = (int)$db->query(
    "SELECT COUNT(*) FROM plays WHERE $diaExpr = $hoyExpr"
)->fetchColumn();
$oyentes_hoy   = (int)$db->query(
    "SELECT COUNT(DISTINCT ip_hash) FROM plays WHERE $diaExpr = $hoyExpr"
)->fetchColumn();

// Pico de concurrencia (máx listeners registrados en un rango de 5 min)
$rango5min = sql_offset('l1.started_at', 5, 'MINUTE');
$pico = (int)$db->query("
    SELECT MAX(cnt) FROM (
        SELECT COUNT(*) AS cnt
        FROM listeners l1
        JOIN listeners l2 ON l2.started_at BETWEEN l1.started_at AND $rango5min
        GROUP BY l1.sid
    ) x
")->fetchColumn();

// Plays por día (rango seleccionado, en hora local ARG = UTC-3)
$desde_dia = (new DateTime('now', new DateTimeZone('UTC')))->modify('-3 hours')->modify("-{$rango} days")->format('Y-m-d');
$stmt = $db->prepare("
    SELECT $diaExpr AS d,
           COUNT(*) AS plays,
           COUNT(DISTINCT ip_hash) AS uniq
    FROM plays
    WHERE $diaExpr >= :desde
    GROUP BY d
    ORDER BY d
");
$stmt->execute([':desde' => $desde_dia]);
$plays_dia = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Completar días faltantes en el rango (para que el eje sea continuo)
$dia_map   = [];
$date_start = new DateTime("-{$rango} days");
$date_end   = new DateTime('today');
$interval   = new DateInterval('P1D');
$period     = new DatePeriod($date_start, $interval, $date_end->modify('+1 day'));
foreach ($period as $d) $dia_map[$d->format('Y-m-d')] = ['plays' => 0, 'uniq' => 0];
foreach ($plays_dia as $r) {
    $dia_map[$r['d']] = ['plays' => (int)$r['plays'], 'uniq' => (int)$r['uniq']];
}
ksort($dia_map);

// Plays por mes (rango seleccionado)
$mesExpr = sql_month_local('played_at', -3);
$stmt = $db->prepare("
    SELECT $mesExpr AS mes,
           COUNT(*) AS plays,
           COUNT(DISTINCT ip_hash) AS uniq
    FROM plays
    GROUP BY mes
    ORDER BY mes DESC
    LIMIT :lim
");
$stmt->bindValue(':lim', $rango, PDO::PARAM_INT);
$stmt->execute();
$plays_mes = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

// Completar meses faltantes
$mes_map = [];
$now_m = new DateTime('first day of this month');
for ($i = $rango - 1; $i >= 0; $i--) {
    $m = (clone $now_m)->modify("-{$i} months")->format('Y-m');
    $mes_map[$m] = ['plays' => 0, 'uniq' => 0];
}
foreach ($plays_mes as $r) {
    if (isset($mes_map[$r['mes']])) {
        $mes_map[$r['mes']] = ['plays' => (int)$r['plays'], 'uniq' => (int)$r['uniq']];
    }
}

// Por hora del día (todo el tiempo, en hora local)
$horaExpr = sql_hour_local('played_at', -3);
$hora_raw = $db->query("
    SELECT $horaExpr AS hora,
           COUNT(*) AS plays
    FROM plays
    GROUP BY hora
    ORDER BY hora
")->fetchAll(PDO::FETCH_ASSOC);
$hora_map = array_fill(0, 24, 0);
foreach ($hora_raw as $r) $hora_map[(int)$r['hora']] = (int)$r['plays'];
// Etiquetas de hora
$hora_labels = [];
for ($h = 0; $h < 24; $h++) $hora_labels[] = sprintf('%02d:00', $h);

// Top emisoras
$duracion = sql_seconds_diff('p.played_at', 'p.ended_at');
$top_emisoras = $db->query("
    SELECT s.nombre, s.slug, COUNT(*) AS plays,
           COUNT(DISTINCT p.ip_hash) AS oyentes,
           SUM(CASE WHEN p.ended_at IS NOT NULL
                    THEN $duracion
                    ELSE 0 END) AS total_secs
    FROM plays p
    JOIN stations s ON s.id = p.station_id
    GROUP BY p.station_id
    ORDER BY plays DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Día más activo
$dia_top = $db->query("
    SELECT $diaExpr AS d, COUNT(*) AS n
    FROM plays GROUP BY d ORDER BY n DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Hora más activa
$hora_max = 0; $hora_max_plays = 0;
foreach ($hora_map as $h => $n) { if ($n > $hora_max_plays) { $hora_max_plays = $n; $hora_max = $h; } }

// Duración media (solo registros con ended_at)
$duracionMedia = sql_seconds_diff('played_at', 'ended_at');
$dur_media = (int)$db->query("
    SELECT AVG($duracionMedia)
    FROM plays WHERE ended_at IS NOT NULL
")->fetchColumn();

// Datos JSON para Chart.js
if ($modo === 'dia') {
    $chart_labels  = json_encode(array_keys($dia_map));
    $chart_plays   = json_encode(array_column($dia_map, 'plays'));
    $chart_oyentes = json_encode(array_column($dia_map, 'uniq'));
} else {
    $chart_labels  = json_encode(array_keys($mes_map));
    $chart_plays   = json_encode(array_column($mes_map, 'plays'));
    $chart_oyentes = json_encode(array_column($mes_map, 'uniq'));
}
$chart_hora  = json_encode(array_values($hora_map));
$top_nombres = json_encode(array_column($top_emisoras, 'nombre'));
$top_plays   = json_encode(array_column($top_emisoras, 'plays'));
$top_oyentes = json_encode(array_column($top_emisoras, 'oyentes'));

function fmt_dur(int $secs): string {
    if ($secs <= 0) return '—';
    if ($secs < 60) return $secs . 's';
    if ($secs < 3600) return floor($secs/60) . 'min';
    return floor($secs/3600) . 'h ' . floor(($secs%3600)/60) . 'min';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Estadísticas de oyentes — Radio Argentina</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
:root{--bg:#0f172a;--card:#1e293b;--card2:#263249;--border:#334155;--text:#e2e8f0;--muted:#94a3b8;--accent:#3b82f6;--green:#22c55e;--yellow:#f59e0b;--purple:#a78bfa}
body.light{--bg:#f1f5f9;--card:#ffffff;--card2:#f8fafc;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--accent:#2563eb;--green:#16a34a;--yellow:#d97706;--purple:#7c3aed}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif;padding:16px;min-height:100vh;transition:background .2s,color .2s}
h1{font-size:20px}
h2{font-size:15px;color:var(--accent);margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:10px;flex-wrap:wrap}
.top-actions{display:flex;gap:8px;align-items:center}
.btn-out{cursor:pointer;border:1px solid var(--border);border-radius:6px;background:var(--card);color:var(--muted);padding:5px 12px;font-size:13px;text-decoration:none}
.btn-out:hover{color:var(--text)}

/* Métricas */
.cards{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px}
.card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px 18px;min-width:130px;flex:1}
.card .v{font-size:28px;font-weight:700;color:var(--accent)}
.card .v.green{color:var(--green)}
.card .v.yellow{color:var(--yellow)}
.card .v.purple{color:var(--purple)}
.card .l{font-size:11px;color:var(--muted);margin-top:3px}

/* Selector modo + rango */
.controles{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:20px}
.tab-group{display:flex;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.tab-group a{padding:7px 16px;font-size:13px;color:var(--muted);text-decoration:none;transition:background .15s}
.tab-group a.active{background:var(--accent);color:#fff}
.tab-group a:hover:not(.active){background:var(--card2)}
.rango-group{display:flex;gap:6px}
.rango-group a{padding:5px 12px;border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--muted);text-decoration:none;background:var(--card)}
.rango-group a.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.rango-group a:hover:not(.active){background:var(--card2)}

/* Charts */
.chart-section{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:18px}
.chart-section h3{font-size:13px;font-weight:600;color:var(--muted);margin-bottom:14px;text-transform:uppercase;letter-spacing:.04em}
.chart-wrap{position:relative;height:240px}
.chart-wrap.tall{height:200px}

/* Sin datos */
.empty-chart{display:flex;align-items:center;justify-content:center;height:180px;color:var(--muted);font-size:13px;font-style:italic;flex-direction:column;gap:6px}
.empty-chart span{font-size:28px}

/* Tabla top */
.tabla{width:100%;border-collapse:collapse;font-size:13px}
.tabla th{text-align:left;padding:8px 10px;background:var(--card2);color:var(--muted);font-weight:600;border-bottom:1px solid var(--border)}
.tabla td{padding:7px 10px;border-bottom:1px solid var(--border)}
.tabla tr:last-child td{border-bottom:none}
.tabla tr:hover td{background:var(--card2)}
.mini-bar-wrap{width:120px;background:var(--border);border-radius:3px;height:8px;display:inline-block;vertical-align:middle}
.mini-bar{height:8px;background:var(--accent);border-radius:3px;transition:width .4s}

a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.note{font-size:12px;color:var(--muted);margin-top:8px}
</style>
</head>
<body>
<script>if(localStorage.getItem('radio_theme')==='light')document.body.classList.add('light');</script>

<div class="top-bar">
  <h1>📊 Estadísticas de oyentes</h1>
  <div class="top-actions">
    <a href="admin.php" class="btn-out">← Admin</a>
    <button class="btn-out" id="theme-btn" onclick="toggleTheme()">☀️ Claro</button>
  </div>
</div>
<script>
var themeBtn = document.getElementById('theme-btn');
function toggleTheme(){var l=document.body.classList.toggle('light');localStorage.setItem('radio_theme',l?'light':'dark');themeBtn.textContent=l?'🌙 Oscuro':'☀️ Claro';}
if(document.body.classList.contains('light'))themeBtn.textContent='🌙 Oscuro';
</script>

<!-- ── Métricas globales ───────────────────────────────────────────────────── -->
<div class="cards">
  <div class="card"><div class="v"><?= number_format($total_plays) ?></div><div class="l">Reproducciones totales</div></div>
  <div class="card"><div class="v green"><?= number_format($total_oyentes) ?></div><div class="l">Oyentes únicos (histórico)</div></div>
  <div class="card"><div class="v yellow"><?= number_format($plays_hoy) ?></div><div class="l">Plays hoy</div></div>
  <div class="card"><div class="v purple"><?= number_format($oyentes_hoy) ?></div><div class="l">Oyentes únicos hoy</div></div>
  <div class="card">
    <div class="v" style="font-size:22px"><?= $hora_max_plays > 0 ? sprintf('%02d:00', $hora_max) : '—' ?></div>
    <div class="l">Hora pico (<?= $hora_max_plays ?> plays)</div>
  </div>
  <div class="card">
    <div class="v" style="font-size:22px"><?= fmt_dur($dur_media) ?></div>
    <div class="l">Duración media sesión</div>
  </div>
  <?php if ($dia_top): ?>
  <div class="card">
    <div class="v" style="font-size:18px"><?= h($dia_top['d']) ?></div>
    <div class="l">Día récord (<?= $dia_top['n'] ?> plays)</div>
  </div>
  <?php endif; ?>
</div>

<!-- ── Controles ──────────────────────────────────────────────────────────── -->
<h2>Tendencia temporal</h2>
<div class="controles">
  <div class="tab-group">
    <a href="?modo=dia&rango=<?= $modo==='dia'?$rango:30 ?>" class="<?= $modo==='dia'?'active':'' ?>">Día a día</a>
    <a href="?modo=mes&rango=<?= $modo==='mes'?$rango:12 ?>" class="<?= $modo==='mes'?'active':'' ?>">Mes a mes</a>
  </div>

  <?php if ($modo === 'dia'): ?>
  <div class="rango-group">
    <?php foreach ([7=>'7 días',30=>'30 días',60=>'60 días',90=>'90 días'] as $v=>$l): ?>
    <a href="?modo=dia&rango=<?= $v ?>" class="<?= $rango===$v?'active':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="rango-group">
    <?php foreach ([6=>'6 meses',12=>'12 meses',24=>'24 meses'] as $v=>$l): ?>
    <a href="?modo=mes&rango=<?= $v ?>" class="<?= $rango===$v?'active':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Chart principal: plays + oyentes -->
<div class="chart-section">
  <h3>Reproducciones <?= $modo==='dia' ? "— últimos {$rango} días" : "— últimos {$rango} meses" ?></h3>
  <?php if ($total_plays === 0): ?>
  <div class="empty-chart">
    <span>📭</span>
    Sin datos todavía. Las reproducciones se registran cuando los oyentes usan el player.
  </div>
  <?php else: ?>
  <div class="chart-wrap">
    <canvas id="chart-main"></canvas>
  </div>
  <?php endif; ?>
</div>

<!-- ── Hora del día ───────────────────────────────────────────────────────── -->
<h2>Horarios de mayor escucha</h2>
<div class="chart-section">
  <h3>Plays acumulados por hora (hora local Argentina)</h3>
  <?php if ($total_plays === 0): ?>
  <div class="empty-chart">
    <span>🕐</span>
    Sin datos todavía.
  </div>
  <?php else: ?>
  <div class="chart-wrap tall">
    <canvas id="chart-hora"></canvas>
  </div>
  <p class="note">Horario local Argentina (UTC-3). Pico actual: <?= sprintf('%02d:00', $hora_max) ?> con <?= $hora_max_plays ?> plays.</p>
  <?php endif; ?>
</div>

<!-- ── Top emisoras ───────────────────────────────────────────────────────── -->
<h2>Top emisoras más escuchadas</h2>

<?php if ($top_emisoras): ?>
<?php
$max_plays_top = max(array_column($top_emisoras, 'plays') ?: [1]);
?>
<div class="chart-section" style="padding:0;overflow:hidden">
  <table class="tabla">
    <thead><tr>
      <th>#</th>
      <th>Emisora</th>
      <th>Plays</th>
      <th>Oyentes únicos</th>
      <th>Tiempo total</th>
      <th>Popularidad</th>
    </tr></thead>
    <tbody>
    <?php foreach ($top_emisoras as $i => $em): ?>
    <tr>
      <td style="color:var(--muted);width:30px"><?= $i+1 ?></td>
      <td><a href="/radio/<?= h($em['slug']) ?>/" target="_blank"><?= h($em['nombre']) ?></a></td>
      <td><?= number_format((int)$em['plays']) ?></td>
      <td><?= number_format((int)$em['oyentes']) ?></td>
      <td style="color:var(--muted)"><?= fmt_dur((int)$em['total_secs']) ?></td>
      <td>
        <div class="mini-bar-wrap">
          <div class="mini-bar" style="width:<?= $max_plays_top > 0 ? round((int)$em['plays']/$max_plays_top*100) : 0 ?>%"></div>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Chart horizontal top 10 -->
<div class="chart-section" style="margin-top:18px">
  <h3>Top 10 — comparativa visual</h3>
  <div class="chart-wrap" style="height:300px">
    <canvas id="chart-top"></canvas>
  </div>
</div>

<?php else: ?>
<div class="empty-chart" style="height:120px">
  <span>📻</span>
  Sin reproducciones registradas todavía.
</div>
<?php endif; ?>

<p style="margin-top:32px;font-size:11px;color:var(--border);text-align:center">
  Radio Argentina Admin · <?= gmdate('Y-m-d H:i') ?> UTC · Hora local = UTC-3
</p>

<script>
var isDark = !document.body.classList.contains('light');
var gridColor  = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.07)';
var textColor  = isDark ? '#94a3b8' : '#64748b';
var accent     = '#3b82f6';
var green      = '#22c55e';
var yellow     = '#f59e0b';
var purple     = '#a78bfa';

Chart.defaults.font.family = 'system-ui, sans-serif';
Chart.defaults.font.size   = 12;

// ── Gráfico principal (plays + oyentes por día/mes) ─────────────────────────
<?php if ($total_plays > 0): ?>
(function () {
  var labels  = <?= $chart_labels ?>;
  var plays   = <?= $chart_plays ?>;
  var oyentes = <?= $chart_oyentes ?>;

  new Chart(document.getElementById('chart-main'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Reproducciones',
          data: plays,
          backgroundColor: 'rgba(59,130,246,.75)',
          borderColor: accent,
          borderWidth: 1,
          borderRadius: 3,
          order: 2
        },
        {
          label: 'Oyentes únicos',
          data: oyentes,
          type: 'line',
          borderColor: green,
          backgroundColor: 'rgba(34,197,94,.12)',
          tension: .35,
          pointRadius: 2,
          borderWidth: 2,
          fill: true,
          order: 1,
          yAxisID: 'y2'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: textColor, boxWidth: 14 } },
        tooltip: {}
      },
      scales: {
        x: {
          ticks: { color: textColor, maxTicksLimit: <?= $modo==='dia' ? 15 : $rango ?>, maxRotation: 45 },
          grid:  { color: gridColor }
        },
        y: {
          ticks: { color: textColor },
          grid:  { color: gridColor },
          beginAtZero: true,
          title: { display: true, text: 'Plays', color: textColor }
        },
        y2: {
          position: 'right',
          ticks: { color: green },
          grid: { drawOnChartArea: false },
          beginAtZero: true,
          title: { display: true, text: 'Oyentes únicos', color: green }
        }
      }
    }
  });
}());
<?php endif; ?>

// ── Gráfico por hora del día ─────────────────────────────────────────────────
<?php if ($total_plays > 0): ?>
(function () {
  var horas  = <?= json_encode($hora_labels) ?>;
  var plays  = <?= $chart_hora ?>;
  var maxVal = Math.max.apply(null, plays);

  var bgColors = plays.map(function(v) {
    var ratio = maxVal > 0 ? v / maxVal : 0;
    return 'rgba(59,130,246,' + (0.2 + ratio * 0.75).toFixed(2) + ')';
  });

  new Chart(document.getElementById('chart-hora'), {
    type: 'bar',
    data: {
      labels: horas,
      datasets: [{
        label: 'Plays',
        data: plays,
        backgroundColor: bgColors,
        borderColor: bgColors,
        borderWidth: 1,
        borderRadius: 3
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: function(items) { return 'Hora: ' + items[0].label; }
          }
        }
      },
      scales: {
        x: {
          ticks: { color: textColor, maxTicksLimit: 12 },
          grid:  { color: gridColor }
        },
        y: {
          ticks: { color: textColor },
          grid:  { color: gridColor },
          beginAtZero: true
        }
      }
    }
  });
}());
<?php endif; ?>

// ── Chart top emisoras (horizontal bar) ──────────────────────────────────────
<?php if ($top_emisoras): ?>
(function () {
  var nombres = <?= $top_nombres ?>;
  var plays   = <?= $top_plays ?>;
  var oyentes = <?= $top_oyentes ?>;

  new Chart(document.getElementById('chart-top'), {
    type: 'bar',
    data: {
      labels: nombres,
      datasets: [
        {
          label: 'Plays',
          data: plays,
          backgroundColor: 'rgba(59,130,246,.8)',
          borderRadius: 4
        },
        {
          label: 'Oyentes únicos',
          data: oyentes,
          backgroundColor: 'rgba(34,197,94,.7)',
          borderRadius: 4
        }
      ]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: textColor, boxWidth: 14 } }
      },
      scales: {
        x: {
          ticks: { color: textColor },
          grid:  { color: gridColor },
          beginAtZero: true
        },
        y: {
          ticks: { color: textColor, font: { size: 11 } },
          grid:  { color: gridColor }
        }
      }
    }
  });
}());
<?php endif; ?>
</script>
</body>
</html>
