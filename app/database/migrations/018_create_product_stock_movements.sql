CREATE SEQUENCE IF NOT EXISTS product_stock_movements_id_seq;

-- FILE PURPOSE: Database definition or seed data for 018 Create Product Stock Movements.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: an append-only ledger of every change to a stock-tracked product's
--       products.stock_quantity (today: accessories — ribbons, gift boxes...).
-- WHY:  accessories are bought ready-made, so they are counted as products,
--       not as recipe materials. Their stock used to be a number typed into
--       the product form that orders never touched. App\Services\AccessoryStockService
--       now changes it only together with a row here:
--         OPENING            stock entered when the accessory was created
--         ADJUSTMENT         a manual correction from the product form
--         ORDER_DEDUCTION    accessories (direct, or inside a set) taken for an
--                            order when it becomes PREPARED
--         ORDER_RESTORATION  the same quantities put back when a prepared,
--                            not-yet-handed-over order is cancelled (moved to Trash)
--       Summing an order's ORDER_* rows gives what it currently holds, which is
--       how deduction and restoration stay exactly-once in both directions.
--       This mirrors material_stock_movements (migration 010) for materials.
-- SECTION PURPOSE: Define the `product_stock_movements` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS product_stock_movements (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('product_stock_movements_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    product_id NUMERIC(20,0) NOT NULL CHECK (product_id BETWEEN 0 AND 18446744073709551615),
    order_id NUMERIC(20,0) DEFAULT NULL CHECK (order_id BETWEEN 0 AND 18446744073709551615),
    movement_type VARCHAR(30) COLLATE homas_unicode_ci NOT NULL,

    -- Signed change applied to products.stock_quantity (negative = taken out)
    quantity_change INT NOT NULL,
    stock_before BIGINT NOT NULL CHECK (stock_before BETWEEN 0 AND 4294967295),
    stock_after BIGINT NOT NULL CHECK (stock_after BETWEEN 0 AND 4294967295),

    created_by_administrator_id NUMERIC(20,0) DEFAULT NULL CHECK (created_by_administrator_id BETWEEN 0 AND 18446744073709551615),
    note VARCHAR(255) COLLATE homas_unicode_ci DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    -- RESTRICT: a product with stock history keeps it (products are
    -- deactivated, not deleted, once they have been sold).
    CONSTRAINT fk_product_stock_movements_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_product_stock_movements_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_product_stock_movements_administrator
        FOREIGN KEY (created_by_administrator_id) REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE ON DELETE SET NULL,

    CONSTRAINT chk_product_stock_movements_type
        CHECK (movement_type IN ('OPENING', 'ADJUSTMENT', 'ORDER_DEDUCTION', 'ORDER_RESTORATION')),
    -- Order movements always name their order and move stock in the right direction.
    CONSTRAINT chk_product_stock_movements_direction
        CHECK (
            (movement_type = 'ORDER_DEDUCTION' AND order_id IS NOT NULL AND quantity_change < 0)
            OR (movement_type = 'ORDER_RESTORATION' AND order_id IS NOT NULL AND quantity_change > 0)
            OR (movement_type IN ('OPENING', 'ADJUSTMENT') AND order_id IS NULL)
        ),
    CONSTRAINT chk_product_stock_movements_balance
        CHECK (CAST(stock_after AS BIGINT) = CAST(stock_before AS BIGINT) + quantity_change)
);

CREATE INDEX IF NOT EXISTS idx_product_stock_movements_product ON product_stock_movements (product_id, created_at);
CREATE INDEX IF NOT EXISTS idx_product_stock_movements_order ON product_stock_movements (order_id);
CREATE INDEX IF NOT EXISTS fk_product_stock_movements_administrator_idx ON product_stock_movements (created_by_administrator_id);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE product_stock_movements_id_seq OWNED BY product_stock_movements.id;
