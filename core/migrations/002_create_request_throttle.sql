-- Rate-limit counters, shared by every action that needs one.
--
-- Why a table of its own rather than counting the rows an action creates:
-- every other limit in this project counts what the action already wrote
-- (Contact Form counts contact_submissions, password reset counts
-- password_resets). Checkout cannot work that way — the thing being limited
-- happens BEFORE an order exists, so counting orders would mean creating the
-- junk orders the limit exists to prevent.
--
-- Not security_log either: that table records only actions capable of turning
-- a site's functionality on or off, on the stated grounds that a log
-- recording everything gets ignored. Throttle hits are routine and
-- high-volume, and would bury the audit trail.
--
-- `action` is an application-chosen string ('checkout', 'login'), never user
-- input. The index covers the only query shape there is: count rows for one
-- action and one address inside a time window, and delete rows older than a
-- cutoff.
--
-- created_at is written explicitly in UTC by core/Throttle.php. The default
-- is a fallback for a direct INSERT only — mixing MySQL's session-local
-- CURRENT_TIMESTAMP with UTC values would distort every window it touched.

CREATE TABLE IF NOT EXISTS request_throttle (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action     VARCHAR(50)  NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_request_throttle_lookup (action, ip, created_at),
    KEY idx_request_throttle_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
