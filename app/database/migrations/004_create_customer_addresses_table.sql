CREATE SEQUENCE IF NOT EXISTS customer_addresses_id_seq;

-- WHAT: saved delivery addresses for a customer (a customer can have several).
-- WHY:  kept separate from customers_accounts instead of adding
--       street/governorate columns there, since one customer needs to store
--       more than one address (e.g. Home and Work) and pick one per order.
CREATE TABLE IF NOT EXISTS customer_addresses (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('customer_addresses_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    customer_id NUMERIC(20,0) NOT NULL CHECK (customer_id BETWEEN 0 AND 18446744073709551615),

    -- Optional customer-facing name, such as Home or Work
    address_label VARCHAR(50) COLLATE homas_unicode_ci DEFAULT NULL,

    street_address VARCHAR(255) COLLATE homas_unicode_ci NOT NULL,
    delegation VARCHAR(100) COLLATE homas_unicode_ci NOT NULL,
    governorate VARCHAR(100) COLLATE homas_unicode_ci NOT NULL,
    postal_code CHAR(4) COLLATE homas_unicode_ci NOT NULL,

    -- WHAT: marks which of a customer's addresses is the default one.
    -- WHY:  lets checkout preselect an address without asking every time;
    --       nothing here stops more than one row being marked primary — that
    --       has to be enforced in PHP when saving (unset the old one first).
    is_primary SMALLINT NOT NULL DEFAULT 0,

    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    CONSTRAINT fk_customer_addresses_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers_accounts (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    -- WHAT: postal_code must be exactly 4 digits.
    -- WHY:  Tunisian postal codes are 4 digits; this same pattern is reused
    --       on the `orders` table's frozen address snapshot (migration 005).
    CONSTRAINT chk_customer_addresses_postal_code
        CHECK (postal_code COLLATE "C" ~ '^[0-9]{4}$'),

    CONSTRAINT chk_customer_addresses_is_primary
        CHECK (is_primary IN (0, 1))
);

CREATE INDEX IF NOT EXISTS idx_customer_addresses_customer ON customer_addresses (customer_id);
CREATE INDEX IF NOT EXISTS idx_customer_addresses_location ON customer_addresses (governorate, postal_code);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE customer_addresses_id_seq OWNED BY customer_addresses.id;

-- PostgreSQL equivalent of ON UPDATE CURRENT_TIMESTAMP; explicit timestamp overrides remain valid.
CREATE OR REPLACE FUNCTION customer_addresses_touch_updated_at_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
        NEW.updated_at := statement_timestamp();
    END IF;
    RETURN NEW;
END;
$homas$;
DROP TRIGGER IF EXISTS zz_customer_addresses_touch_updated_at ON customer_addresses;
CREATE TRIGGER zz_customer_addresses_touch_updated_at BEFORE UPDATE ON customer_addresses
FOR EACH ROW EXECUTE FUNCTION customer_addresses_touch_updated_at_fn();
