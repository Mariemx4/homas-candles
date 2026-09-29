-- FILE PURPOSE: Database definition or seed data for 015 Protect Order Item Snapshots.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: database-level guards that keep order_items snapshots immutable.
-- WHY:  migration 005 explains that the *_snapshot columns are the agreed
--       record of an order. PHP never rewrites them today, but a trigger
--       makes that promise hold for every future code path and for manual
--       SQL as well:
--         1. What was ordered (product/colour/scent names, parent order)
--            can never be rewritten after the row is inserted.
--         2. Quantity and money may still be corrected before PREPARED
--            (after the business contacts the customer — see
--            docs/database-requirements.md "EXISTING ORDER PRICE RULES"),
--            but once orders.price_locked_at is set they are frozen too.
--         3. Lines of a price-locked order cannot be deleted one by one.
--            (Deleting a whole order cascades through the FK; MySQL/MariaDB
--            do not fire triggers for FK cascades, so that path still works.)
--       Manual orders are locked at creation, and their items are inserted
--       afterwards, so there is deliberately no INSERT guard.

DROP TRIGGER IF EXISTS trg_order_items_protect_snapshot_update;
DROP TRIGGER IF EXISTS trg_order_items_protect_locked_delete;

-- SECTION PURPOSE: Reject UPDATEs that would rewrite frozen order-item values.
CREATE TRIGGER trg_order_items_protect_snapshot_update
BEFORE UPDATE ON order_items
FOR EACH ROW
BEGIN
    -- <=> is the NULL-safe equality operator, so NULL -> 'x' counts as a change.
    IF NOT (NEW.order_id <=> OLD.order_id)
        OR NOT (NEW.product_name_snapshot <=> OLD.product_name_snapshot)
        OR NOT (NEW.color_name_snapshot <=> OLD.color_name_snapshot)
        OR NOT (NEW.scent_name_snapshot <=> OLD.scent_name_snapshot)
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Order item snapshots cannot be changed after the order is placed.';
    END IF;

    IF (
        NOT (NEW.quantity <=> OLD.quantity)
        OR NOT (NEW.unit_price_snapshot <=> OLD.unit_price_snapshot)
        OR NOT (NEW.line_total_snapshot <=> OLD.line_total_snapshot)
    ) AND EXISTS (
        SELECT 1 FROM orders WHERE orders.id = OLD.order_id AND orders.price_locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The agreed price of this order is locked.';
    END IF;
END;

-- SECTION PURPOSE: Reject removing a single line from a price-locked order.
CREATE TRIGGER trg_order_items_protect_locked_delete
BEFORE DELETE ON order_items
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM orders WHERE orders.id = OLD.order_id AND orders.price_locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Items cannot be removed from an order whose price is locked.';
    END IF;
END;
