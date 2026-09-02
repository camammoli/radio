<?php
/**
 * ayuda_toast.php — Registra un evento del toast de ayuda/sostener el proyecto.
 *
 * GET /api/ayuda_toast?tipo=mostrado|ok|no_molestar|cafecito|contacto
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET');

$tipos_validos = ['mostrado', 'ok', 'no_molestar', 'cafecito', 'contacto'];
$tipo = str_param('tipo', 20);
if (!in_array($tipo, $tipos_validos, true)) api_error('tipo inválido');

$db = radio_db();

sqlite_lazy_migration($db, fn($db) => $db->exec("CREATE TABLE IF NOT EXISTS ayuda_toast_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL,
    ip_hash TEXT,
    provincia TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)"));

$ip = client_ip();
$db->prepare('INSERT INTO ayuda_toast_eventos (tipo, ip_hash, provincia) VALUES (?, ?, ?)')
   ->execute([$tipo, ip_hash($ip), geo_provincia($db, $ip)]);

api_response(['ok' => true]);
