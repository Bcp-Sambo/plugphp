-- blog module — posts table
--
-- Schema per modules/blog/SKILL.md. slug is UNIQUE-indexed (enforced here
-- in the migration, not just in application code, as the SKILL requires).
-- published_at NULL = draft/unpublished; a non-null value in the past =
-- publicly visible. Indexed because the public listing filters/orders on it.
--
-- slug is VARCHAR(191) so the utf8mb4 unique index stays under InnoDB's
-- legacy 767-byte prefix limit on old shared-host MySQL.

CREATE TABLE IF NOT EXISTS posts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title            VARCHAR(255) NOT NULL,
    slug             VARCHAR(191) NOT NULL,
    excerpt          TEXT NULL,
    body             LONGTEXT NULL,
    featured_image   VARCHAR(255) NULL,
    meta_title       VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    published_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_posts_slug (slug),
    KEY idx_posts_published_at (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
