<?php
// listeners.php — radio — conteo de oyentes en tiempo real
// GET              → {"count": N}
// GET ?action=ping&sid=X&station=Y → registra/refresca heartbeat, devuelve count
// GET ?action=stop&sid=X           → elimina oyente

require_once __DIR__ . '/log.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$file = __DIR__ . '/listeners.json';
$ttl  = 60; // segundos sin heartbeat = ya no escucha

$action  = $_GET['action'] ?? 'count';
$sid     = substr(preg_replace('/[^a-z0-9]/i', '', $_GET['sid'] ?? ''), 0, 32);
$station = substr(strip_tags($_GET['station'] ?? ''), 0, 100);

// Cargar y limpiar entradas vencidas
$listeners = [];
if (file_exists($file)) {
    $data = json_decode(file_get_contents($file), true);
    if (is_array($data)) $listeners = $data;
}
$now = time();
foreach ($listeners as $id => $info) {
    if ($now - ($info['ts'] ?? 0) > $ttl) unset($listeners[$id]);
}

if ($action === 'ping' && $sid) {
    $isNew = !isset($listeners[$sid]);
    $listeners[$sid] = ['ts' => $now, 'station' => $station];
    file_put_contents($file, json_encode($listeners), LOCK_EX);
    if ($isNew) radio_log('play', $station);
    echo json_encode(['ok' => true, 'count' => count($listeners)]);

} elseif ($action === 'stop' && $sid) {
    unset($listeners[$sid]);
    file_put_contents($file, json_encode($listeners), LOCK_EX);
    echo json_encode(['ok' => true, 'count' => count($listeners)]);

} else {
    echo json_encode(['count' => count($listeners)]);
}
