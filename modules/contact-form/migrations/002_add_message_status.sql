-- Turn the raw submissions log into an inbox.
--
-- status: 'new' (unopened), 'read' (opened), 'replied' (answered from the
-- dashboard). Existing rows default to 'new' — they have never been opened in
-- an inbox that did not exist, so that is accurate rather than merely
-- convenient.
ALTER TABLE contact_submissions
    ADD COLUMN status      VARCHAR(20) NOT NULL DEFAULT 'new',
    ADD COLUMN admin_reply TEXT NULL,
    ADD COLUMN replied_at  DATETIME NULL;
