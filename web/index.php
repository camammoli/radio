<?php
/**
 * index.php v2 — Router.
 * Lee config, carga helpers, decide qué página servir.
 */

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
