-- Audit trail for actions that change what a site can do.
--
-- Additive only, per the release discipline: new columns are added alongside
-- old ones and backfilled, never renamed or dropped. A site that jumps several
-- versions at once replays these in order, so each file must stand alone.
CREATE TABLE IF NOT EXISTS security_log (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50)  NOT NULL,
    user_id    INT UNSIGNED NULL,
    context    TEXT         NULL,
    ip         VARCHAR(45)  NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_security_log_created (created_at),
    INDEX idx_security_log_event (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
