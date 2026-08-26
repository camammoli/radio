<?php
/**
 * admin.php — Panel de administración Radio Argentina v4.
 * Autenticación por sesión. No indexado.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_db.php';

session_start();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$ADMIN_USER = defined('ADMIN_USER') ? ADMIN_USER : 'admin';
$ADMIN_PASS = defined('ADMIN_PASS') ? ADMIN_PASS : (defined('RADIO_ADMIN_KEY') ? RADIO_ADMIN_KEY : 'mammoli_radio_2026');

// ── Auth ──────────────────────────────────────────────────────────────────────

$act = $_POST['action'] ?? '';

if ($act === 'login') {
    if (($_POST['u'] ?? '') === $ADMIN_USER && ($_POST['p'] ?? '') === $ADMIN_PASS) {
        $_SESSION['radio_admin'] = true;
        $_SESSION['csrf']        = bin2hex(random_bytes(16));
        // Sin redirect: renderizar el dashboard directamente evita cualquier flash intermedio
    } else {
        $login_err = true;
    }
}
if ($act === 'logout') {
    session_destroy();
    session_start(); // sesión limpia para la página de login
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    login_page(false);
    exit;
}

if (empty($_SESSION['radio_admin'])) {
    login_page($login_err ?? false);
    exit;
}

$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$db   = radio_db();

// Migraciones
try { $db->exec('ALTER TABLE surveys ADD COLUMN location TEXT'); } catch (Exception $e) {}
try { $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)'); } catch (Exception $e) {}
try { $db->exec('CREATE TABLE IF NOT EXISTS shares (id INTEGER PRIMARY KEY AUTOINCREMENT, station_id INTEGER, slug TEXT, channel TEXT, ip_hash TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)'); } catch (Exception $e) {}
try { $db->exec('ALTER TABLE plays ADD COLUMN ended_at TEXT'); } catch (Exception $e) {}
// v3: suscriptores y patrones de programas
try { $db->exec('CREATE TABLE IF NOT EXISTS subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_type TEXT NOT NULL,
    contact_value TEXT NOT NULL,
    preferences TEXT DEFAULT "[]",
    active INTEGER DEFAULT 0,
    token TEXT UNIQUE NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    last_notified TEXT
)'); } catch (Exception $e) {}
try { $db->exec('CREATE TABLE IF NOT EXISTS subscriber_matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id INTEGER REFERENCES subscribers(id) ON DELETE CASCADE,
    station_id INTEGER REFERENCES stations(id),
    keyword TEXT NOT NULL,
    first_seen TEXT DEFAULT CURRENT_TIMESTAMP,
    match_count INTEGER DEFAULT 1,
    notified INTEGER DEFAULT 0
)'); } catch (Exception $e) {}
try { $db->exec('CREATE TABLE IF NOT EXISTS program_patterns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER REFERENCES stations(id),
    keyword TEXT NOT NULL,
    day_of_week INTEGER,
    hour INTEGER,
    confidence REAL DEFAULT 0.0,
    occurrences INTEGER DEFAULT 0,
    last_seen TEXT,
    UNIQUE(station_id, keyword, day_of_week, hour)
)'); } catch (Exception $e) {}
// Metadata de gestión de emisoras (contacto, destacada, seguimiento)
try { $db->exec('ALTER TABLE stations ADD COLUMN en_observacion INTEGER DEFAULT 0') ; } catch (Exception $e) {}
try { $db->exec('ALTER TABLE stations ADD COLUMN destacada INTEGER DEFAULT 0') ; } catch (Exception $e) {}
try { $db->exec('ALTER TABLE stations ADD COLUMN contacto_publico TEXT') ; } catch (Exception $e) {}
try { $db->exec('ALTER TABLE stations ADD COLUMN contacto_privado TEXT') ; } catch (Exception $e) {}
try { $db->exec('ALTER TABLE stations ADD COLUMN notas_privadas TEXT') ; } catch (Exception $e) {}
// ABM de emisoras (TKT pendiente de numerar): nunca se borran, solo se marcan de baja.
// activa=1 (alta) / activa=0 (de baja) — ultimo_cambio se toca SOLO al hacer alta/baja,
// a diferencia de updated_at que cambia con cualquier edición.
try { $db->exec('ALTER TABLE stations ADD COLUMN activa INTEGER DEFAULT 1') ; } catch (Exception $e) {}
try { $db->exec('ALTER TABLE stations ADD COLUMN ultimo_cambio TEXT') ; } catch (Exception $e) {}
$v_stations_sql = $db->query("SELECT sql FROM sqlite_master WHERE type='view' AND name='v_stations'")->fetchColumn();
if ($v_stations_sql === false || strpos($v_stations_sql, 'v_stations_version:5') === false) {
    $db->exec('DROP VIEW IF EXISTS v_stations');
    $db->exec("CREATE VIEW v_stations AS
        -- v_stations_version:5 (agrega respeto por baja manual, activa=0)
        SELECT
            s.id, s.n, s.slug, s.nombre, s.url, s.provincia, s.tags,
            s.codec, s.bitrate, s.homepage, s.logo, s.source,
            s.rb_uuid, s.rb_votes, s.rb_clicks,
            s.contacto_publico, s.destacada,
            COALESCE(ss.estado, 'unknown')          AS estado,
            ss.http_code, ss.response_ms,
            ss.consecutive_failures,
            ss.last_checked, ss.last_ok,
            COALESCE(ic.supported, 0)               AS icy_supported,
            ic.icy_name, ic.stream_title,
            ic.last_checked                         AS icy_last_checked,
            COALESCE(p.total_plays, 0)              AS total_plays
        FROM stations s
        LEFT JOIN stream_status  ss ON ss.station_id = s.id
        LEFT JOIN icy_cache      ic ON ic.station_id = s.id
        LEFT JOIN (
            SELECT station_id, COUNT(*) AS total_plays FROM plays GROUP BY station_id
        ) p ON p.station_id = s.id
        WHERE s.approved = 1
          AND COALESCE(s.activa, 1) = 1
          AND NOT (
                ss.estado = 'muerto'
                AND (ss.last_ok IS NULL OR ss.last_ok < datetime('now','-14 days'))
          )");
}
try { $db->exec('CREATE TABLE IF NOT EXISTS reportes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER REFERENCES stations(id),
    mensaje TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)'); } catch (Exception $e) {}
try { $db->exec('CREATE INDEX IF NOT EXISTS idx_plays_iphash ON plays(ip_hash)'); } catch (Exception $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (ip_hash TEXT PRIMARY KEY, provincia TEXT, updated_at TEXT DEFAULT (datetime('now')))"); } catch (Exception $e) {}

// ── Acciones sobre sugerencias ────────────────────────────────────────────────

if ($act === 'toggle_notify' && ($_POST['csrf'] ?? '') === $csrf) {
    $current = $db->query("SELECT value FROM settings WHERE key='notify_oyentes' LIMIT 1")->fetchColumn();
    if ($current === false) {
        $current = defined('NOTIFY_OYENTES') && NOTIFY_OYENTES ? '1' : '0';
    }
    $new = $current === '1' ? '0' : '1';
    $db->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('notify_oyentes', ?, datetime('now'))")
       ->execute([$new]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#telegram');
    exit;
}
if ($act === 'approve' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare("UPDATE stations SET approved=1, updated_at=datetime('now') WHERE id=? AND source IN ('sugerencia','radio-browser') AND approved=0")
       ->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#sugerencias');
    exit;
}
if ($act === 'reject' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare("DELETE FROM stations WHERE id=? AND source IN ('sugerencia','radio-browser') AND approved=0")
       ->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#sugerencias');
    exit;
}
if ($act === 'set_activa' && ($_POST['csrf'] ?? '') === $csrf) {
    // Alta/baja manual — NUNCA borra la fila, solo togglea activa y toca ultimo_cambio.
    $nueva = !empty($_POST['activa']) ? 1 : 0;
    $db->prepare('UPDATE stations SET activa=?, ultimo_cambio=datetime("now") WHERE id=?')
       ->execute([$nueva, (int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#emisoras');
    exit;
}
if ($act === 'crear_emisora' && ($_POST['csrf'] ?? '') === $csrf) {
    $nombre = trim($_POST['nombre'] ?? '');
    $url    = trim($_POST['url'] ?? '');
    $slug   = trim($_POST['slug'] ?? '');
    if ($slug === '' && $nombre !== '') {
        $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($nombre))), '-');
    }
    if ($nombre === '' || $url === '' || $slug === '') {
        $alta_err = 'Nombre, URL y slug son obligatorios.';
    } else {
        try {
            $db->prepare('INSERT INTO stations
                    (slug, nombre, url, provincia, homepage, source, approved, activa, created_at, updated_at, ultimo_cambio)
                  VALUES (?, ?, ?, ?, ?, "manual", 1, 1, datetime("now"), datetime("now"), datetime("now"))')
               ->execute([
                    $slug, $nombre, $url,
                    trim($_POST['provincia'] ?? '') ?: null,
                    trim($_POST['homepage']  ?? '') ?: null,
                ]);
        } catch (Exception $e) {
            $alta_err = 'No se pudo crear (¿slug o URL ya existen?): ' . $e->getMessage();
        }
    }
    if (empty($alta_err)) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#emisoras');
        exit;
    }
}
if ($act === 'editar_emisora' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare('UPDATE stations SET nombre=?, url=?, provincia=?, homepage=?, updated_at=datetime("now") WHERE id=?')
       ->execute([
            trim($_POST['nombre'] ?? ''),
            trim($_POST['url'] ?? ''),
            trim($_POST['provincia'] ?? '') ?: null,
            trim($_POST['homepage']  ?? '') ?: null,
            (int)($_POST['id'] ?? 0),
       ]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#emisoras');
    exit;
}
if ($act === 'sub_activate' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare('UPDATE subscribers SET active=1 WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#suscriptores');
    exit;
}
if ($act === 'sub_deactivate' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare('UPDATE subscribers SET active=0 WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#suscriptores');
    exit;
}
if ($act === 'sub_delete' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare('DELETE FROM subscribers WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#suscriptores');
    exit;
}
if ($act === 'update_meta' && ($_POST['csrf'] ?? '') === $csrf) {
    $db->prepare('UPDATE stations SET
            en_observacion = ?, destacada = ?,
            contacto_publico = ?, contacto_privado = ?, notas_privadas = ?,
            updated_at = datetime("now")
         WHERE id = ?')
       ->execute([
            !empty($_POST['en_observacion']) ? 1 : 0,
            !empty($_POST['destacada']) ? 1 : 0,
            trim($_POST['contacto_publico'] ?? '') ?: null,
            trim($_POST['contacto_privado'] ?? '') ?: null,
            trim($_POST['notas_privadas'] ?? '') ?: null,
            (int)($_POST['id'] ?? 0),
       ]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#' . preg_replace('/[^a-z]/', '', $_POST['volver'] ?? 'seguimiento'));
    exit;
}

// ── Ajax: auto-refresh ───────────────────────────────────────────────────────

if (isset($_GET['ajax'])) {
    session_write_close(); // liberar lock de sesión antes de las queries
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        // Sin DELETE: listeners.php ya hace el cleanup en cada ping
        $out = [
            'stats' => [
                'total'       => (int)$db->query('SELECT COUNT(*) FROM stations WHERE approved=1')->fetchColumn(),
                'ok'          => (int)$db->query("SELECT COUNT(*) FROM v_stations WHERE estado='ok'")->fetchColumn(),
                'icy'         => (int)$db->query('SELECT COUNT(*) FROM icy_cache WHERE supported=1')->fetchColumn(),
                'icy_activo'  => (int)$db->query("SELECT COUNT(*) FROM icy_cache WHERE supported=1 AND stream_title IS NOT NULL AND stream_title!=''")->fetchColumn(),
                'plays_hoy'   => (int)$db->query("SELECT COUNT(*) FROM plays WHERE played_at>=date('now')")->fetchColumn(),
                'plays_total' => (int)$db->query('SELECT COUNT(*) FROM plays')->fetchColumn(),
                'listeners'   => (int)$db->query("SELECT COUNT(*) FROM listeners WHERE last_seen>=datetime('now','-90 seconds')")->fetchColumn(),
            ],
            'plays' => $db->query(
                "SELECT p.played_at, p.ip_hash, p.provincia, p.source, p.session_id, s.nombre, s.slug,
                        CASE WHEN p.ended_at IS NOT NULL THEN ROUND((julianday(p.ended_at)-julianday(p.played_at))*86400)
                             WHEN l.sid IS NOT NULL      THEN ROUND((julianday('now')-julianday(p.played_at))*86400)
                             ELSE NULL END AS duration_secs,
                        CASE WHEN l.sid IS NOT NULL AND p.ended_at IS NULL THEN 1 ELSE 0 END AS is_active,
                        (SELECT COUNT(DISTINCT date(p2.played_at)) FROM plays p2 WHERE p2.ip_hash = p.ip_hash) AS dias_activos,
                        (SELECT COUNT(DISTINCT p3.station_id) FROM plays p3 WHERE p3.ip_hash = p.ip_hash
                          AND p3.played_at BETWEEN datetime(p.played_at,'-30 minutes') AND datetime(p.played_at,'+30 minutes')) AS hops_1h
                 FROM plays p
                 LEFT JOIN stations s ON s.id=p.station_id
                 LEFT JOIN listeners l ON l.sid=p.session_id
                 ORDER BY p.played_at DESC LIMIT 200"
            )->fetchAll(),
            'shares' => $db->query(
                "SELECT sh.created_at, sh.channel, sh.ip_hash, sh.slug, s.nombre, g.provincia
                 FROM shares sh LEFT JOIN stations s ON s.id=sh.station_id
                                 LEFT JOIN ip_geo_cache g ON g.ip_hash=sh.ip_hash
                 ORDER BY sh.created_at DESC LIMIT 100"
            )->fetchAll(),
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Consultas ─────────────────────────────────────────────────────────────────

$stats = [
    'total'      => (int)$db->query('SELECT COUNT(*) FROM stations WHERE approved=1 AND COALESCE(activa,1)=1')->fetchColumn(),
    'de_baja'    => (int)$db->query('SELECT COUNT(*) FROM stations WHERE approved=1 AND COALESCE(activa,1)=0')->fetchColumn(),
    'ok'         => (int)$db->query("SELECT COUNT(*) FROM v_stations WHERE estado='ok'")->fetchColumn(),
    'icy'        => (int)$db->query('SELECT COUNT(*) FROM icy_cache WHERE supported=1')->fetchColumn(),
    'icy_activo' => (int)$db->query("SELECT COUNT(*) FROM icy_cache WHERE supported=1 AND stream_title IS NOT NULL AND stream_title!=''")->fetchColumn(),
    'plays_hoy'  => (int)$db->query("SELECT COUNT(*) FROM plays WHERE played_at>=date('now')")->fetchColumn(),
    'plays_total'=> (int)$db->query('SELECT COUNT(*) FROM plays')->fetchColumn(),
    'listeners'  => (int)$db->query("SELECT COUNT(*) FROM listeners WHERE last_seen>=datetime('now','-90 seconds')")->fetchColumn(),
    'surveys'    => (int)$db->query('SELECT COUNT(*) FROM surveys')->fetchColumn(),
    'suger_pend' => (int)$db->query("SELECT COUNT(*) FROM stations WHERE source IN ('sugerencia','radio-browser') AND approved=0")->fetchColumn(),
    'problemas'  => (int)$db->query(
        "SELECT COUNT(DISTINCT s.id) FROM stations s
         LEFT JOIN stream_status ss ON ss.station_id = s.id
         WHERE (s.approved = 0 AND s.source NOT IN ('sugerencia','radio-browser'))
            OR ss.estado IN ('muerto','timeout')
            OR s.id IN (SELECT station_id FROM reportes WHERE created_at >= datetime('now','-14 days'))"
    )->fetchColumn(),
    'pendientes_crawler' => (int)$db->query(
        "SELECT COUNT(*) FROM stations s
         LEFT JOIN stream_status ss ON ss.station_id = s.id
         WHERE s.approved = 1 AND ss.station_id IS NULL"
    )->fetchColumn(),
];

// Encuesta bienvenida — rating
$welcome_rating = $db->query(
    "SELECT rating, COUNT(*) AS cnt FROM surveys WHERE station_id IS NULL GROUP BY rating ORDER BY rating DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$wrating = [-1 => 0, 0 => 0, 1 => 0];
foreach ($welcome_rating as $r) $wrating[(int)$r['rating']] = (int)$r['cnt'];

// Encuesta bienvenida — location (¿desde dónde escuchás?)
$welcome_loc = $db->query(
    "SELECT location, COUNT(*) AS cnt FROM surveys
     WHERE station_id IS NULL AND location IS NOT NULL AND location != ''
     GROUP BY location ORDER BY cnt DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$loc_icons = ['casa' => '🏠', 'trabajo' => '💼', 'viaje' => '🚗', 'caminando' => '📱'];

// Oyentes por provincia (geolocalizado por IP, últimos 30 días — TKT-0689)
try { $db->exec('ALTER TABLE plays ADD COLUMN provincia TEXT'); } catch (Exception $e) {}
$geo_provincia = $db->query(
    "SELECT provincia, COUNT(DISTINCT ip_hash) AS cnt
     FROM plays
     WHERE provincia IS NOT NULL AND played_at >= datetime('now','-30 days')
     GROUP BY provincia ORDER BY cnt DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$geo_total = array_sum(array_column($geo_provincia, 'cnt'));

// Encuestas por emisora (top 40)
$station_surveys = $db->query(
    "SELECT s.nombre, s.slug,
            SUM(CASE WHEN sv.rating=1  THEN 1 ELSE 0 END) AS pos,
            SUM(CASE WHEN sv.rating=0  THEN 1 ELSE 0 END) AS neu,
            SUM(CASE WHEN sv.rating=-1 THEN 1 ELSE 0 END) AS neg,
            COUNT(*) AS total,
            MAX(sv.created_at) AS ultima
     FROM surveys sv
     JOIN stations s ON s.id = sv.station_id
     WHERE sv.station_id IS NOT NULL
     GROUP BY sv.station_id
     ORDER BY total DESC
     LIMIT 40"
)->fetchAll(PDO::FETCH_ASSOC);

// Sugerencias pendientes
try { $db->exec('ALTER TABLE stations ADD COLUMN contacto TEXT'); } catch (Exception $e) {}
// "Sugerencias pendientes" incluye lo que escribe la gente (source=sugerencia)
// Y lo que encuentran los crawlers de descubrimiento (source=radio-browser,
// approved=0 hasta que se revisan acá) — mismo flujo de aprobar/rechazar.
$sugerencias = $db->query(
    "SELECT id, nombre, url, provincia, homepage, contacto, source, created_at
     FROM stations WHERE source IN ('sugerencia','radio-browser') AND approved=0
     ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Radios con problemas: ocultas, muertas/timeout, o reportadas en los últimos 14 días.
// Las approved=0 de sugerencia/radio-browser NO entran acá — ya tienen su propio
// lugar (pestaña Sugerencias) con botones de aprobar/rechazar.
$problemas = $db->query(
    "SELECT s.*, ss.estado,
            (SELECT COUNT(*) FROM reportes r WHERE r.station_id = s.id AND r.created_at >= datetime('now','-14 days')) AS reportes_recientes
     FROM stations s
     LEFT JOIN stream_status ss ON ss.station_id = s.id
     WHERE (s.approved = 0 AND s.source NOT IN ('sugerencia','radio-browser'))
        OR ss.estado IN ('muerto','timeout')
        OR s.id IN (SELECT station_id FROM reportes WHERE created_at >= datetime('now','-14 days'))
     ORDER BY s.updated_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Radios aprobadas que el crawler todavía no verificó ni una vez
$pendientes_crawler = $db->query(
    "SELECT s.* FROM stations s
     LEFT JOIN stream_status ss ON ss.station_id = s.id
     WHERE s.approved = 1 AND ss.station_id IS NULL
     ORDER BY s.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// ABM completo de emisoras — TODO el catálogo (activas y de baja), nunca se borra nada acá.
$emisoras_todas = $db->query(
    "SELECT s.*, ss.estado
     FROM stations s
     LEFT JOIN stream_status ss ON ss.station_id = s.id
     WHERE s.approved = 1
     ORDER BY s.nombre COLLATE NOCASE"
)->fetchAll(PDO::FETCH_ASSOC);

// Seguimiento especial: en observación, o con contacto privado cargado
$seguimiento = $db->query(
    "SELECT * FROM stations
     WHERE en_observacion = 1 OR (contacto_privado IS NOT NULL AND contacto_privado != '')
     ORDER BY updated_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Últimas ejecuciones de crawlers
$crawler_runs = $db->query(
    "SELECT crawler, started_at, finished_at,
            ROUND((julianday(finished_at)-julianday(started_at))*86400) AS secs,
            stations_checked, changes_detected, errors, notes
     FROM crawler_runs ORDER BY started_at DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

// Estado Telegram
$notify_db  = $db->query("SELECT value FROM settings WHERE key='notify_oyentes' LIMIT 1")->fetchColumn();
$notify_val = $notify_db !== false ? $notify_db === '1' : (defined('NOTIFY_OYENTES') && NOTIFY_OYENTES);

// Shares recientes (últimas 100)
$shares_recientes = $db->query(
    "SELECT sh.created_at, sh.channel, sh.ip_hash, sh.slug,
            s.nombre, g.provincia
     FROM shares sh
     LEFT JOIN stations s ON s.id = sh.station_id
     LEFT JOIN ip_geo_cache g ON g.ip_hash = sh.ip_hash
     ORDER BY sh.created_at DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

// Toast de ayuda/sostener el proyecto — estadísticas + eventos recientes
try {
    $ayuda_stats = $db->query(
        "SELECT tipo, COUNT(*) AS n FROM ayuda_toast_eventos GROUP BY tipo"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $ayuda_stats = []; }

try {
    $ayuda_eventos = $db->query(
        "SELECT tipo, ip_hash, provincia, created_at FROM ayuda_toast_eventos
         ORDER BY created_at DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $ayuda_eventos = []; }

// Detalle de encuestas con ip_hash (últimas 100)
$surveys_detalle = $db->query(
    "SELECT sv.rating, sv.location, sv.ip_hash, sv.created_at,
            s.nombre, s.slug, g.provincia AS provincia_geo
     FROM surveys sv
     LEFT JOIN stations s ON s.id = sv.station_id
     LEFT JOIN ip_geo_cache g ON g.ip_hash = sv.ip_hash
     ORDER BY sv.created_at DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

// Plays recientes (últimas 200)
$plays_recientes = $db->query(
    "SELECT p.played_at, p.ended_at, p.ip_hash, p.provincia, p.source, p.session_id,
            s.nombre, s.slug,
            CASE
              WHEN p.ended_at IS NOT NULL
                THEN ROUND((julianday(p.ended_at) - julianday(p.played_at)) * 86400)
              WHEN l.sid IS NOT NULL
                THEN ROUND((julianday('now')       - julianday(p.played_at)) * 86400)
              ELSE NULL
            END AS duration_secs,
            CASE WHEN l.sid IS NOT NULL AND p.ended_at IS NULL THEN 1 ELSE 0 END AS is_active,
            (SELECT COUNT(DISTINCT date(p2.played_at)) FROM plays p2 WHERE p2.ip_hash = p.ip_hash) AS dias_activos,
            (SELECT COUNT(DISTINCT p3.station_id) FROM plays p3 WHERE p3.ip_hash = p.ip_hash
              AND p3.played_at BETWEEN datetime(p.played_at,'-30 minutes') AND datetime(p.played_at,'+30 minutes')) AS hops_1h
     FROM plays p
     LEFT JOIN stations s ON s.id = p.station_id
     LEFT JOIN listeners l ON l.sid = p.session_id
     ORDER BY p.played_at DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

// ICY cache — resumen
$icy = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN stream_title IS NOT NULL AND stream_title!='' THEN 1 ELSE 0 END) AS con_titulo,
            MAX(last_checked) AS ultima
     FROM icy_cache WHERE supported=1"
)->fetch(PDO::FETCH_ASSOC);

// ── Suscriptores ──────────────────────────────────────────────────────────────
$sub_stats = [
    'total'      => (int)$db->query('SELECT COUNT(*) FROM subscribers')->fetchColumn(),
    'activos'    => (int)$db->query('SELECT COUNT(*) FROM subscribers WHERE active=1')->fetchColumn(),
    'tg'         => (int)$db->query("SELECT COUNT(*) FROM subscribers WHERE contact_type='telegram' AND active=1")->fetchColumn(),
    'email'      => (int)$db->query("SELECT COUNT(*) FROM subscribers WHERE contact_type='email' AND active=1")->fetchColumn(),
    'pendientes' => (int)$db->query('SELECT COUNT(*) FROM subscribers WHERE active=0')->fetchColumn(),
];

$subscribers_list = $db->query(
    "SELECT id, contact_type, contact_value, preferences, active, token, created_at, last_notified
     FROM subscribers ORDER BY created_at DESC LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

$notif_recientes = $db->query(
    "SELECT sm.first_seen, sm.keyword, sm.match_count, s.nombre AS station, sub.contact_type, sub.contact_value
     FROM subscriber_matches sm
     JOIN subscribers sub ON sub.id = sm.subscriber_id
     LEFT JOIN stations s ON s.id = sm.station_id
     WHERE sm.notified = 1
     ORDER BY sm.first_seen DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

$program_patterns = $db->query(
    "SELECT pp.keyword, pp.day_of_week, pp.hour, pp.confidence, pp.occurrences, pp.last_seen, s.nombre
     FROM program_patterns pp
     JOIN stations s ON s.id = pp.station_id
     ORDER BY pp.confidence DESC LIMIT 40"
)->fetchAll(PDO::FETCH_ASSOC);

// Top artistas desde icy_history (para preview de chips de suscripción)
$noise_icy = ['classic hits','variados','mix','desconocido','various','unknown',
              'fm del mar','sport billy','cop centro','symploké','symploke',
              'el hacedor iglesia','sarah nimmo','variado'];
$preview_raw = $db->query("
    SELECT trim(substr(title, 1, instr(title,' - ')-1)) AS artista, COUNT(*) AS n
    FROM icy_history
    WHERE title LIKE '% - %'
      AND length(trim(substr(title, 1, instr(title,' - ')-1))) BETWEEN 2 AND 45
    GROUP BY lower(trim(substr(title, 1, instr(title,' - ')-1)))
    HAVING n >= 2 ORDER BY n DESC LIMIT 80
")->fetchAll(PDO::FETCH_ASSOC);
$preview_artistas = [];
if ($preview_raw) {
    $max_n = max(array_column($preview_raw, 'n'));
    foreach ($preview_raw as $r) {
        $low = strtolower($r['artista']);
        if (preg_match('/\bfm\b/i', $r['artista'])) continue;
        if (preg_match('/\b\d{4}\b/', $r['artista'])) continue;
        if (in_array($low, $noise_icy)) continue;
        $norm  = mb_convert_case($r['artista'], MB_CASE_TITLE, 'UTF-8');
        $ratio = $r['n'] / $max_n;
        $tier  = $ratio >= 0.4 ? 'hot' : ($ratio >= 0.15 ? 'warm' : 'cool');
        $preview_artistas[] = ['name' => $norm, 'n' => (int)$r['n'], 'tier' => $tier];
        if (count($preview_artistas) >= 40) break;
    }
}

// ── ICY activas (con título, las más recientes)
$icy_activas = $db->query(
    "SELECT s.nombre, s.slug, ic.stream_title, ic.last_checked,
            ROUND((julianday('now')-julianday(ic.last_checked))*1440) AS mins_ago
     FROM icy_cache ic
     JOIN stations s ON s.id = ic.station_id
     WHERE ic.supported=1 AND ic.stream_title IS NOT NULL AND ic.stream_title!=''
     ORDER BY ic.last_checked DESC
     LIMIT 60"
)->fetchAll(PDO::FETCH_ASSOC);

// ── HTML ──────────────────────────────────────────────────────────────────────

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ago(?string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt . ' UTC');
    if ($diff < 60)    return 'hace ' . $diff . 's';
    if ($diff < 3600)  return 'hace ' . floor($diff/60) . 'min';
    if ($diff < 86400) return 'hace ' . floor($diff/3600) . 'h';
    return 'hace ' . floor($diff/86400) . 'd';
}

// Categoría de visitante según días distintos activos en el historial de plays.
// Umbral: 1 día=ocasional, 2-3=recurrente leve, 4-7=frecuente, 8+=núcleo fiel.
function visitor_badge($dias): string {
    if ($dias === null) return '';
    $dias = (int)$dias;
    if ($dias >= 8) return '<span title="Núcleo fiel (8+ días)">💎</span>';
    if ($dias >= 4) return '<span title="Frecuente (4-7 días)">⭐</span>';
    if ($dias >= 2) return '<span title="Recurrente (2-3 días)">🔁</span>';
    return '<span title="Ocasional (1 día)" style="opacity:.5">🆕</span>';
}

// Salud del stream según el último chequeo del crawler (stream_status.estado)
// — independiente del alta/baja manual: una emisora puede estar "Alta" (visible
// al público) y con el stream caído, o "Baja" (oculta) y funcionando bien.
function salud_badge(?string $estado): string {
    switch ($estado) {
        case 'ok':      return '<span class="badge-ok">OK</span>';
        case 'muerto':  return '<span class="badge-err">Muerto</span>';
        case 'timeout': return '<span style="color:var(--muted)">Timeout</span>';
        default:        return '<span style="color:var(--muted)">Desconocido</span>';
    }
}

// Station-hopping: mismo IP saltando de emisora en emisora en poco tiempo
// (caso real documentado: 35 cambios en 3hs). No es un bloqueo, solo una señal
// visual para no confundir esas sesiones con audiencia real al leer métricas.
function bot_badge($hops): string {
    $hops = (int)$hops;
    if ($hops >= 12) return '<span title="'.$hops.' emisoras distintas en 1h — muy probable bot/script" style="color:var(--danger,#c0392b)">🤖 Bot</span>';
    if ($hops >= 6)  return '<span title="'.$hops.' emisoras distintas en 1h — station-hopping, revisar">🤔 Probable bot</span>';
    return '<span style="color:var(--muted)">Persona</span>';
}

// Fila de emisora con formulario de edición (contacto/observación/destacada/notas),
// reusada en las pestañas Problemas, Pendientes y Seguimiento.
function station_meta_row(array $s, string $csrf, string $volver, string $motivo = ''): void {
    ?>
    <tr>
      <td>
        <?= h($s['nombre']) ?>
        <?php if (!empty($s['en_observacion'])): ?><span title="En observación">🔎</span><?php endif; ?>
        <?php if (!empty($s['destacada'])): ?><span title="Destacada">⭐</span><?php endif; ?>
        <br><span style="font-size:11px;color:var(--muted)"><?= h($s['slug']) ?></span>
      </td>
      <td class="url" style="max-width:260px"><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener"><?= h($s['url']) ?></a></td>
      <td style="font-size:12px"><?= $motivo ?: '—' ?></td>
      <td style="white-space:nowrap">
        <details>
          <summary style="cursor:pointer;color:var(--accent)">Editar</summary>
          <form method="post" style="margin-top:8px;display:flex;flex-direction:column;gap:6px;min-width:240px">
            <input type="hidden" name="action" value="update_meta">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="volver" value="<?= h($volver) ?>">
            <label style="font-size:12px"><input type="checkbox" name="en_observacion" value="1" <?= !empty($s['en_observacion']) ? 'checked' : '' ?>> En observación</label>
            <label style="font-size:12px"><input type="checkbox" name="destacada" value="1" <?= !empty($s['destacada']) ? 'checked' : '' ?>> Destacada</label>
            <input type="text" name="contacto_publico" placeholder="Contacto público (visible en la ficha)" value="<?= h($s['contacto_publico'] ?? '') ?>">
            <input type="text" name="contacto_privado" placeholder="Contacto privado (solo nosotros)" value="<?= h($s['contacto_privado'] ?? '') ?>">
            <textarea name="notas_privadas" placeholder="Notas privadas" rows="2"><?= h($s['notas_privadas'] ?? '') ?></textarea>
            <button class="btn-ok" type="submit">Guardar</button>
          </form>
        </details>
      </td>
    </tr>
    <?php
}

// Fila de emisora para la pestaña Emisoras (ABM completo): toggle alta/baja
// (nunca DELETE) + edición de campos core (nombre/url/provincia/homepage).
function emisora_abm_row(array $s, string $csrf): void {
    $activa = (int)($s['activa'] ?? 1) === 1;
    ?>
    <tr>
      <td>
        <?= h($s['nombre']) ?>
        <?php if (!$activa): ?><span title="De baja" style="opacity:.6">🚫</span><?php endif; ?>
        <br><span style="font-size:11px;color:var(--muted)"><?= h($s['slug']) ?></span>
      </td>
      <td class="url" style="max-width:220px"><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener"><?= h($s['url']) ?></a></td>
      <td><?= h($s['provincia'] ?? '—') ?></td>
      <td><?= $activa ? '<span class="badge-ok">Alta</span>' : '<span class="badge-err">Baja</span>' ?></td>
      <td><?= salud_badge($s['estado'] ?? null) ?></td>
      <td style="font-size:12px"><?= h(date('d/m/Y', strtotime($s['created_at'] ?? 'now'))) ?></td>
      <td style="font-size:12px"><?= $s['ultimo_cambio'] ? h(date('d/m/Y H:i', strtotime($s['ultimo_cambio']))) : '—' ?></td>
      <td style="white-space:nowrap">
        <form method="post" class="inline">
          <input type="hidden" name="action" value="set_activa">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="activa" value="<?= $activa ? '0' : '1' ?>">
          <button class="<?= $activa ? 'btn-del' : 'btn-ok' ?>" type="submit"><?= $activa ? 'Dar de baja' : 'Reactivar' ?></button>
        </form>
        <details style="margin-top:4px">
          <summary style="cursor:pointer;color:var(--accent)">Editar</summary>
          <form method="post" style="margin-top:8px;display:flex;flex-direction:column;gap:6px;min-width:220px">
            <input type="hidden" name="action" value="editar_emisora">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="text" name="nombre" placeholder="Nombre" value="<?= h($s['nombre']) ?>" required>
            <input type="text" name="url" placeholder="URL del stream" value="<?= h($s['url']) ?>" required>
            <input type="text" name="provincia" placeholder="Provincia" value="<?= h($s['provincia'] ?? '') ?>">
            <input type="text" name="homepage" placeholder="Homepage" value="<?= h($s['homepage'] ?? '') ?>">
            <button class="btn-ok" type="submit">Guardar</button>
          </form>
        </details>
      </td>
    </tr>
    <?php
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin — Radio Argentina</title>
<style>
:root{--bg:#0f172a;--card:#1e293b;--card2:#263249;--border:#334155;--text:#e2e8f0;--muted:#94a3b8;--accent:#3b82f6;--green:#22c55e;--red:#ef4444;--yellow:#f59e0b}
body.light{--bg:#f1f5f9;--card:#ffffff;--card2:#f8fafc;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--accent:#2563eb;--green:#16a34a;--red:#dc2626;--yellow:#d97706}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif;padding:16px;transition:background .2s,color .2s}
h1{font-size:20px}
h2{font-size:15px;color:var(--accent);margin:0 0 14px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.cards{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px}
.card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 18px;min-width:120px}
.card .v{font-size:26px;font-weight:700;color:var(--accent)}
.card .l{font-size:11px;color:var(--muted);margin-top:2px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px}
th{text-align:left;padding:7px 10px;background:var(--card2);color:var(--muted);font-weight:600;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid var(--border);vertical-align:top}
tr:hover td{background:var(--card2)}
/* ── Tablas — filtro/orden/paginado/agrupado (DT) ─────────────────────────── */
.dt-toolbar{display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
.dt-toolbar input[type=search]{background:var(--card2);border:1px solid var(--border);color:var(--text);
  border-radius:6px;padding:6px 10px;font-size:12px;min-width:170px;outline:none}
.dt-toolbar input[type=search]:focus{border-color:var(--accent)}
.dt-toolbar select{background:var(--card2);border:1px solid var(--border);color:var(--text);
  border-radius:6px;padding:6px 8px;font-size:12px}
.dt-count{font-size:11px;color:var(--muted);margin-left:auto;white-space:nowrap}
.dt-pager{display:flex;gap:10px;align-items:center;margin:6px 0 18px;font-size:12px;color:var(--muted)}
.dt-pager button{background:var(--card2);border:1px solid var(--border);color:var(--text);
  border-radius:6px;padding:4px 12px;font-size:12px;cursor:pointer}
.dt-pager button:disabled{opacity:.35;cursor:default}
th.dt-th{cursor:pointer;user-select:none}
th.dt-th:hover{color:var(--text)}
th.dt-sort-asc::after{content:' \25B2';font-size:9px}
th.dt-sort-desc::after{content:' \25BC';font-size:9px}
tr.dt-group-row td{background:var(--card2);font-weight:600;color:var(--accent);cursor:pointer}
tr.dt-hidden{display:none}
.pos{color:var(--green)} .neu{color:var(--yellow)} .neg{color:var(--red)}
.badge-ok{color:var(--green)} .badge-err{color:var(--red)} .badge-warn{color:var(--yellow)}
.url{font-size:11px;color:var(--muted);word-break:break-all}
form.inline{display:inline}
button{cursor:pointer;border:none;border-radius:4px;padding:4px 10px;font-size:12px;font-weight:600}
.btn-ok{background:#16a34a;color:#fff} .btn-ok:hover{background:#15803d}
.btn-del{background:#b91c1c;color:#fff} .btn-del:hover{background:#991b1b}
.btn-out{background:var(--card);border:1px solid var(--border);color:var(--muted);padding:5px 12px;font-size:13px;border-radius:6px}
.btn-out:hover{color:var(--text)}
.top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap}
.top-actions{display:flex;gap:8px;align-items:center}
.mins-ok{color:var(--green)} .mins-warn{color:var(--yellow)} .mins-old{color:var(--red)}
a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
.empty{color:var(--muted);font-style:italic;padding:10px 0}
.note{font-size:12px;color:var(--muted);margin-top:6px}
.welcome-row{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin-bottom:12px}
.welcome-block{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px 18px;min-width:180px}
.welcome-block h3{font-size:12px;color:var(--muted);font-weight:600;margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em}
.loc-bar{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px}
.loc-bar-fill{height:8px;border-radius:4px;background:var(--accent);min-width:4px;transition:width .3s}
/* ICY chip preview */
.icy-chip{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:20px;border:1px solid var(--border);font-size:12px;background:var(--card2);white-space:nowrap}
.icy-chip.freq-hot{border-color:rgba(34,197,94,.45);color:var(--green)}
.icy-chip.freq-warm{border-color:rgba(59,130,246,.4);color:var(--accent)}
.icy-chip.freq-cool{color:var(--muted)}
.icy-chip .cn{font-size:10px;font-weight:700;opacity:.65}
/* Tabs */
.tab-bar{display:flex;flex-wrap:wrap;gap:2px;margin-bottom:20px;border-bottom:2px solid var(--border);padding-bottom:0}
.tab-btn{background:none;border:none;border-bottom:3px solid transparent;color:var(--muted);padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:-2px;transition:color .15s,border-color .15s;white-space:nowrap}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-badge{background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;margin-left:4px;font-weight:700}
.tab-badge.warn{background:var(--yellow);color:#000}
.tab-content{display:none}
.tab-content.active{display:block}
</style>
</head>
<body>
<script>
if(localStorage.getItem('radio_theme')==='light')document.body.classList.add('light');
</script>

<div class="top-bar">
  <h1>📻 Radio Argentina — Admin v4</h1>
  <div class="top-actions">
    <span id="refresh-ind" style="font-size:11px;color:var(--muted)"></span>
    <a href="admin_stats.php" class="btn-out">📊 Estadísticas</a>
    <button class="btn-out" id="theme-btn" onclick="toggleTheme()">☀️ Claro</button>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="logout">
      <button class="btn-out" type="submit">Cerrar sesión</button>
    </form>
  </div>
</div>
<script>
var themeBtn = document.getElementById('theme-btn');
function toggleTheme() {
  var light = document.body.classList.toggle('light');
  localStorage.setItem('radio_theme', light ? 'light' : 'dark');
  themeBtn.textContent = light ? '🌙 Oscuro' : '☀️ Claro';
}
if (document.body.classList.contains('light')) themeBtn.textContent = '🌙 Oscuro';
</script>

<!-- ── Tab bar ──────────────────────────────────────────────────────────────── -->
<div class="tab-bar" id="tab-bar">
  <button class="tab-btn" data-tab="resumen">Resumen</button>
  <button class="tab-btn" data-tab="emisoras">📻 Emisoras</button>
  <button class="tab-btn" data-tab="telegram">Telegram</button>
  <button class="tab-btn" data-tab="encuestas">Encuestas</button>
  <button class="tab-btn" data-tab="compartidos">Compartidos</button>
  <button class="tab-btn" data-tab="ayuda">🙏 Ayuda</button>
  <button class="tab-btn" data-tab="reproducciones">Reproducciones</button>
  <button class="tab-btn" data-tab="sugerencias">
    Sugerencias
    <?php if ($stats['suger_pend'] > 0): ?><span class="tab-badge"><?= $stats['suger_pend'] ?></span><?php endif; ?>
  </button>
  <button class="tab-btn" data-tab="problemas">
    Problemas
    <?php if ($stats['problemas'] > 0): ?><span class="tab-badge"><?= $stats['problemas'] ?></span><?php endif; ?>
  </button>
  <button class="tab-btn" data-tab="pendientes">
    Pendientes de verificación
    <?php if ($stats['pendientes_crawler'] > 0): ?><span class="tab-badge warn"><?= $stats['pendientes_crawler'] ?></span><?php endif; ?>
  </button>
  <button class="tab-btn" data-tab="seguimiento">
    Seguimiento
    <?php if (count($seguimiento) > 0): ?><span class="tab-badge warn"><?= count($seguimiento) ?></span><?php endif; ?>
  </button>
  <button class="tab-btn" data-tab="suscriptores">
    Suscriptores
    <?php if ($sub_stats['pendientes'] > 0): ?><span class="tab-badge warn"><?= $sub_stats['pendientes'] ?></span><?php endif; ?>
  </button>
  <button class="tab-btn" data-tab="icy">ICY</button>
  <button class="tab-btn" data-tab="crawlers">Crawlers</button>
</div>

<!-- ══ Tab: Resumen ══════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-resumen">
  <h2 id="resumen">Resumen</h2>
  <div class="cards">
    <div class="card"><div class="v" id="stat-total"><?= $stats['total'] ?></div><div class="l">Emisoras activas</div></div>
    <div class="card"><div class="v <?= $stats['de_baja'] > 0 ? 'neu' : '' ?>"><?= $stats['de_baja'] ?></div><div class="l">Emisoras de baja</div></div>
    <div class="card"><div class="v badge-ok" id="stat-ok"><?= $stats['ok'] ?></div><div class="l">Streams OK</div></div>
    <div class="card"><div class="v" id="stat-icy"><?= $stats['icy'] ?></div><div class="l">Con ICY</div></div>
    <div class="card"><div class="v pos" id="stat-icy-activo"><?= $stats['icy_activo'] ?></div><div class="l">ICY con título ahora</div></div>
    <div class="card"><div class="v" id="stat-plays-hoy"><?= $stats['plays_hoy'] ?></div><div class="l">Plays hoy</div></div>
    <div class="card"><div class="v" id="stat-plays-total"><?= $stats['plays_total'] ?></div><div class="l">Plays totales</div></div>
    <div class="card"><div class="v pos" id="stat-listeners"><?= $stats['listeners'] ?></div><div class="l">Oyentes ahora</div></div>
    <div class="card"><div class="v"><?= $stats['surveys'] ?></div><div class="l">Encuestas recibidas</div></div>
    <div class="card"><div class="v <?= $stats['suger_pend'] > 0 ? 'neg' : '' ?>"><?= $stats['suger_pend'] ?></div><div class="l">Sugerencias pendientes</div></div>
    <div class="card"><div class="v pos"><?= $sub_stats['activos'] ?></div><div class="l">Suscriptores activos</div></div>
    <div class="card"><div class="v <?= $sub_stats['pendientes'] > 0 ? 'neu' : '' ?>"><?= $sub_stats['pendientes'] ?></div><div class="l">Pendientes activación</div></div>
  </div>
</div>

<!-- ══ Tab: Emisoras (ABM completo) ══════════════════════════════════════════ -->
<div class="tab-content" id="tab-emisoras">
  <h2 id="emisoras">Emisoras — ABM (<?= count($emisoras_todas) ?>)</h2>
  <p style="color:var(--muted);font-size:12px;margin-bottom:12px">
    Catálogo completo. Las emisoras <strong>nunca se borran</strong> — "Dar de baja" solo las oculta
    del sitio público (deja de aparecer en el listado) y se puede reactivar en cualquier momento.
    "Último cambio" es la fecha del alta/baja más reciente, no de cualquier edición.
  </p>

  <details style="margin-bottom:16px">
    <summary style="cursor:pointer;color:var(--accent);font-weight:600">➕ Nueva emisora (alta manual)</summary>
    <?php if (!empty($alta_err)): ?><p class="empty" style="color:var(--red)"><?= h($alta_err) ?></p><?php endif; ?>
    <form method="post" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
      <input type="hidden" name="action" value="crear_emisora">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <div><label style="font-size:11px;color:var(--muted);display:block">Nombre *</label>
        <input type="text" name="nombre" required></div>
      <div><label style="font-size:11px;color:var(--muted);display:block">URL del stream *</label>
        <input type="text" name="url" required style="min-width:260px"></div>
      <div><label style="font-size:11px;color:var(--muted);display:block">Slug (opcional, se genera solo)</label>
        <input type="text" name="slug"></div>
      <div><label style="font-size:11px;color:var(--muted);display:block">Provincia</label>
        <input type="text" name="provincia"></div>
      <div><label style="font-size:11px;color:var(--muted);display:block">Homepage</label>
        <input type="text" name="homepage"></div>
      <button class="btn-ok" type="submit">Crear</button>
    </form>
  </details>

  <?php if ($emisoras_todas): ?>
  <table id="dt-emisoras" class="dt">
    <thead><tr>
      <th>Nombre</th><th data-nosort="1">URL</th><th data-group="Provincia">Provincia</th><th data-group="Alta/Baja">Estado</th>
      <th data-group="Salud del stream">Salud</th>
      <th>Alta</th><th>Último cambio</th><th data-nosort="1">Acción</th>
    </tr></thead>
    <tbody>
    <?php foreach ($emisoras_todas as $s): emisora_abm_row($s, $csrf); endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No hay emisoras cargadas.</p>
  <?php endif; ?>
</div>

<!-- ══ Tab: Telegram ═════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-telegram">
  <h2 id="telegram">Notificaciones Telegram</h2>
  <div style="display:flex;align-items:center;gap:16px;margin-bottom:8px">
    <span style="font-size:14px">
      Estado actual:
      <strong style="color:<?= $notify_val ? 'var(--green)' : 'var(--muted)' ?>">
        <?= $notify_val ? '● Activas' : '● Inactivas' ?>
      </strong>
      <span style="font-size:12px;color:var(--muted)">(oyentes nuevos + compartidos)</span>
    </span>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="toggle_notify">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button class="<?= $notify_val ? 'btn-del' : 'btn-ok' ?>" type="submit">
        <?= $notify_val ? '⏸ Desactivar' : '▶ Activar' ?>
      </button>
    </form>
  </div>
</div>

<!-- ══ Tab: Encuestas ════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-encuestas">
  <h2 id="encuestas">Encuestas</h2>
  <?php
  $w_total = array_sum($wrating);
  $loc_total = array_sum(array_column($welcome_loc, 'cnt'));
  ?>
  <div class="welcome-row">
    <div class="welcome-block">
      <h3>¿Qué te parece el sitio? (<?= $w_total ?>)</h3>
      <?php if ($w_total > 0): ?>
        <?php foreach ([1=>'👍 Me gusta', 0=>'😐 Regular', -1=>'👎 No me convence'] as $r => $lbl): ?>
        <div class="loc-bar">
          <span style="min-width:130px"><?= $lbl ?></span>
          <div class="loc-bar-fill" style="width:<?= $w_total > 0 ? round($wrating[$r]/$w_total*120) : 0 ?>px"></div>
          <span style="color:var(--muted)"><?= $wrating[$r] ?></span>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="empty" style="padding:6px 0">Sin respuestas aún.</p>
      <?php endif; ?>
    </div>

    <div class="welcome-block">
      <h3>¿Desde dónde escuchás? (<?= $loc_total ?>)</h3>
      <?php if ($welcome_loc): ?>
        <?php foreach ($welcome_loc as $loc): ?>
        <div class="loc-bar">
          <span style="min-width:130px"><?= ($loc_icons[$loc['location']] ?? '📍') . ' ' . h(ucfirst($loc['location'])) ?></span>
          <div class="loc-bar-fill" style="width:<?= $loc_total > 0 ? round($loc['cnt']/$loc_total*120) : 0 ?>px"></div>
          <span style="color:var(--muted)"><?= (int)$loc['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="empty" style="padding:6px 0">Sin respuestas aún.</p>
      <?php endif; ?>
    </div>

    <div class="welcome-block">
      <h3>Oyentes por provincia — geolocalizado (<?= $geo_total ?>, 30 días)</h3>
      <?php if ($geo_provincia): ?>
        <?php foreach ($geo_provincia as $gp): ?>
        <div class="loc-bar">
          <span style="min-width:130px">📍 <?= h($gp['provincia']) ?></span>
          <div class="loc-bar-fill" style="width:<?= $geo_total > 0 ? round($gp['cnt']/$geo_total*120) : 0 ?>px"></div>
          <span style="color:var(--muted)"><?= (int)$gp['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="empty" style="padding:6px 0">Sin datos aún — se completa a medida que llegan oyentes nuevos.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($station_surveys): ?>
  <table id="dt-encuestas-resumen" class="dt">
    <thead><tr>
      <th>Emisora</th><th>👍</th><th>😐</th><th>👎</th><th>Total</th><th>Última</th>
    </tr></thead>
    <tbody>
    <?php foreach ($station_surveys as $sv): ?>
    <tr>
      <td><a href="/radio/<?= h($sv['slug']) ?>/" target="_blank"><?= h($sv['nombre']) ?></a></td>
      <td class="pos"><?= $sv['pos'] ?></td>
      <td class="neu"><?= $sv['neu'] ?></td>
      <td class="neg"><?= $sv['neg'] ?></td>
      <td><?= $sv['total'] ?></td>
      <td style="color:var(--muted);font-size:12px"><?= ago($sv['ultima']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin encuestas de emisoras todavía.</p>
  <?php endif; ?>

  <h2 id="encuestas-detalle" style="margin-top:28px">Encuestas — detalle (últimas 100)</h2>
  <p class="note" style="margin-bottom:10px">IP hasheada: identificador anónimo consistente (misma IP = mismo hash).</p>
  <?php if ($surveys_detalle): ?>
  <table id="dt-encuestas-detalle" class="dt">
    <thead><tr>
      <th>Fecha</th><th data-group="Rating">Rating</th><th data-group="Emisora">Emisora</th><th>Ubicación</th><th title="Provincia geolocalizada por IP, para comparar contra la ubicación autoreportada" data-group="Provincia (geo)">Provincia (geo)</th><th data-nosort="1">IP hash</th>
    </tr></thead>
    <tbody>
    <?php foreach ($surveys_detalle as $sv):
      $rlbl = $sv['rating'] ==  1 ? '<span class="pos">👍</span>'
            : ($sv['rating'] == -1 ? '<span class="neg">👎</span>'
                                   : '<span class="neu">😐</span>');
    ?>
    <tr>
      <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($sv['created_at'],0,19))) ?></td>
      <td><?= $rlbl ?></td>
      <td><?php if ($sv['slug']): ?><a href="/radio/<?= h($sv['slug']) ?>/" target="_blank"><?= h($sv['nombre'] ?? '—') ?></a><?php else: ?><span style="color:var(--muted)">bienvenida</span><?php endif; ?></td>
      <td style="color:var(--muted)"><?= $sv['location'] ? h(ucfirst($sv['location'])) : '—' ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= $sv['provincia_geo'] ? '📍 ' . h($sv['provincia_geo']) : '—' ?></td>
      <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($sv['ip_hash'] ?? '', 0, 16)) ?>…</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin encuestas todavía.</p>
  <?php endif; ?>
</div>

<!-- ══ Tab: Compartidos ══════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-compartidos">
  <h2 id="shares">Compartidos recientes (últimas 100)</h2>
  <?php $ch_labels = ['copy' => '🔗 Link', 'wa' => '💬 WhatsApp', 'qr' => '⬛ QR', 'x' => '𝕏 X', 'tg' => '✈️ Telegram']; ?>
  <table id="dt-compartidos" class="dt">
    <thead><tr>
      <th>Fecha / Hora</th><th data-group="Emisora">Emisora</th><th data-group="Canal">Canal</th><th data-group="Provincia">Provincia</th><th data-nosort="1">IP hash</th>
    </tr></thead>
    <tbody id="shares-body">
    <?php if ($shares_recientes): foreach ($shares_recientes as $sh): ?>
    <tr>
      <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($sh['created_at'],0,19))) ?></td>
      <td><?php if ($sh['slug']): ?><a href="/radio/<?= h($sh['slug']) ?>/" target="_blank"><?= h($sh['nombre'] ?? $sh['slug']) ?></a><?php else: ?>—<?php endif; ?></td>
      <td><?= $ch_labels[$sh['channel']] ?? h($sh['channel']) ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= $sh['provincia'] ? '📍 ' . h($sh['provincia']) : '—' ?></td>
      <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($sh['ip_hash'] ?? '', 0, 16)) ?>…</td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="5" class="empty">Sin compartidos registrados todavía.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ══ Tab: Toast de ayuda ═══════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-ayuda">
  <h2>Toast de ayuda — quién lo vio y qué respondió</h2>
  <?php
    $ay_mostrado   = (int)($ayuda_stats['mostrado']    ?? 0);
    $ay_ok         = (int)($ayuda_stats['ok']          ?? 0);
    $ay_nomolestar = (int)($ayuda_stats['no_molestar'] ?? 0);
    $ay_cafecito   = (int)($ayuda_stats['cafecito']    ?? 0);
    $ay_contacto   = (int)($ayuda_stats['contacto']    ?? 0);
    $ay_respondio  = $ay_ok + $ay_nomolestar + $ay_cafecito + $ay_contacto;
    $ay_tasa       = $ay_mostrado > 0 ? round($ay_respondio / $ay_mostrado * 100, 1) : 0;
  ?>
  <div class="cards">
    <div class="card"><div class="v"><?= $ay_mostrado ?></div><div class="l">Veces mostrado</div></div>
    <div class="card"><div class="v pos">👍 <?= $ay_ok ?></div><div class="l">OK (7 días)</div></div>
    <div class="card"><div class="v pos">☕ <?= $ay_cafecito ?></div><div class="l">Cafecito</div></div>
    <div class="card"><div class="v">💬 <?= $ay_contacto ?></div><div class="l">Contacto (7 días)</div></div>
    <div class="card"><div class="v neg">🚫 <?= $ay_nomolestar ?></div><div class="l">No molestar más</div></div>
    <div class="card"><div class="v"><?= $ay_tasa ?>%</div><div class="l">Tasa de respuesta</div></div>
  </div>
  <h3 style="font-size:14px;margin:20px 0 8px">Eventos recientes (últimos 200)</h3>
  <?php $ay_labels = ['mostrado' => '👁 Mostrado', 'ok' => '👍 OK', 'no_molestar' => '🚫 No molestar', 'cafecito' => '☕ Cafecito', 'contacto' => '💬 Contacto']; ?>
  <table id="dt-ayuda" class="dt">
    <thead><tr><th>Fecha / Hora</th><th data-group="Tipo">Tipo</th><th data-group="Provincia">Provincia</th><th data-nosort="1">IP hash</th></tr></thead>
    <tbody>
    <?php if ($ayuda_eventos): foreach ($ayuda_eventos as $ev): ?>
    <tr>
      <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($ev['created_at'],0,19))) ?></td>
      <td><?= $ay_labels[$ev['tipo']] ?? h($ev['tipo']) ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= $ev['provincia'] ? '📍 ' . h($ev['provincia']) : '—' ?></td>
      <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($ev['ip_hash'] ?? '', 0, 16)) ?>…</td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="4" class="empty">Sin eventos registrados todavía.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ══ Tab: Reproducciones ═══════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-reproducciones">
  <h2 id="plays">Reproducciones recientes (últimas 200)</h2>
  <?php
  function fmt_duration(?int $secs): string {
      if ($secs === null) return '—';
      if ($secs < 60)   return $secs . 's';
      if ($secs < 3600) return floor($secs/60) . 'm ' . ($secs%60) . 's';
      return floor($secs/3600) . 'h ' . floor(($secs%3600)/60) . 'm';
  }
  ?>
  <table id="dt-reproducciones" class="dt">
    <thead><tr>
      <th>Fecha / Hora</th><th data-group="Emisora">Emisora</th><th data-group="Reproduciendo">Estado</th><th>Duración</th><th data-group="Origen">Origen</th><th title="🆕 Ocasional (1 día) · 🔁 Recurrente (2-3) · ⭐ Frecuente (4-7) · 💎 Núcleo fiel (8+)" data-nosort="1">Visitante</th><th title="Mismo IP saltando de emisora en poco tiempo — no bloquea, solo marca para no confundir con audiencia real" data-group="Tipo de oyente">🤖</th><th data-group="Provincia">Provincia</th><th data-nosort="1">IP hash</th><th data-nosort="1">Sesión</th>
    </tr></thead>
    <tbody id="plays-body">
    <?php if ($plays_recientes): foreach ($plays_recientes as $pl): ?>
    <tr>
      <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($pl['played_at'],0,19))) ?></td>
      <td><?php if ($pl['slug']): ?><a href="/radio/<?= h($pl['slug']) ?>/" target="_blank"><?= h($pl['nombre'] ?? '—') ?></a><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
      <td style="font-size:12px;white-space:nowrap">
        <?php if ($pl['is_active']): ?>
          <span style="color:#22c55e">▶ Reproduciendo</span>
        <?php else: ?>
          <span style="color:var(--muted)">Terminada</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;white-space:nowrap">
        <?php if ($pl['is_active']): ?>
          <span style="color:#22c55e"><?= fmt_duration((int)$pl['duration_secs']) ?></span>
        <?php else: ?>
          <?= fmt_duration(isset($pl['duration_secs']) ? (int)$pl['duration_secs'] : null) ?>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--muted)"><?= h($pl['source'] ?? '—') ?></td>
      <td style="font-size:14px;text-align:center"><?= visitor_badge($pl['dias_activos'] ?? null) ?></td>
      <td style="font-size:12px;text-align:center"><?= bot_badge($pl['hops_1h'] ?? 0) ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= $pl['provincia'] ? '📍 ' . h($pl['provincia']) : '—' ?></td>
      <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($pl['ip_hash'] ?? '', 0, 16)) ?>…</td>
      <td style="font-size:11px;color:var(--muted);font-family:monospace"><?= h(substr($pl['session_id'] ?? '', 0, 12)) ?>…</td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="10" class="empty" id="plays-empty">Sin reproducciones registradas todavía.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ══ Tab: Sugerencias ══════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-sugerencias">
  <h2 id="sugerencias">Sugerencias pendientes (<?= count($sugerencias) ?>)</h2>
  <?php if ($sugerencias): ?>
  <table id="dt-sugerencias" class="dt">
    <thead><tr>
      <th>Nombre</th><th data-nosort="1">URL</th><th data-group="Provincia">Provincia</th><th data-group="Origen">Origen</th><th>Contacto</th><th>Recibida</th><th data-nosort="1">Acción</th>
    </tr></thead>
    <tbody>
    <?php foreach ($sugerencias as $sg): ?>
    <tr>
      <td>
        <?= h($sg['nombre']) ?>
        <?php if ($sg['homepage']): ?>
          <br><a href="<?= h($sg['homepage']) ?>" target="_blank" rel="noopener" style="font-size:11px">homepage ↗</a>
        <?php endif; ?>
      </td>
      <td class="url"><a href="<?= h($sg['url']) ?>" target="_blank" rel="noopener"><?= h($sg['url']) ?></a></td>
      <td><?= h($sg['provincia'] ?? '—') ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= $sg['source'] === 'radio-browser' ? '🔍 Radio Browser' : '👤 Sugerida' ?></td>
      <td style="font-size:12px"><?= $sg['contacto'] ? h($sg['contacto']) : '<span style="color:var(--muted)">—</span>' ?></td>
      <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= ago($sg['created_at']) ?></td>
      <td style="white-space:nowrap">
        <form class="inline" method="post">
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?= (int)$sg['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button class="btn-ok" type="submit" onclick="return confirm('¿Aprobar <?= h(addslashes($sg['nombre'])) ?>?')">✓ Aprobar</button>
        </form>
        &nbsp;
        <form class="inline" method="post">
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="id" value="<?= (int)$sg['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button class="btn-del" type="submit" onclick="return confirm('¿Eliminar esta sugerencia?')">✕ Rechazar</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No hay sugerencias pendientes.</p>
  <?php endif; ?>
</div>

<!-- ══ Tab: Problemas ════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-problemas">
  <h2 id="problemas">Radios con problemas (<?= count($problemas) ?>)</h2>
  <p style="color:var(--muted);font-size:12px;margin-bottom:12px">Ocultas, con stream caído/timeout según el último chequeo, o reportadas por oyentes en los últimos 14 días.</p>
  <?php if ($problemas): ?>
  <table id="dt-problemas" class="dt">
    <thead><tr><th>Nombre</th><th data-nosort="1">URL</th><th data-group="Motivo">Motivo</th><th data-nosort="1">Acción</th></tr></thead>
    <tbody>
    <?php foreach ($problemas as $p):
      $motivos = [];
      if (!$p['approved']) $motivos[] = 'oculta';
      if ($p['estado'] === 'muerto') $motivos[] = 'stream caído';
      if ($p['estado'] === 'timeout') $motivos[] = 'timeout';
      if ($p['reportes_recientes'] > 0) $motivos[] = $p['reportes_recientes'] . ' reporte(s)';
      station_meta_row($p, $csrf, 'problemas', implode(', ', $motivos));
    endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin problemas detectados.</p>
  <?php endif; ?>
</div>

<!-- ══ Tab: Pendientes de verificación ═══════════════════════════════════════ -->
<div class="tab-content" id="tab-pendientes">
  <h2 id="pendientes">Pendientes de verificación (<?= count($pendientes_crawler) ?>)</h2>
  <p style="color:var(--muted);font-size:12px;margin-bottom:12px">Aprobadas, pero el crawler de streams (cada 6hs) todavía no las chequeó ni una vez.</p>
  <?php if ($pendientes_crawler): ?>
  <table id="dt-pendientes" class="dt">
    <thead><tr><th>Nombre</th><th data-nosort="1">URL</th><th>Agregada</th><th data-nosort="1">Acción</th></tr></thead>
    <tbody>
    <?php foreach ($pendientes_crawler as $p): station_meta_row($p, $csrf, 'pendientes', ago($p['created_at'])); endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No hay radios esperando el primer chequeo.</p>
  <?php endif; ?>
</div>

<!-- ══ Tab: Seguimiento especial ═════════════════════════════════════════════ -->
<div class="tab-content" id="tab-seguimiento">
  <h2 id="seguimiento">Seguimiento especial (<?= count($seguimiento) ?>)</h2>
  <p style="color:var(--muted);font-size:12px;margin-bottom:12px">En observación, o con un contacto privado cargado (radios que nos escribieron directamente, casos piloto, etc).</p>
  <?php if ($seguimiento): ?>
  <table id="dt-seguimiento" class="dt">
    <thead><tr><th>Nombre</th><th data-nosort="1">URL</th><th>Contacto privado</th><th data-nosort="1">Acción</th></tr></thead>
    <tbody>
    <?php foreach ($seguimiento as $s): station_meta_row($s, $csrf, 'seguimiento', h($s['contacto_privado'] ?? '')); endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin radios en seguimiento especial.</p>
  <?php endif; ?>
</div>

<!-- ══ Tab: Suscriptores ═════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-suscriptores">
  <h2 id="suscriptores">Suscriptores de alertas</h2>

  <div class="cards" style="margin-bottom:16px">
    <div class="card"><div class="v"><?= $sub_stats['total'] ?></div><div class="l">Total registrados</div></div>
    <div class="card"><div class="v pos"><?= $sub_stats['activos'] ?></div><div class="l">Activos</div></div>
    <div class="card"><div class="v <?= $sub_stats['pendientes'] > 0 ? 'neu' : '' ?>"><?= $sub_stats['pendientes'] ?></div><div class="l">Pendientes activación</div></div>
    <div class="card"><div class="v"><?= $sub_stats['tg'] ?></div><div class="l">📱 Telegram</div></div>
    <div class="card"><div class="v"><?= $sub_stats['email'] ?></div><div class="l">✉️ Email</div></div>
  </div>

  <?php
  function mask_contact(string $type, string $val): string {
      if ($type === 'email') {
          [$u, $d] = explode('@', $val, 2);
          return mb_substr($u, 0, 2) . str_repeat('*', max(2, mb_strlen($u)-2)) . '@' . $d;
      }
      return substr($val, 0, 3) . str_repeat('*', max(3, strlen($val)-5)) . substr($val, -2);
  }
  function fmt_prefs(string $json): string {
      $prefs = json_decode($json, true) ?: [];
      $out = [];
      foreach ($prefs as $p) {
          $icon = $p['type'] === 'genre' ? '🎵' : ($p['type'] === 'program' ? '📺' : '🎤');
          $out[] = $icon . ' ' . htmlspecialchars($p['value'], ENT_QUOTES, 'UTF-8');
      }
      return $out ? implode(' · ', $out) : '<span style="color:var(--muted)">—</span>';
  }
  $days_es = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
  ?>

  <?php if ($subscribers_list): ?>
  <table id="dt-suscriptores" class="dt">
    <thead><tr>
      <th>ID</th><th data-group="Tipo">Tipo</th><th data-nosort="1">Contacto</th><th data-nosort="1">Preferencias</th><th data-group="Estado">Estado</th><th>Última notif.</th><th>Registrado</th><th data-nosort="1">Acciones</th>
    </tr></thead>
    <tbody>
    <?php foreach ($subscribers_list as $sub):
      $active = (int)$sub['active'];
    ?>
    <tr>
      <td style="color:var(--muted);font-size:12px">#<?= $sub['id'] ?></td>
      <td><?= $sub['contact_type'] === 'telegram' ? '📱 TG' : '✉️ Mail' ?></td>
      <td style="font-family:monospace;font-size:12px"><?= h(mask_contact($sub['contact_type'], $sub['contact_value'])) ?></td>
      <td style="font-size:12px;max-width:280px"><?= fmt_prefs($sub['preferences'] ?? '[]') ?></td>
      <td>
        <?php if ($active): ?>
          <span class="badge-ok">● Activo</span>
        <?php else: ?>
          <span style="color:var(--yellow)">⏳ Pendiente</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--muted)"><?= ago($sub['last_notified']) ?></td>
      <td style="font-size:12px;color:var(--muted);white-space:nowrap"><?= ago($sub['created_at']) ?></td>
      <td style="white-space:nowrap">
        <?php if (!$active): ?>
        <form class="inline" method="post">
          <input type="hidden" name="action" value="sub_activate">
          <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button class="btn-ok" type="submit" title="Activar">✓</button>
        </form>
        <?php else: ?>
        <form class="inline" method="post">
          <input type="hidden" name="action" value="sub_deactivate">
          <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button class="btn-out" type="submit" title="Pausar">⏸</button>
        </form>
        <?php endif; ?>
        &nbsp;
        <form class="inline" method="post">
          <input type="hidden" name="action" value="sub_delete">
          <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button class="btn-del" type="submit" onclick="return confirm('¿Eliminar suscriptor #<?= $sub['id'] ?>?')" title="Eliminar">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">
    Sin suscriptores todavía. El form público está en
    <a href="/radio/suscribirse.php" target="_blank">/radio/suscribirse.php</a>.
  </p>
  <?php endif; ?>

  <h2 id="notificaciones" style="margin-top:28px">Notificaciones enviadas (últimas 30)</h2>
  <?php if ($notif_recientes): ?>
  <table id="dt-notificaciones" class="dt">
    <thead><tr>
      <th>Fecha</th><th data-nosort="1">Suscriptor</th><th>Keyword detectado</th><th data-group="Emisora">Emisora</th><th>Matches</th>
    </tr></thead>
    <tbody>
    <?php foreach ($notif_recientes as $n): ?>
    <tr>
      <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h(str_replace('T',' ',substr($n['first_seen'],0,16))) ?></td>
      <td style="font-size:12px"><?= $n['contact_type']==='telegram' ? '📱' : '✉️' ?> <?= h(mask_contact($n['contact_type'], $n['contact_value'])) ?></td>
      <td><strong><?= h($n['keyword']) ?></strong></td>
      <td><?= h($n['station'] ?? '—') ?></td>
      <td style="color:var(--green)"><?= (int)$n['match_count'] ?> temas</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin notificaciones enviadas todavía. El cron de alertas debe estar corriendo.</p>
  <?php endif; ?>

  <h2 id="patrones" style="margin-top:28px">Patrones de programas detectados</h2>
  <?php if ($program_patterns): ?>
  <table id="dt-patrones" class="dt">
    <thead><tr>
      <th>Programa / Keyword</th><th data-group="Emisora">Emisora</th><th data-group="Día">Día</th><th>Hora (ARG)</th><th>Confianza</th><th>Ocurrencias</th><th>Último visto</th>
    </tr></thead>
    <tbody>
    <?php foreach ($program_patterns as $pp):
      $conf_color = $pp['confidence'] >= 0.7 ? 'var(--green)' : ($pp['confidence'] >= 0.4 ? 'var(--yellow)' : 'var(--muted)');
    ?>
    <tr>
      <td><strong><?= h($pp['keyword']) ?></strong></td>
      <td style="font-size:12px"><?= h($pp['nombre']) ?></td>
      <td style="font-size:12px"><?= $pp['day_of_week'] !== null ? $days_es[(int)$pp['day_of_week']] : 'Todos' ?></td>
      <td><?= $pp['hour'] !== null ? sprintf('%02d:00', (int)$pp['hour']) : '—' ?></td>
      <td style="color:<?= $conf_color ?>;font-weight:600"><?= round($pp['confidence'] * 100) ?>%</td>
      <td style="color:var(--muted)"><?= (int)$pp['occurrences'] ?>x</td>
      <td style="font-size:12px;color:var(--muted)"><?= h($pp['last_seen'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin patrones todavía. Se aprenden automáticamente con el cron semanal (<code>learn_patterns.php</code>) una vez que haya suficiente historial ICY.</p>
  <?php endif; ?>
</div>

<!-- ══ Tab: ICY ═══════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-icy">
  <h2 id="icy">ICY — títulos en tiempo real</h2>
  <p class="note" style="margin-bottom:10px">
    <?= $icy['total'] ?> emisoras con soporte ICY &nbsp;·&nbsp;
    <?= $icy['con_titulo'] ?> con título activo &nbsp;·&nbsp;
    Última actualización: <strong><?= ago($icy['ultima'] ?? null) ?></strong>
  </p>
  <?php if ($icy_activas): ?>
  <table id="dt-icy" class="dt">
    <thead><tr><th>Emisora</th><th data-nosort="1">Sonando ahora</th><th>Actualizado</th></tr></thead>
    <tbody>
    <?php foreach ($icy_activas as $ic):
      $mins = (int)($ic['mins_ago'] ?? 0);
      $cls  = $mins <= 15 ? 'mins-ok' : ($mins <= 60 ? 'mins-warn' : 'mins-old');
    ?>
    <tr>
      <td><a href="/radio/<?= h($ic['slug']) ?>/" target="_blank"><?= h($ic['nombre']) ?></a></td>
      <td><?= h($ic['stream_title']) ?></td>
      <td class="<?= $cls ?>" style="font-size:12px;white-space:nowrap"><?= ago($ic['last_checked']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin títulos ICY activos.</p>
  <?php endif; ?>

  <?php if ($preview_artistas): ?>
  <h2 style="margin-top:28px;font-size:14px">Artistas detectados — preview de chips</h2>
  <p class="note" style="margin-bottom:12px">
    Así aparecen en el formulario de alertas.
    <span style="color:var(--green)">●</span> muy frecuentes &nbsp;
    <span style="color:var(--accent)">●</span> frecuentes &nbsp;
    <span style="color:var(--muted)">●</span> ocasionales
  </p>
  <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:8px">
    <?php foreach ($preview_artistas as $a): ?>
    <span class="icy-chip freq-<?= $a['tier'] ?>" title="<?= $a['n'] ?> apariciones en el historial">
      <?= h($a['name']) ?> <span class="cn"><?= $a['n'] ?></span>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ Tab: Crawlers ══════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-crawlers">
  <h2 id="crawlers">Crawlers — últimas ejecuciones</h2>
  <?php if ($crawler_runs): ?>
  <table id="dt-crawlers" class="dt">
    <thead><tr>
      <th data-group="Crawler">Crawler</th><th>Inicio</th><th>Duración</th>
      <th>Chequeadas</th><th>Cambios</th><th>Errores</th><th>Notas</th>
    </tr></thead>
    <tbody>
    <?php foreach ($crawler_runs as $cr): ?>
    <tr>
      <td><strong><?= h($cr['crawler']) ?></strong></td>
      <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= ago($cr['started_at']) ?></td>
      <td style="white-space:nowrap">
        <?php if ($cr['secs'] !== null):
          $s = (int)$cr['secs'];
          echo $s >= 60 ? floor($s/60).'min '.($s%60).'s' : $s.'s';
        else: echo '—'; endif; ?>
      </td>
      <td><?= $cr['stations_checked'] ?: '—' ?></td>
      <td class="<?= $cr['changes_detected'] > 0 ? 'pos' : '' ?>"><?= $cr['changes_detected'] ?: '—' ?></td>
      <td style="color:var(--muted)"><?= $cr['errors'] ?: '—' ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= h($cr['notes'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">Sin ejecuciones registradas todavía.</p>
  <?php endif; ?>
  <p class="note" style="margin-top:8px">
    "Sin título" = emisoras que en ese momento no devolvieron StreamTitle (offline, silencio, ICY vacío). No son errores del cron.
  </p>
</div>

<p style="margin-top:32px;font-size:11px;color:var(--border);text-align:center">
  Radio Argentina Admin · <?= gmdate('Y-m-d H:i') ?> UTC
</p>

<script>
// ── DT: filtro + orden por cabecera + paginado + agrupado, genérico para
// cualquier <table class="dt"> del panel. Opera sobre las filas ya
// renderizadas server-side (no pega a la API de nuevo) — las tablas de acá
// son chicas (30-200 filas), así que hacerlo client-side alcanza y sobra.
//
// Marcar un <th> con data-group="Etiqueta" lo agrega como opción de
// "Agrupar por". Marcar un <th> con data-nosort="1" (columnas de Acción,
// IP hash, etc.) evita que se pueda ordenar por esa columna.
//
// DT.refresh(table) se usa desde refreshAdmin() para las 2 tablas que se
// actualizan por AJAX cada 10s (Reproducciones, Compartidos) — recaptura
// las filas nuevas que puso el fetch y vuelve a aplicar filtro/orden/página.
window.DT = (function () {
  function textOf(el) { return (el.textContent || '').trim(); }

  function sortVal(td) {
    var t = textOf(td);
    var digits = t.replace(/[^\d.\-]/g, '');
    var num = (digits === '' || digits === '-') ? NaN : parseFloat(digits);
    return { text: t.toLowerCase(), num: isNaN(num) ? null : num };
  }

  function cmpVal(a, b) {
    if (a.num !== null && b.num !== null) return a.num - b.num;
    return a.text.localeCompare(b.text, 'es');
  }

  function dataRows(tbody, ncols) {
    return Array.prototype.slice.call(tbody.children).filter(function (tr) {
      return tr.tagName === 'TR' && tr.cells.length === ncols;
    });
  }

  function enhance(table) {
    if (!table || table.dataset.dtReady) return;
    var thead = table.querySelector('thead');
    var tbody = table.querySelector('tbody');
    if (!thead || !tbody) return;
    var ths = Array.prototype.slice.call(thead.querySelectorAll('th'));
    var ncols = ths.length;
    var masterRows = dataRows(tbody, ncols);
    if (!masterRows.length) return; // tabla vacía (placeholder "Sin datos") — no agregar controles

    var groupCols = [];
    ths.forEach(function (th, i) { if (th.dataset.group) groupCols.push(i); });

    var toolbar = document.createElement('div');
    toolbar.className = 'dt-toolbar';

    var filterInput = document.createElement('input');
    filterInput.type = 'search';
    filterInput.placeholder = '🔎 Filtrar…';
    toolbar.appendChild(filterInput);

    var groupSelect = null;
    if (groupCols.length) {
      groupSelect = document.createElement('select');
      var o0 = document.createElement('option'); o0.value = ''; o0.textContent = 'Sin agrupar';
      groupSelect.appendChild(o0);
      groupCols.forEach(function (i) {
        var o = document.createElement('option');
        o.value = i;
        o.textContent = 'Agrupar: ' + th_label(ths[i]);
        groupSelect.appendChild(o);
      });
      toolbar.appendChild(groupSelect);
    }

    var sizeSelect = document.createElement('select');
    [10, 25, 50, 100].forEach(function (n) {
      var o = document.createElement('option'); o.value = String(n); o.textContent = n + '/pág';
      sizeSelect.appendChild(o);
    });
    var oAll = document.createElement('option'); oAll.value = 'all'; oAll.textContent = 'Todo';
    sizeSelect.appendChild(oAll);
    sizeSelect.value = masterRows.length > 25 ? '25' : 'all';
    toolbar.appendChild(sizeSelect);

    var countEl = document.createElement('span');
    countEl.className = 'dt-count';
    toolbar.appendChild(countEl);

    table.parentNode.insertBefore(toolbar, table);

    var pager = document.createElement('div');
    pager.className = 'dt-pager';
    var btnPrev = document.createElement('button'); btnPrev.type = 'button'; btnPrev.textContent = '‹ Anterior';
    var pageLabel = document.createElement('span');
    var btnNext = document.createElement('button'); btnNext.type = 'button'; btnNext.textContent = 'Siguiente ›';
    pager.appendChild(btnPrev); pager.appendChild(pageLabel); pager.appendChild(btnNext);
    table.parentNode.insertBefore(pager, table.nextSibling);

    function th_label(th) { return (th.dataset.group || textOf(th)); }

    var st = { sortCol: -1, sortDir: 1, page: 1, filter: '', group: '' };

    ths.forEach(function (th, i) {
      if (th.dataset.nosort) return;
      th.classList.add('dt-th');
      th.addEventListener('click', function () {
        if (st.sortCol === i) st.sortDir *= -1; else { st.sortCol = i; st.sortDir = 1; }
        st.page = 1;
        render();
      });
    });

    filterInput.addEventListener('input', function () {
      st.filter = filterInput.value.toLowerCase();
      st.page = 1;
      render();
    });
    if (groupSelect) {
      groupSelect.addEventListener('change', function () {
        st.group = groupSelect.value;
        st.page = 1;
        render();
      });
    }
    sizeSelect.addEventListener('change', function () { st.page = 1; render(); });
    btnPrev.addEventListener('click', function () { if (st.page > 1) { st.page--; render(); } });
    btnNext.addEventListener('click', function () { st.page++; render(); });

    function render() {
      // limpiar cabeceras de grupo de un render anterior y desenganchar todas
      // las filas de datos (siguen vivas en masterRows, solo se sacan del DOM)
      Array.prototype.slice.call(tbody.querySelectorAll('.dt-group-row, .dt-empty-row')).forEach(function (r) { r.remove(); });
      masterRows.forEach(function (tr) { if (tr.parentNode === tbody) tbody.removeChild(tr); });

      var visible = masterRows;
      if (st.filter) {
        visible = visible.filter(function (tr) { return textOf(tr).toLowerCase().indexOf(st.filter) !== -1; });
      }

      ths.forEach(function (th, i) {
        th.classList.remove('dt-sort-asc', 'dt-sort-desc');
        if (i === st.sortCol) th.classList.add(st.sortDir === 1 ? 'dt-sort-asc' : 'dt-sort-desc');
      });
      if (st.sortCol >= 0) {
        visible = visible.slice().sort(function (a, b) {
          return cmpVal(sortVal(a.cells[st.sortCol]), sortVal(b.cells[st.sortCol])) * st.sortDir;
        });
      }

      countEl.textContent = visible.length + ' de ' + masterRows.length;

      if (st.group !== '') {
        pager.style.display = 'none';
        var gi = parseInt(st.group, 10);
        var groups = {}, order = [];
        visible.forEach(function (tr) {
          var key = textOf(tr.cells[gi]) || '—';
          if (!groups[key]) { groups[key] = []; order.push(key); }
          groups[key].push(tr);
        });
        order.sort(function (a, b) { return a.localeCompare(b, 'es'); });
        order.forEach(function (key) {
          var gr = document.createElement('tr');
          gr.className = 'dt-group-row';
          var td = document.createElement('td');
          td.colSpan = ncols;
          function setLabel() { td.textContent = (gr.classList.contains('collapsed') ? '▸ ' : '▾ ') + key + ' (' + groups[key].length + ')'; }
          setLabel();
          gr.appendChild(td);
          gr.addEventListener('click', function () {
            gr.classList.toggle('collapsed');
            groups[key].forEach(function (tr) { tr.classList.toggle('dt-hidden', gr.classList.contains('collapsed')); });
            setLabel();
          });
          tbody.appendChild(gr);
          groups[key].forEach(function (tr) { tbody.appendChild(tr); });
        });
      } else {
        pager.style.display = '';
        var pageSize = sizeSelect.value === 'all' ? visible.length : parseInt(sizeSelect.value, 10);
        var totalPages = pageSize > 0 ? Math.max(1, Math.ceil(visible.length / pageSize)) : 1;
        if (st.page > totalPages) st.page = totalPages;
        var start = (st.page - 1) * pageSize;
        var pageRows = pageSize > 0 ? visible.slice(start, start + pageSize) : visible;
        pageRows.forEach(function (tr) { tbody.appendChild(tr); });
        pageLabel.textContent = 'Página ' + st.page + ' de ' + totalPages;
        btnPrev.disabled = st.page <= 1;
        btnNext.disabled = st.page >= totalPages;
      }

      if (!visible.length) {
        var er = document.createElement('tr');
        er.className = 'dt-empty-row';
        var etd = document.createElement('td');
        etd.colSpan = ncols;
        etd.className = 'empty';
        etd.textContent = 'Sin resultados para este filtro.';
        er.appendChild(etd);
        tbody.appendChild(er);
      }
    }

    function recapture() {
      masterRows = dataRows(tbody, ncols);
      st.page = 1;
      render();
    }

    table.dataset.dtReady = '1';
    table._dtRecapture = recapture;
    render();
  }

  function refresh(table) {
    if (!table) return;
    if (!table.dataset.dtReady) { enhance(table); return; }
    if (table._dtRecapture) table._dtRecapture();
  }

  function enhanceAll() {
    document.querySelectorAll('table.dt').forEach(enhance);
  }

  document.addEventListener('DOMContentLoaded', enhanceAll);

  return { enhance: enhance, refresh: refresh, enhanceAll: enhanceAll };
}());
</script>
<script>
(function () {
  // ── Tab navigation ──────────────────────────────────────────────────────────
  var HASH_MAP = {
    'resumen': 'resumen',
    'telegram': 'telegram',
    'encuestas': 'encuestas',
    'encuestas-detalle': 'encuestas',
    'shares': 'compartidos',
    'plays': 'reproducciones',
    'sugerencias': 'sugerencias',
    'suscriptores': 'suscriptores',
    'notificaciones': 'suscriptores',
    'patrones': 'suscriptores',
    'icy': 'icy',
    'crawlers': 'crawlers'
  };

  function showTab(id) {
    document.querySelectorAll('.tab-content').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(btn) { btn.classList.remove('active'); });
    var content = document.getElementById('tab-' + id);
    if (content) content.classList.add('active');
    var btn = document.querySelector('.tab-btn[data-tab="' + id + '"]');
    if (btn) btn.classList.add('active');
    localStorage.setItem('radio_admin_tab', id);
  }

  var hash = (location.hash || '').replace('#', '');
  var initialTab = HASH_MAP[hash] || localStorage.getItem('radio_admin_tab') || 'resumen';
  showTab(initialTab);

  document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      showTab(btn.dataset.tab);
      history.replaceState(null, '', location.pathname);
    });
  });

  // ── Auto-refresh ────────────────────────────────────────────────────────────
  function esc(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function fmtDur(s) {
    if (s == null) return '—';
    s = parseInt(s, 10);
    if (s < 60)   return s + 's';
    if (s < 3600) return Math.floor(s/60) + 'm ' + (s%60) + 's';
    return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
  }

  function visitorBadge(dias) {
    if (dias == null) return '';
    dias = parseInt(dias, 10);
    if (dias >= 8) return '<span title="Núcleo fiel (8+ días)">💎</span>';
    if (dias >= 4) return '<span title="Frecuente (4-7 días)">⭐</span>';
    if (dias >= 2) return '<span title="Recurrente (2-3 días)">🔁</span>';
    return '<span title="Ocasional (1 día)" style="opacity:.5">🆕</span>';
  }

  function botBadge(hops) {
    hops = parseInt(hops, 10) || 0;
    if (hops >= 12) return '<span title="' + hops + ' emisoras distintas en 1h — muy probable bot/script" style="color:var(--danger,#c0392b)">🤖 Bot</span>';
    if (hops >= 6)  return '<span title="' + hops + ' emisoras distintas en 1h — station-hopping, revisar">🤔 Probable bot</span>';
    return '<span style="color:var(--muted)">Persona</span>';
  }

  var CH = {copy: '🔗 Link', wa: '💬 WhatsApp', qr: '⬛ QR', x: '𝕏 X', tg: '✈️ Telegram'};

  function upd(id, val) {
    var el = document.getElementById(id);
    if (el && val !== undefined) el.textContent = val;
  }

  function refreshAdmin() {
    fetch(location.pathname + '?ajax=1&_=' + Date.now())
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;

        // Stats
        var s = d.stats || {};
        upd('stat-total',      s.total);
        upd('stat-ok',         s.ok);
        upd('stat-icy',        s.icy);
        upd('stat-icy-activo', s.icy_activo);
        upd('stat-plays-hoy',  s.plays_hoy);
        upd('stat-plays-total',s.plays_total);
        upd('stat-listeners',  s.listeners);

        // Plays
        var pb = document.getElementById('plays-body');
        if (pb && d.plays) {
          pb.innerHTML = d.plays.map(function (p) {
            var estado = p.is_active
              ? '<span style="color:#22c55e">▶ Reproduciendo</span>'
              : '<span style="color:var(--muted)">Terminada</span>';
            var dur = p.is_active
              ? '<span style="color:#22c55e">' + esc(fmtDur(p.duration_secs)) + '</span>'
              : esc(fmtDur(p.duration_secs != null ? p.duration_secs : null));
            var nom = p.slug
              ? '<a href="/radio/' + esc(p.slug) + '/" target="_blank">' + esc(p.nombre || '—') + '</a>'
              : '<span style="color:var(--muted)">—</span>';
            var dt = (p.played_at || '').replace('T', ' ').substring(0, 19);
            return '<tr>'
              + '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' + esc(dt) + '</td>'
              + '<td>' + nom + '</td>'
              + '<td style="font-size:12px;white-space:nowrap">' + estado + '</td>'
              + '<td style="font-size:12px;white-space:nowrap">' + dur + '</td>'
              + '<td style="font-size:12px;color:var(--muted)">' + esc(p.source || '—') + '</td>'
              + '<td style="font-size:14px;text-align:center">' + visitorBadge(p.dias_activos) + '</td>'
              + '<td style="font-size:12px;text-align:center">' + botBadge(p.hops_1h) + '</td>'
              + '<td style="font-size:12px;color:var(--muted)">' + (p.provincia ? '📍 ' + esc(p.provincia) : '—') + '</td>'
              + '<td style="font-size:11px;color:var(--muted);font-family:monospace">' + esc((p.ip_hash || '').substring(0,16)) + '…</td>'
              + '<td style="font-size:11px;color:var(--muted);font-family:monospace">' + esc((p.session_id || '').substring(0,12)) + '…</td>'
              + '</tr>';
          }).join('');
          DT.refresh(document.getElementById('dt-reproducciones'));
        }

        // Shares
        var sb = document.getElementById('shares-body');
        if (sb && d.shares) {
          sb.innerHTML = d.shares.map(function (sh) {
            var nom = sh.slug
              ? '<a href="/radio/' + esc(sh.slug) + '/" target="_blank">' + esc(sh.nombre || sh.slug) + '</a>'
              : '—';
            var dt = (sh.created_at || '').replace('T', ' ').substring(0, 19);
            return '<tr>'
              + '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' + esc(dt) + '</td>'
              + '<td>' + nom + '</td>'
              + '<td>' + esc(CH[sh.channel] || sh.channel) + '</td>'
              + '<td style="font-size:12px;color:var(--muted)">' + (sh.provincia ? '📍 ' + esc(sh.provincia) : '—') + '</td>'
              + '<td style="font-size:11px;color:var(--muted);font-family:monospace">' + esc((sh.ip_hash || '').substring(0,16)) + '…</td>'
              + '</tr>';
          }).join('');
          DT.refresh(document.getElementById('dt-compartidos'));
        }

        // Indicador
        var ind = document.getElementById('refresh-ind');
        if (ind) ind.textContent = '↻ ' + new Date().toLocaleTimeString('es-AR');
      })
      .catch(function () {});
  }

  setInterval(refreshAdmin, 10000);
}());
</script>
</body>
</html>
<?php

// ── Login page ────────────────────────────────────────────────────────────────

function login_page(bool $err): void {
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin — Radio Argentina</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f1f5f9;color:#1e293b;font:14px/1.5 system-ui,sans-serif;
     display:flex;align-items:center;justify-content:center;min-height:100vh}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;width:320px;
     box-shadow:0 4px 24px rgba(0,0,0,.07)}
h1{font-size:18px;margin-bottom:20px;text-align:center}
label{display:block;font-size:12px;color:#64748b;margin-bottom:4px}
input{width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;
      padding:9px 12px;color:#1e293b;font-size:14px;margin-bottom:14px;outline:none}
input:focus{border-color:#3b82f6;background:#fff}
button{width:100%;background:#3b82f6;color:#fff;border:none;border-radius:6px;
       padding:10px;font-size:14px;font-weight:600;cursor:pointer;margin-top:4px}
button:hover{background:#2563eb}
.err{color:#dc2626;font-size:13px;text-align:center;margin-bottom:12px}
</style>
</head>
<body>
<div class="box">
  <h1>📻 Admin</h1>
  <?php if ($err): ?><p class="err">Usuario o contraseña incorrectos</p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <label>Usuario</label>
    <input type="text" name="u" autocomplete="username" autofocus>
    <label>Contraseña</label>
    <input type="password" name="p" autocomplete="current-password">
    <button type="submit">Entrar</button>
  </form>
</div>
</body>
</html>
<?php
}
