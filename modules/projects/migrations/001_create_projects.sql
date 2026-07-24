-- projects module — projects table
--
-- Schema per modules/projects/SKILL.md. gallery_images is a native JSON
-- column (MySQL 5.7.8+): the app stores a JSON array of image paths via
-- json_encode() and reads it back with json_decode() — do NOT create a
-- separate join table (see the module SKILL.md). JSON columns cannot have
-- a DEFAULT in MySQL 5.7, so it is simply nullable.
--
-- slug is UNIQUE (/projects/{slug} = one case study) and VARCHAR(191) to
-- keep the utf8mb4 unique index under InnoDB's legacy 767-byte prefix
-- limit on old shared-host MySQL. No foreign keys anywhere.

CREATE TABLE IF NOT EXISTS projects (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title            VARCHAR(255) NOT NULL,
    slug             VARCHAR(191) NOT NULL,
    client_name      VARCHAR(255) NULL,
    summary          TEXT NULL,
    description      MEDIUMTEXT NULL,
    featured_image   VARCHAR(255) NULL,
    gallery_images   JSON NULL,
    project_url      VARCHAR(255) NULL,
    completed_at     DATE NULL,
    meta_title       VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_projects_slug (slug),
    KEY idx_projects_completed_at (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
