-- WHAT: saved delivery addresses for a customer (a customer can have several).
-- WHY:  kept separate from customers_accounts instead of adding
--       street/governorate columns there, since one customer needs to store
--       more than one address (e.g. Home and Work) and pick one per order.
CREATE TABLE IF NOT EXISTS customer_addresses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,

    -- Optional customer-facing name, such as Home or Work
    address_label VARCHAR(50) DEFAULT NULL,

    street_address VARCHAR(255) NOT NULL,
    delegation VARCHAR(100) NOT NULL,
    governorate VARCHAR(100) NOT NULL,
    postal_code CHAR(4) NOT NULL,

    -- WHAT: marks which of a customer's addresses is the default one.
    -- WHY:  lets checkout preselect an address without asking every time;
    --       nothing here stops more than one row being marked primary — that
    --       has to be enforced in PHP when saving (unset the old one first).
    is_primary TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_customer_addresses_customer (customer_id),
    KEY idx_customer_addresses_location (governorate, postal_code),

    CONSTRAINT fk_customer_addresses_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers_accounts (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    -- WHAT: postal_code must be exactly 4 digits.
    -- WHY:  Tunisian postal codes are 4 digits; this same pattern is reused
    --       on the `orders` table's frozen address snapshot (migration 005).
    CONSTRAINT chk_customer_addresses_postal_code
        CHECK (postal_code REGEXP '^[0-9]{4}$'),

    CONSTRAINT chk_customer_addresses_is_primary
        CHECK (is_primary IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
