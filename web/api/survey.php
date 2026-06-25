<?php
/**
 * survey.php — POST /api/survey
 *
 * Body JSON o POST params: {slug, rating}
 * rating: 1 (👍) | 0 (😐) | -1 (👎)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('POST');

$body     = json_body();
$slug     = substr(strip_tags(trim($body['slug']     ?? str_param('slug'))),     0, 100);
$rating   = isset($body['rating'])   ? (int)$body['rating']                     : (int)str_param('rating');
$location = isset($body['location']) ? substr(strip_tags(trim($body['location'])), 0, 50) : null;

if (!in_array($rating, [-1, 0, 1], true)) api_error('rating debe ser -1, 0 o 1');
if ($slug === '')                          api_error('slug requerido');

$db = radio_db();

// Migración: agregar columna location si no existe (noop si ya está)
try { $db->exec('ALTER TABLE surveys ADD COLUMN location TEXT'); } catch (Exception $e) {}

$r = $db->prepare('SELECT id FROM stations WHERE slug = ? OR nombre = ? LIMIT 1');
$r->execute([$slug, $slug]);
$station_id = $r->fetchColumn() ?: null;

$db->prepare(
    'INSERT INTO surveys (station_id, rating, ip_hash, location) VALUES (?,?,?,?)'
)->execute([$station_id, $rating, ip_hash(client_ip()), $location ?: null]);

api_response(['saved' => true]);
