<?php
/**
 * learn_patterns.php — Aprende patrones de programas desde icy_history.
 *
 * Analiza títulos ICY del último mes y detecta patrones recurrentes:
 * si un keyword aparece regularmente en una emisora en determinado día/hora,
 * construye una entrada en program_patterns con nivel de confianza.
 *
 * Cron sugerido: 0 4 * * 1  (lunes a las 4am)
 */

$base = dirname(__FILE__, 2);
require_once $base . '/web/config.php';
require_once $base . '/web/api/_db.php';

$db  = radio_db();
$log = function(string $msg) { echo date('[H:i:s] ') . $msg . "\n"; };

$db->exec('CREATE TABLE IF NOT EXISTS program_patterns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER,
    keyword TEXT NOT NULL,
    day_of_week INTEGER,
    hour INTEGER,
    confidence REAL DEFAULT 0.0,
    occurrences INTEGER DEFAULT 0,
    last_seen TEXT,
    UNIQUE(station_id, keyword, day_of_week, hour)
)');

$log("Leyendo icy_history de los últimos 60 días...");

// Leer historial ICY (últimos 60 días)
// Extraemos "programa" del título: si el título tiene separadores tipo " - " o "| " tomamos la segunda parte
// como posible nombre de programa. Para artistas tomamos la parte antes del " - ".
$rows = $db->query("
    SELECT station_id,
           title,
           CAST(strftime('%w', datetime(seen_at, '-3 hours')) AS INTEGER) AS dow,
           CAST(strftime('%H', datetime(seen_at, '-3 hours')) AS INTEGER) AS hour,
           date(datetime(seen_at, '-3 hours')) AS day
    FROM icy_history
    WHERE seen_at >= datetime('now', '-60 days')
      AND title IS NOT NULL AND title != ''
    ORDER BY station_id, seen_at
")->fetchAll(PDO::FETCH_ASSOC);

$log("Registros cargados: " . count($rows));

// Extraer keywords de los títulos
// Un keyword válido: aparece en al menos 4 días distintos en el mismo slot hora+dow
// Se normaliza a lowercase y se eliminan números puros, fechas, etc.

function extract_keywords(string $title): array {
    $kws = [];
    $title = trim($title);

    // Separadores comunes en ICY: " - ", " | ", " / "
    $parts = preg_split('/ [-|\/] /', $title, 2);
    foreach ($parts as $part) {
        $part = trim($part);
        // Ignorar muy cortos o puramente numéricos
        if (mb_strlen($part) < 4 || is_numeric($part)) continue;
        // Ignorar publicidades genéricas
        if (preg_match('/^(jingle|publicidad|aviso|tandas?|cortina)/i', $part)) continue;
        $kws[] = $part;
    }
    return array_unique($kws);
}

// Agrupar: [station_id][dow][hour][keyword] → set de días únicos
$patterns = [];

foreach ($rows as $row) {
    $sid  = (int)$row['station_id'];
    $dow  = (int)$row['dow'];
    $hour = (int)$row['hour'];
    $day  = $row['day'];

    foreach (extract_keywords($row['title']) as $kw) {
        $kw_n = strtolower($kw);
        if (!isset($patterns[$sid][$dow][$hour][$kw_n])) {
            $patterns[$sid][$dow][$hour][$kw_n] = ['kw_orig' => $kw, 'days' => []];
        }
        $patterns[$sid][$dow][$hour][$kw_n]['days'][$day] = true;
    }
}

// Calcular cuántas semanas hay en el rango (para normalizar confianza)
$weeks = 8; // ~60 días / 7

$inserted = 0;
$updated  = 0;

foreach ($patterns as $sid => $dows) {
    foreach ($dows as $dow => $hours) {
        foreach ($hours as $hour => $keywords) {
            foreach ($keywords as $kw_n => $data) {
                $occurrences = count($data['days']);
                if ($occurrences < 3) continue; // descartar apariciones esporádicas

                $confidence = min(1.0, round($occurrences / $weeks, 2));
                if ($confidence < 0.25) continue; // menos de 25% de confianza → ignorar

                $kw_orig = $data['kw_orig'];
                $last    = max(array_keys($data['days']));

                // Upsert
                $existing = $db->prepare("SELECT id FROM program_patterns WHERE station_id=? AND keyword=? AND day_of_week=? AND hour=? LIMIT 1");
                $existing->execute([$sid, $kw_orig, $dow, $hour]);
                $row_id = $existing->fetchColumn();

                if ($row_id) {
                    $db->prepare("UPDATE program_patterns SET confidence=?, occurrences=?, last_seen=? WHERE id=?")
                       ->execute([$confidence, $occurrences, $last, $row_id]);
                    $updated++;
                } else {
                    $db->prepare("INSERT INTO program_patterns (station_id, keyword, day_of_week, hour, confidence, occurrences, last_seen) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$sid, $kw_orig, $dow, $hour, $confidence, $occurrences, $last]);
                    $inserted++;
                }
            }
        }
    }
}

// Limpiar patrones viejos (no vistos en más de 45 días)
$db->exec("DELETE FROM program_patterns WHERE last_seen < date('now', '-45 days')");

$total = (int)$db->query("SELECT COUNT(*) FROM program_patterns")->fetchColumn();
$log("Insertados: {$inserted} | Actualizados: {$updated} | Total en DB: {$total}");
$log("Listo.");
