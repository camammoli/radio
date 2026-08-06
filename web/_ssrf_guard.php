<?php
/**
 * _ssrf_guard.php — valida que una URL a fetchear server-side no apunte a
 * red privada, loopback o link-local antes de conectarse.
 *
 * A diferencia de un chequeo por texto sobre el hostname (bypasseable con
 * DNS rebinding o dominios que resuelven a IP interna), esto resuelve el
 * host y valida la(s) IP(s) real(es) con los filtros nativos de PHP.
 *
 * Compartido por proxy.php y sugerir.php (los dos lugares que necesitan
 * aceptar una URL de stream y conectarse a ella desde el servidor).
 */

function radio_url_is_safe(string $url): bool {
    if (!preg_match('#^https?://#i', $url)) return false;

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $host = trim($host, '[]');

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records) {
            foreach ($records as $r) {
                if (!empty($r['ip']))   $ips[] = $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
    }

    if (!$ips) return false; // no se pudo resolver: mejor rechazar que arriesgar

    foreach ($ips as $ip) {
        // Bloquea RFC1918/ULA (NO_PRIV_RANGE) y loopback/link-local/reservado,
        // incluye 169.254.0.0/16 -- metadata de nube (NO_RES_RANGE)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    return true;
}
