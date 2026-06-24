-- Radio Argentina v2 — Schema SQLite
-- 2026-06-24

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ─────────────────────────────────────────────────────────────────────────────
-- EMISORAS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS stations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    n           INTEGER,                        -- número legado (backward compat)
    slug        TEXT    NOT NULL UNIQUE,        -- /radio/{slug}/
    nombre      TEXT    NOT NULL,
    url         TEXT    NOT NULL UNIQUE,
    provincia   TEXT,
    tags        TEXT    DEFAULT '[]',           -- JSON array
    codec       TEXT,
    bitrate     INTEGER,
    homepage    TEXT,
    logo        TEXT,
    source      TEXT    DEFAULT 'manual',       -- manual / radio-browser / gist / sugerencia
    approved    INTEGER DEFAULT 1,              -- 0 = pendiente aprobación
    rb_uuid     TEXT,                           -- Radio Browser UUID (dedup)
    rb_votes    INTEGER DEFAULT 0,
    rb_clicks   INTEGER DEFAULT 0,
    created_at  TEXT    DEFAULT (datetime('now')),
    updated_at  TEXT    DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_stations_slug     ON stations(slug);
CREATE INDEX IF NOT EXISTS idx_stations_prov     ON stations(provincia);
CREATE INDEX IF NOT EXISTS idx_stations_approved ON stations(approved);

-- ─────────────────────────────────────────────────────────────────────────────
-- ESTADO ACTUAL DE STREAMS (una fila por emisora, actualizada por crawler)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS stream_status (
    station_id          INTEGER PRIMARY KEY REFERENCES stations(id) ON DELETE CASCADE,
    estado              TEXT    DEFAULT 'unknown',  -- ok / muerto / timeout / unknown
    http_code           INTEGER,
    response_ms         INTEGER,
    consecutive_failures INTEGER DEFAULT 0,
    last_checked        TEXT,
    last_ok             TEXT,
    updated_at          TEXT    DEFAULT (datetime('now'))
);

-- ─────────────────────────────────────────────────────────────────────────────
-- HISTORIAL DE VERIFICACIONES (memoria de los crawlers)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS stream_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id  INTEGER NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
    checked_at  TEXT    DEFAULT (datetime('now')),
    estado      TEXT    NOT NULL,               -- ok / muerto / timeout
    http_code   INTEGER,
    response_ms INTEGER,
    icy_supported INTEGER DEFAULT 0,            -- 1 si tiene ICY metadata
    icy_name    TEXT,                           -- icy-name header
    stream_title TEXT                           -- StreamTitle del momento
);

CREATE INDEX IF NOT EXISTS idx_history_station ON stream_history(station_id, checked_at);

-- ─────────────────────────────────────────────────────────────────────────────
-- EVENTOS DETECTADOS POR CRAWLERS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS station_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id  INTEGER NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
    event_type  TEXT    NOT NULL,   -- came_back / went_down / icy_gained / icy_lost
                                    -- url_changed / codec_changed
    old_value   TEXT,
    new_value   TEXT,
    detected_at TEXT    DEFAULT (datetime('now')),
    notified    INTEGER DEFAULT 0   -- 0 = pendiente notificación Telegram
);

CREATE INDEX IF NOT EXISTS idx_events_notified ON station_events(notified, detected_at);

-- ─────────────────────────────────────────────────────────────────────────────
-- ICY METADATA (estado actual + última canción detectada)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS icy_cache (
    station_id        INTEGER PRIMARY KEY REFERENCES stations(id) ON DELETE CASCADE,
    supported         INTEGER DEFAULT 0,
    icy_name          TEXT,
    stream_title      TEXT,                     -- canción actual
    last_checked      TEXT,
    last_title_change TEXT                      -- cuándo cambió la canción por última vez
);

-- ─────────────────────────────────────────────────────────────────────────────
-- REPRODUCCIONES (historial)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS plays (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id  INTEGER NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
    played_at   TEXT    DEFAULT (datetime('now')),
    session_id  TEXT,
    ip_hash     TEXT,                           -- SHA256 truncado, sin IP en claro
    source      TEXT    DEFAULT 'web-listing'   -- web-listing / web-station / cli / external
);

CREATE INDEX IF NOT EXISTS idx_plays_station ON plays(station_id, played_at);
CREATE INDEX IF NOT EXISTS idx_plays_date    ON plays(played_at);

-- ─────────────────────────────────────────────────────────────────────────────
-- OYENTES ACTIVOS (TTL 90s, limpieza en cada ping)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS listeners (
    sid         TEXT    PRIMARY KEY,
    station_id  INTEGER REFERENCES stations(id) ON DELETE SET NULL,
    started_at  TEXT    DEFAULT (datetime('now')),
    last_seen   TEXT    DEFAULT (datetime('now')),
    source      TEXT    DEFAULT 'web-listing'   -- web-listing / web-station / cli
);

CREATE INDEX IF NOT EXISTS idx_listeners_station  ON listeners(station_id);
CREATE INDEX IF NOT EXISTS idx_listeners_lastseen ON listeners(last_seen);

-- ─────────────────────────────────────────────────────────────────────────────
-- ENCUESTAS DE SATISFACCIÓN
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS surveys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id  INTEGER REFERENCES stations(id) ON DELETE SET NULL,
    rating      INTEGER NOT NULL CHECK(rating IN (-1, 0, 1)),
    created_at  TEXT    DEFAULT (datetime('now')),
    ip_hash     TEXT
);

CREATE INDEX IF NOT EXISTS idx_surveys_station ON surveys(station_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- LOG DE EJECUCIONES DE CRAWLERS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS crawler_runs (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    crawler             TEXT    NOT NULL,       -- check-streams / hunt-stations / icy-check
    started_at          TEXT    DEFAULT (datetime('now')),
    finished_at         TEXT,
    stations_checked    INTEGER DEFAULT 0,
    changes_detected    INTEGER DEFAULT 0,
    errors              INTEGER DEFAULT 0,
    notes               TEXT
);

-- ─────────────────────────────────────────────────────────────────────────────
-- VISTAS ÚTILES
-- ─────────────────────────────────────────────────────────────────────────────

-- Directorio completo con estado e ICY (reemplaza la unión emisoras.json + status.json)
CREATE VIEW IF NOT EXISTS v_stations AS
SELECT
    s.id, s.n, s.slug, s.nombre, s.url, s.provincia, s.tags,
    s.codec, s.bitrate, s.homepage, s.logo, s.source,
    COALESCE(ss.estado, 'unknown')          AS estado,
    ss.http_code, ss.response_ms,
    ss.consecutive_failures,
    ss.last_checked, ss.last_ok,
    COALESCE(ic.supported, 0)               AS icy_supported,
    ic.icy_name, ic.stream_title,
    ic.last_checked                         AS icy_last_checked,
    COALESCE(p.total_plays, 0)              AS total_plays
FROM stations s
LEFT JOIN stream_status  ss ON ss.station_id = s.id
LEFT JOIN icy_cache      ic ON ic.station_id = s.id
LEFT JOIN (
    SELECT station_id, COUNT(*) AS total_plays FROM plays GROUP BY station_id
) p ON p.station_id = s.id
WHERE s.approved = 1;

-- Oyentes activos por emisora (TTL 90s)
CREATE VIEW IF NOT EXISTS v_active_listeners AS
SELECT
    l.station_id,
    s.nombre,
    COUNT(*) AS count
FROM listeners l
JOIN stations s ON s.id = l.station_id
WHERE l.last_seen >= datetime('now', '-90 seconds')
GROUP BY l.station_id;
