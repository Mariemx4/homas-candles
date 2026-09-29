-- FILE PURPOSE: Database definition or seed data for 009 Create Request Contact Attempts.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: a log of each time an admin followed up on a custom_product_request.
-- WHY:  a single request (008) can take several calls/messages before it's
--       AGREED or DECLINED — this table is the timeline of those attempts,
--       separate from the request itself so nothing gets overwritten.
-- SECTION PURPOSE: Define the `request_contact_attempts` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS request_contact_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NOT NULL,

    -- May become NULL if an old administrator account is removed
    administrator_id BIGINT UNSIGNED DEFAULT NULL,

    contact_method VARCHAR(20) NOT NULL,
    contact_outcome VARCHAR(30) NOT NULL,
    contact_notes TEXT DEFAULT NULL,

    contacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_follow_up_at DATETIME DEFAULT NULL,

    PRIMARY KEY (id),

    KEY idx_request_contacts_request_time (request_id, contacted_at),
    KEY idx_request_contacts_administrator (administrator_id),
    KEY idx_request_contacts_follow_up (next_follow_up_at),

    CONSTRAINT fk_request_contacts_request
        FOREIGN KEY (request_id)
        REFERENCES custom_product_requests (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_request_contacts_administrator
        FOREIGN KEY (administrator_id)
        REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT chk_request_contacts_method
        CHECK (
            contact_method IN (
                'PHONE',
                'WHATSAPP',
                'INSTAGRAM',
                'FACEBOOK',
                'TIKTOK',
                'EMAIL'
            )
        ),

    CONSTRAINT chk_request_contacts_outcome
        CHECK (
            contact_outcome IN (
                'NO_ANSWER',
                'CONTACTED',
                'FOLLOW_UP_REQUIRED',
                'AGREEMENT_REACHED',
                'DECLINED'
            )
        ),

    -- WHAT: a scheduled follow-up must be in the future relative to this attempt.
    CONSTRAINT chk_request_contacts_follow_up_time
        CHECK (
            next_follow_up_at IS NULL
            OR next_follow_up_at > contacted_at
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
