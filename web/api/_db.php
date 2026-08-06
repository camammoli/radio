<?php
/**
 * _db.php — Conexión PDO a radio_v2.sqlite.
 * Retorna un singleton. No incluir directamente desde la web — prefijo _ lo protege.
 */

function radio_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    // RADIO_DB definido en config.php (requerido en producción: __DIR__ de config + /db/radio_v2.sqlite)
    $path = defined('RADIO_DB') ? RADIO_DB : __DIR__ . '/../db/radio_v2.sqlite';

    if (!file_exists($path)) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Base de datos no disponible', 'code' => 503]);
        exit;
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 3000');

    // v_stations se versiona con un comentario (v3, v4, ...) — cuando cambia la
    // definición acá abajo, se compara contra lo guardado en sqlite_master y se
    // recrea sola. La migración vive en _db.php (no solo en admin.php) porque
    // station.php/listing.php pueden recibir tráfico antes de que se cargue el
    // panel admin.
    $v_stations_version = 4; // v4: oculta del público las muertas hace 14+ días
    $v_stations_sql = "
            CREATE VIEW v_stations AS
            -- v_stations_version:$v_stations_version
            SELECT
                s.id, s.n, s.slug, s.nombre, s.url, s.provincia, s.tags,
                s.codec, s.bitrate, s.homepage, s.logo, s.source,
                s.rb_uuid, s.rb_votes, s.rb_clicks,
                s.contacto_publico, s.destacada,
                COALESCE(ss.estado, \"unknown\")          AS estado,
                ss.http_code, ss.response_ms,
                ss.consecutive_failures,
                ss.last_checked, ss.last_ok,
                COALESCE(ic.supported, 0)               AS icy_supported,
                ic.icy_name, ic.stream_title,
                ic.last_checked                         AS icy_last_checked,
                COALESCE(p.total_plays, 0)              AS total_plays
            FROM stations s
            LEFT JOIN stream_status  ss ON ss.station_id = s.id
            LEFT JOIN icy_cache      ic ON ic.station_id = s.id
            LEFT JOIN (
                SELECT station_id, COUNT(*) AS total_plays FROM plays GROUP BY station_id
            ) p ON p.station_id = s.id
            WHERE s.approved = 1
              -- Oculta del listado público las que llevan 14+ días muertas
              -- (siguen en la DB y visibles en el panel admin, solo no se
              -- muestran a los visitantes). 'timeout' no se oculta: es una
              -- señal más ambigua que 'muerto', puede ser algo transitorio.
              AND NOT (
                    ss.estado = 'muerto'
                    AND (ss.last_ok IS NULL OR ss.last_ok < datetime('now','-14 days'))
              )
        ";
    $current_sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='view' AND name='v_stations'")->fetchColumn();
    if (strpos((string)$current_sql, "v_stations_version:$v_stations_version") === false) {
        try { $pdo->exec('ALTER TABLE stations ADD COLUMN contacto_publico TEXT'); } catch (Exception $e2) {}
        try { $pdo->exec('ALTER TABLE stations ADD COLUMN destacada INTEGER DEFAULT 0'); } catch (Exception $e2) {}
        $pdo->exec('DROP VIEW IF EXISTS v_stations');
        $pdo->exec($v_stations_sql);
    }

    return $pdo;
}
