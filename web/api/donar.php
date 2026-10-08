<?php
/**
 * donar.php — Crea una preferencia de pago de Mercado Pago para una donación
 * directa (sin pasar por Cafecito) y la registra como "pendiente".
 *
 * POST /api/donar.php  { monto: number }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

api_method('POST');

if (!defined('MP_ACCESS_TOKEN')) define('MP_ACCESS_TOKEN', '');
if (!MP_ACCESS_TOKEN) api_error('Donación no disponible por el momento', 503);

$body  = json_body();
$monto = (float)($body['monto'] ?? 0);

// Montos sugeridos en el toast + "otro monto" libre, con un rango razonable
// para evitar valores absurdos (cero, negativos, o un número gigante tipeado
// por error) sin limitar a nadie que quiera aportar más de $1000.
if ($monto < 50 || $monto > 200000) api_error('Monto inválido', 400);

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

$ip = client_ip();

// Insertamos ANTES de llamar a Mercado Pago — así, aunque el visitante nunca
// vuelva, ya sabemos que llegó hasta acá (esto es justamente lo que hoy no
// podemos medir con Cafecito: cuánta gente entra al checkout real).
$db->prepare('INSERT INTO donaciones (monto, estado, ip_hash) VALUES (?, ?, ?)')
   ->execute([$monto, 'pendiente', ip_hash($ip)]);
$donacionId = (int)$db->lastInsertId();

$base = (defined('RADIO_BASE') ? RADIO_BASE : '/radio');
$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

$preference = [
    'items' => [[
        'title'       => 'Colaboración con Radio Argentina',
        'description' => 'Aporte voluntario para mantener el proyecto online',
        'quantity'    => 1,
        'currency_id' => 'ARS',
        'unit_price'  => $monto,
    ]],
    'back_urls' => [
        'success' => $origin . $base . '/?donacion=ok',
        'failure' => $origin . $base . '/?donacion=error',
        'pending' => $origin . $base . '/?donacion=pendiente',
    ],
    'auto_return'         => 'approved',
    'external_reference'  => (string)$donacionId,
    'notification_url'    => $origin . $base . '/api/mp_webhook.php?secret=' . urlencode(MP_WEBHOOK_SECRET),
    'statement_descriptor' => 'RADIO ARGENTINA',
];

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($preference, JSON_UNESCAPED_UNICODE),
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err || $code >= 400 || !$resp) {
    // No dejamos el registro como "pendiente" fantasma: si Mercado Pago no
    // pudo crear la preferencia, no hubo intento real de pago.
    $db->prepare('DELETE FROM donaciones WHERE id = ?')->execute([$donacionId]);
    api_error('No se pudo iniciar el pago, probá de nuevo en un rato', 502);
}

$data = json_decode($resp, true);
$initPoint = $data['init_point'] ?? null;
$prefId    = $data['id'] ?? null;

if (!$initPoint || !$prefId) {
    $db->prepare('DELETE FROM donaciones WHERE id = ?')->execute([$donacionId]);
    api_error('No se pudo iniciar el pago, probá de nuevo en un rato', 502);
}

$db->prepare('UPDATE donaciones SET mp_preference_id = ? WHERE id = ?')
   ->execute([$prefId, $donacionId]);

api_response(['init_point' => $initPoint]);
