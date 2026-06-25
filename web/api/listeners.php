<?php
/**
 * listeners.php — Oyentes en tiempo real
 *
 * GET  /api/listeners              → {count: N}
 * GET  /api/listeners?action=ping&sid=X&station=SLUG&source=web-station
 *                                  → registra heartbeat, devuelve {count, listeners_station}
 * GET  /api/listeners?action=stop&sid=X
 *                                  → elimina oyente
 * GET  /api/listeners?action=top&limit=N
 *                                  → top N emisoras por reproducciones históricas
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET', 'POST');

if (!defined('NOTIFY_OYENTES')) define('NOTIFY_OYENTES', false);
if (!defined('TG_TOKEN'))       define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID'))     define('TG_CHAT_ID', '');

$db     = radio_db();
$action = str_param('action', 20, 'count');
$sid    = substr(preg_replace('/[^a-z0-9]/i', '', str_param('sid', 40)), 0, 40);
$slug   = str_param('station', 100);   // ahora recibe slug (no nombre)
$source = str_param('source', 30, 'web-listing');

// Limpiar expirados
$db->exec("DELETE FROM listeners WHERE last_seen < datetime('now', '-90 seconds')");

// ── Resolver station_id desde slug ────────────────────────────────────────────

$station_id   = null;
$station_name = $slug;

if ($slug !== '') {
    // Aceptar slug O nombre (compatibilidad con v1 que mandaba nombre)
    $r = $db->prepare(
        'SELECT id, nombre FROM stations WHERE slug = ? OR nombre = ? LIMIT 1'
    );
    $r->execute([$slug, $slug]);
    if ($row = $r->fetch()) {
        $station_id   = (int)$row['id'];
        $station_name = $row['nombre'];
    }
}

// ── Top ───────────────────────────────────────────────────────────────────────

if ($action === 'top') {
    $limit = int_param('limit', 10, 1, 50);
    $stmt  = $db->prepare(
        'SELECT s.nombre, s.slug, COUNT(p.id) AS plays
         FROM plays p JOIN stations s ON s.id = p.station_id
         GROUP BY p.station_id
         ORDER BY plays DESC
         LIMIT ?'
    );
    $stmt->execute([$limit]);
    api_response($stmt->fetchAll());
}

// ── Count ─────────────────────────────────────────────────────────────────────

if ($action === 'count' || ($action !== 'ping' && $action !== 'stop')) {
    $count = (int)$db->query('SELECT COUNT(*) FROM listeners')->fetchColumn();
    api_response(['count' => $count]);
}

// ── Stop ──────────────────────────────────────────────────────────────────────

if ($action === 'stop') {
    if (!$sid) api_error('sid requerido', 400);
    $db->prepare('DELETE FROM listeners WHERE sid = ?')->execute([$sid]);
    $count = (int)$db->query('SELECT COUNT(*) FROM listeners')->fetchColumn();
    api_response(['count' => $count]);
}

// ── Ping ──────────────────────────────────────────────────────────────────────

if ($action === 'ping') {
    if (!$sid) api_error('sid requerido', 400);

    $existing = $db->prepare('SELECT sid FROM listeners WHERE sid = ?');
    $existing->execute([$sid]);
    $is_new = !$existing->fetch();

    if ($is_new) {
        $db->prepare(
            'INSERT INTO listeners (sid, station_id, source) VALUES (?,?,?)'
        )->execute([$sid, $station_id, $source]);

        // Registrar reproducción histórica
        if ($station_id) {
            $db->prepare(
                'INSERT INTO plays (station_id, session_id, ip_hash, source) VALUES (?,?,?,?)'
            )->execute([$station_id, $sid, ip_hash(client_ip()), $source]);
        }

        // Notificación Telegram
        if (notify_active($db) && TG_TOKEN && TG_CHAT_ID && $station_name) {
            $count_now = (int)$db->query('SELECT COUNT(*) FROM listeners')->fetchColumn();
            $ip        = client_ip();
            $text      = "🎙 Oyente: $station_name\n🌐 IP: $ip\n👥 Activos: $count_now";
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
    } else {
        $db->prepare(
            "UPDATE listeners SET last_seen = datetime('now') WHERE sid = ?"
        )->execute([$sid]);
    }

    $count = (int)$db->query('SELECT COUNT(*) FROM listeners')->fetchColumn();

    // Oyentes en esta emisora específica
    $station_count = 0;
    if ($station_id) {
        $sc = $db->prepare('SELECT COUNT(*) FROM listeners WHERE station_id = ?');
        $sc->execute([$station_id]);
        $station_count = (int)$sc->fetchColumn();
    }

    api_response([
        'count'            => $count,
        'listeners_station' => $station_count,
    ]);
}
