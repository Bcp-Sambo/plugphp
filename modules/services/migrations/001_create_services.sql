-- services module — services table
--
-- Schema per modules/services/SKILL.md. Public listing is ordered by
-- display_order (admin-controlled), so it is indexed. slug is UNIQUE
-- because /services/{slug} resolves to exactly one service.
--
-- `icon` holds an icon-set NAME/CLASS string only (e.g. "bi-gear"), never
-- raw SVG/HTML — see the module SKILL.md; raw markup here would reopen the
-- XSS risk e() exists to prevent.
--
-- slug is VARCHAR(191) to keep the utf8mb4 unique index under InnoDB's
-- legacy 767-byte prefix limit on old shared-host MySQL.

CREATE TABLE IF NOT EXISTS services (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title            VARCHAR(255) NOT NULL,
    slug             VARCHAR(191) NOT NULL,
    summary          TEXT NULL,
    description      MEDIUMTEXT NULL,
    icon             VARCHAR(100) NULL,
    display_order    INT NOT NULL DEFAULT 0,
    meta_title       VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_services_slug (slug),
    KEY idx_services_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
