-- auth module — users table
--
-- Required by core/Auth.php (register / attemptLogin / password reset all
-- read and write this table). Columns kept minimal; `name` is an optional
-- profile field passed through Auth::register()'s $extra array.
--
-- email is UNIQUE and indexed. VARCHAR(191) (not 255) keeps the utf8mb4
-- unique index under InnoDB's legacy 767-byte prefix limit so it still
-- creates cleanly on old shared-host MySQL.

CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(255) NULL,
    email         VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
