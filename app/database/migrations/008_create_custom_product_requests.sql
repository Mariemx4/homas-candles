-- FILE PURPOSE: Database definition or seed data for 008 Create Custom Product Requests.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: a "make me something custom" inquiry, before it becomes a real order.
-- WHY:  separate from `orders` because at this stage there's no agreed price
--       or product yet — it's a conversation. An admin later either
--       converts it into a real order (see chk_custom_requests_conversion
--       below) or declines/cancels it.
-- SECTION PURPOSE: Define the `custom_product_requests` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS custom_product_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_number VARCHAR(30) NOT NULL,

    -- Nullable because visitors may request a callback without an account
    customer_id BIGINT UNSIGNED DEFAULT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED DEFAULT NULL,

    -- Contact information frozen at the time of the request
    customer_name_snapshot VARCHAR(201) NOT NULL,
    customer_email_snapshot VARCHAR(255) DEFAULT NULL,
    customer_phone_snapshot CHAR(8) NOT NULL,
    preferred_contact_method VARCHAR(20) NOT NULL,

    -- Initial preferences; final agreements are stored on the order
    budget DECIMAL(12,3) DEFAULT NULL,
    requested_quantity INT UNSIGNED DEFAULT NULL,
    required_date DATE DEFAULT NULL,
    color_preferences VARCHAR(500) DEFAULT NULL,
    scent_preferences VARCHAR(500) DEFAULT NULL,
    packaging_preferences VARCHAR(500) DEFAULT NULL,
    customer_message TEXT DEFAULT NULL,

    request_status VARCHAR(20) NOT NULL DEFAULT 'SUBMITTED',
    assigned_administrator_id BIGINT UNSIGNED DEFAULT NULL,

    -- Filled only when an administrator converts the agreement into an order
    converted_order_id BIGINT UNSIGNED DEFAULT NULL,
    converted_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_custom_requests_number (request_number),
    UNIQUE KEY uq_custom_requests_order (converted_order_id),
    KEY idx_custom_requests_customer (customer_id),
    KEY idx_custom_requests_category (category_id),
    KEY idx_custom_requests_product (product_id),
    KEY idx_custom_requests_status (request_status),
    KEY idx_custom_requests_administrator (assigned_administrator_id),
    KEY idx_custom_requests_required_date (required_date),

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
        CHECK (customer_phone_snapshot REGEXP '^[0-9]{8}$'),

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
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
