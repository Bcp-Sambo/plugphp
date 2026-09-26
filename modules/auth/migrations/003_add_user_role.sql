-- Two roles: 'admin' (full access) and 'editor' (content modules only).
--
-- DEFAULT 'admin' is deliberate. Every user that exists when this runs was
-- created before roles existed and already had full access, so defaulting to
-- admin preserves exactly what they could do. Defaulting to 'editor' would
-- silently lock every existing site out of its own Settings and Updates.
ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin';
