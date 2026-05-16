<?php
/**
 * proxy.php — resuelve y pipa streams de audio para el player web
 *
 * Problemas que resuelve:
 *  - HTTP streams en página HTTPS (mixed content bloqueado por el browser)
 *  - .pls / .m3u playlists que el elemento <audio> no puede parsear
 *
 * Uso desde JS:
 *   /radio/proxy.php?url=http%3A%2F%2F...
 *   /radio/proxy.php?url=http%3A%2F%2F...stream.pls
 *   /radio/proxy.php?url=https%3A%2F%2F...listen.m3u%3Fradio%3D123
 */

require_once __DIR__ . '/log.php';

$url = rawurldecode($_GET['url'] ?? '');

// ── Validación básica ─────────────────────────────────────────────────────────
if (!preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    exit('URL inválida');
}

// Protección SSRF: bloquear redes privadas y loopback
$host = parse_url($url, PHP_URL_HOST);
if (!$host || preg_match(
    '#^(localhost|127\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|::1$|0\.0\.0\.0)#i',
    $host
)) {
    http_response_code(403);
    exit('Acceso denegado');
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

    if (!$resolved) {
        http_response_code(502);
        exit('Playlist vacía o formato desconocido');
    }

    // Si el stream resuelto es HTTPS, redirigir directamente al browser
    if (strpos($resolved, 'https://') === 0) {
        header('Location: ' . $resolved);
        exit;
    }

    // Si sigue siendo HTTP, seguir y proxiarlo
    $url = $resolved;
}

// ── Proxy del stream ──────────────────────────────────────────────────────────
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
