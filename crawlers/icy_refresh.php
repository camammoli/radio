<?php
/**
 * icy_refresh.php — Refresca stream_title de todas las emisoras ICY.
 * Usa cURL Multi (20 simultáneas) para completar en ~20 segundos.
 *
 * Cron cPanel: * /10 * * * *  php /home/.../radio/crawlers/icy_refresh.php
 */

$BATCH_SIZE = 20;
$TIMEOUT    = 20;   // segundos por conexión
$t0         = microtime(true);

// Producción: crawlers/ junto a config.php y api/; local dev: junto a web/
$_base = file_exists(__DIR__ . '/../config.php') ? __DIR__ . '/..' : __DIR__ . '/../web';
require_once $_base . '/config.php';
require_once $_base . '/api/_db.php';

$db = radio_db();

$db->exec('CREATE TABLE IF NOT EXISTS icy_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL REFERENCES stations(id),
    title      TEXT    NOT NULL,
    seen_at    TEXT    NOT NULL
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_icy_hist_station ON icy_history(station_id, seen_at DESC)');

$rows = $db->query(
    "SELECT s.id, s.slug, s.url
     FROM stations s
     JOIN icy_cache ic ON ic.station_id = s.id
     WHERE ic.supported = 1 AND s.url IS NOT NULL AND s.url != ''"
)->fetchAll();

if (!$rows) {
    echo "Sin emisoras ICY\n";
    exit(0);
}

echo count($rows) . " emisoras a refrescar\n";

// ── Build handle ──────────────────────────────────────────────────────────────
// Usa stdClass como estado compartido: los objetos se pasan por handle en PHP,
// así las closures modifican el mismo estado sin necesitar referencias explícitas.

function icy_make_handle(string $url, int $timeout): ?array {
    if (preg_match('/\.(pls|m3u|m3u8)(\?|$)/i', $url)) return null;

    $st           = new stdClass();
    $st->metaint  = 0;
    $st->phase    = 'audio';
    $st->left     = 0;
    $st->metaLen  = 0;
    $st->buf      = '';
    $st->title    = null;
    $st->attempts = 0;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => max($timeout, 20),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'WinampMPEG/5.0',
        CURLOPT_HTTPHEADER     => ['Icy-MetaData: 1'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use ($st) {
            if (stripos($line, 'icy-metaint:') === 0) {
                $st->metaint = (int) trim(substr($line, 12));
                $st->left    = $st->metaint;
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($st) {
            if ($st->phase === 'done') return -1;
            if (!$st->metaint)         return -1;

            $pos = 0;
            $len = strlen($chunk);
            while ($pos < $len) {
                if ($st->phase === 'audio') {
                    $take     = min($st->left, $len - $pos);
                    $pos     += $take;
                    $st->left -= $take;
                    if ($st->left === 0) $st->phase = 'meta_len';

                } elseif ($st->phase === 'meta_len') {
                    $st->metaLen = ord($chunk[$pos]) * 16;
                    $pos++;
                    if ($st->metaLen === 0) {
                        $st->attempts++;
                        if ($st->attempts >= 4) { $st->phase = 'done'; return -1; }
                        $st->phase = 'audio';
                        $st->left  = $st->metaint;
                    } else {
                        $st->phase = 'meta';
                        $st->buf   = '';
                    }

                } elseif ($st->phase === 'meta') {
                    $need     = $st->metaLen - strlen($st->buf);
                    $take     = min($need, $len - $pos);
                    $st->buf .= substr($chunk, $pos, $take);
                    $pos     += $take;
                    if (strlen($st->buf) >= $st->metaLen) {
                        if (preg_match("/StreamTitle='([^']*)'/i", $st->buf, $m)) {
                            $t = trim($m[1]);
                            $st->title = $t !== '' ? $t : null;
                        }
                        $st->phase = 'done';
                        return -1;
                    }
                }
            }
            return $len;
        },
    ]);

    return ['ch' => $ch, 'st' => $st];
}

// ── Sentencias DB ─────────────────────────────────────────────────────────────

$stmtUpdate = $db->prepare(
    'INSERT INTO icy_cache (station_id, supported, stream_title, last_checked, last_title_change)
     VALUES (?,1,?,?,?)
     ON CONFLICT(station_id) DO UPDATE SET
       supported=1, stream_title=excluded.stream_title,
       last_checked=excluded.last_checked,
       last_title_change=CASE WHEN excluded.stream_title != stream_title
                              THEN excluded.last_title_change
                              ELSE last_title_change END'
);
$stmtPrev    = $db->prepare('SELECT stream_title FROM icy_cache WHERE station_id = ?');
$stmtHistory = $db->prepare('INSERT INTO icy_history (station_id, title, seen_at) VALUES (?,?,?)');
$stmtClean   = $db->prepare(
    'DELETE FROM icy_history WHERE station_id = ? AND id NOT IN
     (SELECT id FROM icy_history WHERE station_id = ? ORDER BY id DESC LIMIT 50)'
);

// ── Procesar en batches ───────────────────────────────────────────────────────

$updated = 0;
$skipped = 0;
$failed  = 0;

foreach (array_chunk($rows, $BATCH_SIZE) as $batch) {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($batch as $row) {
        $h = icy_make_handle($row['url'], $TIMEOUT);
        if (!$h) { $skipped++; continue; }
        $handles[(int)$h['ch']] = ['ch' => $h['ch'], 'st' => $h['st'],
                                   'id' => (int)$row['id'], 'slug' => $row['slug']];
        curl_multi_add_handle($mh, $h['ch']);
    }

    if (!$handles) { curl_multi_close($mh); continue; }

    // Ejecutar hasta que terminen todas (o timeout)
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.5);
    } while ($running > 0 && $status === CURLM_OK);

    // Recolectar resultados y guardar en DB
    $now = gmdate('Y-m-d H:i:s');
    foreach ($handles as &$info) {
        $title = $info['st']->title;
        if ($title !== null) {
            $stmtPrev->execute([$info['id']]);
            $prev    = $stmtPrev->fetchColumn();
            $changed = ($prev !== $title);
            $stmtUpdate->execute([$info['id'], $title, $now, $changed ? $now : null]);
            if ($changed) {
                $stmtHistory->execute([$info['id'], $title, $now]);
                $stmtClean->execute([$info['id'], $info['id']]);
            }
            $updated++;
            echo "  + {$info['slug']}: {$title}\n";
        } else {
            $failed++;
        }
        curl_multi_remove_handle($mh, $info['ch']);
        curl_close($info['ch']);
    }
    unset($info);
    curl_multi_close($mh);
}

$elapsed = round(microtime(true) - $t0, 1);
$now_fin  = gmdate('Y-m-d H:i:s');
echo "Listo: {$updated} actualizadas, {$failed} sin título, {$skipped} sin soporte ({$elapsed}s)\n";

// Registrar en crawler_runs para el panel admin
try {
    $db->prepare(
        'INSERT INTO crawler_runs (crawler, started_at, finished_at, stations_checked, changes_detected, errors, notes)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        'icy-refresh',
        gmdate('Y-m-d H:i:s', (int)$t0),
        $now_fin,
        $updated + $failed,
        $updated,
        $failed,
        "skipped: {$skipped}",
    ]);
} catch (Exception $e) {
    // No interrumpir si falla el log
}
