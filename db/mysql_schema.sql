-- Radio AR — esquema MySQL 5.7.44 (InnoDB, utf8mb4)
-- Traducido desde SQLite (radio_v2.sqlite) el 2026-09-02.
-- Excluye: sqlite_sequence (interno SQLite), lost_and_found (artefacto de una
-- recuperación .recover previa, 1 fila huérfana ya superada, no es dato de la app).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE stations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    n               INT NULL,
    slug            VARCHAR(150) NOT NULL,
    nombre          VARCHAR(255) NOT NULL,
    url             VARCHAR(500) NOT NULL,
    url_hash        CHAR(64) GENERATED ALWAYS AS (SHA2(url, 256)) STORED,
    provincia       VARCHAR(100) NULL,
    tags            TEXT NULL,
    codec           VARCHAR(20) NULL,
    bitrate         INT NULL,
    homepage        VARCHAR(500) NULL,
    logo            VARCHAR(700) NULL,
    source          VARCHAR(30) DEFAULT 'manual',
    approved        TINYINT(1) DEFAULT 1,
    rb_uuid         VARCHAR(64) NULL,
    rb_votes        INT DEFAULT 0,
    rb_clicks       INT DEFAULT 0,
    created_at      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    contacto        TEXT NULL,
    en_observacion  TINYINT(1) DEFAULT 0,
    contacto_publico  VARCHAR(255) NULL,
    destacada       TINYINT(1) DEFAULT 0,
    contacto_privado VARCHAR(255) NULL,
    notas_privadas  TEXT NULL,
    activa          TINYINT(1) DEFAULT 1,
    ultimo_cambio   DATETIME NULL,
    UNIQUE KEY uk_stations_slug (slug),
    UNIQUE KEY uk_stations_url_hash (url_hash),
    KEY idx_stations_slug (slug),
    KEY idx_stations_prov (provincia),
    KEY idx_stations_approved (approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stream_status (
    station_id           INT UNSIGNED PRIMARY KEY,
    estado               VARCHAR(20) DEFAULT 'unknown',
    http_code            INT NULL,
    response_ms          INT NULL,
    consecutive_failures INT DEFAULT 0,
    last_checked         DATETIME NULL,
    last_ok               DATETIME NULL,
    updated_at           DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stream_status_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stream_history (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id    INT UNSIGNED NOT NULL,
    checked_at    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    estado        VARCHAR(20) NOT NULL,
    http_code     INT NULL,
    response_ms   INT NULL,
    icy_supported TINYINT(1) DEFAULT 0,
    icy_name      VARCHAR(150) NULL,
    stream_title  TEXT NULL,
    KEY idx_history_station (station_id, checked_at),
    KEY idx_history_date (checked_at),
    CONSTRAINT fk_stream_history_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE station_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id  INT UNSIGNED NOT NULL,
    event_type  VARCHAR(30) NOT NULL,
    old_value   TEXT NULL,
    new_value   TEXT NULL,
    detected_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    notified    TINYINT(1) DEFAULT 0,
    KEY idx_events_notified (notified, detected_at),
    CONSTRAINT fk_station_events_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE icy_cache (
    station_id        INT UNSIGNED PRIMARY KEY,
    supported         TINYINT(1) DEFAULT 0,
    icy_name          VARCHAR(150) NULL,
    stream_title      TEXT NULL,
    last_checked      DATETIME NULL,
    last_title_change DATETIME NULL,
    CONSTRAINT fk_icy_cache_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plays (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id  INT UNSIGNED NOT NULL,
    played_at   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    session_id  VARCHAR(64) NULL,
    ip_hash     VARCHAR(32) NULL,
    source      VARCHAR(30) DEFAULT 'web-listing',
    ended_at    DATETIME NULL,
    provincia   VARCHAR(100) NULL,
    KEY idx_plays_station (station_id, played_at),
    KEY idx_plays_date (played_at),
    KEY idx_plays_iphash (ip_hash),
    CONSTRAINT fk_plays_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listeners (
    sid         VARCHAR(64) PRIMARY KEY,
    station_id  INT UNSIGNED NULL,
    started_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    source      VARCHAR(30) DEFAULT 'web-listing',
    KEY idx_listeners_station (station_id),
    KEY idx_listeners_lastseen (last_seen),
    CONSTRAINT fk_listeners_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE surveys (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id  INT UNSIGNED NULL,
    rating      TINYINT NOT NULL,
    created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash     VARCHAR(32) NULL,
    location    VARCHAR(50) NULL,
    KEY idx_surveys_station (station_id),
    CONSTRAINT fk_surveys_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE SET NULL,
    CONSTRAINT chk_surveys_rating CHECK (rating IN (-1, 0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crawler_runs (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    crawler           VARCHAR(50) NOT NULL,
    started_at        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at       DATETIME NULL,
    stations_checked  INT DEFAULT 0,
    changes_detected  INT DEFAULT 0,
    errors            INT DEFAULT 0,
    notes             TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE icy_history (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(500) NOT NULL,
    seen_at     DATETIME NOT NULL,
    KEY idx_icy_hist_station (station_id, seen_at),
    CONSTRAINT fk_icy_history_station FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shares (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id  INT UNSIGNED NULL,
    slug        VARCHAR(150) NULL,
    channel     VARCHAR(20) NULL,
    ip_hash     VARCHAR(32) NULL,
    created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    `key`       VARCHAR(64) PRIMARY KEY,
    value       TEXT NULL,
    updated_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscribers (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_type   VARCHAR(20) NOT NULL,
    contact_value  VARCHAR(255) NOT NULL,
    preferences    TEXT NULL,
    active         TINYINT(1) DEFAULT 0,
    token          VARCHAR(64) NOT NULL,
    created_at     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    last_notified  DATETIME NULL,
    UNIQUE KEY uk_subscribers_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriber_matches (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscriber_id  INT UNSIGNED NULL,
    station_id     INT UNSIGNED NULL,
    keyword        VARCHAR(100) NOT NULL,
    first_seen     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    match_count    INT DEFAULT 1,
    notified       TINYINT(1) DEFAULT 0,
    CONSTRAINT fk_sub_matches_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_matches_station FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_patterns (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id    INT UNSIGNED NULL,
    keyword       VARCHAR(100) NOT NULL,
    day_of_week   INT NULL,
    hour          INT NULL,
    confidence    DOUBLE DEFAULT 0.0,
    occurrences   INT DEFAULT 0,
    last_seen     DATETIME NULL,
    UNIQUE KEY uk_program_patterns (station_id, keyword, day_of_week, hour),
    CONSTRAINT fk_program_patterns_station FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reportes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id  INT UNSIGNED NULL,
    mensaje     TEXT NULL,
    created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reportes_station FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ip_geo_cache (
    ip_hash     VARCHAR(32) PRIMARY KEY,
    provincia   VARCHAR(100) NULL,
    updated_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacto_mensajes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(255) NULL,
    email       VARCHAR(255) NULL,
    mensaje     TEXT NOT NULL,
    created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ayuda_toast_eventos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo        VARCHAR(30) NOT NULL,
    ip_hash     VARCHAR(32) NULL,
    provincia   VARCHAR(100) NULL,
    created_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Vistas (MySQL 5.7.44 no soporta CTE — reescritas con subconsulta/derived table)

CREATE OR REPLACE VIEW v_active_listeners AS
SELECT
    l.station_id,
    s.nombre,
    COUNT(*) AS count
FROM listeners l
JOIN stations s ON s.id = l.station_id
WHERE l.last_seen >= (NOW() - INTERVAL 90 SECOND)
GROUP BY l.station_id, s.nombre;

CREATE OR REPLACE VIEW v_stations AS
SELECT
    s.id, s.n, s.slug, s.nombre, s.url, s.provincia, s.tags,
    s.codec, s.bitrate, s.homepage, s.logo, s.source,
    s.rb_uuid, s.rb_votes, s.rb_clicks,
    s.contacto_publico, s.destacada,
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
WHERE s.approved = 1
  AND COALESCE(s.activa, 1) = 1
  AND NOT (
        ss.estado = 'muerto'
        AND (ss.last_ok IS NULL OR ss.last_ok < (NOW() - INTERVAL 14 DAY))
  );
