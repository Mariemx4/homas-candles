-- FILE PURPOSE: Database definition or seed data for 012 Make Delivery Fee Explicit.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- Every order receives an explicitly entered delivery fee. Pickup may use
-- zero, while delivery may use any non-negative manually agreed amount.
-- SECTION PURPOSE: Evolve the existing `orders` table while preserving previously stored data.
ALTER TABLE orders
    ALTER delivery_fee DROP DEFAULT;

-- SECTION PURPOSE: Evolve the existing `orders` table while preserving previously stored data.
ALTER TABLE orders
    DROP CONSTRAINT chk_orders_delivery_fee;

-- SECTION PURPOSE: Evolve the existing `orders` table while preserving previously stored data.
ALTER TABLE orders
    ADD CONSTRAINT chk_orders_delivery_fee_nonnegative
        CHECK (delivery_fee >= 0);
