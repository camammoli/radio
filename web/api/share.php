<?php
/**
 * share.php — Registra un evento de compartir y notifica por Telegram.
 *
 * GET /api/share?slug=SLUG&channel=copy|wa|qr
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET', 'POST');

if (!defined('NOTIFY_OYENTES')) define('NOTIFY_OYENTES', false);
if (!defined('TG_TOKEN'))       define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID'))     define('TG_CHAT_ID', '');

$slug    = str_param('slug', 100);
$channel = str_param('channel', 20);

if (!$slug) api_error('slug requerido', 400);

$db = radio_db();

$r = $db->prepare('SELECT nombre FROM stations WHERE slug = ? LIMIT 1');
$r->execute([$slug]);
$nombre = ($r->fetchColumn()) ?: $slug;

$icons = ['copy' => '🔗', 'wa' => '💬', 'qr' => '⬛'];
$icon  = $icons[$channel] ?? '📤';
$label = ['copy' => 'Copió el link', 'wa' => 'Compartió por WhatsApp', 'qr' => 'Abrió el QR'][$channel] ?? 'Compartió';

if (NOTIFY_OYENTES && TG_TOKEN && TG_CHAT_ID) {
    $text = "$icon $label: $nombre";
    $ch   = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_POSTFIELDS     => ['chat_id' => TG_CHAT_ID, 'text' => $text],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

api_response(['ok' => true]);
