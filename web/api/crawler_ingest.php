<?php
/**
 * crawler_ingest.php — API de lectura/escritura para los crawlers Python.
 *
 * Reemplaza el patrón viejo (GitHub Actions baja el .sqlite completo por FTP,
 * el script lo edita en el runner, se vuelve a subir) — con MySQL eso deja de
 * ser posible (Remote MySQL no acepta conexiones desde los runners de GitHub,
 * sin IP fija) y tampoco conviene: los crawlers ahora solo hacen el trabajo de
 * red (chequear streams, consultar APIs externas) y mandan el resultado acá,
 * que es quien realmente escribe en la DB (mismo motor que usa el resto del
 * sitio, vía radio_db()).
 *
 * Auth: header X-Crawler-Token o ?token=, contra CRAWLER_TOKEN en config.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

$token = $_SERVER['HTTP_X_CRAWLER_TOKEN'] ?? ($_GET['token'] ?? '');
if (!defined('CRAWLER_TOKEN') || $token === '' || !hash_equals(CRAWLER_TOKEN, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$db     = radio_db();
$accion = $_GET['accion'] ?? $_POST['accion'] ?? (json_body()['accion'] ?? '');

// ── Lecturas ──────────────────────────────────────────────────────────────────

if ($accion === 'stations_check_context') {
    // Para check_streams_v2.py: catálogo aprobado + estado previo.
    $rows = $db->query("
        SELECT s.id, s.slug, s.nombre, s.url,
               COALESCE(ss.estado, 'unknown')       AS prev_estado,
               COALESCE(ss.consecutive_failures, 0) AS prev_fails,
               COALESCE(ic.supported, 0)            AS prev_icy
        FROM stations s
        LEFT JOIN stream_status ss ON ss.station_id = s.id
        LEFT JOIN icy_cache     ic ON ic.station_id = s.id
        WHERE s.approved = 1
        ORDER BY s.id
    ")->fetchAll();
    api_response($rows);
}

if ($accion === 'stations_full') {
    // Para enrich_v2.py / competitor_scan.py / dedupe_streamtheworld_v2.py /
    // hunt_stations_v2.py / gist_sync.py: catálogo completo con metadata.
    $rows = $db->query("
        SELECT s.id, s.n, s.slug, s.nombre, s.url, s.provincia, s.tags, s.codec,
               s.bitrate, s.homepage, s.logo, s.source, s.approved, s.rb_uuid,
               s.rb_votes, s.rb_clicks, s.created_at, s.updated_at,
               COALESCE(ss.estado, 'unknown') AS estado, ss.last_ok
        FROM stations s
        LEFT JOIN stream_status ss ON ss.station_id = s.id
        ORDER BY s.id
    ")->fetchAll();
    api_response($rows);
}

// ── Escrituras ────────────────────────────────────────────────────────────────

if ($accion === 'check_streams_report') {
    api_method('POST');
    $body       = json_body();
    $results    = $body['results'] ?? [];
    $started_at = $body['started_at'] ?? gmdate('Y-m-d H:i:s');
    $icy_titles = $body['icy_titles'] ?? []; // {station_id: stream_title|null}

    if (!is_array($results)) api_error('results debe ser un array', 400);

    $DOWN_AFTER = 2;

    $selPrev = $db->prepare(
        "SELECT COALESCE(ss.estado,'unknown') AS prev_estado,
                COALESCE(ss.consecutive_failures,0) AS prev_fails,
                COALESCE(ic.supported,0) AS prev_icy
         FROM stations s
         LEFT JOIN stream_status ss ON ss.station_id = s.id
         LEFT JOIN icy_cache     ic ON ic.station_id = s.id
         WHERE s.id = ?"
    );

    $sqlStatusUpsert = db_engine() === 'mysql'
        ? "INSERT INTO stream_status
                (station_id, estado, http_code, response_ms, consecutive_failures,
                 last_checked, last_ok, updated_at)
           VALUES (?, ?, ?, ?, ?, " . sql_now() . ", ?, " . sql_now() . ")
           ON DUPLICATE KEY UPDATE
                estado               = VALUES(estado),
                http_code            = VALUES(http_code),
                response_ms          = VALUES(response_ms),
                consecutive_failures = VALUES(consecutive_failures),
                last_checked         = VALUES(last_checked),
                last_ok              = CASE WHEN VALUES(estado) = 'ok'
                                           THEN VALUES(last_ok)
                                           ELSE stream_status.last_ok END,
                updated_at           = VALUES(updated_at)"
        : "INSERT INTO stream_status
                (station_id, estado, http_code, response_ms, consecutive_failures,
                 last_checked, last_ok, updated_at)
           VALUES (?, ?, ?, ?, ?, " . sql_now() . ", ?, " . sql_now() . ")
           ON CONFLICT(station_id) DO UPDATE SET
                estado               = excluded.estado,
                http_code            = excluded.http_code,
                response_ms          = excluded.response_ms,
                consecutive_failures = excluded.consecutive_failures,
                last_checked         = excluded.last_checked,
                last_ok              = CASE WHEN excluded.estado = 'ok'
                                           THEN excluded.last_ok
                                           ELSE stream_status.last_ok END,
                updated_at           = excluded.updated_at";
    $stmtStatus = $db->prepare($sqlStatusUpsert);

    $stmtHistory = $db->prepare(
        "INSERT INTO stream_history
            (station_id, checked_at, estado, http_code, response_ms, icy_supported, icy_name)
         VALUES (?, " . sql_now() . ", ?, ?, ?, ?, ?)"
    );
    $stmtEvent = $db->prepare(
        "INSERT INTO station_events (station_id, event_type, old_value, new_value)
         VALUES (?, ?, ?, ?)"
    );

    $sqlIcyUpsert = db_engine() === 'mysql'
        ? "INSERT INTO icy_cache (station_id, supported, icy_name, stream_title, last_checked)
           VALUES (?, ?, ?, ?, " . sql_now() . ")
           ON DUPLICATE KEY UPDATE
                supported    = VALUES(supported),
                icy_name     = VALUES(icy_name),
                stream_title = CASE WHEN VALUES(stream_title) IS NOT NULL
                                    THEN VALUES(stream_title)
                                    ELSE icy_cache.stream_title END,
                last_title_change = CASE
                    WHEN VALUES(stream_title) IS NOT NULL
                     AND VALUES(stream_title) != icy_cache.stream_title
                    THEN " . sql_now() . "
                    ELSE icy_cache.last_title_change END,
                last_checked = VALUES(last_checked)"
        : "INSERT INTO icy_cache (station_id, supported, icy_name, stream_title, last_checked)
           VALUES (?, ?, ?, ?, " . sql_now() . ")
           ON CONFLICT(station_id) DO UPDATE SET
                supported    = excluded.supported,
                icy_name     = excluded.icy_name,
                stream_title = CASE WHEN excluded.stream_title IS NOT NULL
                                    THEN excluded.stream_title
                                    ELSE icy_cache.stream_title END,
                last_title_change = CASE
                    WHEN excluded.stream_title IS NOT NULL
                     AND excluded.stream_title != icy_cache.stream_title
                    THEN " . sql_now() . "
                    ELSE icy_cache.last_title_change END,
                last_checked = excluded.last_checked";
    $stmtIcy = $db->prepare($sqlIcyUpsert);

    $count_ok = $count_dead = $count_timeout = 0;
    $events_detected = 0;
    $errors = 0;

    foreach ($results as $r) {
        $sid = (int)($r['station_id'] ?? 0);
        if (!$sid) { $errors++; continue; }

        $selPrev->execute([$sid]);
        $prevRow = $selPrev->fetch();
        if (!$prevRow) { $errors++; continue; }

        $prev_estado = $prevRow['prev_estado'];
        $prev_fails  = (int)$prevRow['prev_fails'];
        $prev_icy    = (int)$prevRow['prev_icy'];

        $nuevo         = $r['estado'] ?? 'timeout';
        $http_code     = $r['http_code'] ?? null;
        $response_ms   = $r['response_ms'] ?? null;
        $cur_icy       = !empty($r['icy_supported']) ? 1 : 0;
        $icy_name      = $r['icy_name'] ?? null;

        if ($nuevo === 'ok') { $count_ok++; $new_fails = 0; }
        elseif ($nuevo === 'timeout') { $count_timeout++; $new_fails = $prev_fails + 1; }
        else { $count_dead++; $new_fails = $prev_fails + 1; }

        try {
            $now = gmdate('Y-m-d H:i:s');
            $stmtStatus->execute([
                $sid, $nuevo, $http_code, $response_ms, $new_fails,
                $nuevo === 'ok' ? $now : null,
            ]);
            $stmtHistory->execute([$sid, $nuevo, $http_code, $response_ms, $cur_icy, $icy_name]);
        } catch (Exception $e) {
            $errors++;
            continue;
        }

        if ($nuevo === 'ok' && in_array($prev_estado, ['muerto', 'unknown'], true)) {
            $stmtEvent->execute([$sid, 'came_back', $prev_estado, $nuevo]);
            $events_detected++;
        } elseif ($nuevo === 'muerto' && $new_fails >= $DOWN_AFTER && $prev_estado !== 'muerto') {
            $stmtEvent->execute([$sid, 'went_down', $prev_estado, $nuevo]);
            $events_detected++;
        }

        if ($cur_icy !== $prev_icy) {
            $stmtEvent->execute([$sid, $cur_icy ? 'icy_gained' : 'icy_lost', (string)$prev_icy, (string)$cur_icy]);
            $events_detected++;
        }

        $stream_title = $icy_titles[(string)$sid] ?? $icy_titles[$sid] ?? null;
        $stmtIcy->execute([$sid, $cur_icy, $icy_name, $stream_title]);
    }

    $db->prepare(
        "INSERT INTO crawler_runs (crawler, started_at, finished_at, stations_checked, changes_detected, errors)
         VALUES (?, ?, " . sql_now() . ", ?, ?, ?)"
    )->execute(['check-streams', $started_at, count($results), $events_detected, $errors]);

    // Eventos pendientes de notificar (el propio endpoint no manda Telegram —
    // eso lo sigue haciendo el script Python, que ya tiene el token a mano).
    $pending = $db->query(
        "SELECT se.event_type, s.nombre, se.old_value, se.new_value
         FROM station_events se
         JOIN stations s ON s.id = se.station_id
         WHERE se.notified = 0
         ORDER BY se.detected_at DESC
         LIMIT 20"
    )->fetchAll();
    if ($pending) {
        $db->exec('UPDATE station_events SET notified = 1 WHERE notified = 0');
    }

    api_response([
        'ok_count' => $count_ok, 'dead_count' => $count_dead, 'timeout_count' => $count_timeout,
        'events_detected' => $events_detected, 'errors' => $errors,
        'pending_notify' => $pending,
    ]);
}

if ($accion === 'crawler_run_log') {
    // Genérico para crawlers que no necesitan un endpoint dedicado (competitor
    // scan, gist sync, etc.) — solo dejan constancia en crawler_runs.
    api_method('POST');
    $body = json_body();
    $db->prepare(
        "INSERT INTO crawler_runs (crawler, started_at, finished_at, stations_checked, changes_detected, errors, notes)
         VALUES (?, ?, " . sql_now() . ", ?, ?, ?, ?)"
    )->execute([
        $body['crawler'] ?? 'unknown',
        $body['started_at'] ?? gmdate('Y-m-d H:i:s'),
        (int)($body['stations_checked'] ?? 0),
        (int)($body['changes_detected'] ?? 0),
        (int)($body['errors'] ?? 0),
        $body['notes'] ?? null,
    ]);
    api_response(['ok' => true]);
}

api_error('acción desconocida', 400);
