-- auth module — password_resets table
--
-- Required by core/Auth.php. Only the SHA-256 HASH of the reset token is
-- ever stored here (token_hash, 64 hex chars) — never the raw token.
-- Rows are looked up by token_hash and by user_id (rate-limit check), so
-- both are indexed. No foreign key on user_id (indexed instead) to stay
-- compatible with legacy shared-host MySQL configs.

CREATE TABLE IF NOT EXISTS password_resets (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_resets_token_hash (token_hash),
    KEY idx_password_resets_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
