<?php
/**
 * _admin_listas.php — listas del panel de admin SIN TOPE de filas (TKT-0827).
 *
 * Antes cada tabla traía las últimas 30/100/200 filas y el filtro/orden/paginado
 * corría en el navegador sobre esas pocas. Ahora el navegador pide cada página al
 * servidor (admin.php?lista=…): se puede llegar a la última fila, buscar y agrupar
 * sobre TODOS los datos, y exportar la lista completa a CSV. El HTML de cada fila
 * se arma acá (un solo lugar; antes las de Reproducciones y Compartidos estaban
 * duplicadas en PHP y en el JS del auto-refresh).
 *
 * Cada lista define:
 *   select/from/where  la consulta base (sin ORDER/LIMIT)
 *   id                 clave única: desempate fijo del orden (si no, con horas repetidas una fila podía
 *                      aparecer en dos páginas o en ninguna)
 *   orden              clave de columna (data-col del <th>) => expresión SQL; 'def' = [clave, dir]
 *   buscar             expresiones donde busca el filtro de texto (LIKE)
 *   grupos             clave (data-gk del <th>) => expresión SQL para "Agrupar por"
 *   post               opcional: completa datos de las filas de la página en lote
 *   fila               fila → <tr>…</tr>
 *   csv                encabezado => fila → texto
 *
 * Requiere admin.php (sesión de admin ya validada, h(), ago(), ua_corto(), etc.).
 */

function admin_listas(): array {
    $now = db_engine() === 'mysql' ? 'NOW()' : "'now'";
    $dur = sql_seconds_diff('p.played_at', 'p.ended_at');
    $durVivo = sql_seconds_diff('p.played_at', $now);
    $durCrawler = sql_seconds_diff('started_at', 'finished_at');
    $ch = ['copy' => '🔗 Link', 'wa' => '💬 WhatsApp', 'qr' => '⬛ QR', 'x' => '𝕏 X', 'tg' => '✈️ Telegram', 'm3u' => '📻 M3U'];
    $ay = ['mostrado' => '👁 Mostrado', 'ok' => '👍 OK', 'no_molestar' => '🚫 No molestar', 'cafecito' => '☕ Cafecito', 'contacto' => '💬 Contacto'];
    $dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $fecha = fn($v, $n = 19) => h(str_replace('T', ' ', substr((string)$v, 0, $n)));
    $emisora = fn($r) => !empty($r['slug'])
        ? '<a href="/radio/' . h($r['slug']) . '/" target="_blank">' . h($r['nombre'] ?? $r['slug']) . '</a>'
        : '<span style="color:var(--muted)">—</span>';
    $prov = fn($p) => $p ? '📍 ' . h($p) : '—';
    $hash = fn($v, $n = 16) => '<td style="font-size:11px;color:var(--muted);font-family:monospace">' . h(substr((string)($v ?? ''), 0, $n)) . '…</td>';

    return [
        // ── Reproducciones ──────────────────────────────────────────────────
        'plays' => [
            'select' => "p.played_at, p.ended_at, p.ip_hash, p.provincia, p.source, p.session_id, s.nombre, s.slug,
                         CASE WHEN p.ended_at IS NOT NULL THEN $dur
                              WHEN l.sid IS NOT NULL THEN $durVivo ELSE NULL END AS duration_secs,
                         CASE WHEN l.sid IS NOT NULL AND p.ended_at IS NULL THEN 1 ELSE 0 END AS is_active",
            'id'     => 'p.id',
            'from'   => "plays p LEFT JOIN stations s ON s.id = p.station_id LEFT JOIN listeners l ON l.sid = p.session_id",
            'orden'  => ['fecha' => 'p.played_at', 'emisora' => 's.nombre', 'estado' => 'is_active', 'duracion' => 'duration_secs',
                         'origen' => 'p.source', 'provincia' => 'p.provincia', 'def' => ['fecha', 'desc']],
            'buscar' => ['s.nombre', 'p.provincia', 'p.source', 'p.ip_hash', 'p.session_id'],
            'grupos' => ['emisora' => 's.nombre', 'estado' => "CASE WHEN l.sid IS NOT NULL AND p.ended_at IS NULL THEN 'Reproduciendo' ELSE 'Terminada' END",
                         'origen' => 'p.source', 'provincia' => 'p.provincia'],
            'post'   => 'admin_listas_plays_post',
            'fila'   => function ($pl) use ($emisora, $prov, $hash) {
                $activa = !empty($pl['is_active']);
                $d = $pl['duration_secs'] !== null ? fmt_duration((int)$pl['duration_secs']) : '—';
                return '<tr>'
                    . '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' . h(str_replace('T', ' ', substr($pl['played_at'], 0, 19))) . '</td>'
                    . '<td>' . $emisora($pl) . '</td>'
                    . '<td style="font-size:12px;white-space:nowrap">' . ($activa ? '<span style="color:#22c55e">▶ Reproduciendo</span>' : '<span style="color:var(--muted)">Terminada</span>') . '</td>'
                    . '<td style="font-size:12px;white-space:nowrap">' . ($activa ? '<span style="color:#22c55e">' . $d . '</span>' : $d) . '</td>'
                    . '<td style="font-size:12px;color:var(--muted)">' . h($pl['source'] ?? '—') . '</td>'
                    . '<td style="font-size:14px;text-align:center">' . visitor_badge($pl['dias_activos'] ?? null) . '</td>'
                    . '<td style="font-size:12px;text-align:center">' . bot_badge($pl['hops_1h'] ?? 0) . '</td>'
                    . '<td style="font-size:12px;color:var(--muted)">' . $prov($pl['provincia']) . '</td>'
                    . $hash($pl['ip_hash']) . $hash($pl['session_id'], 12)
                    . '</tr>';
            },
            'csv' => ['Fecha (UTC)' => 'played_at', 'Fin (UTC)' => 'ended_at', 'Emisora' => 'nombre', 'Slug' => 'slug',
                      'Estado' => fn($r) => $r['is_active'] ? 'Reproduciendo' : 'Terminada', 'Duración (s)' => 'duration_secs',
                      'Origen' => 'source', 'Días activos del visitante' => 'dias_activos',
                      'Emisoras distintas ±30 min' => 'hops_1h', 'Provincia' => 'provincia', 'IP hash' => 'ip_hash', 'Sesión' => 'session_id'],
        ],

        // ── Compartidos ─────────────────────────────────────────────────────
        'shares' => [
            'select' => "sh.created_at, sh.channel, sh.ip_hash, sh.slug, sh.ua, s.nombre, g.provincia",
            'id'     => 'sh.id',
            'from'   => "shares sh LEFT JOIN stations s ON s.id = sh.station_id LEFT JOIN ip_geo_cache g ON g.ip_hash = sh.ip_hash",
            'orden'  => ['fecha' => 'sh.created_at', 'emisora' => 's.nombre', 'canal' => 'sh.channel', 'provincia' => 'g.provincia',
                         'cliente' => 'sh.ua', 'def' => ['fecha', 'desc']],
            'buscar' => ['s.nombre', 'sh.slug', 'sh.channel', 'g.provincia', 'sh.ua', 'sh.ip_hash'],
            'grupos' => ['emisora' => 's.nombre', 'canal' => 'sh.channel', 'provincia' => 'g.provincia'],
            'fila'   => function ($sh) use ($ch, $emisora, $prov, $hash) {
                return '<tr>'
                    . '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' . h(str_replace('T', ' ', substr($sh['created_at'], 0, 19))) . '</td>'
                    . '<td>' . $emisora($sh) . '</td>'
                    . '<td>' . ($ch[$sh['channel']] ?? h($sh['channel'])) . '</td>'
                    . '<td style="font-size:12px;color:var(--muted)">' . $prov($sh['provincia']) . '</td>'
                    . '<td style="font-size:12px;white-space:nowrap"' . ($sh['ua'] ? ' title="' . h($sh['ua']) . '"' : '') . '>'
                    . ($sh['ua'] ? h(ua_corto($sh['ua'])) : '<span style="color:var(--muted)">—</span>') . '</td>'
                    . $hash($sh['ip_hash'])
                    . '</tr>';
            },
            'csv' => ['Fecha (UTC)' => 'created_at', 'Emisora' => 'nombre', 'Slug' => 'slug', 'Canal' => 'channel',
                      'Provincia' => 'provincia', 'Cliente' => fn($r) => ua_corto($r['ua']), 'User-agent' => 'ua', 'IP hash' => 'ip_hash'],
        ],

        // ── Cartel de ayuda: eventos ────────────────────────────────────────
        'ayuda' => [
            'select' => "tipo, ip_hash, provincia, created_at",
            'id'     => 'id',
            'from'   => "ayuda_toast_eventos",
            'orden'  => ['fecha' => 'created_at', 'tipo' => 'tipo', 'provincia' => 'provincia', 'def' => ['fecha', 'desc']],
            'buscar' => ['tipo', 'provincia', 'ip_hash'],
            'grupos' => ['tipo' => 'tipo', 'provincia' => 'provincia'],
            'fila'   => function ($ev) use ($ay, $prov, $hash) {
                return '<tr>'
                    . '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' . h(str_replace('T', ' ', substr($ev['created_at'], 0, 19))) . '</td>'
                    . '<td>' . ($ay[$ev['tipo']] ?? h($ev['tipo'])) . '</td>'
                    . '<td style="font-size:12px;color:var(--muted)">' . $prov($ev['provincia']) . '</td>'
                    . $hash($ev['ip_hash'])
                    . '</tr>';
            },
            'csv' => ['Fecha (UTC)' => 'created_at', 'Tipo' => 'tipo', 'Provincia' => 'provincia', 'IP hash' => 'ip_hash'],
        ],

        // ── Encuestas: detalle ──────────────────────────────────────────────
        'encuestas' => [
            'select' => "sv.rating, sv.location, sv.ip_hash, sv.created_at, s.nombre, s.slug, g.provincia AS provincia_geo",
            'id'     => 'sv.id',
            'from'   => "surveys sv LEFT JOIN stations s ON s.id = sv.station_id LEFT JOIN ip_geo_cache g ON g.ip_hash = sv.ip_hash",
            'orden'  => ['fecha' => 'sv.created_at', 'rating' => 'sv.rating', 'emisora' => 's.nombre', 'ubicacion' => 'sv.location',
                         'provincia' => 'g.provincia', 'def' => ['fecha', 'desc']],
            'buscar' => ['s.nombre', 'sv.location', 'g.provincia', 'sv.ip_hash'],
            'grupos' => ['rating' => "CASE sv.rating WHEN 1 THEN '👍 Me gusta' WHEN -1 THEN '👎 No me convence' ELSE '😐 Regular' END",
                         'emisora' => "COALESCE(s.nombre, 'bienvenida')", 'ubicacion' => 'sv.location', 'provincia' => 'g.provincia'],
            'fila'   => function ($sv) use ($prov, $hash) {
                $r = (int)$sv['rating'];
                $rl = $r === 1 ? '<span class="pos">👍</span>' : ($r === -1 ? '<span class="neg">👎</span>' : '<span class="neu">😐</span>');
                $em = $sv['slug'] ? '<a href="/radio/' . h($sv['slug']) . '/" target="_blank">' . h($sv['nombre'] ?? '—') . '</a>'
                                  : '<span style="color:var(--muted)">bienvenida</span>';
                return '<tr>'
                    . '<td style="white-space:nowrap;font-size:12px;color:var(--muted)">' . h(str_replace('T', ' ', substr($sv['created_at'], 0, 19))) . '</td>'
                    . '<td>' . $rl . '</td><td>' . $em . '</td>'
                    . '<td style="color:var(--muted)">' . ($sv['location'] ? h(ucfirst($sv['location'])) : '—') . '</td>'
                    . '<td style="font-size:12px;color:var(--muted)">' . $prov($sv['provincia_geo']) . '</td>'
                    . $hash($sv['ip_hash'])
                    . '</tr>';
            },
            'csv' => ['Fecha (UTC)' => 'created_at', 'Rating' => 'rating', 'Emisora' => fn($r) => $r['nombre'] ?? 'bienvenida',
                      'Ubicación' => 'location', 'Provincia (geo)' => 'provincia_geo', 'IP hash' => 'ip_hash'],
        ],

        // ── Crawlers: ejecuciones ───────────────────────────────────────────
        'crawlers' => [
            'select' => "crawler, started_at, finished_at, $durCrawler AS secs, stations_checked, changes_detected, errors, notes",
            'id'     => 'id',
            'from'   => "crawler_runs",
            'orden'  => ['crawler' => 'crawler', 'inicio' => 'started_at', 'duracion' => 'secs', 'chequeadas' => 'stations_checked',
                         'cambios' => 'changes_detected', 'errores' => 'errors', 'def' => ['inicio', 'desc']],
            'buscar' => ['crawler', 'notes'],
            'grupos' => ['crawler' => 'crawler'],
            'fila'   => function ($cr) {
                $s = $cr['secs'] !== null ? (int)$cr['secs'] : null;
                $d = $s === null ? '—' : ($s >= 60 ? floor($s / 60) . 'min ' . ($s % 60) . 's' : $s . 's');
                return '<tr>'
                    . '<td><strong>' . h($cr['crawler']) . '</strong></td>'
                    . '<td style="color:var(--muted);font-size:12px;white-space:nowrap" title="' . h($cr['started_at']) . ' UTC">' . ago($cr['started_at']) . '</td>'
                    . '<td style="white-space:nowrap">' . $d . '</td>'
                    . '<td>' . ($cr['stations_checked'] ?: '—') . '</td>'
                    . '<td class="' . ($cr['changes_detected'] > 0 ? 'pos' : '') . '">' . ($cr['changes_detected'] ?: '—') . '</td>'
                    . '<td style="color:var(--muted)">' . ($cr['errors'] ?: '—') . '</td>'
                    . '<td style="font-size:12px;color:var(--muted)">' . h($cr['notes'] ?? '') . '</td>'
                    . '</tr>';
            },
            'csv' => ['Crawler' => 'crawler', 'Inicio (UTC)' => 'started_at', 'Fin (UTC)' => 'finished_at', 'Duración (s)' => 'secs',
                      'Chequeadas' => 'stations_checked', 'Cambios' => 'changes_detected', 'Errores' => 'errors', 'Notas' => 'notes'],
        ],

        // ── Patrones de programas ───────────────────────────────────────────
        'patrones' => [
            'select' => "pp.keyword, pp.day_of_week, pp.hour, pp.confidence, pp.occurrences, pp.last_seen, s.nombre",
            'id'     => 'pp.id',
            'from'   => "program_patterns pp JOIN stations s ON s.id = pp.station_id",
            'orden'  => ['keyword' => 'pp.keyword', 'emisora' => 's.nombre', 'dia' => 'pp.day_of_week', 'hora' => 'pp.hour',
                         'confianza' => 'pp.confidence', 'ocurrencias' => 'pp.occurrences', 'visto' => 'pp.last_seen', 'def' => ['confianza', 'desc']],
            'buscar' => ['pp.keyword', 's.nombre'],
            'grupos' => ['emisora' => 's.nombre',
                         'dia' => "CASE pp.day_of_week WHEN 0 THEN 'Dom' WHEN 1 THEN 'Lun' WHEN 2 THEN 'Mar' WHEN 3 THEN 'Mié' WHEN 4 THEN 'Jue' WHEN 5 THEN 'Vie' WHEN 6 THEN 'Sáb' ELSE 'Todos' END"],
            'fila'   => function ($pp) use ($dias) {
                $c = (float)$pp['confidence'];
                $col = $c >= 0.7 ? 'var(--green)' : ($c >= 0.4 ? 'var(--yellow)' : 'var(--muted)');
                return '<tr>'
                    . '<td><strong>' . h($pp['keyword']) . '</strong></td>'
                    . '<td style="font-size:12px">' . h($pp['nombre']) . '</td>'
                    . '<td style="font-size:12px">' . ($pp['day_of_week'] !== null ? $dias[(int)$pp['day_of_week']] : 'Todos') . '</td>'
                    . '<td>' . ($pp['hour'] !== null ? sprintf('%02d:00', (int)$pp['hour']) : '—') . '</td>'
                    . '<td style="color:' . $col . ';font-weight:600">' . round($c * 100) . '%</td>'
                    . '<td style="color:var(--muted)">' . (int)$pp['occurrences'] . 'x</td>'
                    . '<td style="font-size:12px;color:var(--muted)">' . h($pp['last_seen'] ?? '—') . '</td>'
                    . '</tr>';
            },
            'csv' => ['Keyword' => 'keyword', 'Emisora' => 'nombre',
                      'Día' => fn($r) => $r['day_of_week'] !== null ? $dias[(int)$r['day_of_week']] : 'Todos',
                      'Hora' => 'hour', 'Confianza' => 'confidence', 'Ocurrencias' => 'occurrences', 'Último visto' => 'last_seen'],
        ],
    ];
}

/** Días activos y saltos de emisora del visitante, en lote para las filas de la página
 *  (antes eran dos subconsultas POR FILA sobre toda la tabla plays). */
function admin_listas_plays_post(PDO $db, array $rows): array {
    $hashes = array_values(array_unique(array_filter(array_column($rows, 'ip_hash'))));
    if (!$hashes) return $rows;
    $dias = [];
    $diaExpr = sql_date_local('played_at', 0);
    foreach (array_chunk($hashes, 500) as $lote) {
        $in = implode(',', array_fill(0, count($lote), '?'));
        $st = $db->prepare("SELECT ip_hash, COUNT(DISTINCT $diaExpr) AS n FROM plays WHERE ip_hash IN ($in) GROUP BY ip_hash");
        $st->execute($lote);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $dias[$r['ip_hash']] = (int)$r['n'];
    }
    // Saltos: emisoras distintas del mismo visitante a ±30 min de cada reproducción.
    $ts = array_map(fn($r) => strtotime($r['played_at'] . ' UTC'), $rows);
    $desde = gmdate('Y-m-d H:i:s', min($ts) - 1800);
    $hasta = gmdate('Y-m-d H:i:s', max($ts) + 1800);
    $porHash = [];
    foreach (array_chunk($hashes, 500) as $lote) {
        $in = implode(',', array_fill(0, count($lote), '?'));
        $st = $db->prepare("SELECT ip_hash, station_id, played_at FROM plays WHERE ip_hash IN ($in) AND played_at BETWEEN ? AND ?");
        $st->execute(array_merge($lote, [$desde, $hasta]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porHash[$r['ip_hash']][] = [strtotime($r['played_at'] . ' UTC'), $r['station_id']];
        }
    }
    foreach ($rows as $i => &$r) {
        $r['dias_activos'] = $dias[$r['ip_hash']] ?? null;
        $vistas = [];
        foreach ($porHash[$r['ip_hash']] ?? [] as [$t, $sid]) {
            if (abs($t - $ts[$i]) <= 1800) $vistas[$sid] = true;
        }
        $r['hops_1h'] = count($vistas);
    }
    return $rows;
}

/** Atiende admin.php?lista=… — JSON con la página pedida, el resumen agrupado o el CSV completo. */
function admin_listas_responder(PDO $db, string $nombre): void {
    $L = admin_listas()[$nombre] ?? null;
    if (!$L) { http_response_code(404); echo json_encode(['error' => 'lista inexistente']); return; }

    // Filtros: texto libre (en las columnas de 'buscar') y valor exacto de un grupo.
    $where = []; $args = [];
    if (!empty($L['where'])) $where[] = $L['where'];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $L['buscar'])) . ')';
        foreach ($L['buscar'] as $_) $args[] = '%' . $q . '%';
    }
    $gk = (string)($_GET['gk'] ?? '');
    if ($gk !== '' && isset($L['grupos'][$gk]) && array_key_exists('gv', $_GET)) {
        $where[] = "COALESCE(" . $L['grupos'][$gk] . ", '') = ?";
        $args[] = (string)$_GET['gv'];
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // Resumen "Agrupar por": todos los valores con su cantidad (sobre todos los datos).
    $agr = (string)($_GET['agrupar'] ?? '');
    if ($agr !== '' && isset($L['grupos'][$agr])) {
        $e = $L['grupos'][$agr];
        $st = $db->prepare("SELECT COALESCE($e, '') AS v, COUNT(*) AS n FROM {$L['from']}$w GROUP BY COALESCE($e, '') ORDER BY n DESC");
        $st->execute($args);
        echo json_encode(['grupos' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$defCol, $defDir] = $L['orden']['def'];
    $col = (string)($_GET['orden'] ?? $defCol);
    if ($col === 'def' || !isset($L['orden'][$col])) $col = $defCol;
    $dir = strtolower((string)($_GET['dir'] ?? ($col === $defCol ? $defDir : 'asc'))) === 'asc' ? 'ASC' : 'DESC';
    $ord = " ORDER BY {$L['orden'][$col]} $dir";
    if ($col !== $defCol) $ord .= ', ' . $L['orden'][$defCol] . ' ' . strtoupper($defDir);
    $ord .= ', ' . $L['id'] . ' ' . ($col === $defCol ? $dir : strtoupper($defDir));
    $sql = "SELECT {$L['select']} FROM {$L['from']}$w$ord";

    // CSV: la lista completa (con los filtros activos), por tandas para no cargar todo en memoria.
    if (!empty($_GET['csv'])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="radio_' . $nombre . '_' . gmdate('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");                       // BOM: Excel abre bien los acentos
        fputcsv($out, array_keys($L['csv']));
        for ($off = 0; ; $off += 2000) {
            $st = $db->prepare("$sql LIMIT 2000 OFFSET $off");
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            if (!empty($L['post'])) $rows = ($L['post'])($db, $rows);
            foreach ($rows as $r) {
                // Solo los Closure se llaman: un nombre de columna como 'ip_hash' coincide con una función global.
                fputcsv($out, array_map(fn($c) => $c instanceof Closure ? $c($r) : ($r[$c] ?? ''), array_values($L['csv'])));
            }
            if (count($rows) < 2000) break;
        }
        fclose($out);
        return;
    }

    $st = $db->prepare("SELECT COUNT(*) FROM {$L['from']}" . (!empty($L['where']) ? " WHERE {$L['where']}" : ''));
    $st->execute();
    $total = (int)$st->fetchColumn();
    if ($w === (!empty($L['where']) ? " WHERE {$L['where']}" : '')) {
        $filtrado = $total;
    } else {
        $st = $db->prepare("SELECT COUNT(*) FROM {$L['from']}$w");
        $st->execute($args);
        $filtrado = (int)$st->fetchColumn();
    }
    // Tamaño de cada pedido: 1 a 2000 filas. "Todas" lo arma el navegador pidiendo tandas de 2000 y
    // sumándolas, así el servidor nunca junta decenas de miles de filas en memoria (con 30 mil se pasaba de 128 MB).
    $n = (int)($_GET['n'] ?? 25);
    $n = $n <= 0 ? 2000 : min($n, 2000);
    $paginas = max(1, (int)ceil($filtrado / $n));
    $p = min(max(1, (int)($_GET['p'] ?? 1)), $paginas);
    $st = $db->prepare("$sql LIMIT $n OFFSET " . (($p - 1) * $n));
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows && !empty($L['post'])) $rows = ($L['post'])($db, $rows);
    echo json_encode([
        'total' => $total, 'filtrado' => $filtrado, 'pagina' => $p, 'paginas' => $paginas,
        'desde' => $rows ? ($p - 1) * $n + 1 : 0, 'hasta' => ($p - 1) * $n + count($rows),
        'html' => implode('', array_map($L['fila'], $rows)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
