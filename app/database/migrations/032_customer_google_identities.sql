-- A provider identity is separate from a customer's editable contact email.
-- Subject IDs are case-sensitive and stable; emails must never authenticate a link.
CREATE TABLE customer_google_identities (
    google_subject VARCHAR(255) COLLATE "C" PRIMARY KEY,
    customer_id NUMERIC(20,0) NOT NULL UNIQUE
        REFERENCES customers_accounts(id) ON DELETE CASCADE,
    email_at_link VARCHAR(255) NOT NULL,
    linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT (statement_timestamp() AT TIME ZONE 'UTC'),
    last_used_at TIMESTAMP(0) WITHOUT TIME ZONE,
    CHECK (length(google_subject) BETWEEN 1 AND 255)
);

COMMENT ON TABLE customer_google_identities IS
    'Verified Google subject to customer mapping. No access, refresh or identity tokens are stored.';
