<?php
/**
 * nowplaying.php — GET /api/nowplaying?slug=SLUG
 *
 * 1. Sirve desde icy_cache (DB) si fue chequeado en los últimos 60s.
 * 2. Si está desactualizado, fetcha el stream ICY en tiempo real y actualiza la caché.
 *
 * También acepta ?url=URL directo (para compatibilidad con v1 nowplaying.php).
 * Respuesta: {ok, title, cached, checked_at}
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET');

$db   = radio_db();
$slug = str_param('slug', 100);
$url  = str_param('url', 500);   // compatibilidad v1

// Resolver URL desde slug o usarla directamente
$station_id = null;
if ($slug !== '') {
    $r = $db->prepare('SELECT id, url FROM stations WHERE slug = ? LIMIT 1');
    $r->execute([$slug]);
    if ($row = $r->fetch()) {
        $station_id = (int)$row['id'];
        $url        = $row['url'];
    }
}

if ($url === '') api_error('slug o url requerido', 400);

// ── Leer caché ────────────────────────────────────────────────────────────────

if ($station_id) {
    $cache = $db->prepare(
        "SELECT stream_title, last_checked,
                (strftime('%s','now') - strftime('%s', last_checked)) AS age_s
         FROM icy_cache WHERE station_id = ? AND supported = 1"
    );
    $cache->execute([$station_id]);
    $cached = $cache->fetch();

    if ($cached && $cached['age_s'] < 60 && $cached['stream_title']) {
        api_response([
            'title'      => $cached['stream_title'],
            'cached'     => true,
            'checked_at' => $cached['last_checked'],
        ]);
    }
}

// ── Fetch ICY en tiempo real ──────────────────────────────────────────────────

function fetch_icy_title(string $url, int $timeout = 5): ?string {
    // Solo HTTP (sockets directos)
    if (!str_starts_with($url, 'http://')) return null;

    $parsed = parse_url($url);
    $host   = $parsed['host'] ?? '';
    $port   = $parsed['port'] ?? 80;
    $path   = ($parsed['path'] ?? '/');
    if (isset($parsed['query'])) $path .= '?' . $parsed['query'];
    if (!$path) $path = '/';

    $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$sock) return null;

    stream_set_timeout($sock, $timeout);
    fwrite($sock, "GET $path HTTP/1.0\r\nHost: $host\r\nIcy-MetaData: 1\r\nUser-Agent: WinampMPEG/5.0\r\n\r\n");

    // Leer cabeceras
    $headers = '';
    $metaint = 0;
    while (!feof($sock)) {
        $line = fgets($sock, 512);
        if ($line === "\r\n") break;
        $headers .= $line;
        if (stripos($line, 'icy-metaint:') === 0) {
            $metaint = (int)trim(substr($line, 12));
        }
    }

    if (!$metaint) { fclose($sock); return null; }

    // Leer hasta el primer bloque de metadata
    $audio = '';
    $need  = $metaint;
    while (!feof($sock) && strlen($audio) < $need) {
        $chunk = fread($sock, $need - strlen($audio));
        if ($chunk === false || $chunk === '') break;
        $audio .= $chunk;
    }

    // Leer longitud del bloque de metadata (1 byte × 16)
    $len_byte = fread($sock, 1);
    if ($len_byte === false || $len_byte === '') { fclose($sock); return null; }
    $meta_len = ord($len_byte) * 16;

    $title = null;
    if ($meta_len > 0) {
        $meta = '';
        while (!feof($sock) && strlen($meta) < $meta_len) {
            $chunk = fread($sock, $meta_len - strlen($meta));
            if ($chunk === false || $chunk === '') break;
            $meta .= $chunk;
        }
        if (preg_match("/StreamTitle='([^;]*)'/", $meta, $m)) {
            $title = trim($m[1]);
            if ($title === '') $title = null;
        }
    }

    fclose($sock);
    return $title;
}

$title = fetch_icy_title($url);
$now   = gmdate('Y-m-d H:i:s');

// ── Actualizar caché en DB ────────────────────────────────────────────────────

if ($station_id) {
    if ($title !== null) {
        // Ver si el título cambió para registrar last_title_change
        $prev = $db->prepare('SELECT stream_title FROM icy_cache WHERE station_id = ?');
        $prev->execute([$station_id]);
        $prev_title = $prev->fetchColumn();
        $changed    = ($prev_title !== $title);

        $db->prepare(
            'INSERT INTO icy_cache (station_id, supported, stream_title, last_checked, last_title_change)
             VALUES (?,1,?,?,?)
             ON CONFLICT(station_id) DO UPDATE SET
               supported=1, stream_title=excluded.stream_title,
               last_checked=excluded.last_checked,
               last_title_change=CASE WHEN excluded.stream_title != stream_title
                                      THEN excluded.last_title_change
                                      ELSE last_title_change END'
        )->execute([$station_id, $title, $now, $changed ? $now : null]);
    } else {
        $db->prepare(
            'INSERT INTO icy_cache (station_id, supported, last_checked)
             VALUES (?,0,?)
             ON CONFLICT(station_id) DO UPDATE SET last_checked=excluded.last_checked'
        )->execute([$station_id, $now]);
    }
}

api_response([
    'title'      => $title,
    'cached'     => false,
    'checked_at' => $now,
]);
