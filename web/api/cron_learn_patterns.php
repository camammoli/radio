<?php
/**
 * cron_learn_patterns.php — Dispara crawlers/learn_patterns.php vía HTTP.
 *
 * El cron de cPanel documentado en el script (0 4 * * 1) nunca se configuró —
 * nunca corrió en 3+ semanas desde v3.0. Reemplazado por GitHub Actions semanal,
 * que no tiene acceso al filesystem del hosting compartido, así que dispara
 * este endpoint autenticado en su lugar (misma idea que cron_close_sessions.php).
 *
 * GET /api/cron_learn_patterns.php?key=RADIO_ADMIN_KEY
 */

require_once __DIR__ . '/../config.php';

if (!defined('RADIO_ADMIN_KEY') || ($_GET['key'] ?? '') !== RADIO_ADMIN_KEY) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No autorizado\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// producción: crawlers/ al mismo nivel que api/ · repo local: web/api/ -> repo/crawlers/
$crawler = __DIR__ . '/../crawlers/learn_patterns.php';
if (!is_file($crawler)) $crawler = __DIR__ . '/../../crawlers/learn_patterns.php';
require $crawler;
