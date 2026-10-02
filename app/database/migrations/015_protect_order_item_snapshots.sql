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

DROP TRIGGER IF EXISTS trg_order_items_protect_snapshot_update ON order_items;
DROP TRIGGER IF EXISTS trg_order_items_protect_locked_delete ON order_items;

-- SECTION PURPOSE: Reject UPDATEs that would rewrite frozen order-item values.
CREATE OR REPLACE FUNCTION trg_order_items_protect_snapshot_update_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    -- <=> is the NULL-safe equality operator, so NULL -> 'x' counts as a change.
    IF NOT (NEW.order_id IS NOT DISTINCT FROM OLD.order_id)
        OR NOT (NEW.product_name_snapshot IS NOT DISTINCT FROM OLD.product_name_snapshot)
        OR NOT (NEW.color_name_snapshot IS NOT DISTINCT FROM OLD.color_name_snapshot)
        OR NOT (NEW.scent_name_snapshot IS NOT DISTINCT FROM OLD.scent_name_snapshot)
    THEN
        RAISE EXCEPTION USING ERRCODE = '45000', MESSAGE = 'Order item snapshots cannot be changed after the order is placed.';
    END IF;

    IF (
        NOT (NEW.quantity IS NOT DISTINCT FROM OLD.quantity)
        OR NOT (NEW.unit_price_snapshot IS NOT DISTINCT FROM OLD.unit_price_snapshot)
        OR NOT (NEW.line_total_snapshot IS NOT DISTINCT FROM OLD.line_total_snapshot)
    ) AND EXISTS (
        SELECT 1 FROM orders WHERE orders.id = OLD.order_id AND orders.price_locked_at IS NOT NULL
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '45000', MESSAGE = 'The agreed price of this order is locked.';
    END IF;
    RETURN NEW;
END;
$homas$;

CREATE TRIGGER trg_order_items_protect_snapshot_update
BEFORE UPDATE ON order_items
FOR EACH ROW EXECUTE FUNCTION trg_order_items_protect_snapshot_update_fn();

-- SECTION PURPOSE: Reject removing a single line from a price-locked order.
CREATE OR REPLACE FUNCTION trg_order_items_protect_locked_delete_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF EXISTS (
        SELECT 1 FROM orders WHERE orders.id = OLD.order_id AND orders.price_locked_at IS NOT NULL
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '45000', MESSAGE = 'Items cannot be removed from an order whose price is locked.';
    END IF;
    RETURN OLD;
END;
$homas$;

CREATE TRIGGER trg_order_items_protect_locked_delete
BEFORE DELETE ON order_items
FOR EACH ROW EXECUTE FUNCTION trg_order_items_protect_locked_delete_fn();

-- PostgreSQL fires row triggers during FK cascades. The parent lookup above
-- finds no deleted parent during whole-order deletion, allowing the cascade.
-- The original MySQL cascade comments are retained as historical context.
