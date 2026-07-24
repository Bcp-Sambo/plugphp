-- contact-form module — contact_submissions table
--
-- Schema per modules/contact-form/SKILL.md. Doubles as the IP rate-limit
-- store: the handler counts recent rows for the submitter's IP before
-- inserting a new one, so (ip_address, created_at) is indexed to keep that
-- COUNT cheap. ip_address is VARCHAR(45) to hold a full IPv6 address.

CREATE TABLE IF NOT EXISTS contact_submissions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    message    TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
