<?php
/**
 * mp_webhook.php — Recibe la notificación de Mercado Pago cuando un pago
 * cambia de estado, confirma el detalle real contra la API de MP (el aviso
 * en sí solo trae un ID) y actualiza el registro en `donaciones`.
 *
 * GET/POST /api/mp_webhook.php?secret=...&type=payment&data.id=XXXX
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

if (!defined('MP_ACCESS_TOKEN'))   define('MP_ACCESS_TOKEN', '');
if (!defined('MP_WEBHOOK_SECRET')) define('MP_WEBHOOK_SECRET', '');
if (!defined('NOTIFY_OYENTES'))    define('NOTIFY_OYENTES', false);
if (!defined('TG_TOKEN'))          define('TG_TOKEN', '');
if (!defined('TG_CHAT_ID'))        define('TG_CHAT_ID', '');

// MP reintenta igual aunque devolvamos error, así que validamos el secreto
// antes de cualquier otra cosa — cualquiera que adivine la URL sin el
// secreto no puede forzarnos a consultar/actualizar nada.
if (!MP_WEBHOOK_SECRET || ($_GET['secret'] ?? '') !== MP_WEBHOOK_SECRET) {
    http_response_code(403);
    exit;
}

// Formato actual: ?type=payment&data.id=XXXX · Formato IPN viejo: ?topic=payment&id=XXXX
$tipo = $_GET['type'] ?? $_GET['topic'] ?? '';
$paymentId = $_GET['data.id'] ?? $_GET['id'] ?? '';

if ($tipo !== 'payment' || !$paymentId || !MP_ACCESS_TOKEN) {
    http_response_code(200); // confirmamos recepción igual, para que MP no reintente sin sentido
    exit;
}

$ch = curl_init('https://api.mercadopago.com/v1/payments/' . urlencode($paymentId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . MP_ACCESS_TOKEN],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$resp || $code >= 400) { http_response_code(200); exit; }

$pago = json_decode($resp, true);
$estadoMp = $pago['status'] ?? null; // approved | rejected | pending | ...
$externalRef = $pago['external_reference'] ?? null; // nuestro id de `donaciones`
$montoReal = $pago['transaction_amount'] ?? null;

if (!$estadoMp || !$externalRef) { http_response_code(200); exit; }

$db = radio_db();
sqlite_lazy_migration($db, fn($db) => $db->exec("CREATE TABLE IF NOT EXISTS donaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monto REAL NOT NULL,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    mp_preference_id TEXT,
    mp_payment_id TEXT,
    ip_hash TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TEXT
)"));

$estadoLocal = $estadoMp === 'approved' ? 'aprobado' : ($estadoMp === 'rejected' ? 'rechazado' : 'pendiente');

$r = $db->prepare('SELECT id, estado FROM donaciones WHERE id = ?');
$r->execute([(int)$externalRef]);
$donacion = $r->fetch();

if (!$donacion) { http_response_code(200); exit; }

// Evitar doble notificación si MP reenvía el mismo webhook (pasa seguido).
$yaNotificado = $donacion['estado'] === 'aprobado';

$stmt = $db->prepare('UPDATE donaciones SET estado = ?, mp_payment_id = ?,
    confirmed_at = CASE WHEN ? = ? THEN CURRENT_TIMESTAMP ELSE confirmed_at END
    WHERE id = ?');
$stmt->execute([$estadoLocal, $paymentId, $estadoLocal, 'aprobado', $donacion['id']]);

if ($estadoLocal === 'aprobado' && !$yaNotificado && TG_TOKEN && TG_CHAT_ID) {
    $monto = $montoReal ?? 0;
    $text = "☕ Nueva colaboración vía Mercado Pago: ARS $" . number_format((float)$monto, 0, ',', '.');
    $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_POSTFIELDS     => ['chat_id' => TG_CHAT_ID, 'text' => $text],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

http_response_code(200);
