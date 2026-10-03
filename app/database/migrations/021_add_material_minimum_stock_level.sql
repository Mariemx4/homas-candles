-- NULL means unconfigured: existing materials must never receive an invented threshold.
-- The value is expressed in the material's existing unit, like stock_quantity.
ALTER TABLE materials
    ADD COLUMN minimum_stock_level DECIMAL(12,3) DEFAULT NULL,
    ADD CONSTRAINT chk_materials_minimum_stock_level
        CHECK (minimum_stock_level IS NULL OR minimum_stock_level >= 0);
