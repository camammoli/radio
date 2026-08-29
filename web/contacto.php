<?php
/**
 * contacto.php — formulario público de contacto general
 * (reemplaza al mailto directo del pedido de ayuda / "contame tus ideas")
 *
 * El envío se hace por GET vía fetch() desde JS, no por POST de formulario
 * nativo — este hosting rechaza con 406 (Mod_Security) cualquier POST cuyo
 * User-Agent sea un navegador real, sin importar el Content-Type. Mismo
 * patrón ya usado en el Starlink Panel para esquivarlo. El parámetro
 * "enviar" marca que es un pedido de envío (JSON), no la carga normal
 * de la página.
 */

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

if (!defined('TG_TOKEN'))   define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID')) define('TG_CHAT_ID', '');

function tg_send(string $text): void {
    if (!TG_TOKEN || !TG_CHAT_ID) return;
    $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['chat_id' => TG_CHAT_ID, 'text' => $text],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Envío (GET con ?enviar=1, o POST — se deja POST funcionando por si se
// prueba con curl/scripts, pero el frontend usa GET) — responde JSON ──────
if (isset($_GET['enviar']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

    // Honeypot: campo oculto para humanos, invisible por CSS. Un bot que
    // completa todos los inputs del formulario lo va a llenar; un humano no.
    $honeypot = trim($src['web'] ?? '');

    // Trampa de tiempo: "ts" es el timestamp (server-side) de cuando se
    // renderizó la página. Un envío a menos de 2s de haberse mostrado es
    // casi seguro un bot (ningún humano lee y completa el form tan rápido).
    $ts_render = (int) ($src['ts'] ?? 0);
    $es_rapido = $ts_render <= 0 || (time() - $ts_render) < 2;

    $nombre  = trim(strip_tags($src['nombre']  ?? ''));
    $email   = trim(strip_tags($src['email']   ?? ''));
    $mensaje = trim(strip_tags($src['mensaje'] ?? ''));

    if ($honeypot !== '' || $es_rapido) {
        // Bot: fingir éxito sin guardar ni notificar, no darle feedback útil.
        echo json_encode(['ok' => true]);
        exit;
    }

    // Rate limit: 5 mensajes por IP por hora, sin DB — este sí es un error
    // real y visible, un humano de verdad casi nunca lo choca.
    $ip_clave = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x'), 0, 16);
    $archivo_limite = sys_get_temp_dir() . '/radio_contacto_' . $ip_clave . '.json';
    $marcas = file_exists($archivo_limite) ? (@json_decode(file_get_contents($archivo_limite), true) ?? []) : [];
    $marcas = array_values(array_filter($marcas, fn($t) => $t > time() - 3600));
    if (count($marcas) >= 5) {
        echo json_encode(['ok' => false, 'error' => 'Demasiados mensajes en poco tiempo. Probá de nuevo en un rato.']);
        exit;
    }
    $marcas[] = time();
    @file_put_contents($archivo_limite, json_encode($marcas));

    if (strlen($mensaje) < 5) {
        echo json_encode(['ok' => false, 'error' => 'Contanos un poco más — el mensaje es muy corto.']);
        exit;
    }
    if (strlen($mensaje) > 2000) {
        echo json_encode(['ok' => false, 'error' => 'El mensaje es demasiado largo (máximo 2000 caracteres).']);
        exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'El email no parece válido — dejalo vacío si preferís no compartirlo.']);
        exit;
    }

    try {
        $db = radio_db();
        $db->exec('CREATE TABLE IF NOT EXISTS contacto_mensajes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT,
            email TEXT,
            mensaje TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $db->prepare(
            'INSERT INTO contacto_mensajes (nombre, email, mensaje, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$nombre ?: null, $email ?: null, $mensaje, date('c')]);

        $quien = $nombre ?: 'Anónimo';
        $contacto_linea = $email ? "\nContacto: {$email}" : '';
        tg_send("📻 [Radio Argentina] Nuevo mensaje de contacto\nDe: {$quien}{$contacto_linea}\n\n{$mensaje}");

        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo enviar el mensaje. Intentá de nuevo más tarde, o escribinos por Telegram/redes.']);
    }
    exit;
}
$render_ts = time(); // trampa de tiempo: momento en que se renderizó el form
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contacto · Radio Argentina</title>
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
input,textarea{width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:14px;outline:none;transition:border-color .15s;font-family:inherit}
textarea{resize:vertical;min-height:120px}
input:focus,textarea:focus{border-color:var(--accent)}
body.light input,body.light textarea{background:#f9fafb}
.hint{font-size:11px;color:var(--muted);margin-top:3px}
.btn{width:100%;padding:11px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:15px;font-weight:600;cursor:pointer;margin-top:20px;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn:disabled{opacity:.5;cursor:not-allowed}
.error{background:#2a0a0a;border:1px solid var(--red);border-radius:8px;padding:12px 14px;color:#ff8a8a;font-size:13px;margin-bottom:16px;line-height:1.5}
.success{background:#0a2e12;border:1px solid var(--green);border-radius:8px;padding:16px;color:#7dd49f;font-size:14px;line-height:1.6;text-align:center}
.success strong{display:block;font-size:1.1rem;margin-bottom:6px}
/* Honeypot: fuera de pantalla, invisible pero completable por bots simples */
.hp{position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden}
</style>
</head>
<body>
<header>
  <h1>✉️ Contacto</h1>
  <p class="sub">Ideas, sugerencias, o simplemente saludar</p>
</header>
<div class="nav">
  <a href="index.php">← Volver al player</a>
  <button id="theme-btn">☀️ Modo claro</button>
</div>
<div class="container">
<div class="card" id="card">
  <h2>Escribinos</h2>
  <p>¿Se te ocurre algo para mejorar el sitio? ¿Encontraste un problema? ¿Solo querés saludar? Este mensaje me llega directo.</p>

  <div id="msg-area"></div>

  <form id="form-contacto">
    <label for="nombre">Tu nombre <span style="font-weight:normal">(opcional)</span></label>
    <input type="text" id="nombre" name="nombre" maxlength="100">

    <label for="email">Tu email <span style="font-weight:normal">(opcional, solo si querés respuesta)</span></label>
    <input type="email" id="email" name="email" maxlength="150" placeholder="tu@email.com">

    <label for="mensaje">Mensaje *</label>
    <textarea id="mensaje" name="mensaje" required maxlength="2000" placeholder="Contame..."></textarea>

    <input type="hidden" id="ts" name="ts" value="<?= $render_ts ?>">
    <div class="hp" aria-hidden="true">
      <label for="web">No completar</label>
      <input type="text" id="web" name="web" tabindex="-1" autocomplete="off">
    </div>

    <button type="submit" class="btn" id="btn-contacto">Enviar mensaje</button>
  </form>
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

  var form = document.getElementById('form-contacto');
  var msgArea = document.getElementById('msg-area');
  var btnEl = document.getElementById('btn-contacto');

  form.addEventListener('submit', function(e){
    e.preventDefault();
    btnEl.disabled = true;
    btnEl.textContent = 'Enviando...';
    msgArea.innerHTML = '';

    var params = new URLSearchParams({
      enviar: '1',
      nombre: document.getElementById('nombre').value,
      email: document.getElementById('email').value,
      mensaje: document.getElementById('mensaje').value,
      web: document.getElementById('web').value,
      ts: document.getElementById('ts').value
    });

    fetch('contacto.php?' + params.toString())
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) {
          form.style.display = 'none';
          msgArea.innerHTML = '<div class="success"><strong>¡Gracias por escribir!</strong>Leo todos los mensajes personalmente — si dejaste tu email y hace falta, te respondo.</div>';
        } else {
          msgArea.innerHTML = '<div class="error">⚠️ ' + (data.error || 'No se pudo enviar el mensaje.') + '</div>';
          btnEl.disabled = false;
          btnEl.textContent = 'Enviar mensaje';
        }
      })
      .catch(function(){
        msgArea.innerHTML = '<div class="error">⚠️ No se pudo enviar el mensaje. Intentá de nuevo más tarde.</div>';
        btnEl.disabled = false;
        btnEl.textContent = 'Enviar mensaje';
      });
  });
})();
</script>
</body>
</html>
