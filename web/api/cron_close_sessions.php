<?php
/**
 * cron_close_sessions.php — Cierre proactivo de sesiones huérfanas.
 *
 * listeners.php ya limpia sesiones vencidas, pero solo cuando alguien pega
 * (ping/count/stop) — en tráfico bajo (madrugada) pueden quedar "reproduciendo"
 * colgadas hasta la próxima visita. Este endpoint dispara la misma limpieza
 * bajo demanda, pensado para un cron externo (GitHub Actions) cada 15 min.
 *
 * GET /api/cron_close_sessions.php?key=RADIO_ADMIN_KEY
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('GET');

if (!defined('RADIO_ADMIN_KEY') || str_param('key', 100) !== RADIO_ADMIN_KEY) {
    api_error('No autorizado', 403);
}

$db = radio_db();
api_response(cerrar_sesiones_expiradas($db));
