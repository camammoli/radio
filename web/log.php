<?php
/**
 * log.php — función compartida de logging para el player web
 * Incluido por proxy.php e index.php
 */
function radio_log(string $tipo, string $dato): void {
    $dir  = __DIR__ . '/logs';
    $mes  = date('Y-m');
    $file = "$dir/accesos_$mes.csv";
    $ip   = $_SERVER['HTTP_X_FORWARDED_FOR']
          ?? $_SERVER['HTTP_CF_CONNECTING_IP']
          ?? $_SERVER['REMOTE_ADDR']
          ?? '-';
    $ip   = trim(explode(',', $ip)[0]);
    $ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 120);
    $ref  = substr($_SERVER['HTTP_REFERER']    ?? '-', 0, 120);
    $line = implode('|', [
        date('Y-m-d H:i:s'),
        $ip, $tipo,
        str_replace('|', '%7C', $dato),
        str_replace('|', '%7C', $ua),
        str_replace('|', '%7C', $ref),
    ]) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
