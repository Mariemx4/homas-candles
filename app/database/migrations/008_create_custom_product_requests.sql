CREATE SEQUENCE IF NOT EXISTS custom_product_requests_id_seq;

-- WHAT: a "make me something custom" inquiry, before it becomes a real order.
-- WHY:  separate from `orders` because at this stage there's no agreed price
--       or product yet — it's a conversation. An admin later either
--       converts it into a real order (see chk_custom_requests_conversion
--       below) or declines/cancels it.
CREATE TABLE IF NOT EXISTS custom_product_requests (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('custom_product_requests_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    request_number VARCHAR(30) COLLATE homas_unicode_ci NOT NULL,

    -- Nullable because visitors may request a callback without an account
    customer_id NUMERIC(20,0) DEFAULT NULL CHECK (customer_id BETWEEN 0 AND 18446744073709551615),
    category_id NUMERIC(20,0) NOT NULL CHECK (category_id BETWEEN 0 AND 18446744073709551615),
    product_id NUMERIC(20,0) DEFAULT NULL CHECK (product_id BETWEEN 0 AND 18446744073709551615),

    -- Contact information frozen at the time of the request
    customer_name_snapshot VARCHAR(201) COLLATE homas_unicode_ci NOT NULL,
    customer_email_snapshot VARCHAR(255) COLLATE homas_unicode_ci DEFAULT NULL,
    customer_phone_snapshot CHAR(8) COLLATE homas_unicode_ci NOT NULL,
    preferred_contact_method VARCHAR(20) COLLATE homas_unicode_ci NOT NULL,

    -- Initial preferences; final agreements are stored on the order
    budget DECIMAL(12,3) DEFAULT NULL,
    requested_quantity BIGINT DEFAULT NULL CHECK (requested_quantity BETWEEN 0 AND 4294967295),
    required_date DATE DEFAULT NULL,
    color_preferences VARCHAR(500) COLLATE homas_unicode_ci DEFAULT NULL,
    scent_preferences VARCHAR(500) COLLATE homas_unicode_ci DEFAULT NULL,
    packaging_preferences VARCHAR(500) COLLATE homas_unicode_ci DEFAULT NULL,
    customer_message TEXT COLLATE homas_unicode_ci DEFAULT NULL,

    request_status VARCHAR(20) COLLATE homas_unicode_ci NOT NULL DEFAULT 'SUBMITTED',
    assigned_administrator_id NUMERIC(20,0) DEFAULT NULL CHECK (assigned_administrator_id BETWEEN 0 AND 18446744073709551615),

    -- Filled only when an administrator converts the agreement into an order
    converted_order_id NUMERIC(20,0) DEFAULT NULL CHECK (converted_order_id BETWEEN 0 AND 18446744073709551615),
    converted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,

    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    CONSTRAINT uq_custom_requests_number UNIQUE (request_number),
    CONSTRAINT uq_custom_requests_order UNIQUE (converted_order_id),

    CONSTRAINT fk_custom_requests_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_custom_requests_category
        FOREIGN KEY (category_id)
        REFERENCES categories (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_custom_requests_product
        FOREIGN KEY (product_id)
        REFERENCES products (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_custom_requests_administrator
        FOREIGN KEY (assigned_administrator_id)
        REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_custom_requests_order
        FOREIGN KEY (converted_order_id)
        REFERENCES orders (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_custom_requests_phone
        CHECK (customer_phone_snapshot COLLATE "C" ~ '^[0-9]{8}$'),

    CONSTRAINT chk_custom_requests_contact_method
        CHECK (
            preferred_contact_method IN (
                'PHONE',
                'WHATSAPP',
                'INSTAGRAM',
                'FACEBOOK',
                'TIKTOK',
                'EMAIL'
            )
        ),

    CONSTRAINT chk_custom_requests_budget
        CHECK (budget IS NULL OR budget > 0),

    CONSTRAINT chk_custom_requests_quantity
        CHECK (requested_quantity IS NULL OR requested_quantity > 0),

    CONSTRAINT chk_custom_requests_status
        CHECK (
            request_status IN (
                'SUBMITTED',
                'CONTACTED',
                'DISCUSSING',
                'AGREED',
                'CONVERTED',
                'DECLINED',
                'CANCELLED'
            )
        ),

    -- WHAT: converted_order_id/converted_at exist together, only once, and
    --       only when the request has actually reached CONVERTED.
    -- WHY:  ties the request_status value to the columns that record the
    --       conversion, so the two can't drift out of sync with each other.
    CONSTRAINT chk_custom_requests_conversion
        CHECK (
            (
                request_status = 'CONVERTED'
                AND converted_order_id IS NOT NULL
                AND converted_at IS NOT NULL
            )
            OR
            (
                request_status <> 'CONVERTED'
                AND converted_order_id IS NULL
                AND converted_at IS NULL
            )
        )
);

CREATE INDEX IF NOT EXISTS idx_custom_requests_customer ON custom_product_requests (customer_id);
CREATE INDEX IF NOT EXISTS idx_custom_requests_category ON custom_product_requests (category_id);
CREATE INDEX IF NOT EXISTS idx_custom_requests_product ON custom_product_requests (product_id);
CREATE INDEX IF NOT EXISTS idx_custom_requests_status ON custom_product_requests (request_status);
CREATE INDEX IF NOT EXISTS idx_custom_requests_administrator ON custom_product_requests (assigned_administrator_id);
CREATE INDEX IF NOT EXISTS idx_custom_requests_required_date ON custom_product_requests (required_date);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE custom_product_requests_id_seq OWNED BY custom_product_requests.id;

-- PostgreSQL equivalent of ON UPDATE CURRENT_TIMESTAMP; explicit timestamp overrides remain valid.
CREATE OR REPLACE FUNCTION custom_product_requests_touch_updated_at_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
        NEW.updated_at := statement_timestamp();
    END IF;
    RETURN NEW;
END;
$homas$;
DROP TRIGGER IF EXISTS zz_custom_product_requests_touch_updated_at ON custom_product_requests;
CREATE TRIGGER zz_custom_product_requests_touch_updated_at BEFORE UPDATE ON custom_product_requests
FOR EACH ROW EXECUTE FUNCTION custom_product_requests_touch_updated_at_fn();
