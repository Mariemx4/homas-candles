-- Catalogue configuration and immutable sale explanations; reuse existing orders/items/materials.
CREATE TABLE product_quantity_prices (
    id BIGSERIAL PRIMARY KEY,
    product_id NUMERIC(20,0) NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    minimum_quantity INTEGER NOT NULL CHECK (minimum_quantity BETWEEN 1 AND 1000000),
    maximum_quantity INTEGER NULL CHECK (maximum_quantity BETWEEN 1 AND 1000000),
    unit_price DECIMAL(12,3) NOT NULL CHECK (unit_price > 0),
    is_active SMALLINT NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    CHECK (maximum_quantity IS NULL OR maximum_quantity >= minimum_quantity),
    UNIQUE (product_id, minimum_quantity)
);
-- Serialize configuration on the product row and reject ambiguous ranges in the database too.
CREATE FUNCTION guard_quantity_price_range() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM id FROM products WHERE id = NEW.product_id FOR UPDATE;
    IF NEW.is_active = 1 AND EXISTS (
        SELECT 1 FROM product_quantity_prices p WHERE p.product_id = NEW.product_id
          AND p.id <> NEW.id AND p.is_active = 1
          AND p.minimum_quantity <= COALESCE(NEW.maximum_quantity, 1000000)
          AND NEW.minimum_quantity <= COALESCE(p.maximum_quantity, 1000000)
    ) THEN RAISE EXCEPTION 'Quantity price ranges must not overlap.'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER trg_quantity_price_range BEFORE INSERT OR UPDATE ON product_quantity_prices
FOR EACH ROW EXECUTE FUNCTION guard_quantity_price_range();

CREATE TABLE promotion_codes (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(40) COLLATE "C" NOT NULL UNIQUE CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{1,39}$'),
    discount_type VARCHAR(20) NOT NULL CHECK (discount_type IN ('PERCENTAGE','FIXED_TND')),
    discount_value DECIMAL(12,3) NOT NULL CHECK (discount_value > 0),
    is_active SMALLINT NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    valid_from DATE NULL,
    valid_until DATE NULL,
    minimum_subtotal DECIMAL(12,3) NOT NULL DEFAULT 0 CHECK (minimum_subtotal >= 0),
    CHECK (discount_type <> 'PERCENTAGE' OR discount_value <= 100),
    CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)
);

ALTER TABLE orders
    ADD COLUMN promo_code_snapshot VARCHAR(40) NULL,
    ADD COLUMN promo_type_snapshot VARCHAR(20) NULL,
    ADD COLUMN promo_value_snapshot DECIMAL(12,3) NULL,
    ADD COLUMN promo_discount_amount DECIMAL(12,3) NOT NULL DEFAULT 0 CHECK (promo_discount_amount >= 0),
    ADD COLUMN manual_discount_type VARCHAR(20) NULL,
    ADD COLUMN manual_discount_value DECIMAL(12,3) NULL,
    ADD COLUMN manual_discount_reason VARCHAR(500) NULL,
    ADD COLUMN manual_discount_amount DECIMAL(12,3) NOT NULL DEFAULT 0 CHECK (manual_discount_amount >= 0),
    ADD COLUMN quantity_savings_snapshot DECIMAL(12,3) NOT NULL DEFAULT 0 CHECK (quantity_savings_snapshot >= 0);
ALTER TABLE orders DROP CONSTRAINT chk_orders_total;
ALTER TABLE orders ADD CONSTRAINT chk_orders_total CHECK (
    promo_discount_amount + manual_discount_amount <= subtotal
    AND total_amount = subtotal - promo_discount_amount - manual_discount_amount + delivery_fee
);
ALTER TABLE order_items
    ADD COLUMN catalogue_unit_price_snapshot DECIMAL(12,3) NULL CHECK (catalogue_unit_price_snapshot >= 0),
    ADD COLUMN quantity_unit_price_snapshot DECIMAL(12,3) NULL CHECK (quantity_unit_price_snapshot >= 0),
    ADD COLUMN quantity_tier_snapshot VARCHAR(100) NULL,
    ADD COLUMN price_override_reason VARCHAR(500) NULL,
    ADD COLUMN recipe_source VARCHAR(20) NULL CHECK (recipe_source IN ('CATALOGUE','CUSTOM'));

-- Catalogue snapshots cannot be replaced by the exceptional one-off recipe editor.
CREATE FUNCTION protect_catalogue_recipe_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_items WHERE id = OLD.order_item_id AND recipe_source = 'CATALOGUE') THEN
        RAISE EXCEPTION 'The catalogue recipe captured at sale cannot be changed.';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER trg_catalogue_recipe_snapshot BEFORE UPDATE OR DELETE ON order_item_materials
FOR EACH ROW EXECUTE FUNCTION protect_catalogue_recipe_snapshot();

CREATE FUNCTION protect_sale_pricing_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.catalogue_unit_price_snapshot IS DISTINCT FROM OLD.catalogue_unit_price_snapshot
       OR NEW.quantity_unit_price_snapshot IS DISTINCT FROM OLD.quantity_unit_price_snapshot
       OR NEW.quantity_tier_snapshot IS DISTINCT FROM OLD.quantity_tier_snapshot
       OR NEW.price_override_reason IS DISTINCT FROM OLD.price_override_reason
       OR NEW.recipe_source IS DISTINCT FROM OLD.recipe_source THEN
        RAISE EXCEPTION 'Sale pricing and recipe audit snapshots cannot be changed.';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER trg_sale_pricing_audit BEFORE UPDATE ON order_items
FOR EACH ROW EXECUTE FUNCTION protect_sale_pricing_audit();

CREATE FUNCTION protect_order_discount_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.price_locked_at IS NOT NULL AND (
        NEW.promo_code_snapshot IS DISTINCT FROM OLD.promo_code_snapshot OR
        NEW.promo_type_snapshot IS DISTINCT FROM OLD.promo_type_snapshot OR
        NEW.promo_value_snapshot IS DISTINCT FROM OLD.promo_value_snapshot OR
        NEW.promo_discount_amount IS DISTINCT FROM OLD.promo_discount_amount OR
        NEW.manual_discount_type IS DISTINCT FROM OLD.manual_discount_type OR
        NEW.manual_discount_value IS DISTINCT FROM OLD.manual_discount_value OR
        NEW.manual_discount_reason IS DISTINCT FROM OLD.manual_discount_reason OR
        NEW.manual_discount_amount IS DISTINCT FROM OLD.manual_discount_amount OR
        NEW.quantity_savings_snapshot IS DISTINCT FROM OLD.quantity_savings_snapshot
    ) THEN RAISE EXCEPTION 'The agreed discounts of this order are locked.'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER trg_order_discount_audit BEFORE UPDATE ON orders
FOR EACH ROW EXECUTE FUNCTION protect_order_discount_audit();
