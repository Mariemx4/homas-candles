-- WHAT: a placed order, and the individual products inside it.
-- WHY:  this is the table an in-progress cart eventually becomes — there's
--       no separate "cart" table, because a cart is really just an order
--       with order_status = 'REQUESTED' and order_source = 'CART'.
--
--       The "_snapshot" columns all over this file are the most important
--       idea here: they COPY the customer's name/phone/address and the
--       product's name/price at the moment of the order, instead of just
--       pointing at customers_accounts/products. That's deliberate — if a
--       customer edits their address next week, or a product's price
--       changes next month, this order must still show exactly what was
--       agreed on the day it was placed.
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number VARCHAR(30) NOT NULL,

    -- Nullable for administrator-created orders made for non-account customers
    customer_id BIGINT UNSIGNED DEFAULT NULL,
    created_by_administrator_id BIGINT UNSIGNED DEFAULT NULL,

    language_code CHAR(2) NOT NULL DEFAULT 'en',
    order_source VARCHAR(20) NOT NULL DEFAULT 'CART',
    order_status VARCHAR(30) NOT NULL DEFAULT 'REQUESTED',
    delivery_method VARCHAR(20) NOT NULL,
    required_date DATE NOT NULL,

    -- Customer information frozen for this order
    customer_name_snapshot VARCHAR(201) NOT NULL,
    customer_email_snapshot VARCHAR(255) DEFAULT NULL,
    customer_phone_snapshot CHAR(8) NOT NULL,

    -- Delivery address frozen for this order
      street_address_snapshot VARCHAR(255) DEFAULT NULL,
    delegation_snapshot VARCHAR(100) DEFAULT NULL,
    governorate_snapshot VARCHAR(100) DEFAULT NULL,
    postal_code_snapshot CHAR(4) DEFAULT NULL,

    subtotal DECIMAL(12,3) NOT NULL,
    delivery_fee DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    total_amount DECIMAL(12,3) NOT NULL,

    -- Frozen minimum payment required before confirmation
    required_deposit_amount DECIMAL(12,3) NOT NULL DEFAULT 0.000,


    -- Set when the agreed price becomes permanently locked
    price_locked_at DATETIME DEFAULT NULL,

    customer_notes TEXT DEFAULT NULL,
    administrator_notes TEXT DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_orders_order_number (order_number),
    KEY idx_orders_customer (customer_id),
    KEY idx_orders_administrator (created_by_administrator_id),
    KEY idx_orders_status (order_status),
    KEY idx_orders_required_date (required_date),

    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_orders_administrator
        FOREIGN KEY (created_by_administrator_id)
        REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_orders_language
        FOREIGN KEY (language_code)
        REFERENCES languages (code)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_orders_source
        CHECK (order_source IN ('CART', 'MANUAL')),

    -- WHAT: every stage an order can be in, from placed to delivered.
    -- WHY:  this exact list is repeated in order_status_history (migration
    --       006) below for previous_status/new_status — if you ever add a
    --       status (e.g. CANCELLED), all three CHECKs need updating together.
    CONSTRAINT chk_orders_status
        CHECK (
            order_status IN (
                'REQUESTED',
                'CONFIRMED',
                'PREPARING',
                'PREPARED',
                'WAITING_FOR_PICKUP',
                'PICKED_UP',
                'IN_DELIVERY',
                'DELIVERED'
            )
        ),

    CONSTRAINT chk_orders_delivery_method
        CHECK (delivery_method IN ('DELIVERY', 'PICKUP')),

    CONSTRAINT chk_orders_customer_phone
        CHECK (customer_phone_snapshot REGEXP '^[0-9]{8}$'),

    CONSTRAINT chk_orders_postal_code
        CHECK (
            postal_code_snapshot IS NULL
            OR postal_code_snapshot REGEXP '^[0-9]{4}$'
        ),

    -- WHAT: a DELIVERY order must have a full address; PICKUP doesn't need one.
    -- WHY:  conditional CHECK — the rule only applies "when delivery_method
    --       is DELIVERY", so PICKUP orders are free to leave these NULL.
    CONSTRAINT chk_orders_delivery_address
        CHECK (
            delivery_method <> 'DELIVERY'
            OR (
                street_address_snapshot IS NOT NULL
                AND delegation_snapshot IS NOT NULL
                AND governorate_snapshot IS NOT NULL
                AND postal_code_snapshot IS NOT NULL
            )
        ),

    CONSTRAINT chk_orders_subtotal
        CHECK (subtotal >= 0),

    -- WHAT: PICKUP orders pay no delivery fee; DELIVERY orders pay >= 9 TND.
    -- WHY:  same conditional-CHECK idea as the address rule above — without
    --       this, a PICKUP order could still be charged a delivery fee.
    CONSTRAINT chk_orders_delivery_fee
        CHECK (
            (delivery_method = 'PICKUP' AND delivery_fee = 0)
            OR (delivery_method = 'DELIVERY' AND delivery_fee >= 9)
        ),

    CONSTRAINT chk_orders_total
        CHECK (total_amount = subtotal + delivery_fee),

    -- WHAT: enforces the 40% deposit rule for orders of 100 TND or more.
    -- WHY:  business rule from docs/database-requirements.md — big orders
    --       need a partial payment upfront before they're confirmed.
    CONSTRAINT chk_orders_required_deposit
        CHECK (
            required_deposit_amount >= 0
            AND required_deposit_amount <= total_amount
            AND (
                total_amount < 100.000
                OR required_deposit_amount >= ROUND(total_amount * 0.400, 3)
            )
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- WHAT: one row per product inside an order (an order usually has several).
-- WHY:  same snapshot idea as `orders` — product_name_snapshot,
--       unit_price_snapshot etc. freeze what was actually agreed, so this
--       row still makes sense even if the product is later renamed, its
--       price changes, or it's deleted entirely (product_id goes NULL).
CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,

    -- Nullable so historical items survive product removal
    product_id BIGINT UNSIGNED DEFAULT NULL,

    -- Product information frozen for this order
    product_name_snapshot VARCHAR(180) NOT NULL,

    selected_color_id BIGINT UNSIGNED DEFAULT NULL,
    color_name_snapshot VARCHAR(150) DEFAULT NULL,

    selected_scent_id BIGINT UNSIGNED DEFAULT NULL,
    scent_name_snapshot VARCHAR(150) DEFAULT NULL,

    quantity INT UNSIGNED NOT NULL,

    -- Agreed financial values frozen for this order
    unit_price_snapshot DECIMAL(12,3) NOT NULL,
    unit_cost_snapshot DECIMAL(12,3) DEFAULT NULL,
    line_total_snapshot DECIMAL(12,3) NOT NULL,

    customization_notes TEXT DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_order_items_order (order_id),
    KEY idx_order_items_product (product_id),
    KEY idx_order_items_color (selected_color_id),
    KEY idx_order_items_scent (selected_scent_id),

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id)
        REFERENCES orders (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id)
        REFERENCES products (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_order_items_color
        FOREIGN KEY (selected_color_id)
        REFERENCES colors (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_order_items_scent
        FOREIGN KEY (selected_scent_id)
        REFERENCES scents (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT chk_order_items_quantity
        CHECK (quantity > 0),

    CONSTRAINT chk_order_items_unit_price
        CHECK (unit_price_snapshot >= 0),

    CONSTRAINT chk_order_items_unit_cost
        CHECK (
            unit_cost_snapshot IS NULL
            OR unit_cost_snapshot >= 0
        ),

    CONSTRAINT chk_order_items_line_total
        CHECK (line_total_snapshot >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
