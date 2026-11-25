-- Candle pricing integration.
--
-- Backward-compatible: adds columns and tables and updates two kinds of data only:
--   * material unit costs for the owner's new purchase prices (stock quantities are not touched);
--   * the candle weight, derived from each candle's existing recipe (sum of its material grams).
-- Existing selling_price values are kept. They are marked 'automatic_incorrect' because they
-- were produced by the earlier cost x 2 / x 2.5 rules, and nothing changes them until the
-- owner approves a suggestion. Completed orders keep their own price and cost snapshots.
-- Historical purchase prices and effective dates are not invented: earlier prices are not
-- recorded and their effective_from stays NULL.

ALTER TABLE products
    ADD COLUMN candle_weight_grams NUMERIC(8, 2) NULL,
    ADD COLUMN selling_price_source VARCHAR(30) NOT NULL DEFAULT 'manual',
    ADD CONSTRAINT chk_products_candle_weight CHECK (candle_weight_grams IS NULL OR candle_weight_grams > 0),
    ADD CONSTRAINT chk_products_selling_price_source CHECK (
        selling_price_source IN ('manual', 'calculated', 'automatic_incorrect')
    );

-- Candles: the weight starts at the recipe total, so the new formula reproduces the recipe's own mass.
UPDATE products p
SET candle_weight_grams = recipe.total_grams
FROM (
    SELECT pm.product_id, SUM(pm.quantity_required) AS total_grams
    FROM product_materials pm
    WHERE pm.recipe_type = 'ACTUAL'
    GROUP BY pm.product_id
) recipe
JOIN categories c ON c.id = (SELECT category_id FROM products WHERE id = recipe.product_id)
WHERE p.id = recipe.product_id
  AND c.slug = 'candles';

-- Prices that exist today were generated automatically by the old rules, so they are flagged.
UPDATE products
SET selling_price_source = 'automatic_incorrect'
WHERE selling_price IS NOT NULL;

CREATE TABLE product_price_history (
    id BIGINT GENERATED ALWAYS AS IDENTITY,
    product_id NUMERIC(20, 0) NOT NULL,
    previous_price NUMERIC(12, 3) NULL,
    new_price NUMERIC(12, 3) NOT NULL,
    previous_source VARCHAR(30) NULL,
    new_source VARCHAR(30) NOT NULL,
    administrator_id NUMERIC(20, 0) NULL,
    changed_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_product_price_history PRIMARY KEY (id),
    CONSTRAINT fk_product_price_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_product_price_history_new_price CHECK (new_price >= 0),
    CONSTRAINT chk_product_price_history_source CHECK (new_source IN ('manual', 'calculated'))
);

CREATE INDEX idx_product_price_history_product ON product_price_history (product_id, changed_at);

CREATE TABLE material_purchase_prices (
    id BIGINT GENERATED ALWAYS AS IDENTITY,
    material_id NUMERIC(20, 0) NOT NULL,
    purchase_price NUMERIC(12, 3) NOT NULL,
    purchase_quantity NUMERIC(12, 3) NOT NULL,
    purchase_unit VARCHAR(30) NOT NULL,
    unit_cost NUMERIC(12, 6) NOT NULL,
    effective_from TIMESTAMP(0) NULL,
    recorded_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) NULL,
    CONSTRAINT pk_material_purchase_prices PRIMARY KEY (id),
    CONSTRAINT fk_material_purchase_prices_material FOREIGN KEY (material_id) REFERENCES materials(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_material_purchase_prices_price CHECK (purchase_price > 0 AND purchase_quantity > 0 AND unit_cost >= 0)
);

CREATE INDEX idx_material_purchase_prices_material ON material_purchase_prices (material_id, recorded_at);

-- Owner's purchase prices. Only the current price is recorded; earlier prices are unknown.
INSERT INTO material_purchase_prices (material_id, purchase_price, purchase_quantity, purchase_unit, unit_cost, note)
SELECT m.id, v.purchase_price, v.purchase_quantity, v.purchase_unit, v.unit_cost,
       'Owner purchase price, 2026-11-23. Earlier purchase prices were not recorded.'
FROM materials m
JOIN (VALUES
    ('SOY-WAX', 40.000, 1000.000, 'kg', 0.040000),
    ('BEESWAX', 50.000, 1000.000, 'kg', 0.050000),
    ('STEARIC-ACID', 22.000, 1000.000, 'kg', 0.022000)
) AS v(sku, purchase_price, purchase_quantity, purchase_unit, unit_cost) ON v.sku = m.sku COLLATE "C";

INSERT INTO material_purchase_prices (material_id, purchase_price, purchase_quantity, purchase_unit, unit_cost, note)
SELECT m.id, 25.000, 100.000, 'ml', 0.250000,
       'Owner purchase price, 2026-11-23. Fragrance is provisional: grams per millilitre not confirmed.'
FROM materials m
WHERE m.sku LIKE 'FRAG-%' COLLATE "C";

UPDATE materials SET unit_cost = 0.040 WHERE sku = 'SOY-WAX' COLLATE "C";
UPDATE materials SET unit_cost = 0.022 WHERE sku = 'STEARIC-ACID' COLLATE "C";
UPDATE materials SET unit_cost = 0.050 WHERE sku = 'BEESWAX' COLLATE "C";
UPDATE materials SET unit_cost = 0.250 WHERE sku LIKE 'FRAG-%' COLLATE "C";

-- Retail, minimum markup and quantity settings. The margin settings from migration 034 are
-- kept in the table for history but no longer drive any price.
INSERT INTO pricing_settings (setting_key, setting_value) VALUES
    ('retail_multiplier', 2.10),
    ('candle_minimum_markup_percent', 40),
    ('quantity_price_exponent', 0.23),
    ('retail_rounding_tnd', 0.5),
    ('quantity_rounding_tnd', 0.05),
    ('packaging_per_candle_tnd', 1),
    ('fragrance_density_confirmed', 0)
ON CONFLICT (setting_key) DO NOTHING;
