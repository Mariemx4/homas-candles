-- FILE PURPOSE: Database definition or seed data for 017 Create Order Item Materials.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: an explicit production recipe attached to one order item — the
--       materials (and quantities per unit) needed to make that item.
-- WHY:  recipe-based deductions (OrderStatusService at PREPARED) and the
--       production sheet read product_materials through order_items.product_id.
--       A manual order line for a bespoke product has no catalogue product, and
--       a customised catalogue product may not follow its catalogue recipe, so
--       both used to be invisible to production. When an order item has rows
--       here they REPLACE the catalogue recipe for that item; otherwise the
--       catalogue recipe (or, for a set, its components' recipes) applies.
--
--       material_name_snapshot / unit_snapshot / unit_cost_snapshot freeze what
--       was agreed, exactly like order_items (migration 005): renaming a
--       material or changing its cost later never rewrites this record.
--       order_items.unit_cost_snapshot holds the per-unit total of these lines.
-- SECTION PURPOSE: Define the `order_item_materials` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS order_item_materials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_item_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,

    -- Material information frozen when the recipe was agreed
    material_name_snapshot VARCHAR(180) NOT NULL,
    unit_snapshot VARCHAR(30) NOT NULL,
    unit_cost_snapshot DECIMAL(12,3) NOT NULL,

    -- Quantity of the material needed for ONE unit of the order item
    quantity_required DECIMAL(12,3) NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_order_item_materials_line (order_item_id, material_id),
    KEY idx_order_item_materials_material (material_id),

    CONSTRAINT fk_order_item_materials_item
        FOREIGN KEY (order_item_id) REFERENCES order_items (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    -- RESTRICT, like product_materials: a material used by an agreed recipe
    -- must stay available so its deduction can still be recorded.
    CONSTRAINT fk_order_item_materials_material
        FOREIGN KEY (material_id) REFERENCES materials (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_order_item_materials_quantity
        CHECK (quantity_required > 0),
    CONSTRAINT chk_order_item_materials_unit_cost
        CHECK (unit_cost_snapshot >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: database-level guards that freeze a custom recipe once production
--       has been recorded.
-- WHY:  the materials deducted at PREPARED (migration 010 ledger) must stay
--       explainable by the recipe stored here. After the order has reached
--       PREPARED or later, or once an ORDER_DEDUCTION exists for it, recipe
--       lines can no longer be added, changed or removed — by PHP or by manual
--       SQL. Deleting a whole order still works: FK cascades do not fire
--       triggers in MySQL/MariaDB (see migration 015).
DROP TRIGGER IF EXISTS trg_order_item_materials_protect_insert;
DROP TRIGGER IF EXISTS trg_order_item_materials_protect_update;
DROP TRIGGER IF EXISTS trg_order_item_materials_protect_delete;

-- SECTION PURPOSE: Reject adding recipe lines to an item already in production records.
CREATE TRIGGER trg_order_item_materials_protect_insert
BEFORE INSERT ON order_item_materials
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM order_items
        INNER JOIN orders ON orders.id = order_items.order_id
        WHERE order_items.id = NEW.order_item_id
          AND (
              orders.order_status NOT IN ('REQUESTED', 'CONFIRMED', 'PREPARING')
              OR EXISTS (
                  SELECT 1 FROM material_stock_movements
                  WHERE material_stock_movements.order_id = orders.id
                    AND material_stock_movements.movement_type = 'ORDER_DEDUCTION'
              )
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The production recipe of a prepared order cannot be changed.';
    END IF;
END;

-- SECTION PURPOSE: Reject rewriting recipe lines of an item already in production records.
CREATE TRIGGER trg_order_item_materials_protect_update
BEFORE UPDATE ON order_item_materials
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM order_items
        INNER JOIN orders ON orders.id = order_items.order_id
        WHERE order_items.id = OLD.order_item_id
          AND (
              orders.order_status NOT IN ('REQUESTED', 'CONFIRMED', 'PREPARING')
              OR EXISTS (
                  SELECT 1 FROM material_stock_movements
                  WHERE material_stock_movements.order_id = orders.id
                    AND material_stock_movements.movement_type = 'ORDER_DEDUCTION'
              )
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The production recipe of a prepared order cannot be changed.';
    END IF;
END;

-- SECTION PURPOSE: Reject removing recipe lines of an item already in production records.
CREATE TRIGGER trg_order_item_materials_protect_delete
BEFORE DELETE ON order_item_materials
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM order_items
        INNER JOIN orders ON orders.id = order_items.order_id
        WHERE order_items.id = OLD.order_item_id
          AND (
              orders.order_status NOT IN ('REQUESTED', 'CONFIRMED', 'PREPARING')
              OR EXISTS (
                  SELECT 1 FROM material_stock_movements
                  WHERE material_stock_movements.order_id = orders.id
                    AND material_stock_movements.movement_type = 'ORDER_DEDUCTION'
              )
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The production recipe of a prepared order cannot be changed.';
    END IF;
END;
