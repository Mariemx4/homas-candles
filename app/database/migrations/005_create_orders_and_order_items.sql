CREATE SEQUENCE IF NOT EXISTS orders_id_seq;
CREATE SEQUENCE IF NOT EXISTS order_items_id_seq;

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
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('orders_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    order_number VARCHAR(30) COLLATE homas_unicode_ci NOT NULL,

    -- Nullable for administrator-created orders made for non-account customers
    customer_id NUMERIC(20,0) DEFAULT NULL CHECK (customer_id BETWEEN 0 AND 18446744073709551615),
    created_by_administrator_id NUMERIC(20,0) DEFAULT NULL CHECK (created_by_administrator_id BETWEEN 0 AND 18446744073709551615),

    language_code CHAR(2) COLLATE homas_unicode_ci NOT NULL DEFAULT 'en',
    order_source VARCHAR(20) COLLATE homas_unicode_ci NOT NULL DEFAULT 'CART',
    order_status VARCHAR(30) COLLATE homas_unicode_ci NOT NULL DEFAULT 'REQUESTED',
    delivery_method VARCHAR(20) COLLATE homas_unicode_ci NOT NULL,
    required_date DATE NOT NULL,

    -- Customer information frozen for this order
    customer_name_snapshot VARCHAR(201) COLLATE homas_unicode_ci NOT NULL,
    customer_email_snapshot VARCHAR(255) COLLATE homas_unicode_ci DEFAULT NULL,
    customer_phone_snapshot CHAR(8) COLLATE homas_unicode_ci NOT NULL,

    -- Delivery address frozen for this order
      street_address_snapshot VARCHAR(255) COLLATE homas_unicode_ci DEFAULT NULL,
    delegation_snapshot VARCHAR(100) COLLATE homas_unicode_ci DEFAULT NULL,
    governorate_snapshot VARCHAR(100) COLLATE homas_unicode_ci DEFAULT NULL,
    postal_code_snapshot CHAR(4) COLLATE homas_unicode_ci DEFAULT NULL,

    subtotal DECIMAL(12,3) NOT NULL,
    delivery_fee DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    total_amount DECIMAL(12,3) NOT NULL,

    -- Frozen minimum payment required before confirmation
    required_deposit_amount DECIMAL(12,3) NOT NULL DEFAULT 0.000,


    -- Set when the agreed price becomes permanently locked
    price_locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,

    customer_notes TEXT COLLATE homas_unicode_ci DEFAULT NULL,
    administrator_notes TEXT COLLATE homas_unicode_ci DEFAULT NULL,

    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    CONSTRAINT uq_orders_order_number UNIQUE (order_number),

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
        CHECK (customer_phone_snapshot COLLATE "C" ~ '^[0-9]{8}$'),

    CONSTRAINT chk_orders_postal_code
        CHECK (
            postal_code_snapshot IS NULL
            OR postal_code_snapshot COLLATE "C" ~ '^[0-9]{4}$'
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
);

CREATE INDEX IF NOT EXISTS idx_orders_customer ON orders (customer_id);
CREATE INDEX IF NOT EXISTS idx_orders_administrator ON orders (created_by_administrator_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (order_status);
CREATE INDEX IF NOT EXISTS idx_orders_required_date ON orders (required_date);
CREATE INDEX IF NOT EXISTS fk_orders_language_idx ON orders (language_code);


-- WHAT: one row per product inside an order (an order usually has several).
-- WHY:  same snapshot idea as `orders` — product_name_snapshot,
--       unit_price_snapshot etc. freeze what was actually agreed, so this
--       row still makes sense even if the product is later renamed, its
--       price changes, or it's deleted entirely (product_id goes NULL).
CREATE TABLE IF NOT EXISTS order_items (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('order_items_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    order_id NUMERIC(20,0) NOT NULL CHECK (order_id BETWEEN 0 AND 18446744073709551615),

    -- Nullable so historical items survive product removal
    product_id NUMERIC(20,0) DEFAULT NULL CHECK (product_id BETWEEN 0 AND 18446744073709551615),

    -- Product information frozen for this order
    product_name_snapshot VARCHAR(180) COLLATE homas_unicode_ci NOT NULL,

    selected_color_id NUMERIC(20,0) DEFAULT NULL CHECK (selected_color_id BETWEEN 0 AND 18446744073709551615),
    color_name_snapshot VARCHAR(150) COLLATE homas_unicode_ci DEFAULT NULL,

    selected_scent_id NUMERIC(20,0) DEFAULT NULL CHECK (selected_scent_id BETWEEN 0 AND 18446744073709551615),
    scent_name_snapshot VARCHAR(150) COLLATE homas_unicode_ci DEFAULT NULL,

    quantity BIGINT NOT NULL CHECK (quantity BETWEEN 0 AND 4294967295),

    -- Agreed financial values frozen for this order
    unit_price_snapshot DECIMAL(12,3) NOT NULL,
    unit_cost_snapshot DECIMAL(12,3) DEFAULT NULL,
    line_total_snapshot DECIMAL(12,3) NOT NULL,

    customization_notes TEXT COLLATE homas_unicode_ci DEFAULT NULL,

    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

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
);

CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items (order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_product ON order_items (product_id);
CREATE INDEX IF NOT EXISTS idx_order_items_color ON order_items (selected_color_id);
CREATE INDEX IF NOT EXISTS idx_order_items_scent ON order_items (selected_scent_id);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE orders_id_seq OWNED BY orders.id;
ALTER SEQUENCE order_items_id_seq OWNED BY order_items.id;

-- PostgreSQL equivalent of ON UPDATE CURRENT_TIMESTAMP; explicit timestamp overrides remain valid.
CREATE OR REPLACE FUNCTION orders_touch_updated_at_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
        NEW.updated_at := statement_timestamp();
    END IF;
    RETURN NEW;
END;
$homas$;
DROP TRIGGER IF EXISTS zz_orders_touch_updated_at ON orders;
CREATE TRIGGER zz_orders_touch_updated_at BEFORE UPDATE ON orders
FOR EACH ROW EXECUTE FUNCTION orders_touch_updated_at_fn();

-- PostgreSQL equivalent of ON UPDATE CURRENT_TIMESTAMP; explicit timestamp overrides remain valid.
CREATE OR REPLACE FUNCTION order_items_touch_updated_at_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
        NEW.updated_at := statement_timestamp();
    END IF;
    RETURN NEW;
END;
$homas$;
DROP TRIGGER IF EXISTS zz_order_items_touch_updated_at ON order_items;
CREATE TRIGGER zz_order_items_touch_updated_at BEFORE UPDATE ON order_items
FOR EACH ROW EXECUTE FUNCTION order_items_touch_updated_at_fn();
