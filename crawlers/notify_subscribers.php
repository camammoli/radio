<?php
/**
 * notify_subscribers.php — Notifica a suscriptores cuando detecta sus preferencias en el aire.
 *
 * Lógica:
 *  - Lee preferencias activas de subscribers
 *  - Busca matches en icy_cache (títulos actuales) e icy_history (últimos 30 min)
 *  - Notifica si una emisora lleva 2+ tracks matching en los últimos 30 min
 *  - Respeta cooldown: no re-notifica el mismo (suscriptor + emisora) por 4 horas
 *
 * Cron sugerido (cPanel): * /5 * * * * php /home/carlos/radio/notify_subscribers.php >> /tmp/notify_sub.log 2>&1
 */

// En producción los archivos web están en el mismo nivel que crawlers/ (sin subdirectorio web/)
$base = dirname(__FILE__, 2);
$cfg  = is_file($base . '/config.php') ? $base . '/config.php' : $base . '/web/config.php';
$dbf  = is_file($base . '/api/_db.php') ? $base . '/api/_db.php' : $base . '/web/api/_db.php';
require_once $cfg;
require_once $dbf;

$db = radio_db();

// Asegurar tablas (idempotente)
$db->exec('CREATE TABLE IF NOT EXISTS subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_type TEXT NOT NULL,
    contact_value TEXT NOT NULL,
    preferences TEXT DEFAULT "[]",
    active INTEGER DEFAULT 0,
    token TEXT UNIQUE NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    last_notified TEXT
)');
$db->exec('CREATE TABLE IF NOT EXISTS subscriber_matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id INTEGER,
    station_id INTEGER,
    keyword TEXT NOT NULL,
    first_seen TEXT DEFAULT CURRENT_TIMESTAMP,
    match_count INTEGER DEFAULT 1,
    notified INTEGER DEFAULT 0
)');

$now = date('Y-m-d H:i:s');
$log = function(string $msg) { echo date('[H:i:s] ') . $msg . "\n"; };

// ── Cargar suscriptores activos ───────────────────────────────────────────────
$subscribers = $db->query("SELECT * FROM subscribers WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
if (!$subscribers) { $log("Sin suscriptores activos."); exit(0); }

// ── Cargar ICY reciente (últimos 30 min) ──────────────────────────────────────
// Los registros de icy_history de los últimos 30 minutos, agrupados por estación
$recent_icy = $db->query("
    SELECT station_id, title, seen_at
    FROM icy_history
    WHERE seen_at >= datetime('now', '-30 minutes')
    ORDER BY station_id, seen_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Organizar por station_id: {station_id: [titles]}
$icy_by_station = [];
foreach ($recent_icy as $row) {
    $sid = $row['station_id'];
    if (!isset($icy_by_station[$sid])) $icy_by_station[$sid] = [];
    $icy_by_station[$sid][] = strtolower($row['title'] ?? '');
}

// Nombres de estaciones para notificaciones
$station_names = [];
if ($icy_by_station) {
    $ids_ph = implode(',', array_map('intval', array_keys($icy_by_station)));
    $stn = $db->query("SELECT id, nombre, slug FROM stations WHERE id IN ({$ids_ph})")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stn as $s) $station_names[$s['id']] = $s;
}

// ── Cooldown — cargar notificaciones recientes (últimas 4 horas) ──────────────
$cooldown_raw = $db->query("
    SELECT subscriber_id, station_id, MAX(first_seen) AS last
    FROM subscriber_matches
    WHERE notified=1 AND first_seen >= datetime('now', '-4 hours')
    GROUP BY subscriber_id, station_id
")->fetchAll(PDO::FETCH_ASSOC);
$cooldowns = [];
foreach ($cooldown_raw as $c) {
    $cooldowns[$c['subscriber_id'] . '_' . $c['station_id']] = true;
}

// ── Función de normalización ──────────────────────────────────────────────────
function keywords_match(string $text, array $prefs): array {
    $matches = [];
    foreach ($prefs as $pref) {
        if (($pref['type'] ?? '') === 'genre') continue;
        $kw = strtolower($pref['value']);
        if (mb_strlen($kw) >= 3 && strpos($text, $kw) !== false) {
            $matches[] = $pref['value'];
        }
    }
    return $matches;
}

// ── Función de envío Telegram ─────────────────────────────────────────────────
function tg_send(string $chat_id, string $msg): bool {
    $token = defined('TG_TOKEN') ? TG_TOKEN : '';
    if (!$token) return false;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $r = @file_get_contents($url . '?' . http_build_query([
        'chat_id'    => $chat_id,
        'text'       => $msg,
        'parse_mode' => 'Markdown',
    ]));
    $data = $r ? json_decode($r, true) : null;
    return $data['ok'] ?? false;
}

// ── Función de envío email ────────────────────────────────────────────────────
function email_send(string $to, string $subject, string $body): bool {
    $from = defined('SITE_EMAIL') ? SITE_EMAIL : 'no-reply@mammoli.ar';
    return @mail($to, $subject, $body,
        "From: Radio Argentina <{$from}>\r\nContent-Type: text/plain; charset=UTF-8");
}

// ── Procesar cada suscriptor ──────────────────────────────────────────────────
$total_notified = 0;

foreach ($subscribers as $sub) {
    $prefs = json_decode($sub['preferences'] ?? '[]', true) ?: [];
    if (!$prefs) continue;

    foreach ($icy_by_station as $sid => $titles) {
        $ck = $sub['id'] . '_' . $sid;
        if (isset($cooldowns[$ck])) continue;

        // Contar cuántos titles del historial reciente matchean alguna preferencia
        $matched_keywords = [];
        $match_count = 0;
        foreach ($titles as $title) {
            $m = keywords_match($title, $prefs);
            if ($m) {
                $match_count++;
                $matched_keywords = array_unique(array_merge($matched_keywords, $m));
            }
        }

        if ($match_count < 2) continue;

        // Suficientes matches → notificar
        $stn = $station_names[$sid] ?? null;
        if (!$stn) continue;

        $stn_nombre = $stn['nombre'];
        $stn_url    = 'https://mammoli.ar/radio/' . ($stn['slug'] ?? '') . '/';
        $kw_text    = implode(', ', array_slice($matched_keywords, 0, 3));

        $msg_tg = "📻 *Radio Argentina — Alerta de escucha*\n\n"
                . "¡Están pasando *{$kw_text}* en [{$stn_nombre}]({$stn_url})!\n\n"
                . "Llevamos {$match_count} temas en los últimos 30 minutos. Andá a escuchar 🎵";

        $msg_email = "📻 Alerta de Radio Argentina\n\n"
                   . "Están pasando {$kw_text} en {$stn_nombre}.\n"
                   . "{$match_count} temas en los últimos 30 minutos.\n\n"
                   . "Escuchá en: {$stn_url}\n\n"
                   . "— Radio Argentina";

        $sent = false;
        if ($sub['contact_type'] === 'telegram') {
            $sent = tg_send($sub['contact_value'], $msg_tg);
        } else {
            $sent = email_send($sub['contact_value'], "🎵 {$kw_text} — en el aire ahora", $msg_email);
        }

        if ($sent) {
            // Registrar notificación enviada
            $db->prepare("INSERT INTO subscriber_matches (subscriber_id, station_id, keyword, match_count, notified) VALUES (?,?,?,?,1)")
               ->execute([$sub['id'], $sid, $kw_text, $match_count]);
            $cooldowns[$ck] = true;
            $total_notified++;
            $log("Notificado: suscriptor #{$sub['id']} → {$stn_nombre} ({$kw_text}, {$match_count} matches)");
        }
    }
}

// ── Verificar patrones de programas próximos ──────────────────────────────────
// Busca programas que empiezan en los próximos 15 minutos según historial aprendido
$next_hour   = (int)date('G');
$next_mins   = (int)date('i');
$notify_hour = $next_mins >= 45 ? ($next_hour + 1) % 24 : $next_hour;
$dow         = (int)date('w');

$upcoming = $db->prepare("
    SELECT pp.*, s.nombre, s.slug
    FROM program_patterns pp
    JOIN stations s ON s.id = pp.station_id
    WHERE pp.confidence >= 0.6
      AND pp.hour = ?
      AND (pp.day_of_week IS NULL OR pp.day_of_week = ?)
")->execute([$notify_hour, $dow]);
$upcoming_patterns = $db->query("
    SELECT pp.*, s.nombre, s.slug
    FROM program_patterns pp
    JOIN stations s ON s.id = pp.station_id
    WHERE pp.confidence >= 0.6 AND pp.hour = {$notify_hour}
      AND (pp.day_of_week IS NULL OR pp.day_of_week = {$dow})
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($upcoming_patterns as $pat) {
    foreach ($subscribers as $sub) {
        $prefs = json_decode($sub['preferences'] ?? '[]', true) ?: [];
        $kw_lower = strtolower($pat['keyword']);
        $match = false;
        foreach ($prefs as $p) {
            if (($p['type'] ?? '') !== 'genre' && strpos($kw_lower, strtolower($p['value'])) !== false) {
                $match = true;
                break;
            }
        }
        if (!$match) continue;

        $ck = 'prog_' . $sub['id'] . '_' . $pat['id'];
        if (isset($cooldowns[$ck])) continue;

        $stn_url = 'https://mammoli.ar/radio/' . ($pat['slug'] ?? '') . '/';
        $msg_tg  = "📅 *Próximamente en Radio Argentina*\n\n"
                 . "En unos minutos empieza *{$pat['keyword']}* en [{$pat['nombre']}]({$stn_url})\n"
                 . "Confianza del horario: " . round($pat['confidence'] * 100) . "% basado en el historial.";
        $msg_em  = "📅 En breve: {$pat['keyword']} en {$pat['nombre']}\n{$stn_url}";

        $sent = false;
        if ($sub['contact_type'] === 'telegram') {
            $sent = tg_send($sub['contact_value'], $msg_tg);
        } else {
            $sent = email_send($sub['contact_value'], "📅 En breve: {$pat['keyword']}", $msg_em);
        }
        if ($sent) {
            $cooldowns[$ck] = true;
            $total_notified++;
            $log("Programa próximo notificado: suscriptor #{$sub['id']} → {$pat['keyword']} @ {$pat['nombre']}");
        }
    }
}

// ── Limpiar matches viejos (>48h) ─────────────────────────────────────────────
$db->exec("DELETE FROM subscriber_matches WHERE first_seen < datetime('now', '-48 hours')");

$log("Fin. Notificaciones enviadas: {$total_notified}");
