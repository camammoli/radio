<?php
/**
 * stations.php — GET /api/stations[/{slug}]
 *
 * Listado con filtros:
 *   q         búsqueda de texto (nombre, provincia, tags)
 *   provincia filtro exacto de provincia (case-insensitive)
 *   tag       filtro por tag/género
 *   estado    ok | muerto | timeout | unknown
 *   icy       1 = solo emisoras con ICY metadata
 *   limit     default 50, max 200
 *   offset    paginación
 *
 * Página individual:
 *   slug      devuelve una emisora + related (misma provincia)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET');

$db   = radio_db();
$slug = str_param('slug', 100);

// ── Página individual ─────────────────────────────────────────────────────────

if ($slug !== '') {
    $row = $db->prepare('SELECT * FROM v_stations WHERE slug = ?');
    $row->execute([$slug]);
    $station = $row->fetch();

    if (!$station) api_error('Emisora no encontrada', 404);

    $station = station_row($station);

    // Relacionadas: misma provincia, distintas, activas, hasta 6
    $related = [];
    if ($station['provincia']) {
        $rel = $db->prepare(
            'SELECT id, slug, nombre, provincia, logo, estado, icy_supported
             FROM v_stations
             WHERE provincia = ? AND slug != ? AND estado != ?
             ORDER BY total_plays DESC, rb_votes DESC
             LIMIT 6'
        );
        $rel->execute([$station['provincia'], $slug, 'muerto']);
        foreach ($rel->fetchAll() as $r) {
            $r['icy_supported'] = (bool)$r['icy_supported'];
            $related[] = $r;
        }
    }

    // Oyentes activos para esta emisora
    $db->exec("DELETE FROM listeners WHERE last_seen < datetime('now', '-90 seconds')");
    $active = $db->prepare(
        'SELECT COUNT(*) FROM listeners l
         JOIN stations s ON s.id = l.station_id
         WHERE s.slug = ?'
    );
    $active->execute([$slug]);
    $station['listeners_now'] = (int)$active->fetchColumn();

    api_response($station, ['related' => $related]);
}

// ── Listado con filtros ───────────────────────────────────────────────────────

$q        = str_param('q', 100);
$prov     = str_param('provincia', 100);
$tag      = str_param('tag', 60);
$estado   = str_param('estado', 20);
$icy_only = (str_param('icy') === '1');
$limit    = int_param('limit', 50, 1, 200);
$offset   = int_param('offset', 0, 0);

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[]  = '(nombre LIKE ? OR provincia LIKE ? OR tags LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($prov !== '') {
    $where[]  = 'provincia LIKE ?';
    $params[] = '%' . $prov . '%';
}

if ($tag !== '') {
    $where[]  = 'tags LIKE ?';
    $params[] = '%' . $tag . '%';
}

if (in_array($estado, ['ok', 'muerto', 'timeout', 'unknown'], true)) {
    $where[]  = 'estado = ?';
    $params[] = $estado;
}

if ($icy_only) {
    $where[] = 'icy_supported = 1';
}

$sql_where = implode(' AND ', $where);

// Total sin paginar
$count_stmt = $db->prepare("SELECT COUNT(*) FROM v_stations WHERE $sql_where");
$count_stmt->execute($params);
$total_filtered = (int)$count_stmt->fetchColumn();

// Resultados
$data_stmt = $db->prepare(
    "SELECT id, n, slug, nombre, url, provincia, tags, codec, bitrate,
            homepage, logo, estado, icy_supported, icy_name, stream_title,
            total_plays, rb_votes
     FROM v_stations
     WHERE $sql_where
     ORDER BY
       CASE estado WHEN 'ok' THEN 0 WHEN 'timeout' THEN 1 ELSE 2 END,
       total_plays DESC,
       rb_votes DESC,
       n ASC
     LIMIT ? OFFSET ?"
);
$data_stmt->execute([...$params, $limit, $offset]);

$stations = array_map('station_row', $data_stmt->fetchAll());

api_response($stations, [
    'total'    => (int)$db->query('SELECT COUNT(*) FROM stations WHERE approved=1')->fetchColumn(),
    'filtered' => $total_filtered,
    'limit'    => $limit,
    'offset'   => $offset,
]);
