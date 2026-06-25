<?php
/**
 * playlist.php — GET /api/playlist.m3u
 *
 * Genera M3U con todas las emisoras activas (ok + timeout).
 * Filtros opcionales: provincia, tag, estado (mismos que stations.php).
 *
 * Retrocompatible con v1: el .htaccess redirige ?m3u=1 aquí.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET');

$db     = radio_db();
$prov   = str_param('provincia', 100);
$tag    = str_param('tag', 60);
$estado = str_param('estado', 20);

$where  = ["estado != 'muerto'"];   // v_stations ya filtra approved=1
$params = [];

if ($prov !== '') {
    $where[]  = 'provincia LIKE ?';
    $params[] = '%' . $prov . '%';
}
if ($tag !== '') {
    $where[]  = 'tags LIKE ?';
    $params[] = '%' . $tag . '%';
}
if (in_array($estado, ['ok', 'timeout', 'unknown'], true)) {
    $where[]  = 'estado = ?';
    $params[] = $estado;
}

$sql = 'SELECT nombre, url, provincia, tags, bitrate, codec
        FROM v_stations
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY
          CASE estado WHEN \'ok\' THEN 0 WHEN \'timeout\' THEN 1 ELSE 2 END,
          total_plays DESC, n ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Salida M3U ────────────────────────────────────────────────────────────────

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: attachment; filename="radio-argentina.m3u"');
header('Cache-Control: public, max-age=3600');

echo "#EXTM3U\n";
echo '# Radio Argentina — mammoli.ar/radio — ' . count($rows) . " emisoras\n";

foreach ($rows as $r) {
    $tags    = json_decode($r['tags'] ?? '[]', true) ?: [];
    $genre   = implode(', ', array_slice($tags, 0, 2));
    $display = $r['nombre'];
    if ($r['provincia']) $display .= ' (' . trim(explode(',', $r['provincia'])[0]) . ')';
    $br = $r['bitrate'] ? (int)$r['bitrate'] : -1;

    echo "#EXTINF:$br" . ($genre ? " genre=\"$genre\"" : '') . ",$display\n";
    echo $r['url'] . "\n";
}
