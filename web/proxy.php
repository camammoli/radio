<?php
/**
 * proxy.php — resuelve y pipa streams de audio para el player web
 *
 * Recibe el slug de una emisora ya existente en la DB (nunca una URL
 * arbitraria del visitante) — antes aceptaba ?url= directo, lo que lo
 * convertía en un proxy HTTP abierto hacia cualquier destino. La URL real
 * se busca server-side a partir del slug.
 *
 * Problemas que resuelve:
 *  - HTTP streams en página HTTPS (mixed content bloqueado por el browser)
 *  - .pls / .m3u playlists que el elemento <audio> no puede parsear
 *
 * Uso desde JS:
 *   /radio/proxy.php?station=SLUG
 */

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/_ssrf_guard.php';
require_once __DIR__ . '/api/_db.php';

$slug = trim($_GET['station'] ?? '');
if ($slug === '' || !preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
    http_response_code(400);
    exit('Emisora inválida');
}

$db  = radio_db();
$stm = $db->prepare('SELECT url FROM stations WHERE slug = ? AND approved = 1 LIMIT 1');
$stm->execute([$slug]);
$url = $stm->fetchColumn();

if (!$url || !radio_url_is_safe($url)) {
    http_response_code(404);
    exit('Emisora no encontrada');
}

// ── Resolución de playlists ───────────────────────────────────────────────────
$isPls  = (bool) preg_match('/\.pls(\?.*)?$/i', $url);
$isM3u  = (bool) preg_match('/\.m3u(\?.*)?$/i', $url) && !preg_match('/\.m3u8(\?.*)?$/i', $url);

if ($isPls || $isM3u) {
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'Mozilla/5.0']]);
    $content = @file_get_contents($url, false, $ctx);

    if ($content === false) {
        http_response_code(502);
        exit('No se pudo leer la playlist');
    }

    $resolved = '';

    if ($isPls) {
        if (preg_match('/File1=([^\r\n]+)/i', $content, $m)) {
            $resolved = trim($m[1]);
        }
    } else {
        // M3U: primera línea no-comentario con URL
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line && $line[0] !== '#' && preg_match('#^https?://#', $line)) {
                $resolved = $line;
                break;
            }
        }
    }

    if (!$resolved || !radio_url_is_safe($resolved)) {
        http_response_code(502);
        exit('Playlist vacía o formato desconocido');
    }

    $url = $resolved;
}

// Si la URL (directa o resuelta de playlist) es HTTPS, redirigir al browser
// en vez de pipear por PHP. El hosting compartido corta las respuestas
// largas de proxy.php a los ~300s (confirmado: la misma URL con VLC directo
// nunca se corta) — evitarlo del todo es mejor que sostener la conexión acá.
// La URL real deja de estar oculta en este caso, pero nunca fue secreta.
if (strpos($url, 'https://') === 0) {
    header('Location: ' . $url);
    exit;
}

// ── Proxy del stream (solo queda HTTP acá) ────────────────────────────────────
set_time_limit(0);
ignore_user_abort(false);

if (!function_exists('curl_init')) {
    http_response_code(500);
    exit('cURL no disponible');
}

header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION  => true,
    CURLOPT_MAXREDIRS       => 5,
    CURLOPT_TIMEOUT         => 0,
    CURLOPT_CONNECTTIMEOUT  => 8,
    CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; radio-proxy/1.0)',
    CURLOPT_HTTPHEADER      => ['Icy-MetaData: 0'],
    CURLOPT_HEADERFUNCTION  => function ($ch, $header) {
        $lower = strtolower(trim($header));
        if (strpos($lower, 'content-type:') === 0) {
            header(trim($header));
        }
        return strlen($header);
    },
    CURLOPT_WRITEFUNCTION   => function ($ch, $data) {
        echo $data;
        if (ob_get_level()) { ob_flush(); }
        flush();
        return connection_aborted() ? -1 : strlen($data);
    },
]);

radio_log('stream', $url);
$ok = curl_exec($ch);
if (!$ok && !connection_aborted()) {
    http_response_code(502);
}
curl_close($ch);
