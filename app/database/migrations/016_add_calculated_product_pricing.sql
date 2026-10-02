-- FILE PURPOSE: Database definition or seed data for 016 Add Calculated Product Pricing.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: columns recording how each product's current selling price was
--       calculated by App\Services\PricingService.
-- WHY:  selling_price used to be typed in by hand, so it silently drifted
--       when material costs changed. PricingService now recalculates it from
--       the documented rules (docs/database-requirements.md "BASE-PRICE
--       RULES") whenever a cost input changes, and these columns explain the
--       result:
--         actual_cost          current real production cost (profit = price - cost)
--         price_rule           which documented rule produced selling_price
--         price_calculated_at  when selling_price was last set by the rules;
--                              NULL means it is a legacy manual price
--         pricing_issue        why the rules could not price it (missing recipe...)
--
--       These are CURRENT catalogue values only. Placed orders keep their own
--       frozen order_items.*_snapshot prices (migrations 005, 011, 015), so a
--       recalculation never changes an agreed historical price.
-- SECTION PURPOSE: Evolve the existing `products` table while preserving previously stored data.
ALTER TABLE products
    ADD COLUMN actual_cost DECIMAL(12,3) NULL,
    ADD COLUMN price_rule VARCHAR(40) COLLATE homas_unicode_ci NULL,
    ADD COLUMN price_calculated_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    ADD COLUMN pricing_issue VARCHAR(255) COLLATE homas_unicode_ci NULL,
    ADD CONSTRAINT chk_products_actual_cost
        CHECK (actual_cost IS NULL OR actual_cost >= 0),
    -- Must match App\Services\PricingService::RULES.
    ADD CONSTRAINT chk_products_price_rule
        CHECK (
            price_rule IS NULL
            OR price_rule IN (
                'CANDLE_COST_X2',
                'SET_COMPONENTS',
                'ACCESSORY_PURCHASE_COST',
                'BOUQUET_BASE_COST_X2',
                'EVENT_FAVOR_TIER',
                'DIFFUSER_REFERENCE_CANDLE',
                'DIFFUSER_CANDLE_EQUIVALENT'
            )
        ),
    -- A rule-calculated price always records its rule and time together.
    ADD CONSTRAINT chk_products_price_calculation
        CHECK (
            (price_rule IS NULL AND price_calculated_at IS NULL)
            OR (price_rule IS NOT NULL AND price_calculated_at IS NOT NULL AND selling_price IS NOT NULL)
        );
