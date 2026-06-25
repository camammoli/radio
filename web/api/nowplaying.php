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

// ── Batch: todos los títulos ICY en caché ─────────────────────────────────────

if (isset($_GET['batch'])) {
    $rows = $db->query(
        "SELECT s.slug, ic.stream_title
         FROM icy_cache ic
         JOIN stations s ON s.id = ic.station_id
         WHERE ic.supported = 1
           AND ic.stream_title IS NOT NULL AND ic.stream_title != ''
           AND (strftime('%s','now') - strftime('%s', ic.last_checked)) < 25200"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    api_response($rows ?: new stdClass());
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

    // Servir desde caché si fue chequeado en los últimos 60s
    if ($cached && $cached['age_s'] < 60 && $cached['stream_title']) {
        api_response([
            'title'      => $cached['stream_title'],
            'cached'     => true,
            'checked_at' => $cached['last_checked'],
        ]);
    }
}

// ── Fetch ICY en tiempo real (cURL — HTTP + HTTPS) ───────────────────────────

function fetch_icy_title(string $url, int $timeout = 6): ?string {
    if (!function_exists('curl_init')) return null;

    // attempts: algunos servidores envían el primer bloque de metadata vacío
    $st = ['metaint' => 0, 'phase' => 'audio', 'audio_left' => 0,
           'meta_len' => 0, 'buf' => '', 'title' => null, 'attempts' => 0];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => max($timeout, 15),  // mínimo 15s: a 48kbps un bloque tarda ~2.7s
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => 'WinampMPEG/5.0',
        CURLOPT_HTTPHEADER     => ['Icy-MetaData: 1'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$st) {
            if (stripos($line, 'icy-metaint:') === 0) {
                $st['metaint']    = (int) trim(substr($line, 12));
                $st['audio_left'] = $st['metaint'];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$st) {
            if ($st['phase'] === 'done') return -1;
            if (!$st['metaint'])         return -1;

            $pos = 0;
            $len = strlen($chunk);

            while ($pos < $len) {
                if ($st['phase'] === 'audio') {
                    $take = min($st['audio_left'], $len - $pos);
                    $pos += $take;
                    $st['audio_left'] -= $take;
                    if ($st['audio_left'] === 0) $st['phase'] = 'meta_len';

                } elseif ($st['phase'] === 'meta_len') {
                    $st['meta_len'] = ord($chunk[$pos]) * 16;
                    $pos++;
                    if ($st['meta_len'] === 0) {
                        // Bloque vacío — intentar el siguiente (máx 4)
                        $st['attempts']++;
                        if ($st['attempts'] >= 4) { $st['phase'] = 'done'; return -1; }
                        $st['phase']      = 'audio';
                        $st['audio_left'] = $st['metaint'];
                    } else {
                        $st['phase'] = 'meta';
                        $st['buf']   = '';
                    }

                } elseif ($st['phase'] === 'meta') {
                    $need = $st['meta_len'] - strlen($st['buf']);
                    $take = min($need, $len - $pos);
                    $st['buf'] .= substr($chunk, $pos, $take);
                    $pos += $take;
                    if (strlen($st['buf']) >= $st['meta_len']) {
                        if (preg_match("/StreamTitle='([^']*)'/i", $st['buf'], $m)) {
                            $t = trim($m[1]);
                            $st['title'] = $t !== '' ? $t : null;
                        }
                        $st['phase'] = 'done';
                        return -1;
                    }
                }
            }
            return $len;
        },
    ]);

    curl_exec($ch);
    curl_close($ch);
    return $st['title'];
}

$title = fetch_icy_title($url);
$now   = gmdate('Y-m-d H:i:s');

// Si el fetch en tiempo real falló pero tenemos datos en caché, usar de fallback
if ($title === null && isset($cached) && $cached && $cached['stream_title']) {
    api_response([
        'title'      => $cached['stream_title'],
        'cached'     => true,
        'checked_at' => $cached['last_checked'],
    ]);
}

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
