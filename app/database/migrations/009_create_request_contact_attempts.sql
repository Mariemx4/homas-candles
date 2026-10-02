CREATE SEQUENCE IF NOT EXISTS request_contact_attempts_id_seq;

-- WHAT: a log of each time an admin followed up on a custom_product_request.
-- WHY:  a single request (008) can take several calls/messages before it's
--       AGREED or DECLINED — this table is the timeline of those attempts,
--       separate from the request itself so nothing gets overwritten.
CREATE TABLE IF NOT EXISTS request_contact_attempts (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('request_contact_attempts_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    request_id NUMERIC(20,0) NOT NULL CHECK (request_id BETWEEN 0 AND 18446744073709551615),

    -- May become NULL if an old administrator account is removed
    administrator_id NUMERIC(20,0) DEFAULT NULL CHECK (administrator_id BETWEEN 0 AND 18446744073709551615),

    contact_method VARCHAR(20) COLLATE homas_unicode_ci NOT NULL,
    contact_outcome VARCHAR(30) COLLATE homas_unicode_ci NOT NULL,
    contact_notes TEXT COLLATE homas_unicode_ci DEFAULT NULL,

    contacted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),
    next_follow_up_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,

    PRIMARY KEY (id),

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
);

CREATE INDEX IF NOT EXISTS idx_request_contacts_request_time ON request_contact_attempts (request_id, contacted_at);
CREATE INDEX IF NOT EXISTS idx_request_contacts_administrator ON request_contact_attempts (administrator_id);
CREATE INDEX IF NOT EXISTS idx_request_contacts_follow_up ON request_contact_attempts (next_follow_up_at);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE request_contact_attempts_id_seq OWNED BY request_contact_attempts.id;
