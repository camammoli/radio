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
sqlite_lazy_migration($db, fn($db) => $db->exec('CREATE TABLE IF NOT EXISTS subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_type TEXT NOT NULL,
    contact_value TEXT NOT NULL,
    preferences TEXT DEFAULT "[]",
    active INTEGER DEFAULT 0,
    token TEXT UNIQUE NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    last_notified TEXT
)'));
sqlite_lazy_migration($db, fn($db) => $db->exec('CREATE TABLE IF NOT EXISTS subscriber_matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id INTEGER,
    station_id INTEGER,
    keyword TEXT NOT NULL,
    first_seen TEXT DEFAULT CURRENT_TIMESTAMP,
    match_count INTEGER DEFAULT 1,
    notified INTEGER DEFAULT 0
)'));

$now = date('Y-m-d H:i:s');
$log = function(string $msg) { echo date('[H:i:s] ') . $msg . "\n"; };

// ── Cargar suscriptores activos ───────────────────────────────────────────────
$subscribers = $db->query("SELECT * FROM subscribers WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
if (!$subscribers) { $log("Sin suscriptores activos."); exit(0); }

// ── Cargar ICY reciente (últimos 30 min) ──────────────────────────────────────
// Los registros de icy_history de los últimos 30 minutos, agrupados por estación
$desde30min = sql_now_offset(-30, 'MINUTE');
$recent_icy = $db->query("
    SELECT station_id, title, seen_at
    FROM icy_history
    WHERE seen_at >= $desde30min
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
$desde4h = sql_now_offset(-4, 'HOUR');
$cooldown_raw = $db->query("
    SELECT subscriber_id, station_id, MAX(first_seen) AS last
    FROM subscriber_matches
    WHERE notified=1 AND first_seen >= $desde4h
    GROUP BY subscriber_id, station_id
")->fetchAll(PDO::FETCH_ASSOC);
$cooldowns = [];
foreach ($cooldown_raw as $c) {
    $cooldowns[$c['subscriber_id'] . '_' . $c['station_id']] = true;
}

// ── Función de normalización ──────────────────────────────────────────────────
// Devuelve array de ['value'=>..., 'type'=>...] de las prefs que coinciden en el texto
function keywords_match(string $text, array $prefs): array {
    $matches = [];
    foreach ($prefs as $pref) {
        if (($pref['type'] ?? '') === 'genre') continue;
        $kw = strtolower($pref['value']);
        if (mb_strlen($kw) >= 3 && strpos($text, $kw) !== false) {
            $matches[] = $pref;
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

        // Contar matches por tipo: artist requiere 2+, program requiere 1+
        // Acumular: [pref_value => ['type'=>..., 'count'=>int]]
        $pref_hits = [];
        foreach ($titles as $title) {
            foreach (keywords_match($title, $prefs) as $m) {
                $key = strtolower($m['value']);
                if (!isset($pref_hits[$key])) $pref_hits[$key] = ['type' => $m['type'], 'value' => $m['value'], 'count' => 0];
                $pref_hits[$key]['count']++;
            }
        }

        // Determinar qué prefs superan su umbral
        $matched_keywords = [];
        foreach ($pref_hits as $hit) {
            $threshold = ($hit['type'] === 'program') ? 1 : 2;
            if ($hit['count'] >= $threshold) {
                $matched_keywords[] = $hit['value'];
            }
        }

        $match_count = count($matched_keywords);
        if ($match_count < 1) continue;

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

// ── Género: notificar si el género coincide con los tags de una emisora en vivo ──
// (antes, keywords_match() excluía explícitamente 'genre' del matching por texto
// de título — correcto, un género no aparece en "Artista - Canción". Pero eso
// dejaba a los suscriptores de género sin ningún camino de notificación. Acá se
// compara contra stations.tags en vez de contra el texto ICY.)
if ($icy_by_station) {
    $st_tags = [];
    $ids_ph2 = implode(',', array_map('intval', array_keys($icy_by_station)));
    $stn_tags_rows = $db->query("SELECT id, tags FROM stations WHERE id IN ({$ids_ph2})")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stn_tags_rows as $r) {
        $st_tags[$r['id']] = array_map('strtolower', json_decode($r['tags'] ?? '[]', true) ?: []);
    }

    foreach ($subscribers as $sub) {
        $prefs = json_decode($sub['preferences'] ?? '[]', true) ?: [];
        $genre_prefs = array_filter($prefs, fn($p) => ($p['type'] ?? '') === 'genre');
        if (!$genre_prefs) continue;

        foreach (array_keys($icy_by_station) as $sid) {
            $ck = $sub['id'] . '_' . $sid;
            if (isset($cooldowns[$ck])) continue;

            $tags = $st_tags[$sid] ?? [];
            $matched_genre = null;
            foreach ($genre_prefs as $gp) {
                if (in_array(strtolower($gp['value']), $tags, true)) { $matched_genre = $gp['value']; break; }
            }
            if (!$matched_genre) continue;

            $stn = $station_names[$sid] ?? null;
            if (!$stn) continue;
            $stn_nombre = $stn['nombre'];
            $stn_url    = 'https://mammoli.ar/radio/' . ($stn['slug'] ?? '') . '/';

            $msg_tg = "📻 *Radio Argentina — Tu género favorito está sonando*\n\n"
                    . "[{$stn_nombre}]({$stn_url}) — género *{$matched_genre}* — está al aire ahora.";
            $msg_email = "📻 {$stn_nombre} — género {$matched_genre} — está al aire ahora.\n"
                       . "Escuchá en: {$stn_url}\n\n— Radio Argentina";

            $sent = ($sub['contact_type'] === 'telegram')
                ? tg_send($sub['contact_value'], $msg_tg)
                : email_send($sub['contact_value'], "🎶 {$matched_genre} — en el aire ahora", $msg_email);

            if ($sent) {
                $db->prepare("INSERT INTO subscriber_matches (subscriber_id, station_id, keyword, match_count, notified) VALUES (?,?,?,?,1)")
                   ->execute([$sub['id'], $sid, $matched_genre, 1]);
                $cooldowns[$ck] = true;
                $total_notified++;
                $log("Género notificado: suscriptor #{$sub['id']} → {$stn_nombre} ({$matched_genre})");
            }
        }
    }
}

// ── Verificar patrones de programas próximos ──────────────────────────────────
// Busca programas que empiezan en los próximos 15 minutos según historial aprendido
$next_hour   = (int)date('G');
$next_mins   = (int)date('i');
$notify_hour = $next_mins >= 45 ? ($next_hour + 1) % 24 : $next_hour;
$dow         = (int)date('w');

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

        // Misma forma de clave que el resto (sub+estación) para que el cooldown
        // persista de verdad: se recarga de subscriber_matches al inicio del
        // script, y antes esta rama nunca insertaba ahí (bug: reenviaba la misma
        // alerta hasta 9 veces/hora, una por corrida de cron).
        $ck = $sub['id'] . '_' . $pat['station_id'];
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
            $db->prepare("INSERT INTO subscriber_matches (subscriber_id, station_id, keyword, match_count, notified) VALUES (?,?,?,?,1)")
               ->execute([$sub['id'], $pat['station_id'], $pat['keyword'], 1]);
            $cooldowns[$ck] = true;
            $total_notified++;
            $log("Programa próximo notificado: suscriptor #{$sub['id']} → {$pat['keyword']} @ {$pat['nombre']}");
        }
    }
}

// ── Limpiar matches viejos (>48h) ─────────────────────────────────────────────
$limite48h = sql_now_offset(-48, 'HOUR');
$db->exec("DELETE FROM subscriber_matches WHERE first_seen < $limite48h");

$log("Fin. Notificaciones enviadas: {$total_notified}");
