<?php
/**
 * sitemap.php v2 — lee slugs y estado directamente desde v_stations (SQLite).
 */

if (file_exists(__DIR__ . '/config.php'))     require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/api/config.php')) require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/_db.php';

header('Content-Type: application/xml; charset=utf-8');

$db = radio_db();
$rows = $db->query(
    "SELECT slug, nombre, estado FROM v_stations
     WHERE estado != 'muerto'
     ORDER BY total_plays DESC, rb_votes DESC, n ASC"
)->fetchAll(PDO::FETCH_ASSOC);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo "  <url><loc>https://mammoli.ar/radio/</loc><changefreq>daily</changefreq><priority>0.9</priority></url>\n";

foreach ($rows as $r) {
    $loc = htmlspecialchars('https://mammoli.ar/radio/' . $r['slug'] . '/');
    $pri = $r['estado'] === 'ok' ? '0.6' : '0.4';
    echo "  <url><loc>{$loc}</loc><changefreq>weekly</changefreq><priority>{$pri}</priority></url>\n";
}

echo '</urlset>';
