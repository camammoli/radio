<?php
header('Content-Type: application/xml; charset=utf-8');

define('EMISORAS_JSON_URL', 'https://raw.githubusercontent.com/camammoli/radio/master/emisoras.json');
define('CACHE_JSON_SM',     sys_get_temp_dir() . '/radio_emisoras_cache.json');
define('CACHE_TTL_SM',      3600);

function sm_load(): ?array {
    if (file_exists(CACHE_JSON_SM) && (time() - filemtime(CACHE_JSON_SM)) < CACHE_TTL_SM) {
        $d = json_decode(file_get_contents(CACHE_JSON_SM), true);
        if (is_array($d)) return $d;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = @file_get_contents(EMISORAS_JSON_URL, false, $ctx);
    if ($raw !== false) {
        $d = json_decode($raw, true);
        if (is_array($d)) { file_put_contents(CACHE_JSON_SM, $raw); return $d; }
    }
    if (file_exists(CACHE_JSON_SM)) {
        $d = json_decode(file_get_contents(CACHE_JSON_SM), true);
        if (is_array($d)) return $d;
    }
    return null;
}

function sm_slug(array $s): string {
    $t = $s['nombre'];
    if (!empty($s['provincia'])) $t .= ' ' . trim(explode(',', $s['provincia'])[0]);
    $t = mb_strtolower($t, 'UTF-8');
    $t = strtr($t, ['á'=>'a','à'=>'a','é'=>'e','è'=>'e','í'=>'i','ì'=>'i',
                     'ó'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ñ'=>'n','ü'=>'u','ç'=>'c']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
}

$stations = sm_load();
if (!$stations) {
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>';
    exit;
}

$status = [];
$sf = __DIR__ . '/status.json';
if (file_exists($sf)) {
    $sd = json_decode(file_get_contents($sf), true);
    $status = $sd['streams'] ?? [];
}

// Índice de slugs para resolver colisiones
$idx = [];
foreach ($stations as $s) {
    $b = sm_slug($s);
    if (!isset($idx[$b])) $idx[$b] = $s['n'];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo "  <url><loc>https://mammoli.ar/radio/</loc><changefreq>daily</changefreq><priority>0.9</priority></url>\n";

$seen = [];
foreach ($stations as $s) {
    $estado = $status[$s['url']]['estado'] ?? 'unknown';
    if ($estado === 'muerto') continue;

    $b    = sm_slug($s);
    $slug = ($idx[$b] === $s['n']) ? $b : $b . '-' . $s['n'];
    if (isset($seen[$slug])) continue;
    $seen[$slug] = true;

    $loc  = htmlspecialchars('https://mammoli.ar/radio/' . $slug . '/');
    $pri  = $estado === 'ok' ? '0.6' : '0.4';
    echo "  <url><loc>{$loc}</loc><changefreq>weekly</changefreq><priority>{$pri}</priority></url>\n";
}

echo '</urlset>';
