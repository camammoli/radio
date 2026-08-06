<?php
/**
 * estadisticas.php — evolución del estado de los streams · Radio Argentina v2
 * Lee desde SQLite (stream_status + stream_history) — no usa status_history.json
 */

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

$db = radio_db();

// Índice de performance (solo corre una vez)
$db->exec("CREATE INDEX IF NOT EXISTS idx_history_date ON stream_history(checked_at)");

// Filtros disponibles: 7d, 30d, 90d
$rango = in_array($_GET['rango'] ?? '', ['7d','30d','90d']) ? $_GET['rango'] : '7d';
$dias  = ['7d'=>7, '30d'=>30, '90d'=>90][$rango];

// ── Estado actual (stream_status, uno por estación) ──────────────────────────
$actual = $db->query("
    SELECT
        SUM(CASE WHEN estado='ok'      THEN 1 ELSE 0 END) AS ok,
        SUM(CASE WHEN estado='muerto'  THEN 1 ELSE 0 END) AS muertos,
        SUM(CASE WHEN estado='timeout' THEN 1 ELSE 0 END) AS timeout,
        COUNT(*) AS total,
        MAX(last_checked) AS ts
    FROM stream_status
")->fetch(PDO::FETCH_ASSOC);

$ultimo = $actual && $actual['total'] > 0 ? [
    'ts'      => $actual['ts'] ?? null,
    'ok'      => (int)$actual['ok'],
    'muertos' => (int)$actual['muertos'],
    'timeout' => (int)$actual['timeout'],
    'total'   => (int)$actual['total'],
] : null;

$ts_last = $ultimo && $ultimo['ts']
    ? (new DateTime($ultimo['ts'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Argentina/Mendoza'))->format('d/m/Y H:i')
    : '—';

// ── Historial diario desde stream_history (91 días para comparativas + chart) ─
// Por cada día, toma el último estado de cada estación (ROW_NUMBER window function)
$history_raw = $db->query("
    WITH latest AS (
        SELECT station_id, estado, date(checked_at) AS day,
               ROW_NUMBER() OVER (
                   PARTITION BY station_id, date(checked_at)
                   ORDER BY checked_at DESC
               ) AS rn
        FROM stream_history
        WHERE checked_at >= date('now', '-91 days')
    )
    SELECT day,
           SUM(CASE WHEN estado='ok'      THEN 1 ELSE 0 END) AS ok,
           SUM(CASE WHEN estado='muerto'  THEN 1 ELSE 0 END) AS muertos,
           SUM(CASE WHEN estado='timeout' THEN 1 ELSE 0 END) AS timeout,
           COUNT(*) AS total
    FROM latest WHERE rn = 1
    GROUP BY day
    ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

// Indexar por fecha para lookups de comparativa
$history_by_day = [];
foreach ($history_raw as $r) {
    $history_by_day[$r['day']] = [
        'ts'      => $r['day'],
        'ok'      => (int)$r['ok'],
        'muertos' => (int)$r['muertos'],
        'timeout' => (int)$r['timeout'],
        'total'   => (int)$r['total'],
    ];
}

// ── Comparativas: buscar el día más cercano si el exacto no existe ────────────
function closest_day(array $map, string $target): ?array {
    if (isset($map[$target])) return $map[$target];
    // buscar el día más próximo dentro de ±2 días
    for ($d = 1; $d <= 2; $d++) {
        $prev = date('Y-m-d', strtotime($target . " -$d days"));
        $next = date('Y-m-d', strtotime($target . " +$d days"));
        if (isset($map[$prev])) return $map[$prev];
        if (isset($map[$next])) return $map[$next];
    }
    return null;
}

$hace24h = closest_day($history_by_day, date('Y-m-d', strtotime('-1 day')));
$hace7d  = closest_day($history_by_day, date('Y-m-d', strtotime('-7 days')));
$hace30d = closest_day($history_by_day, date('Y-m-d', strtotime('-30 days')));

// ── Filtrar rango seleccionado para el chart ──────────────────────────────────
$desde   = date('Y-m-d', strtotime("-{$dias} days"));
$filtered = array_values(array_filter($history_by_day, fn($r) => $r['ts'] >= $desde));

// Reducir a máx 120 puntos para no saturar Chart.js
$n    = count($filtered);
$step = max(1, (int)ceil($n / 120));
$chart_data = [];
for ($i = 0; $i < $n; $i += $step) $chart_data[] = $filtered[$i];

// Si no hay historial pero hay estado actual, mostrar un solo punto de hoy
if (empty($chart_data) && $ultimo) {
    $chart_data[] = array_merge($ultimo, ['ts' => date('Y-m-d')]);
}

function delta(int $a, ?array $ref, string $key): string {
    if (!$ref) return '';
    $d = $a - (int)($ref[$key] ?? $a);
    if ($d === 0) return '<span style="color:#6b7280">±0</span>';
    $color = ($key === 'ok') ? ($d > 0 ? '#22c55e' : '#ef4444') : ($d > 0 ? '#ef4444' : '#22c55e');
    return sprintf('<span style="color:%s">%+d</span>', $color, $d);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Estadísticas · Radio Argentina</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
:root{--bg:#111827;--surface:#1f2937;--border:#374151;--text:#f9fafb;--muted:#9ca3af;--accent:#3b82f6}
body.light{--bg:#f3f4f6;--surface:#fff;--border:#d1d5db;--text:#111827;--muted:#6b7280;--accent:#2563eb}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:0 0 48px}
header{background:linear-gradient(135deg,#1e3a5f 0%,#111827 70%);padding:24px 20px 20px;text-align:center;border-bottom:1px solid var(--border)}
body.light header{background:linear-gradient(135deg,#dbeafe 0%,#f3f4f6 70%)}
header h1{font-size:1.4rem;font-weight:700;margin-bottom:4px}
header .sub{font-size:.85rem;color:var(--muted)}
.nav{display:flex;gap:8px;justify-content:center;padding:14px 16px;flex-wrap:wrap;border-bottom:1px solid var(--border)}
.nav a{color:var(--muted);text-decoration:none;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--border);transition:background .15s}
.nav a:hover,.nav a.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.container{max-width:900px;margin:0 auto;padding:20px 16px}
.section{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:20px}
.section h2{font-size:1rem;font-weight:600;margin-bottom:16px;color:var(--text)}
.rango-btns{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.rango-btn{padding:5px 14px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;font-size:13px;text-decoration:none}
.rango-btn.active,.rango-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.chart-wrap{position:relative;height:280px}
.comparativa{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:8px}
.cmp-card{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px}
.cmp-card h3{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:10px}
.cmp-row{display:flex;justify-content:space-between;align-items:center;font-size:14px;padding:3px 0}
.cmp-val{font-weight:600}
.dot-ok{color:#22c55e} .dot-muerto{color:#ef4444} .dot-timeout{color:#f59e0b}
.tabla{width:100%;border-collapse:collapse;font-size:13px}
.tabla th{text-align:left;padding:8px 10px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:500}
.tabla td{padding:7px 10px;border-bottom:1px solid var(--border)}
.tabla tr:last-child td{border-bottom:none}
.tabla tr:hover td{background:rgba(255,255,255,.03)}
body.light .tabla tr:hover td{background:rgba(0,0,0,.02)}
.sin-datos{text-align:center;padding:40px;color:var(--muted);font-size:14px}
.badge-ok{color:#22c55e} .badge-muerto{color:#ef4444} .badge-timeout{color:#f59e0b}
</style>
</head>
<body>
<script>if(localStorage.getItem('radio_theme')==='light')document.body.classList.add('light');</script>

<header>
  <h1>📊 Estadísticas · Radio Argentina</h1>
  <p class="sub">Evolución del estado de los streams · <?= $ts_last ?></p>
</header>

<div class="nav">
  <a href="index.php">← Volver al player</a>
  <button id="theme-btn" class="nav" style="cursor:pointer;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--muted)">☀️ Modo claro</button>
</div>

<div class="container">

<!-- Comparativa rápida -->
<div class="section">
  <h2>Comparativa</h2>
  <?php if (!$ultimo): ?>
    <p class="sin-datos">Sin datos todavía. El primer snapshot se generará en el próximo chequeo automático.</p>
  <?php else: ?>
  <div class="comparativa">
    <?php
    $cols = [
      ['label' => 'Ahora',       'data' => $ultimo],
      ['label' => 'Hace 24 hs',  'data' => $hace24h],
      ['label' => 'Hace 7 días', 'data' => $hace7d],
      ['label' => 'Hace 30 días','data' => $hace30d],
    ];
    foreach ($cols as $col):
      $d = $col['data'];
      if (!$d && $col['label'] !== 'Ahora') continue;
    ?>
    <div class="cmp-card">
      <h3><?= $col['label'] ?></h3>
      <?php if ($d): ?>
        <div class="cmp-row"><span class="dot-ok">● Activas</span><span class="cmp-val"><?= $d['ok'] ?> <?= $col['label'] !== 'Ahora' ? delta($ultimo['ok'], $d, 'ok') : '' ?></span></div>
        <div class="cmp-row"><span class="dot-muerto">● Caídas</span><span class="cmp-val"><?= $d['muertos'] ?> <?= $col['label'] !== 'Ahora' ? delta($ultimo['muertos'], $d, 'muertos') : '' ?></span></div>
        <div class="cmp-row"><span class="dot-timeout">● Timeout</span><span class="cmp-val"><?= $d['timeout'] ?> <?= $col['label'] !== 'Ahora' ? delta($ultimo['timeout'], $d, 'timeout') : '' ?></span></div>
        <div class="cmp-row" style="font-size:11px;color:var(--muted);margin-top:4px"><?= $d['ts'] ?></div>
      <?php else: ?>
        <div style="color:var(--muted);font-size:13px">Sin datos</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Gráfico de evolución -->
<div class="section">
  <h2>Evolución</h2>
  <div class="rango-btns">
    <?php foreach (['7d'=>'7 días','30d'=>'30 días','90d'=>'90 días'] as $v=>$l): ?>
    <a href="?rango=<?= $v ?>" class="rango-btn <?= $rango===$v?'active':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (count($chart_data) < 2): ?>
    <p class="sin-datos">Sin suficientes datos para este rango. Los snapshots se acumulan con cada chequeo (cada 6 horas).</p>
  <?php else: ?>
  <div class="chart-wrap">
    <canvas id="chart"></canvas>
  </div>
  <?php endif; ?>
</div>

<!-- Tabla de últimos snapshots -->
<div class="section">
  <h2>Últimos snapshots</h2>
  <?php
  $tabla_data = array_reverse(array_slice(array_values($history_by_day), -30));
  if (!$tabla_data && !$ultimo): ?>
    <p class="sin-datos">Sin datos todavía.</p>
  <?php else: ?>
  <table class="tabla">
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Total</th>
        <th class="dot-ok">Activas</th>
        <th class="dot-muerto">Caídas</th>
        <th class="dot-timeout">Timeout</th>
      </tr>
    </thead>
    <tbody>
    <?php
    // Insertar estado actual como primera fila si es de hoy y no está en el historial
    $today = date('Y-m-d');
    if ($ultimo && !isset($history_by_day[$today])):
    ?>
      <tr>
        <td><?= $ts_last ?> <span style="color:var(--accent);font-size:11px">• ahora</span></td>
        <td><?= $ultimo['total'] ?></td>
        <td class="badge-ok"><?= $ultimo['ok'] ?></td>
        <td class="badge-muerto"><?= $ultimo['muertos'] ?></td>
        <td class="badge-timeout"><?= $ultimo['timeout'] ?></td>
      </tr>
    <?php endif; ?>
    <?php foreach ($tabla_data as $e): ?>
      <tr>
        <td><?= $e['ts'] ?></td>
        <td><?= $e['total'] ?></td>
        <td class="badge-ok"><?= $e['ok'] ?></td>
        <td class="badge-muerto"><?= $e['muertos'] ?></td>
        <td class="badge-timeout"><?= $e['timeout'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</div><!-- /container -->

<?php if (count($chart_data) >= 2): ?>
<script>
var labels      = <?= json_encode(array_column($chart_data, 'ts')) ?>;
var dataOk      = <?= json_encode(array_column($chart_data, 'ok')) ?>;
var dataMuertos = <?= json_encode(array_column($chart_data, 'muertos')) ?>;
var dataTimeout = <?= json_encode(array_column($chart_data, 'timeout')) ?>;

var isDark    = !document.body.classList.contains('light');
var gridColor = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.08)';
var textColor = isDark ? '#9ca3af' : '#6b7280';

var chart = new Chart(document.getElementById('chart'), {
  type: 'line',
  data: {
    labels: labels,
    datasets: [
      {label:'Activas',  data:dataOk,      borderColor:'#22c55e', backgroundColor:'rgba(34,197,94,.1)',  tension:.3, pointRadius:1, borderWidth:2, fill:true},
      {label:'Caídas',   data:dataMuertos, borderColor:'#ef4444', backgroundColor:'rgba(239,68,68,.08)',  tension:.3, pointRadius:1, borderWidth:2, fill:true},
      {label:'Timeout',  data:dataTimeout, borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,.06)', tension:.3, pointRadius:1, borderWidth:2, fill:true},
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:false,
    interaction:{mode:'index', intersect:false},
    plugins:{
      legend:{labels:{color:textColor, boxWidth:12, font:{size:12}}},
      tooltip:{
        callbacks:{
          title: function(items){ return items[0].label; }
        }
      }
    },
    scales:{
      x:{ticks:{color:textColor, maxTicksLimit:8, maxRotation:0}, grid:{color:gridColor}},
      y:{ticks:{color:textColor}, grid:{color:gridColor}, beginAtZero:true}
    }
  }
});
</script>
<?php endif; ?>

<script>
(function(){
  var theme = localStorage.getItem('radio_theme');
  if (theme === 'light') document.body.classList.add('light');
  var btn = document.getElementById('theme-btn');
  function syncBtn(){ btn.textContent = document.body.classList.contains('light') ? '🌙 Modo oscuro' : '☀️ Modo claro'; }
  syncBtn();
  btn.addEventListener('click', function(){
    var isLight = document.body.classList.toggle('light');
    localStorage.setItem('radio_theme', isLight ? 'light' : 'dark');
    syncBtn();
  });
})();
</script>
</body>
</html>
