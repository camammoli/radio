<?php
/**
 * _helpers.php — Respuestas JSON, sanitización, utilidades comunes.
 */

// ── Respuestas ────────────────────────────────────────────────────────────────

function api_response(mixed $data, array $meta = [], int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body = ['ok' => true, 'data' => $data];
    if ($meta) $body['meta'] = $meta;
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 400): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message, 'code' => $status],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

function api_method(string ...$allowed): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        api_error('Método no permitido', 405);
    }
}

// ── Input ─────────────────────────────────────────────────────────────────────

function str_param(string $key, int $max = 200, string $default = ''): string {
    $v = $_GET[$key] ?? $_POST[$key] ?? $default;
    return mb_substr(trim(strip_tags((string)$v)), 0, $max);
}

function int_param(string $key, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int {
    $v = (int)($_GET[$key] ?? $_POST[$key] ?? $default);
    return max($min, min($max, $v));
}

function json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── Formatos ──────────────────────────────────────────────────────────────────

function station_row(array $row): array {
    $row['tags']         = json_decode($row['tags'] ?? '[]', true) ?: [];
    $row['icy_supported'] = (bool)$row['icy_supported'];
    foreach (['n', 'bitrate', 'rb_votes', 'rb_clicks', 'total_plays'] as $int) {
        if (array_key_exists($int, $row)) $row[$int] = (int)$row[$int];
    }
    return $row;
}

// ── IP ────────────────────────────────────────────────────────────────────────

function client_ip(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?';
    return trim(explode(',', $ip)[0]);
}

// Código ISO 3166-2:AR (campo "region" de ip-api.com) → provincia canónica.
const AR_REGION_CODE_A_PROVINCIA = [
    'B' => 'Buenos Aires', 'C' => 'CABA', 'K' => 'Catamarca', 'H' => 'Chaco',
    'U' => 'Chubut', 'X' => 'Córdoba', 'W' => 'Corrientes', 'E' => 'Entre Ríos',
    'P' => 'Formosa', 'Y' => 'Jujuy', 'L' => 'La Pampa', 'F' => 'La Rioja',
    'M' => 'Mendoza', 'N' => 'Misiones', 'Q' => 'Neuquén', 'R' => 'Río Negro',
    'A' => 'Salta', 'J' => 'San Juan', 'D' => 'San Luis', 'Z' => 'Santa Cruz',
    'S' => 'Santa Fe', 'G' => 'Santiago del Estero', 'V' => 'Tierra del Fuego',
    'T' => 'Tucumán',
];

/**
 * Geolocaliza un IP a provincia argentina, con caché en ip_geo_cache por ip_hash
 * (el IP crudo nunca se persiste — solo se usa en memoria para esta llamada).
 * Nunca lanza ni bloquea: cualquier fallo de red/API devuelve null.
 */
function geo_provincia(PDO $db, string $ip): ?string {
    $hash = ip_hash($ip);

    sqlite_lazy_migration($db, fn($db) => $db->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
        ip_hash TEXT PRIMARY KEY, provincia TEXT, updated_at TEXT DEFAULT (datetime('now'))
    )"));

    $stmt = $db->prepare('SELECT provincia FROM ip_geo_cache WHERE ip_hash = ?');
    $stmt->execute([$hash]);
    if ($row = $stmt->fetch()) {
        return $row['provincia']; // puede ser null: ya se intentó antes y no dio resultado
    }

    $provincia = null;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        try {
            $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,region,regionName&lang=es');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            if ($resp) {
                $data = json_decode($resp, true);
                if (($data['status'] ?? '') === 'success' && ($data['country'] ?? '') === 'Argentina') {
                    $code = $data['region'] ?? '';
                    $provincia = AR_REGION_CODE_A_PROVINCIA[$code] ?? normalizar_provincia($data['regionName'] ?? null);
                }
            }
        } catch (Exception $e) {}
    }

    try {
        sql_upsert($db, 'ip_geo_cache', ['ip_hash' => $hash, 'provincia' => $provincia, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    } catch (Exception $e) {}

    return $provincia;
}

function ip_hash(string $ip): string {
    return substr(hash('sha256', $ip), 0, 16);
}

// ── Compatibilidad SQLite/MySQL ──────────────────────────────────────────────
// Motor real controlado por RADIO_DB_ENGINE en config.php (ver _db.php). Estas
// funciones dejan que el mismo código arme la sintaxis de fecha/upsert correcta
// para el motor activo, sin duplicar queries por archivo.

function db_engine(): string {
    return (defined('RADIO_DB_ENGINE') && RADIO_DB_ENGINE === 'mysql') ? 'mysql' : 'sqlite';
}

// "ahora" como expresión SQL.
function sql_now(): string {
    return db_engine() === 'mysql' ? 'NOW()' : "datetime('now')";
}

// $expr (columna o expresión SQL) desplazada $n unidades ($unit: SECOND/MINUTE/HOUR/DAY).
function sql_offset(string $expr, int $n, string $unit): string {
    $op  = $n < 0 ? '-' : '+';
    $abs = abs($n);
    if (db_engine() === 'mysql') {
        return "($expr $op INTERVAL $abs $unit)";
    }
    $u = strtolower($unit) . 's';
    return "datetime($expr,'$op$abs $u')";
}

// "ahora" desplazado $n unidades.
function sql_now_offset(int $n, string $unit): string {
    return db_engine() === 'mysql'
        ? sql_offset('NOW()', $n, $unit)
        : sql_offset("'now'", $n, $unit);
}

// Fecha (sin hora) de $col, con corrimiento horario fijo (para agrupar "día"
// según un huso horario distinto al de la DB — este proyecto usa -3hs).
function sql_date_local(string $col, int $hoursOffset = 0): string {
    if (db_engine() === 'mysql') {
        $expr = $hoursOffset ? sql_offset($col, $hoursOffset, 'HOUR') : $col;
        return "DATE($expr)";
    }
    $sign = $hoursOffset < 0 ? '-' : '+';
    $arg  = $hoursOffset ? "$col,'$sign" . abs($hoursOffset) . " hours'" : $col;
    return "date($arg)";
}

// "YYYY-MM" de $col, mismo corrimiento horario que sql_date_local().
function sql_month_local(string $col, int $hoursOffset = 0): string {
    if (db_engine() === 'mysql') {
        $expr = $hoursOffset ? sql_offset($col, $hoursOffset, 'HOUR') : $col;
        return "DATE_FORMAT($expr, '%Y-%m')";
    }
    $sign = $hoursOffset < 0 ? '-' : '+';
    $arg  = $hoursOffset ? "$col,'$sign" . abs($hoursOffset) . " hours'" : $col;
    return "strftime('%Y-%m',$arg)";
}

// Hora (0-23, entero) de $col, mismo corrimiento horario.
function sql_hour_local(string $col, int $hoursOffset = 0): string {
    if (db_engine() === 'mysql') {
        $expr = $hoursOffset ? sql_offset($col, $hoursOffset, 'HOUR') : $col;
        return "HOUR($expr)";
    }
    $sign = $hoursOffset < 0 ? '-' : '+';
    $arg  = $hoursOffset ? "$col,'$sign" . abs($hoursOffset) . " hours'" : $col;
    return "CAST(strftime('%H',$arg) AS INTEGER)";
}

// Diferencia entre dos datetimes, en segundos o minutos ($to puede ser 'now'/NOW()).
function sql_seconds_diff(string $from, string $to): string {
    if (db_engine() === 'mysql') return "TIMESTAMPDIFF(SECOND, $from, $to)";
    $toExpr = $to === 'NOW()' ? "'now'" : $to;
    return "ROUND((julianday($toExpr)-julianday($from))*86400)";
}
function sql_minutes_diff(string $from, string $to): string {
    if (db_engine() === 'mysql') return "TIMESTAMPDIFF(MINUTE, $from, $to)";
    $toExpr = $to === 'NOW()' ? "'now'" : $to;
    return "ROUND((julianday($toExpr)-julianday($from))*1440)";
}

// Segundos transcurridos desde $col hasta ahora (equivalente a
// strftime('%s','now') - strftime('%s', $col), usado para "edad" de un timestamp).
function sql_age_seconds(string $col): string {
    if (db_engine() === 'mysql') return "TIMESTAMPDIFF(SECOND, $col, NOW())";
    return "(strftime('%s','now') - strftime('%s', $col))";
}

// INSERT ... ON DUPLICATE KEY UPDATE / INSERT OR REPLACE genérico (upsert simple,
// sin lógica condicional — para eso armar el SQL a mano por motor, ver nowplaying.php).
function sql_upsert(PDO $db, string $table, array $data): void {
    $cols         = array_keys($data);
    // Backticks siempre (no solo en MySQL): protege contra columnas que son
    // palabra reservada ahí (ej. `key` en settings) — SQLite las acepta igual
    // como cita de identificador, así que no hace falta ramificar por motor.
    $colsSql      = implode(',', array_map(fn($c) => "`$c`", $cols));
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    if (db_engine() === 'mysql') {
        $updates = implode(', ', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
        $sql = "INSERT INTO `$table` ($colsSql) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
    } else {
        $sql = "INSERT OR REPLACE INTO `$table` ($colsSql) VALUES ($placeholders)";
    }
    $db->prepare($sql)->execute(array_values($data));
}

// Migraciones "al vuelo" (CREATE TABLE IF NOT EXISTS / ALTER TABLE ADD COLUMN)
// solo tienen sentido en SQLite — en MySQL el esquema ya está creado por la
// migración y los cambios futuros van por deploy explícito, no por request.
function sqlite_lazy_migration(PDO $db, callable $fn): void {
    if (db_engine() === 'mysql') return;
    try { $fn($db); } catch (Exception $e) {}
}

// ── Provincia ─────────────────────────────────────────────────────────────────

const PROVINCIAS_AR = [
    'Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba', 'Corrientes',
    'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
    'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe',
    'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
];

const CIUDAD_A_PROVINCIA = [
    'rosario' => 'Santa Fe', 'mar del plata' => 'Buenos Aires', 'la plata' => 'Buenos Aires',
    'bahia blanca' => 'Buenos Aires', 'comodoro rivadavia' => 'Chubut', 'trelew' => 'Chubut',
    'posadas' => 'Misiones', 'goya' => 'Corrientes', 'rio cuarto' => 'Córdoba',
    'mina clavero' => 'Córdoba', 'allen' => 'Río Negro', 'quilmes' => 'Buenos Aires',
    'san bernardo' => 'Buenos Aires', 'eduardo castex' => 'La Pampa', 'resistencia' => 'Chaco',
    'castelar' => 'Buenos Aires',
];

const CABA_ALIAS = [
    'ciudad autonoma de buenos aires', 'ciudad de buenos aires', 'capital federal', 'caba',
];

function _norm_texto(string $s): string {
    $t = strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','ñ'=>'n','Ñ'=>'n',
    ]);
    $t = mb_strtolower($t);
    $t = preg_replace('/[.,()\/\-]/', ' ', $t);
    $t = preg_replace('/\bprovincia de\b/', ' ', $t);
    $t = preg_replace('/\bpcia de\b/', ' ', $t);
    $t = preg_replace('/\bpcia\b/', ' ', $t);
    $t = preg_replace('/\bargentina\b/', ' ', $t);
    $t = trim(preg_replace('/\s+/', ' ', $t));
    return $t;
}

/**
 * Normaliza texto libre de ubicación (provincia de stations, regionName de geoloc, etc.)
 * a una de las 24 provincias canónicas de PROVINCIAS_AR (o 'CABA'). Devuelve null si
 * el texto es vacío, genérico ("Argentina") o no matchea nada conocido — nunca adivina.
 */
function normalizar_provincia(?string $raw): ?string {
    if (!$raw) return null;
    $t = _norm_texto($raw);
    if ($t === '') return null;

    foreach (CABA_ALIAS as $alias) {
        if (str_contains($t, $alias)) return 'CABA';
    }

    $porLargo = PROVINCIAS_AR;
    usort($porLargo, fn($a, $b) => mb_strlen(_norm_texto($b)) <=> mb_strlen(_norm_texto($a)));
    foreach ($porLargo as $prov) {
        if (str_contains($t, _norm_texto($prov))) return $prov;
    }

    foreach (CIUDAD_A_PROVINCIA as $ciudad => $prov) {
        if (str_contains($t, $ciudad)) return $prov;
    }

    return null;
}

// ── Sesiones ──────────────────────────────────────────────────────────────────

/**
 * Cierra sesiones huérfanas: primero marca plays.ended_at con el último
 * heartbeat conocido, luego borra los listeners vencidos. La llama tanto
 * listeners.php (pasivo, en cada ping/count/stop real) como
 * cron_close_sessions.php (activo, disparado por GitHub Actions cada 15min
 * para que también corra sin tráfico).
 */
function cerrar_sesiones_expiradas(PDO $db): array {
    sqlite_lazy_migration($db, fn($db) => $db->exec('ALTER TABLE plays ADD COLUMN ended_at TEXT'));

    $limite = sql_now_offset(-90, 'SECOND');

    $cerradas = 0;
    try {
        $cerradas = $db->exec(
            "UPDATE plays SET ended_at = (SELECT last_seen FROM listeners WHERE sid = plays.session_id)
             WHERE session_id IN (SELECT sid FROM listeners WHERE last_seen < $limite)
             AND ended_at IS NULL"
        );
    } catch (Exception $e) {}

    $expiradas = (int)$db->exec("DELETE FROM listeners WHERE last_seen < $limite");

    return ['plays_cerradas' => (int)$cerradas, 'listeners_expirados' => $expiradas];
}

// ── Configuración dinámica ────────────────────────────────────────────────────

function notify_active(PDO $db): bool {
    sqlite_lazy_migration($db, fn($db) => $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)"));
    try {
        $r = $db->query("SELECT value FROM settings WHERE `key`='notify_oyentes' LIMIT 1");
        $v = $r ? $r->fetchColumn() : false;
        if ($v !== false) return $v === '1';
    } catch (Exception $e) {}
    return defined('NOTIFY_OYENTES') && NOTIFY_OYENTES;
}

// ── Compartir en X (hashtags) ─────────────────────────────────────────────────

// Texto libre (nombre de emisora, provincia) → hashtag válido en CamelCase,
// sin acentos ni espacios. "Buenos Aires" -> "#BuenosAires".
function radio_hashtag(string $texto): string {
    $t = strtr($texto, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ñ'=>'n','Ñ'=>'N',
    ]);
    $words = preg_split('/[^a-zA-Z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY);
    return '#' . implode('', array_map(function ($w) {
        // Preservar siglas ya en mayúsculas (CABA, AMBA, etc.) tal cual.
        if (mb_strtoupper($w) === $w && mb_strlen($w) > 1) return $w;
        return ucfirst(mb_strtolower($w));
    }, $words));
}

/**
 * Arma el texto para compartir en X con la mayor cantidad de hashtags que
 * entren, en orden de prioridad. Presupuesto real de un tweet con link:
 * 280 (máximo) - 23 (URL siempre acortada a t.co) - 1 (espacio) = 256
 * caracteres para el texto — se deja margen (240) por el conteo "weighted"
 * de X para emojis (cuentan como 2, no como 1).
 */
function radio_x_share_text(string $nombre, ?string $prov = null): string {
    $base = '📻 Estoy escuchando ' . $nombre . ' en vivo';

    $tags = ['#RadioArgentina'];
    if ($prov) {
        $provTag = radio_hashtag($prov);
        if (mb_strlen($provTag) > 1) $tags[] = $provTag;
    }
    $tags[] = '#RadioEnVivo';
    $tags[] = '#EscuchaRadio';
    $tags[] = '#EnVivo';

    $budget = 240;
    $texto  = $base;
    foreach ($tags as $tag) {
        $candidato = $texto . ' ' . $tag;
        if (mb_strlen($candidato) <= $budget) $texto = $candidato; else break;
    }
    return $texto;
}
