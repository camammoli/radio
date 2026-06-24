<?php
/**
 * suggest.php — POST /api/suggest
 *
 * Body JSON o POST params: {nombre, url, provincia, homepage, comment}
 * Guarda la sugerencia en stations con approved=0.
 * Notifica por Telegram si está configurado.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('POST');

if (!defined('TG_TOKEN'))   define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID')) define('TG_CHAT_ID', '');

$body     = json_body();
$nombre   = substr(strip_tags(trim($body['nombre']   ?? str_param('nombre'))),   0, 120);
$url      = substr(strip_tags(trim($body['url']      ?? str_param('url'))),      0, 500);
$provincia = substr(strip_tags(trim($body['provincia'] ?? str_param('provincia'))), 0, 100);
$homepage = substr(strip_tags(trim($body['homepage'] ?? str_param('homepage'))), 0, 500);
$comment  = substr(strip_tags(trim($body['comment']  ?? str_param('comment'))),  0, 500);

if ($nombre === '') api_error('nombre requerido');
if ($url === '')    api_error('url requerido');
if (!filter_var($url, FILTER_VALIDATE_URL)) api_error('url inválida');

$db = radio_db();

// Verificar duplicado por URL
$dup = $db->prepare('SELECT id FROM stations WHERE url = ?');
$dup->execute([$url]);
if ($dup->fetchColumn()) api_error('Esta URL ya está en el directorio', 409);

// Generar slug provisional
function suggest_slug(string $nombre, string $provincia, PDO $db): string {
    $accent = ['á'=>'a','à'=>'a','é'=>'e','è'=>'e','í'=>'i','ì'=>'i',
               'ó'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c'];
    $text = strtolower($nombre . ($provincia ? ' ' . explode(',', $provincia)[0] : ''));
    $text = strtr($text, $accent);
    $text = trim(preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    // Resolver colisión
    $existing = $db->prepare('SELECT COUNT(*) FROM stations WHERE slug = ?');
    $existing->execute([$text]);
    if ((int)$existing->fetchColumn() === 0) return $text;
    return $text . '-' . substr(md5($url), 0, 4);
}

$slug = suggest_slug($nombre, $provincia, $db);

$db->prepare(
    'INSERT INTO stations (slug, nombre, url, provincia, homepage, source, approved)
     VALUES (?,?,?,?,?,?,0)'
)->execute([$slug, $nombre, $url, $provincia ?: null, $homepage ?: null, 'sugerencia']);

// Telegram
if (TG_TOKEN && TG_CHAT_ID) {
    $text = "📻 Nueva sugerencia\n$nombre\n$url"
          . ($provincia ? "\n📍 $provincia" : '')
          . ($comment   ? "\n💬 $comment"   : '');
    $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_POSTFIELDS     => ['chat_id' => TG_CHAT_ID, 'text' => $text],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

api_response(['saved' => true, 'slug' => $slug], [], 201);
