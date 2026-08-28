-- Every order receives an explicitly entered delivery fee. Pickup may use
-- zero, while delivery may use any non-negative manually agreed amount.
ALTER TABLE orders
    ALTER delivery_fee DROP DEFAULT;

ALTER TABLE orders
    DROP CONSTRAINT chk_orders_delivery_fee;

ALTER TABLE orders
    ADD CONSTRAINT chk_orders_delivery_fee_nonnegative
        CHECK (delivery_fee >= 0);
