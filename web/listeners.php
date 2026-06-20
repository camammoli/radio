<?php
// listeners.php — radio — oyentes en tiempo real + ranking de emisoras
// GET                           → {"count": N}
// GET ?action=ping&sid=X&station=Y → registra heartbeat, devuelve count
// GET ?action=stop&sid=X           → elimina oyente
// GET ?action=top&limit=N          → top N emisoras por reproducciones

require_once __DIR__ . '/log.php';
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (!defined('NOTIFY_OYENTES')) define('NOTIFY_OYENTES', false);
if (!defined('TG_TOKEN'))       define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID'))     define('TG_CHAT_ID', '');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$fileListeners = __DIR__ . '/listeners.json';
$filePlays     = __DIR__ . '/plays.json';
$ttl           = 90;

$action  = $_GET['action'] ?? 'count';
$sid     = substr(preg_replace('/[^a-z0-9]/i', '', $_GET['sid'] ?? ''), 0, 32);
$station = substr(strip_tags($_GET['station'] ?? ''), 0, 100);

// ── Top emisoras ──────────────────────────────────────────────────────────────
if ($action === 'top') {
    $limit = min((int)($_GET['limit'] ?? 10), 50);
    $plays = [];
    if (file_exists($filePlays)) {
        $data = json_decode(file_get_contents($filePlays), true);
        if (is_array($data)) $plays = $data;
    }
    arsort($plays);
    $top = array_slice(array_keys($plays), 0, $limit);
    echo json_encode(['top' => $top, 'plays' => array_slice($plays, 0, $limit, true)]);
    exit;
}

// ── Oyentes activos ───────────────────────────────────────────────────────────
$listeners = [];
if (file_exists($fileListeners)) {
    $data = json_decode(file_get_contents($fileListeners), true);
    if (is_array($data)) $listeners = $data;
}
$now = time();
foreach ($listeners as $id => $info) {
    if ($now - ($info['ts'] ?? 0) > $ttl) unset($listeners[$id]);
}

if ($action === 'ping' && $sid) {
    $isNew = !isset($listeners[$sid]);
    $listeners[$sid] = ['ts' => $now, 'station' => $station];
    file_put_contents($fileListeners, json_encode($listeners), LOCK_EX);
    if ($isNew && $station) {
        radio_log('play', $station);
        // Incrementar contador histórico
        $plays = [];
        if (file_exists($filePlays)) {
            $data = json_decode(file_get_contents($filePlays), true);
            if (is_array($data)) $plays = $data;
        }
        $plays[$station] = ($plays[$station] ?? 0) + 1;
        file_put_contents($filePlays, json_encode($plays), LOCK_EX);
        // Notificación Telegram (debug — activar con NOTIFY_OYENTES en config.php)
        if (NOTIFY_OYENTES && TG_TOKEN && TG_CHAT_ID) {
            $ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?';
            $ip   = trim(explode(',', $ip)[0]);
            $text = "🎙 Oyente: $station\n🌐 IP: $ip\n👥 Activos: " . count($listeners);
            $ch   = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
                CURLOPT_POSTFIELDS => ['chat_id' => TG_CHAT_ID, 'text' => $text],
            ]);
            curl_exec($ch); curl_close($ch);
        }
    }
    echo json_encode(['ok' => true, 'count' => count($listeners)]);

} elseif ($action === 'stop' && $sid) {
    unset($listeners[$sid]);
    file_put_contents($fileListeners, json_encode($listeners), LOCK_EX);
    echo json_encode(['ok' => true, 'count' => count($listeners)]);

} else {
    echo json_encode(['count' => count($listeners)]);
}
