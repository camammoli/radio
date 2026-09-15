<?php
/**
 * faq.php — cómo conectar Radio Argentina a reproductores externos.
 *
 * Pedido recurrente desde 2026-07 (TKT-0728, quedó pendiente a propósito
 * hasta tener más demanda). El M3U (?m3u=1 → api/playlist.m3u) es la URL
 * contractual, ya estable — esto solo documenta cómo usarla en cada
 * reproductor, con pasos numerados en vez de párrafos (idea tomada de
 * cómo Carrizo Logística / FP Construcción explican sus procesos).
 */
$__base = defined('RADIO_BASE') ? RADIO_BASE : '/radio';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Cómo conectar Radio Argentina a otros reproductores</title>
<meta name="description" content="Guía paso a paso para escuchar Radio Argentina en VLC, Kodi, Rhythmbox o cualquier reproductor compatible con M3U.">
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
.nav a,.nav button{color:var(--muted);text-decoration:none;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:transparent;cursor:pointer;transition:background .15s}
.nav a:hover,.nav button:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.container{max-width:640px;margin:0 auto;padding:28px 16px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:22px 24px;margin-bottom:16px}
.card h2{font-size:1rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px}
.card > p.intro{font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.6}
.pasos{list-style:none;counter-reset:paso;margin:0;padding:0}
.pasos li{counter-increment:paso;display:flex;gap:14px;padding:10px 0;border-top:1px solid var(--border)}
.pasos li:first-child{border-top:none}
.pasos li::before{
  content:counter(paso);
  flex:0 0 26px;height:26px;border-radius:50%;
  background:var(--accent);color:#fff;font-size:13px;font-weight:700;
  display:flex;align-items:center;justify-content:center;
}
.pasos li span{font-size:14px;line-height:1.6;padding-top:2px}
.pasos code{background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px;font-size:13px;word-break:break-all}
body.light .pasos code{background:rgba(0,0,0,.06)}
.m3u-box{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.m3u-box code{font-size:12px;word-break:break-all}
.m3u-box button{background:var(--accent);color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;white-space:nowrap}
.m3u-box button:hover{opacity:.85}
.nota{font-size:12px;color:var(--muted);margin-top:14px;line-height:1.5}
.empty{color:var(--muted);font-size:13px;padding:12px 0;text-align:center}
</style>
</head>
<body>
<header>
  <h1>🎧 Conectar otros reproductores</h1>
  <p class="sub">VLC, Kodi, Rhythmbox y cualquier programa compatible con M3U</p>
</header>
<div class="nav">
  <a href="<?= $__base ?>/">← Volver al listado</a>
  <a href="<?= $__base ?>/contacto.php">✉️ Contacto</a>
  <button id="theme-btn">☀️ Modo claro</button>
</div>

<div class="container">

<div class="card">
  <h2>📻 Tu lista M3U</h2>
  <p class="intro">Este es el link que vas a usar en todos los reproductores de abajo — abre las 1200+ emisoras del catálogo, siempre actualizado, sin instalar nada extra.</p>
  <div class="m3u-box">
    <code id="m3u-url">https://mammoli.ar<?= $__base ?>/api/playlist.m3u</code>
    <button id="btn-copiar">Copiar</button>
  </div>
  <p class="nota" id="copiado" style="display:none;color:#22c55e">✓ Copiado al portapapeles</p>
</div>

<div class="card">
  <h2>🎬 VLC (Windows, Mac, Linux)</h2>
  <ol class="pasos">
    <li><span>Abrí VLC y andá a <strong>Medio → Abrir ubicación de red</strong> (Ctrl+N).</span></li>
    <li><span>Pegá el link M3U de arriba y tocá <strong>Reproducir</strong>.</span></li>
    <li><span>VLC va a cargar el catálogo completo como una lista de reproducción — buscá la emisora por nombre en el panel de la izquierda.</span></li>
  </ol>
</div>

<div class="card">
  <h2>📺 Kodi</h2>
  <ol class="pasos">
    <li><span>Instalá el addon <strong>PVR IPTV Simple Client</strong> desde el repositorio oficial de Kodi (Complementos → Descargar → PVR).</span></li>
    <li><span>Configurá el addon: en <strong>M3U Play List URL</strong> pegá el link de arriba.</span></li>
    <li><span>Reiniciá Kodi (o recargá el addon) — las emisoras aparecen en la sección de TV/Radio en vivo.</span></li>
  </ol>
</div>

<div class="card">
  <h2>🐧 Rhythmbox (Linux)</h2>
  <ol class="pasos">
    <li><span>Abrí Rhythmbox y andá a <strong>Música → Abrir ubicación</strong> (Ctrl+O).</span></li>
    <li><span>Pegá el link M3U de arriba y confirmá.</span></li>
    <li><span>Se agrega como una fuente nueva en el panel izquierdo, con todas las emisoras dentro.</span></li>
  </ol>
</div>

<div class="card">
  <h2>📱 Otros reproductores (Android/iOS/genéricos)</h2>
  <p class="intro">Cualquier app que acepte cargar una playlist M3U por URL funciona igual: buscá una opción del tipo "Agregar por URL", "Open network stream" o "Import playlist" y pegá el mismo link.</p>
  <p class="nota">¿Probaste con otro reproductor y no funcionó? <a href="<?= $__base ?>/contacto.php" style="color:var(--accent)">Contanos</a> y lo agregamos a esta guía.</p>
</div>

</div>

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

  document.getElementById('btn-copiar').addEventListener('click', function(){
    var texto = document.getElementById('m3u-url').textContent;
    navigator.clipboard.writeText(texto).then(function(){
      var aviso = document.getElementById('copiado');
      aviso.style.display = 'block';
      setTimeout(function(){ aviso.style.display = 'none'; }, 2000);
    });
  });
})();
</script>
</body>
</html>
