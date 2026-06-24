<?php
/**
 * _db.php — Conexión PDO a radio_v2.sqlite.
 * Retorna un singleton. No incluir directamente desde la web — prefijo _ lo protege.
 */

function radio_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    // Ruta configurable desde config.php; por defecto dos niveles arriba de /api/
    $path = defined('RADIO_DB') ? RADIO_DB : __DIR__ . '/../../db/radio_v2.sqlite';

    if (!file_exists($path)) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Base de datos no disponible', 'code' => 503]);
        exit;
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 3000');
    return $pdo;
}
