<?php
/**
 * suscribirse.php — Suscripción a alertas de Radio Argentina v4
 * El oyente deja su Telegram o email y sus preferencias (artistas, programas, géneros).
 */

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

$db  = radio_db();
$err = '';
$ok  = false;
$pending_token = '';
$mode = 'register'; // register | manage | baja_ok | baja_link

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

// Top artistas desde icy_history (para chips de selección)
$noise_artistas = ['classic hits','variados','mix','desconocido','various','unknown',
                   'fm del mar','sport billy','cop centro','symploké','symploke',
                   'el hacedor iglesia','sarah nimmo','variado'];
$artistas_raw = $db->query("
    SELECT trim(substr(title, 1, instr(title,' - ')-1)) AS artista, COUNT(*) AS n
    FROM icy_history
    WHERE title LIKE '% - %'
      AND length(trim(substr(title, 1, instr(title,' - ')-1))) BETWEEN 2 AND 45
    GROUP BY lower(trim(substr(title, 1, instr(title,' - ')-1)))
    HAVING n >= 2
    ORDER BY n DESC
    LIMIT 80
")->fetchAll(PDO::FETCH_ASSOC);
$top_artistas = [];
if ($artistas_raw) {
    $max_n = max(array_column($artistas_raw, 'n'));
    foreach ($artistas_raw as $r) {
        $low = strtolower($r['artista']);
        if (preg_match('/\bfm\b/i', $r['artista'])) continue;
        if (preg_match('/\b\d{4}\b/', $r['artista']))  continue;
        if (in_array($low, $noise_artistas))            continue;
        $norm  = mb_convert_case($r['artista'], MB_CASE_TITLE, 'UTF-8');
        $ratio = $r['n'] / $max_n;
        $tier  = $ratio >= 0.4 ? 'hot' : ($ratio >= 0.15 ? 'warm' : 'cool');
        $top_artistas[] = ['name' => $norm, 'n' => (int)$r['n'], 'tier' => $tier];
        if (count($top_artistas) >= 40) break;
    }
}

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

// Acción: gestión (editar / dar de baja) — GET ?manage=TOKEN
$manage_sub = null;
if (isset($_GET['manage'])) {
    $tok = preg_replace('/[^a-f0-9]/', '', $_GET['manage']);
    if (strlen($tok) === 32) {
        $st = $db->prepare("SELECT * FROM subscribers WHERE token=? LIMIT 1");
        $st->execute([$tok]);
        $manage_sub = $st->fetch(PDO::FETCH_ASSOC);
    }
    $mode = $manage_sub ? 'manage' : 'register';
    if (!$manage_sub) $err = 'Link de gestión inválido o expirado.';
}

// Acción: dar de baja con token — GET ?baja=TOKEN
if (isset($_GET['baja']) && strlen($_GET['baja']) === 32) {
    $tok = preg_replace('/[^a-f0-9]/', '', $_GET['baja']);
    $st  = $db->prepare("SELECT id FROM subscribers WHERE token=? LIMIT 1");
    $st->execute([$tok]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare("DELETE FROM subscribers WHERE token=?")->execute([$tok]);
        $mode = 'baja_ok';
    } else {
        $err = 'Link de baja inválido o ya procesado.';
    }
}

// Acción: solicitar link de gestión por email/telegram (GET ?baja=1 sin token)
if (isset($_GET['baja']) && $_GET['baja'] === '1') {
    $mode = 'baja_link';
}

// POST: guardar cambios desde la página de gestión
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar') {
    $tok = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    $st  = $db->prepare("SELECT * FROM subscribers WHERE token=? LIMIT 1");
    $st->execute([$tok]);
    $manage_sub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$manage_sub) {
        $err = 'Token inválido.'; $mode = 'register';
    } else {
        $prefs = [];
        if (!empty($_POST['artist_chips']) && is_array($_POST['artist_chips'])) {
            foreach ($_POST['artist_chips'] as $k) {
                $k = trim($k);
                if ($k && mb_strlen($k) <= 60) $prefs[] = ['type' => 'artist', 'value' => $k];
            }
        }
        if (!empty($_POST['keywords_artist'])) {
            foreach (preg_split('/[\n,]+/', $_POST['keywords_artist']) as $k) {
                $k = trim($k);
                if ($k && mb_strlen($k) <= 60) $prefs[] = ['type' => 'artist', 'value' => $k];
            }
        }
        if (!empty($_POST['keywords_program'])) {
            foreach (preg_split('/[\n,]+/', $_POST['keywords_program']) as $k) {
                $k = trim($k);
                if ($k && mb_strlen($k) <= 60) $prefs[] = ['type' => 'program', 'value' => $k];
            }
        }
        if (!empty($_POST['generos']) && is_array($_POST['generos'])) {
            foreach ($_POST['generos'] as $g) {
                $g = trim($g);
                if ($g) $prefs[] = ['type' => 'genre', 'value' => $g];
            }
        }
        // Deduplicar por tipo+valor (por si el chip y el textarea tienen lo mismo)
        $seen_prefs = []; $prefs_ok = [];
        foreach ($prefs as $p) {
            $key = ($p['type'] ?? '') . ':' . strtolower($p['value'] ?? '');
            if (!isset($seen_prefs[$key])) { $seen_prefs[$key] = true; $prefs_ok[] = $p; }
        }
        $prefs = $prefs_ok;
        if (empty($prefs)) {
            $err = 'Agregá al menos un artista, programa o género.';
        } else {
            $db->prepare("UPDATE subscribers SET preferences=? WHERE token=?")
               ->execute([json_encode($prefs, JSON_UNESCAPED_UNICODE), $tok]);
            $manage_sub['preferences'] = json_encode($prefs, JSON_UNESCAPED_UNICODE);
            $ok = true;
        }
        $mode = 'manage';
    }
}

// POST: solicitar link de gestión — sin proteger, mandaba mail/Telegram real
// a quien esté registrado con ese contacto sin ningún filtro (mail-bombing
// de terceros). Mismo honeypot+trampa de tiempo que el resto del sitio, más
// rate limit — acá el error de límite se muestra igual que "completá el
// campo" para no revelar nada nuevo sobre qué lo frenó.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_link') {
    $honeypot3  = trim($_POST['web3'] ?? '');
    $ts_render3 = (int) ($_POST['ts2'] ?? 0);
    $es_bot3    = $honeypot3 !== '' || $ts_render3 <= 0 || (time() - $ts_render3) < 2;

    $type  = in_array($_POST['type'] ?? '', ['telegram', 'email']) ? $_POST['type'] : '';
    $value = trim($_POST['value'] ?? '');

    if ($es_bot3) {
        $mode = 'link_sent'; // fingir éxito, no se toca la DB ni se manda nada
    } elseif ($type && $value) {
        // Rate limit: 5 pedidos de link por IP por hora, sin DB.
        $ip_clave3 = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x'), 0, 16);
        $archivo_limite3 = sys_get_temp_dir() . '/radio_sendlink_' . $ip_clave3 . '.json';
        $marcas3 = file_exists($archivo_limite3) ? (@json_decode(file_get_contents($archivo_limite3), true) ?? []) : [];
        $marcas3 = array_values(array_filter($marcas3, fn($t) => $t > time() - 3600));
        if (count($marcas3) >= 5) {
            $err  = 'Demasiados intentos en poco tiempo. Probá de nuevo en un rato.';
            $mode = 'baja_link';
        } else {
            $marcas3[] = time();
            @file_put_contents($archivo_limite3, json_encode($marcas3));
            $st = $db->prepare("SELECT token, contact_type FROM subscribers WHERE contact_type=? AND contact_value=? LIMIT 1");
            $st->execute([$type, $value]);
            $found = $st->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $manage_url = 'https://mammoli.ar/radio/suscribirse.php?manage=' . $found['token'];
                $baja_url   = 'https://mammoli.ar/radio/suscribirse.php?baja='   . $found['token'];
                $tg_token   = defined('TG_TOKEN') ? TG_TOKEN : '';
                if ($type === 'telegram') {
                    $msg = "📻 *Radio Argentina — Gestión de suscripción*\n\n"
                         . "✏️ Editar preferencias: {$manage_url}\n"
                         . "❌ Dar de baja: {$baja_url}";
                    @file_get_contents("https://api.telegram.org/bot{$tg_token}/sendMessage?" . http_build_query([
                        'chat_id' => $value, 'text' => $msg, 'parse_mode' => 'Markdown'
                    ]));
                } else {
                    $body = "Gestión de suscripción — Radio Argentina\n\n"
                          . "✏️ Editar preferencias:\n{$manage_url}\n\n"
                          . "❌ Dar de baja:\n{$baja_url}\n\n"
                          . "Si no pediste esto, ignorá el mensaje.";
                    @mail($value, 'Gestión de suscripción — Radio Argentina', $body,
                        "From: Radio Argentina <radio@mammoli.ar>\r\nContent-Type: text/plain; charset=UTF-8");
                }
            }
            // Mostrar siempre el mismo mensaje (no revelar si existe o no)
            $mode = 'link_sent';
        }
    } else {
        $err  = 'Completá el campo.';
        $mode = 'baja_link';
    }
}

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

// Acción: nueva suscripción POST (NO para action=editar ni action=send_link)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(($_POST['action'] ?? ''), ['editar', 'send_link'])) {
    // Honeypot (campo oculto por CSS) + trampa de tiempo (ts = timestamp del
    // render anterior, viaja de ida y vuelta en el form). Envío casi
    // instantáneo o con el honeypot completo = bot: se finge éxito sin
    // tocar la DB ni mandar Telegram/email a nadie (evita mail-bombing de
    // terceros con chat IDs/emails ajenos).
    $honeypot  = trim($_POST['web2'] ?? '');
    $ts_render = (int) ($_POST['ts'] ?? 0);
    $es_bot    = $honeypot !== '' || $ts_render <= 0 || (time() - $ts_render) < 2;

    // Rate limit: 5 intentos de suscripción por IP por hora, sin DB — solo
    // se cuenta si ya pasó el filtro de bot (a un bot no le damos ni esa
    // pista). Este sí es un error real y visible.
    $limite_excedido = false;
    if (!$es_bot) {
        $ip_clave = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x'), 0, 16);
        $archivo_limite = sys_get_temp_dir() . '/radio_suscribir_' . $ip_clave . '.json';
        $marcas = file_exists($archivo_limite) ? (@json_decode(file_get_contents($archivo_limite), true) ?? []) : [];
        $marcas = array_values(array_filter($marcas, fn($t) => $t > time() - 3600));
        if (count($marcas) >= 5) {
            $limite_excedido = true;
        } else {
            $marcas[] = time();
            @file_put_contents($archivo_limite, json_encode($marcas));
        }
    }

    $type  = in_array($_POST['type'] ?? '', ['telegram', 'email']) ? $_POST['type'] : '';
    $value = trim($_POST['value'] ?? '');
    $prefs = [];

    // Artistas desde chips seleccionados
    if (!empty($_POST['artist_chips']) && is_array($_POST['artist_chips'])) {
        foreach ($_POST['artist_chips'] as $k) {
            $k = trim($k);
            if ($k && mb_strlen($k) <= 60) $prefs[] = ['type' => 'artist', 'value' => $k];
        }
    }
    // Artistas desde campo libre
    if (!empty($_POST['keywords_artist'])) {
        foreach (preg_split('/[\n,]+/', $_POST['keywords_artist']) as $k) {
            $k = trim($k);
            if ($k && mb_strlen($k) <= 60) $prefs[] = ['type' => 'artist', 'value' => $k];
        }
    }
    // Programas / periodistas
    if (!empty($_POST['keywords_program'])) {
        foreach (preg_split('/[\n,]+/', $_POST['keywords_program']) as $k) {
            $k = trim($k);
            if ($k && mb_strlen($k) <= 60) $prefs[] = ['type' => 'program', 'value' => $k];
        }
    }

    // Géneros
    if (!empty($_POST['generos']) && is_array($_POST['generos'])) {
        foreach ($_POST['generos'] as $g) {
            $g = trim($g);
            if ($g) $prefs[] = ['type' => 'genre', 'value' => $g];
        }
    }

    // Deduplicar por tipo+valor
    $seen_prefs = []; $prefs_ok = [];
    foreach ($prefs as $p) {
        $key = ($p['type'] ?? '') . ':' . strtolower($p['value'] ?? '');
        if (!isset($seen_prefs[$key])) { $seen_prefs[$key] = true; $prefs_ok[] = $p; }
    }
    $prefs = $prefs_ok;

    if ($es_bot) {
        $pending_token = 'x'; // fingir éxito, no se toca la DB ni se envía nada
    } elseif ($limite_excedido) {
        $err = 'Demasiados intentos en poco tiempo. Probá de nuevo en un rato.';
    } elseif (!$type) {
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
        $exists = $db->prepare("SELECT id, active, token FROM subscribers WHERE contact_type=? AND lower(contact_value)=lower(?) LIMIT 1");
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
            $manage_url = 'https://mammoli.ar/radio/suscribirse.php?manage=' . $pending_token;
            $baja_url   = 'https://mammoli.ar/radio/suscribirse.php?baja='   . $pending_token;
            $msg = "📻 *Radio Argentina — Confirmar suscripción*\n\n"
                 . "Tus preferencias quedaron registradas. Para activarlas:\n\n"
                 . "👉 https://mammoli.ar/radio/suscribirse.php?activar=" . $pending_token . "\n\n"
                 . "Guardá estos links:\n"
                 . "✏️ Editar: {$manage_url}\n"
                 . "❌ Dar de baja: {$baja_url}\n\n"
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
            // Email: enviar confirmación con links de gestión
            $activar_url = 'https://mammoli.ar/radio/suscribirse.php?activar=' . $pending_token;
            $manage_url  = 'https://mammoli.ar/radio/suscribirse.php?manage='  . $pending_token;
            $baja_url    = 'https://mammoli.ar/radio/suscribirse.php?baja='    . $pending_token;
            $body = "Hola!\n\nTe suscribiste a alertas de Radio Argentina.\n\n"
                  . "Activá tu suscripción:\n{$activar_url}\n\n"
                  . "Guardá estos links para gestionar tu suscripción:\n"
                  . "✏️ Editar preferencias: {$manage_url}\n"
                  . "❌ Dar de baja: {$baja_url}\n\n"
                  . "Si no fuiste vos, ignorá este mensaje.\n\n— Radio Argentina";
            @mail($value, 'Confirmá tu suscripción — Radio Argentina', $body,
                "From: Radio Argentina <radio@mammoli.ar>\r\nContent-Type: text/plain; charset=UTF-8");
        }
    }
}

$cafecito  = defined('CAFECITO_URL') ? CAFECITO_URL : 'https://cafecito.app/mammoli';
$bot_name  = defined('BOT_NAME') ? BOT_NAME : 'claude_ariel_bot';
$render_ts = time(); // trampa de tiempo: momento en que se renderizó el form de suscripción
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
.hp{position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden}
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
.genre-chip.freq-hot:not(.sel){border-color:rgba(34,197,94,.5);color:var(--green)}
.genre-chip.freq-warm:not(.sel){border-color:rgba(59,130,246,.45);color:var(--accent)}
.genre-chip.freq-cool:not(.sel){color:var(--muted)}
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

<?php if ($mode === 'baja_ok'): ?>
  <div class="alert-ok">
    ✅ <strong>Suscripción cancelada.</strong><br>
    Ya no vas a recibir más alertas de Radio Argentina. Si cambiás de opinión podés volver a suscribirte cuando quieras.
    <br><br>
    <a href="/radio/">← Volver al player</a>
  </div>

<?php elseif ($mode === 'link_sent'): ?>
  <div class="alert-ok" style="color:var(--text)">
    📨 <strong>Listo.</strong> Si tu email o Telegram estaba registrado, te mandamos el link de gestión ahora mismo.
    <br><br>
    <a href="/radio/">← Volver al player</a>
  </div>

<?php elseif ($mode === 'baja_link'): ?>
  <?php if ($err): ?><div class="alert-err">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <div class="card">
    <h2>Gestionar mi suscripción</h2>
    <p style="font-size:14px;color:var(--muted);margin-bottom:16px">
      Ingresá tu Telegram o email y te mandamos el link para editar tus preferencias o darte de baja.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="send_link">
      <input type="hidden" name="ts2" value="<?= $render_ts ?>">
      <div class="hp" aria-hidden="true">
        <label for="web3">Dejar en blanco</label>
        <input type="text" id="web3" name="web3" tabindex="-1" autocomplete="off">
      </div>
      <div class="radio-group">
        <input type="radio" name="type" id="bl-tg" value="telegram" checked>
        <label for="bl-tg">📱 Telegram</label>
        <input type="radio" name="type" id="bl-em" value="email">
        <label for="bl-em">✉️ Email</label>
      </div>
      <input type="text" name="value" placeholder="Chat ID o correo electrónico">
      <button type="submit" class="btn">Enviar link de gestión</button>
    </form>
  </div>

<?php elseif ($mode === 'manage' && $manage_sub): ?>
  <?php
    $existing_prefs    = json_decode($manage_sub['preferences'] ?? '[]', true) ?: [];
    $existing_artists  = implode("\n", array_map(fn($p) => $p['value'], array_filter($existing_prefs, fn($p) => ($p['type'] ?? '') === 'artist')));
    $existing_programs = implode("\n", array_map(fn($p) => $p['value'], array_filter($existing_prefs, fn($p) => ($p['type'] ?? '') === 'program')));
    $existing_genres   = array_map(fn($p) => $p['value'], array_filter($existing_prefs, fn($p) => ($p['type'] ?? '') === 'genre'));
  ?>
  <?php if ($ok): ?>
  <div class="alert-ok">✅ <strong>Preferencias actualizadas.</strong></div>
  <?php endif; ?>
  <?php if ($err): ?><div class="alert-err">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="card">
    <h2>Editar mis alertas</h2>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
      <?= $manage_sub['contact_type'] === 'telegram' ? '📱 Telegram' : '✉️ Email' ?> ·
      Estado: <?= $manage_sub['active'] ? '<span style="color:var(--green)">Activa</span>' : '<span style="color:var(--yellow)">Pendiente activación</span>' ?>
    </p>
    <form method="post">
      <input type="hidden" name="action" value="editar">
      <input type="hidden" name="token" value="<?= htmlspecialchars($manage_sub['token']) ?>">

      <label style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:6px">🎤 Artistas o música</label>

      <?php if ($top_artistas): ?>
      <p style="font-size:12px;color:var(--muted);margin-bottom:8px">
        Artistas detectados en el aire — tocá para seleccionar:
      </p>
      <div class="genre-grid" id="artist-chip-grid">
        <?php
        $existing_artist_vals = array_map('strtolower', array_map(fn($p) => $p['value'],
            array_filter($existing_prefs, fn($p) => ($p['type'] ?? '') === 'artist')));
        foreach ($top_artistas as $a):
            $sel_a = in_array(strtolower($a['name']), $existing_artist_vals);
        ?>
        <div class="genre-chip freq-<?= $a['tier'] ?> <?= $sel_a ? 'sel' : '' ?>" data-val="<?= htmlspecialchars($a['name']) ?>" title="<?= $a['n'] ?> veces en el aire"><?= htmlspecialchars($a['name']) ?></div>
        <?php endforeach; ?>
      </div>
      <div id="artist-chip-hidden"></div>
      <p style="font-size:12px;color:var(--muted);margin-top:4px;margin-bottom:14px">
        ¿No está tu artista? Escribilo abajo.
      </p>
      <?php endif; ?>

      <label style="font-size:13px;color:var(--muted);margin-bottom:4px">Otros artistas / canciones específicas</label>
      <textarea name="keywords_artist" placeholder="Michael Jackson&#10;Gustavo Cerati"><?= htmlspecialchars($existing_artists) ?></textarea>
      <p style="font-size:12px;color:var(--muted);margin-top:-10px;margin-bottom:18px">Avisa cuando haya <strong>2 o más temas</strong> en 30 minutos.</p>

      <label style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:6px">📺 Programas o periodistas</label>
      <textarea name="keywords_program" placeholder="Feinmann&#10;La Cornisa&#10;Lanata"><?= htmlspecialchars($existing_programs) ?></textarea>
      <p style="font-size:12px;color:var(--muted);margin-top:-10px;margin-bottom:18px">Avisa en la <strong>primera detección</strong>.</p>

      <label>Géneros (opcional)</label>
      <div class="genre-grid" id="genre-grid">
        <?php foreach ($generos_disponibles as $g):
          $sel = in_array($g, $existing_genres);
        ?>
        <div class="genre-chip <?= $sel ? 'sel' : '' ?>" data-val="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></div>
        <?php endforeach; ?>
      </div>
      <div id="genre-hidden"></div>

      <button type="submit" class="btn" style="margin-top:8px">💾 Guardar cambios</button>
    </form>
  </div>

  <div class="card" style="border-color:rgba(239,68,68,.3)">
    <h2 style="color:var(--red)">Cancelar suscripción</h2>
    <p style="font-size:14px;color:var(--muted);margin-bottom:16px">
      Si cancelás, dejás de recibir alertas. Podés volver a suscribirte cuando quieras.
    </p>
    <a href="/radio/suscribirse.php?baja=<?= htmlspecialchars($manage_sub['token']) ?>"
       class="btn" style="background:#b91c1c;text-align:center;display:block;text-decoration:none"
       onclick="return confirm('¿Confirmar baja?')">
      ❌ Dar de baja
    </a>
  </div>

<?php elseif ($ok && $mode === 'register'): ?>
  <div class="alert-ok">
    ✅ <strong>¡Suscripción activada!</strong><br>
    A partir de ahora te vamos a avisar cuando detectemos tus preferencias en el aire.
    <br><br>
    <a href="/radio/">← Volver al player</a>
  </div>

<?php elseif ($ok): ?>
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
    <input type="hidden" name="ts" value="<?= $render_ts ?>">
    <div class="hp" aria-hidden="true">
      <label for="web2">Dejar en blanco</label>
      <input type="text" id="web2" name="web2" tabindex="-1" autocomplete="off">
    </div>

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
        <p style="font-size:12px;color:var(--muted);margin-top:-10px;margin-bottom:14px">
          Te vamos a mandar un email de confirmación. Hacé clic en el link para activar las alertas.
        </p>
      </div>
    </div>

    <!-- Preferencias -->
    <div class="card">
      <h2>¿Qué querés escuchar?</h2>

      <div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#f59e0b">
        🧪 <strong>Función experimental.</strong> Las alertas dependen de que la emisora transmita metadatos ICY con el nombre del artista o programa. Muchas emisoras argentinas no los envían todavía.
      </div>

      <label style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:6px">🎤 Artistas o música</label>

      <?php if ($top_artistas): ?>
      <p style="font-size:12px;color:var(--muted);margin-bottom:8px">
        Artistas detectados en el aire las últimas semanas — tocá para seleccionar:
      </p>
      <div class="genre-grid" id="artist-chip-grid">
        <?php foreach ($top_artistas as $a): ?>
        <div class="genre-chip freq-<?= $a['tier'] ?>" data-val="<?= htmlspecialchars($a['name']) ?>" title="<?= $a['n'] ?> veces en el aire"><?= htmlspecialchars($a['name']) ?></div>
        <?php endforeach; ?>
      </div>
      <div id="artist-chip-hidden"></div>
      <p style="font-size:12px;color:var(--muted);margin-top:4px;margin-bottom:14px">
        ¿No está tu artista? Escribilo abajo.
      </p>
      <?php endif; ?>

      <label style="font-size:13px;color:var(--muted);margin-bottom:4px">Otros artistas / canciones específicas</label>
      <textarea name="keywords_artist" placeholder="Michael Jackson&#10;Gustavo Cerati&#10;Soda Stereo"><?= htmlspecialchars($_POST['keywords_artist'] ?? '') ?></textarea>
      <p style="font-size:12px;color:var(--muted);margin-top:-10px;margin-bottom:18px">
        Te avisamos cuando una emisora lleve <strong>2 o más temas</strong> del artista en 30 minutos — así filtramos el tema suelto ocasional.
      </p>

      <label style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:6px">📺 Programas o periodistas</label>
      <textarea name="keywords_program" placeholder="Feinmann&#10;La Cornisa&#10;Lanata&#10;Baby Radio"><?= htmlspecialchars($_POST['keywords_program'] ?? '') ?></textarea>
      <p style="font-size:12px;color:var(--muted);margin-top:-10px;margin-bottom:18px">
        Te avisamos <strong>en la primera detección</strong> — si aparece el nombre en el aire, el programa ya arrancó.
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
    <a href="/radio/suscribirse.php?baja=1" style="color:var(--muted)">Editar o dar de baja</a> ·
    ¿Problemas? <a href="mailto:radio@mammoli.ar">Escribinos</a>
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

// Chips de artistas
document.querySelectorAll('#artist-chip-grid .genre-chip').forEach(function(chip){
  chip.addEventListener('click', function(){
    chip.classList.toggle('sel');
    updateArtistChipHidden();
  });
});
function updateArtistChipHidden(){
  var container = document.getElementById('artist-chip-hidden');
  if (!container) return;
  container.innerHTML = '';
  document.querySelectorAll('#artist-chip-grid .genre-chip.sel').forEach(function(c){
    var inp = document.createElement('input');
    inp.type='hidden'; inp.name='artist_chips[]'; inp.value=c.dataset.val;
    container.appendChild(inp);
  });
}
updateArtistChipHidden();
</script>
</body>
</html>
