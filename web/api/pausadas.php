<?php
/**
 * pausadas.php — GET /api/pausadas?q=texto
 *
 * Busca entre las emisoras dadas de baja con motivo conocido (ver TKT-0741,
 * columna motivo_baja) que coincidan por nombre con el texto buscado.
 *
 * Usado por el buscador del listado (listing.php): cuando una búsqueda no
 * encuentra ninguna emisora activa, antes de mandar a "¿no la encontrás?
 * sugerila" (que sería engañoso si la emisora YA existe, solo que está
 * pausada) se chequea acá. Solo lectura, sin CSRF — igual criterio que
 * nowplaying.php.
 *
 * Respuesta: {ok, data: [{slug, nombre}]}
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET');

$db = radio_db();
$q  = str_param('q', 100);

if (mb_strlen($q) < 3) {
    api_response([]);
}

$stmt = $db->prepare(
    'SELECT slug, nombre FROM stations
     WHERE motivo_baja IS NOT NULL AND nombre LIKE ?
     ORDER BY rb_votes DESC
     LIMIT 3'
);
$stmt->execute(['%' . $q . '%']);

api_response($stmt->fetchAll());
