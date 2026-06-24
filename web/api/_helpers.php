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

function ip_hash(string $ip): string {
    return substr(hash('sha256', $ip), 0, 16);
}
