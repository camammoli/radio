<?php
/**
 * api/maintenance.php — soporte de web/mantenimiento.html:
 *   GET action=status                → {active, started_at, elapsed_seconds, viewers_now, viewers_total}
 *   GET action=ping&sid=X             → heartbeat de un visitante viendo la página de mantenimiento
 *   GET/POST action=contact&...       → formulario de contacto de la página de mantenimiento
 *
 * Excluido a propósito del bloqueo de .htaccess durante el mantenimiento —
 * sin esto la propia página de mantenimiento no podría mostrar el contador
 * ni recibir mensajes. Reusa la tabla contacto_mensajes ya existente (no
 * hace falta una tabla nueva para eso); sí crea maintenance_viewers, que no
 * existía antes.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET', 'POST');

if (!defined('TG_TOKEN'))   define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID')) define('TG_CHAT_ID', '');

$db     = radio_db();
$action = str_param('action', 20, 'status');

function ensure_maintenance_viewers_table(PDO $db): void {
    try {
        if (db_engine() === 'mysql') {
            $db->exec("CREATE TABLE IF NOT EXISTS maintenance_viewers (
                sid         VARCHAR(64) PRIMARY KEY,
                first_seen  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                ip_hash     VARCHAR(32) NULL,
                provincia   VARCHAR(100) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS maintenance_viewers (
                sid         TEXT PRIMARY KEY,
                first_seen  TEXT DEFAULT (datetime('now')),
                last_seen   TEXT DEFAULT (datetime('now')),
                ip_hash     TEXT,
                provincia   TEXT
            )");
        }
    } catch (Exception $e) {}
}

function maintenance_flag_path(): string {
    return __DIR__ . '/../MAINTENANCE_ON';
}

// ── Status ───────────────────────────────────────────────────────────────────

if ($action === 'status') {
    ensure_maintenance_viewers_table($db);

    $flag   = maintenance_flag_path();
    $active = file_exists($flag);
    $started_at = $active ? trim((string)file_get_contents($flag)) : null;

    $limite = sql_now_offset(-90, 'SECOND');
    $viewers_now = 0;
    $viewers_total = 0;
    try {
        $viewers_now = (int)$db->query("SELECT COUNT(*) FROM maintenance_viewers WHERE last_seen >= $limite")->fetchColumn();
        if ($started_at) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM maintenance_viewers WHERE first_seen >= ?');
            $stmt->execute([$started_at]);
            $viewers_total = (int)$stmt->fetchColumn();
        }
    } catch (Exception $e) {}

    api_response([
        'active'         => $active,
        'started_at'     => $started_at,
        'viewers_now'    => $viewers_now,
        'viewers_total'  => $viewers_total,
    ]);
}

// ── Ping (heartbeat de un visitante viendo mantenimiento.html) ───────────────

if ($action === 'ping') {
    ensure_maintenance_viewers_table($db);

    $sid = substr(preg_replace('/[^a-z0-9]/i', '', str_param('sid', 40)), 0, 40);
    if (!$sid) api_error('sid requerido', 400);

    // first_seen no va en la lista de columnas: en MySQL (motor real hoy)
    // sql_upsert hace ON DUPLICATE KEY UPDATE con solo estas columnas, así
    // que first_seen no se toca en pings repetidos (usa el DEFAULT
    // CURRENT_TIMESTAMP solo la primera vez). En SQLite, INSERT OR REPLACE
    // sí reescribe la fila entera — first_seen se resetearía en cada ping
    // ahí; sin impacto hoy porque el motor real es MySQL, pero a tener en
    // cuenta si alguna vez se vuelve a SQLite.
    $ip = client_ip();
    sql_upsert($db, 'maintenance_viewers', [
        'sid'        => $sid,
        'last_seen'  => gmdate('Y-m-d H:i:s'),
        'ip_hash'    => ip_hash($ip),
        'provincia'  => geo_provincia($db, $ip),
    ]);

    api_response(['ok' => true]);
}

// ── Contact (formulario de la página de mantenimiento) ───────────────────────
// Mismo checklist anti-spam que contacto.php: honeypot camuflado (nombre no
// genérico), trampa de tiempo (timestamp server-side ida y vuelta), rate
// limit por IP sin DB, fingir éxito ante bot/trampa, error real distinto
// ante validación real, origen identificado en la notificación.

if ($action === 'contact') {
    $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

    $honeypot = trim($src['asunto2'] ?? '');
    $ts_render = (int)($src['tsm'] ?? 0);
    $es_rapido = $ts_render <= 0 || (time() - $ts_render) < 2;

    if ($honeypot !== '' || $es_rapido) {
        api_response(['ok' => true]);
    }

    $nombre  = trim(strip_tags($src['nombre']  ?? ''));
    $email   = trim(strip_tags($src['email']   ?? ''));
    $mensaje = trim(strip_tags($src['mensaje'] ?? ''));

    $ip_clave = ip_hash(client_ip());
    $archivo_limite = sys_get_temp_dir() . '/radio_mant_contacto_' . $ip_clave . '.json';
    $marcas = file_exists($archivo_limite) ? (@json_decode(file_get_contents($archivo_limite), true) ?? []) : [];
    $marcas = array_values(array_filter($marcas, fn($t) => $t > time() - 3600));
    if (count($marcas) >= 5) {
        api_error('Demasiados mensajes en poco tiempo. Probá de nuevo en un rato.', 429);
    }
    $marcas[] = time();
    @file_put_contents($archivo_limite, json_encode($marcas));

    if (strlen($mensaje) < 5)   api_error('Contanos un poco más — el mensaje es muy corto.');
    if (strlen($mensaje) > 2000) api_error('El mensaje es demasiado largo (máximo 2000 caracteres).');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('El email no parece válido — dejalo vacío si preferís no compartirlo.');
    }

    try {
        sqlite_lazy_migration($db, fn($db) => $db->exec('CREATE TABLE IF NOT EXISTS contacto_mensajes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT, email TEXT, mensaje TEXT NOT NULL, created_at TEXT NOT NULL
        )'));
        $db->prepare('INSERT INTO contacto_mensajes (nombre, email, mensaje, created_at) VALUES (?, ?, ?, ?)')
           ->execute([$nombre ?: null, $email ?: null, '[Durante mantenimiento] ' . $mensaje, gmdate('Y-m-d H:i:s')]);

        if (TG_TOKEN && TG_CHAT_ID) {
            $quien = $nombre ?: 'Anónimo';
            $contacto_linea = $email ? "\nContacto: {$email}" : '';
            $texto = "📻 [Radio Argentina · Mantenimiento] Nuevo mensaje\nDe: {$quien}{$contacto_linea}\n\n{$mensaje}";
            $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4,
                CURLOPT_POSTFIELDS => ['chat_id' => TG_CHAT_ID, 'text' => $texto],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        api_response(['ok' => true]);
    } catch (Exception $e) {
        api_error('No se pudo enviar el mensaje. Intentá de nuevo más tarde.', 500);
    }
}

api_error('Acción desconocida', 400);
