<?php
/**
 * index.php v2 — Router.
 * Lee config, carga helpers, decide qué página servir.
 */

// Compresión de salida a nivel PHP — el hosting no tiene mod_deflate/mod_brotli
// habilitados a nivel de Apache (probado: header Content-Encoding nunca aparece
// pese a las directivas en .htaccess), así que se comprime acá. ob_gzhandler ya
// respeta el Accept-Encoding del cliente y no rompe nada si no puede comprimir.
if (function_exists('ob_gzhandler') && !ob_start('ob_gzhandler')) {
    ob_start();
}

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/api/config.php')) require_once __DIR__ . '/api/config.php';

// Retrocompatibilidad: ?m3u=1 → /api/playlist.m3u
if (isset($_GET['m3u'])) {
    header('Location: /radio/api/playlist.m3u', true, 301);
    exit;
}

// Retrocompatibilidad: ?n=NNN → /radio/{slug}/
if (isset($_GET['n']) && ctype_digit($_GET['n'])) {
    require_once __DIR__ . '/api/_db.php';
    $db   = radio_db();
    $slug = $db->prepare('SELECT slug FROM stations WHERE n = ? LIMIT 1');
    $slug->execute([(int)$_GET['n']]);
    if ($s = $slug->fetchColumn()) {
        header('Location: /radio/' . $s . '/', true, 301);
        exit;
    }
}

// Página de emisora individual
$req = $_GET['station'] ?? null;
if ($req !== null) {
    require __DIR__ . '/pages/station.php';
    exit;
}

// Directorio principal
require __DIR__ . '/pages/listing.php';
