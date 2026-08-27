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

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
            ip_hash TEXT PRIMARY KEY, provincia TEXT, updated_at TEXT DEFAULT (datetime('now'))
        )");
    } catch (Exception $e) {}

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
        $db->prepare('INSERT OR REPLACE INTO ip_geo_cache (ip_hash, provincia, updated_at) VALUES (?, ?, datetime("now"))')
           ->execute([$hash, $provincia]);
    } catch (Exception $e) {}

    return $provincia;
}

function ip_hash(string $ip): string {
    return substr(hash('sha256', $ip), 0, 16);
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
    try { $db->exec('ALTER TABLE plays ADD COLUMN ended_at TEXT'); } catch (Exception $e) {}

    $cerradas = 0;
    try {
        $cerradas = $db->exec(
            "UPDATE plays SET ended_at = (SELECT last_seen FROM listeners WHERE sid = plays.session_id)
             WHERE session_id IN (SELECT sid FROM listeners WHERE last_seen < datetime('now', '-90 seconds'))
             AND ended_at IS NULL"
        );
    } catch (Exception $e) {}

    $expiradas = (int)$db->exec("DELETE FROM listeners WHERE last_seen < datetime('now', '-90 seconds')");

    return ['plays_cerradas' => (int)$cerradas, 'listeners_expirados' => $expiradas];
}

// ── Configuración dinámica ────────────────────────────────────────────────────

function notify_active(PDO $db): bool {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $r = $db->query("SELECT value FROM settings WHERE key='notify_oyentes' LIMIT 1");
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
