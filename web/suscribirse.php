<?php
/**
 * suscribirse.php — Suscripción a alertas de Radio Argentina v3
 * El oyente deja su Telegram o email y sus preferencias (artistas, programas, géneros).
 */

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

$db  = radio_db();
$err = '';
$ok  = false;
$pending_token = '';

// Asegurar tablas
try { $db->exec('CREATE TABLE IF NOT EXISTS subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_type TEXT NOT NULL,
    contact_value TEXT NOT NULL,
    preferences TEXT DEFAULT "[]",
    active INTEGER DEFAULT 0,
    token TEXT UNIQUE NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    last_notified TEXT
)'); } catch (Exception $e) {}

// Géneros disponibles (top tags de la DB)
$top_tags = $db->query("
    SELECT tags FROM stations WHERE approved=1 AND tags IS NOT NULL AND tags != '[]' LIMIT 500
")->fetchAll(PDO::FETCH_COLUMN);
$tag_counts = [];
foreach ($top_tags as $raw) {
    foreach (json_decode($raw, true) ?: [] as $t) {
        $t = trim($t);
        if ($t) $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1;
    }
}
arsort($tag_counts);
$generos_disponibles = array_keys(array_slice($tag_counts, 0, 16, true));

// Acción: activar por token GET
if (isset($_GET['activar']) && strlen($_GET['activar']) === 32) {
    $tok = preg_replace('/[^a-f0-9]/', '', $_GET['activar']);
    $sub = $db->prepare("SELECT * FROM subscribers WHERE token = ? LIMIT 1");
    $sub->execute([$tok]);
    $row = $sub->fetch(PDO::FETCH_ASSOC);
    if ($row && !$row['active']) {
        $db->prepare("UPDATE subscribers SET active=1 WHERE token=?")->execute([$tok]);
        $ok = true;
    } elseif ($row && $row['active']) {
        $ok = true;
    } else {
        $err = 'Código de activación inválido o expirado.';
    }
}

// Acción: nueva suscripción POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type  = in_array($_POST['type'] ?? '', ['telegram', 'email']) ? $_POST['type'] : '';
    $value = trim($_POST['value'] ?? '');
    $prefs = [];

    // Artistas/keywords (texto libre)
    if (!empty($_POST['keywords'])) {
        foreach (preg_split('/[\n,]+/', $_POST['keywords']) as $k) {
            $k = trim($k);
            if ($k && mb_strlen($k) <= 60) $prefs[] = ['type' => 'artist', 'value' => $k];
        }
    }

    // Géneros
    if (!empty($_POST['generos']) && is_array($_POST['generos'])) {
        foreach ($_POST['generos'] as $g) {
            $g = trim($g);
            if ($g) $prefs[] = ['type' => 'genre', 'value' => $g];
        }
    }

    if (!$type) {
        $err = 'Seleccioná un método de contacto.';
    } elseif (!$value) {
        $err = $type === 'telegram' ? 'Ingresá tu chat ID de Telegram.' : 'Ingresá tu correo electrónico.';
    } elseif ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $err = 'Correo electrónico inválido.';
    } elseif ($type === 'telegram' && !preg_match('/^\d{5,15}$/', $value)) {
        $err = 'El chat ID de Telegram debe ser un número (ej: 124659252). Usá @userinfobot para obtenerlo.';
    } elseif (empty($prefs)) {
        $err = 'Agregá al menos un artista, programa o género.';
    } else {
        // Verificar si ya existe
        $exists = $db->prepare("SELECT id, active, token FROM subscribers WHERE contact_type=? AND contact_value=? LIMIT 1");
        $exists->execute([$type, $value]);
        $existing = $exists->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Actualizar preferencias
            $db->prepare("UPDATE subscribers SET preferences=?, active=0 WHERE id=?")->execute([
                json_encode($prefs, JSON_UNESCAPED_UNICODE), $existing['id']
            ]);
            $pending_token = $existing['token'];
        } else {
            $token = bin2hex(random_bytes(16));
            $db->prepare("INSERT INTO subscribers (contact_type, contact_value, preferences, active, token) VALUES (?,?,?,0,?)")
               ->execute([$type, $value, json_encode($prefs, JSON_UNESCAPED_UNICODE), $token]);
            $pending_token = $token;
        }

        // Enviar confirmación según tipo
        if ($type === 'telegram') {
            $tg_token = defined('TG_TOKEN') ? TG_TOKEN : '';
            $msg = "📻 *Radio Argentina — Confirmar suscripción*\n\n"
                 . "Tus preferencias quedaron registradas. Para activarlas:\n\n"
                 . "👉 https://mammoli.ar/radio/suscribirse.php?activar=" . $pending_token . "\n\n"
                 . "Si no pediste esto, ignorá el mensaje.";
            $r = @file_get_contents("https://api.telegram.org/bot{$tg_token}/sendMessage?" . http_build_query([
                'chat_id'    => $value,
                'text'       => $msg,
                'parse_mode' => 'Markdown'
            ]));
            $tg_ok = $r && json_decode($r, true)['ok'] ?? false;
            if (!$tg_ok) {
                $err = 'No pude enviarte el mensaje de confirmación. Verificá que el chat ID sea correcto y que hayas iniciado el bot (@' . (defined('BOT_NAME') ? BOT_NAME : 'claude_ariel_bot') . ') con /start.';
                // Limpiar registro fallido
                if (!$existing) {
                    $db->prepare("DELETE FROM subscribers WHERE token=?")->execute([$pending_token]);
                }
            } else {
                // Mostrar pantalla de "revisá tu Telegram"
                $ok = false; // mostrar pantalla de pendiente
            }
        } else {
            // Email: enviar confirmación
            $url   = 'https://mammoli.ar/radio/suscribirse.php?activar=' . $pending_token;
            $body  = "Hola!\n\nTe suscribiste a alertas de Radio Argentina.\n\nActivá tu suscripción en:\n{$url}\n\nSi no fuiste vos, ignorá este mensaje.\n\n— Radio Argentina";
            @mail($value, 'Confirmá tu suscripción — Radio Argentina', $body,
                "From: Radio Argentina <no-reply@mammoli.ar>\r\nContent-Type: text/plain; charset=UTF-8");
        }
    }
}

$cafecito = defined('CAFECITO_URL') ? CAFECITO_URL : 'https://cafecito.app/mammoli';
$bot_name = defined('BOT_NAME') ? BOT_NAME : 'claude_ariel_bot';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="index, follow">
<title>Alertas de escucha — Radio Argentina</title>
<style>
:root{--bg:#0f172a;--card:#1e293b;--border:#334155;--text:#e2e8f0;--muted:#94a3b8;--accent:#3b82f6;--green:#22c55e;--red:#ef4444}
body.light{--bg:#f1f5f9;--card:#fff;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--accent:#2563eb;--green:#16a34a;--red:#dc2626}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font:15px/1.6 system-ui,sans-serif;min-height:100vh;padding:0 0 48px}
header{background:linear-gradient(135deg,#1e3a5f 0%,#0f172a 70%);padding:24px 20px 20px;text-align:center;border-bottom:1px solid var(--border)}
body.light header{background:linear-gradient(135deg,#dbeafe,#f1f5f9)}
header h1{font-size:1.5rem;font-weight:700;margin-bottom:4px}
header .sub{font-size:.9rem;color:var(--muted)}
.nav{display:flex;gap:8px;justify-content:center;padding:12px;border-bottom:1px solid var(--border)}
.nav a{color:var(--muted);text-decoration:none;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--border)}
.nav a:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.container{max-width:600px;margin:32px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:20px}
h2{font-size:1.05rem;font-weight:700;margin-bottom:14px;color:var(--accent)}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:4px;font-weight:500}
input[type=text],input[type=email],textarea,select{
  width:100%;background:var(--bg);border:1px solid var(--border);border-radius:8px;
  padding:10px 12px;color:var(--text);font-size:14px;outline:none;margin-bottom:14px}
input:focus,textarea:focus{border-color:var(--accent)}
textarea{resize:vertical;min-height:80px}
.radio-group{display:flex;gap:12px;margin-bottom:14px}
.radio-group label{display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 16px;border:2px solid var(--border);border-radius:8px;font-size:14px;color:var(--text);flex:1;justify-content:center;transition:border-color .15s}
.radio-group input[type=radio]{display:none}
.radio-group input[type=radio]:checked + label{border-color:var(--accent);background:rgba(59,130,246,.08)}
.genre-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.genre-chip{cursor:pointer;padding:6px 12px;border-radius:20px;border:1px solid var(--border);font-size:13px;color:var(--muted);background:var(--bg);transition:all .15s}
.genre-chip.sel{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn{display:block;width:100%;padding:13px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:background .15s}
.btn:hover{background:#2563eb}
.alert-ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);border-radius:8px;padding:16px;color:var(--green);font-size:14px;margin-bottom:16px}
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:8px;padding:16px;color:var(--red);font-size:14px;margin-bottom:16px}
.alert-pend{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:16px;color:#f59e0b;font-size:14px;margin-bottom:16px}
.how{background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);border-radius:8px;padding:14px;font-size:13px;color:var(--muted);margin-bottom:14px}
.how strong{color:var(--text)}
.step{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px}
.step-n{background:var(--accent);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;margin-top:1px}
a{color:var(--accent)}
#tg-help{display:none;margin-top:-8px;margin-bottom:14px}
</style>
</head>
<body>
<script>if(localStorage.getItem('radio_theme')==='light')document.body.classList.add('light');</script>

<header>
  <h1>🔔 Alertas de Radio Argentina</h1>
  <p class="sub">Recibí un aviso cuando suene tu artista favorito o empiece tu programa</p>
</header>
<div class="nav">
  <a href="/radio/">← Volver al player</a>
</div>

<div class="container">

<?php if ($ok): ?>
  <div class="alert-ok">
    ✅ <strong>¡Suscripción activada!</strong><br>
    A partir de ahora te vamos a avisar cuando detectemos tus preferencias en el aire.
    <br><br>
    <a href="/radio/">← Volver al player</a>
  </div>

<?php elseif ($pending_token && !$err): ?>
  <div class="alert-pend">
    📨 <strong>¡Casi listo!</strong><br>
    <?php if (($_POST['type'] ?? '') === 'telegram'): ?>
      Te mandamos un mensaje de Telegram con el link de confirmación. Revisá tu chat con <strong>@<?= htmlspecialchars($bot_name) ?></strong>.
    <?php else: ?>
      Te mandamos un correo a <strong><?= htmlspecialchars($_POST['value'] ?? '') ?></strong>. Revisá tu bandeja (y spam). El link expira en 24 hs.
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php if ($err): ?>
  <div class="alert-err">⚠️ <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- Cómo funciona -->
  <div class="card">
    <h2>¿Cómo funciona?</h2>
    <div class="step"><div class="step-n">1</div><div>Dejás tu Telegram o email y qué te gusta (artistas, programas, géneros).</div></div>
    <div class="step"><div class="step-n">2</div><div>El sistema monitorea en tiempo real lo que están transmitiendo más de 1.200 emisoras.</div></div>
    <div class="step"><div class="step-n">3</div><div>Cuando detecta que una emisora lleva <strong>un par de temas</strong> de tu artista (no el primero suelto), te avisa.</div></div>
    <div class="step"><div class="step-n">4</div><div>También te puede avisar antes de que empiece tu programa favorito, basándose en el historial de horarios.</div></div>
  </div>

  <form method="post">

    <!-- Tipo de contacto -->
    <div class="card">
      <h2>¿Dónde te avisamos?</h2>
      <div class="radio-group">
        <input type="radio" name="type" id="t-tg" value="telegram" <?= ($_POST['type'] ?? '') !== 'email' ? 'checked' : '' ?>>
        <label for="t-tg">📱 Telegram</label>
        <input type="radio" name="type" id="t-em" value="email" <?= ($_POST['type'] ?? '') === 'email' ? 'checked' : '' ?>>
        <label for="t-em">✉️ Email</label>
      </div>

      <div id="tg-field">
        <label for="val-tg">Chat ID de Telegram</label>
        <input type="text" id="val-tg" name="value" placeholder="Ej: 124659252"
               value="<?= htmlspecialchars(($_POST['type'] ?? '') !== 'email' ? ($_POST['value'] ?? '') : '') ?>"
               inputmode="numeric">
        <div id="tg-help" class="how">
          <strong>¿Cómo obtengo mi chat ID?</strong><br>
          1. Buscá <strong>@userinfobot</strong> en Telegram y mandá cualquier mensaje.<br>
          2. Te responde con tu ID numérico. Copialo y pegalo acá.<br>
          3. También necesitás haber iniciado <strong>@<?= htmlspecialchars($bot_name) ?></strong> con /start al menos una vez.
        </div>
        <a href="#" onclick="document.getElementById('tg-help').style.display=document.getElementById('tg-help').style.display==='block'?'none':'block';return false"
           style="font-size:12px;display:block;margin-top:-10px;margin-bottom:14px">
          ¿Cómo obtengo mi chat ID? ▾
        </a>
      </div>
      <div id="em-field" style="display:none">
        <label for="val-em">Correo electrónico</label>
        <input type="email" id="val-em" placeholder="tu@correo.com"
               value="<?= htmlspecialchars(($_POST['type'] ?? '') === 'email' ? ($_POST['value'] ?? '') : '') ?>">
      </div>
    </div>

    <!-- Preferencias -->
    <div class="card">
      <h2>¿Qué querés escuchar?</h2>

      <label>Artistas o programas (uno por línea o separados por coma)</label>
      <textarea name="keywords" placeholder="Michael Jackson&#10;La Cornisa&#10;Rock Nacional&#10;Gustavo Cerati"><?= htmlspecialchars($_POST['keywords'] ?? '') ?></textarea>
      <p style="font-size:12px;color:var(--muted);margin-top:-10px;margin-bottom:14px">
        El sistema busca estos textos en los títulos ICY que transmiten las emisoras. Podés poner cualquier cosa: artista, nombre de programa, locución, etc.
      </p>

      <label>Géneros (opcional)</label>
      <div class="genre-grid" id="genre-grid">
        <?php foreach ($generos_disponibles as $g):
          $sel = !empty($_POST['generos']) && in_array($g, (array)$_POST['generos']);
        ?>
        <div class="genre-chip <?= $sel ? 'sel' : '' ?>" data-val="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></div>
        <?php endforeach; ?>
      </div>
      <div id="genre-hidden"></div>
    </div>

    <button type="submit" class="btn">🔔 Suscribirme</button>
  </form>

<?php endif; ?>

  <p style="font-size:12px;color:var(--muted);text-align:center;margin-top:20px">
    No spam. Solo alertas cuando hay algo que te interesa.<br>
    <a href="/radio/suscribirse.php?baja=1" style="color:var(--muted)">Dar de baja</a> ·
    ¿Problemas? <a href="mailto:<?= htmlspecialchars(defined('SITE_EMAIL') ? SITE_EMAIL : 'carlos@mammoli.ar') ?>">Escribinos</a>
  </p>
</div>

<script>
// Toggle campo Telegram / email
document.querySelectorAll('input[name=type]').forEach(function(r){
  r.addEventListener('change', function(){
    var tg = document.getElementById('tg-field');
    var em = document.getElementById('em-field');
    var vt = document.getElementById('val-tg');
    var ve = document.getElementById('val-em');
    if(this.value==='telegram'){
      tg.style.display=''; em.style.display='none';
      vt.name='value'; ve.removeAttribute('name');
    } else {
      tg.style.display='none'; em.style.display='';
      ve.name='value'; vt.removeAttribute('name');
    }
  });
});
// Inicializar según estado actual
(function(){
  var sel = document.querySelector('input[name=type]:checked');
  if(sel && sel.value==='email'){
    document.getElementById('tg-field').style.display='none';
    document.getElementById('em-field').style.display='';
    document.getElementById('val-em').name='value';
    document.getElementById('val-tg').removeAttribute('name');
  }
}());

// Chips de géneros
document.querySelectorAll('#genre-grid .genre-chip').forEach(function(chip){
  chip.addEventListener('click', function(){
    chip.classList.toggle('sel');
    updateGenreHidden();
  });
});
function updateGenreHidden(){
  var container = document.getElementById('genre-hidden');
  container.innerHTML = '';
  document.querySelectorAll('#genre-grid .genre-chip.sel').forEach(function(c){
    var inp = document.createElement('input');
    inp.type='hidden'; inp.name='generos[]'; inp.value=c.dataset.val;
    container.appendChild(inp);
  });
}
updateGenreHidden();
</script>
</body>
</html>
