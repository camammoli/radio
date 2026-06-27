<?php
/**
 * export.php — GET /radio/api/stations.json
 *
 * Exporta todas las emisoras aprobadas como JSON array plano.
 * Sin paginación — pensado para consumo externo (gist, apps, scripts).
 *
 * Campos: slug, nombre, url, provincia, logo, tags, codec, bitrate, estado
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');

$db = radio_db();

$rows = $db->query("
    SELECT slug, nombre, url, provincia, logo, tags, codec, bitrate, estado
    FROM v_stations
    ORDER BY
        CASE estado WHEN 'ok' THEN 0 WHEN 'timeout' THEN 1 ELSE 2 END,
        nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['tags'] = $r['tags'] ? json_decode($r['tags'], true) : [];
    $r['bitrate'] = $r['bitrate'] ? (int)$r['bitrate'] : null;
}

echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
