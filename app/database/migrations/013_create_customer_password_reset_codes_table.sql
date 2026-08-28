-- WHAT: short-lived, one-time email codes used to reset customer passwords.
-- WHY:  reset secrets must not live on customers_accounts or in plain text;
--       a separate table supports expiry, attempt limits, revocation, and
--       automatic cleanup when the customer account is deleted.
CREATE TABLE IF NOT EXISTS customer_password_reset_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,

    -- password_hash() protects the six-digit code if the database is exposed.
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts_remaining TINYINT UNSIGNED NOT NULL DEFAULT 5,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_password_resets_customer_created (customer_id, created_at),
    KEY idx_password_resets_expiry (expires_at),

    CONSTRAINT fk_password_resets_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers_accounts (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT chk_password_resets_attempts
        CHECK (attempts_remaining <= 5)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
