CREATE SEQUENCE IF NOT EXISTS customer_password_reset_codes_id_seq;

-- WHAT: short-lived, one-time email codes used to reset customer passwords.
-- WHY:  reset secrets must not live on customers_accounts or in plain text;
--       a separate table supports expiry, attempt limits, revocation, and
--       automatic cleanup when the customer account is deleted.
CREATE TABLE IF NOT EXISTS customer_password_reset_codes (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('customer_password_reset_codes_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    customer_id NUMERIC(20,0) NOT NULL CHECK (customer_id BETWEEN 0 AND 18446744073709551615),

    -- password_hash() protects the six-digit code if the database is exposed.
    code_hash VARCHAR(255) COLLATE homas_unicode_ci NOT NULL,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    attempts_remaining SMALLINT NOT NULL DEFAULT 5 CHECK (attempts_remaining BETWEEN 0 AND 255),
    used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    CONSTRAINT fk_password_resets_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers_accounts (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT chk_password_resets_attempts
        CHECK (attempts_remaining <= 5)
);

CREATE INDEX IF NOT EXISTS idx_password_resets_customer_created ON customer_password_reset_codes (customer_id, created_at);
CREATE INDEX IF NOT EXISTS idx_password_resets_expiry ON customer_password_reset_codes (expires_at);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE customer_password_reset_codes_id_seq OWNED BY customer_password_reset_codes.id;
