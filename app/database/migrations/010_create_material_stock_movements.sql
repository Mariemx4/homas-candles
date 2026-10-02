CREATE SEQUENCE IF NOT EXISTS material_stock_movements_id_seq;

-- WHAT: a ledger of every change to a material's stock quantity.
-- WHY:  `materials.stock_quantity` only holds the CURRENT amount — this
--       table is the audit trail of every stock-in, order deduction,
--       restock, or manual adjustment that got it there. Never update
--       materials.stock_quantity directly in PHP; insert a row here and let
--       the before/after numbers describe the change.
CREATE TABLE IF NOT EXISTS material_stock_movements (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('material_stock_movements_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    material_id NUMERIC(20,0) NOT NULL CHECK (material_id BETWEEN 0 AND 18446744073709551615),

    -- Required only for movements caused by an order
    order_id NUMERIC(20,0) DEFAULT NULL CHECK (order_id BETWEEN 0 AND 18446744073709551615),

    movement_type VARCHAR(30) COLLATE homas_unicode_ci NOT NULL,

    -- Positive adds stock; negative removes stock
    quantity_change DECIMAL(12,3) NOT NULL,
    unit_cost_snapshot DECIMAL(12,3) NOT NULL,

    -- Audit snapshot of the complete stock change
    stock_before DECIMAL(12,3) NOT NULL,
    stock_after DECIMAL(12,3) NOT NULL,

    performed_by_administrator_id NUMERIC(20,0) DEFAULT NULL CHECK (performed_by_administrator_id BETWEEN 0 AND 18446744073709551615),
    movement_note VARCHAR(500) COLLATE homas_unicode_ci DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    -- Prevents duplicate deduction or restock movements for one order/material
    CONSTRAINT uq_material_stock_order_movement UNIQUE (
        order_id,
        material_id,
        movement_type
    ),

    CONSTRAINT fk_material_stock_material
        FOREIGN KEY (material_id)
        REFERENCES materials (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_material_stock_order
        FOREIGN KEY (order_id)
        REFERENCES orders (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_material_stock_administrator
        FOREIGN KEY (performed_by_administrator_id)
        REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT chk_material_stock_movement_type
        CHECK (
            movement_type IN (
                'STOCK_IN',
                'ORDER_DEDUCTION',
                'ORDER_RESTOCK',
                'ADJUSTMENT_IN',
                'ADJUSTMENT_OUT',
                'DAMAGED_OR_WASTED'
            )
        ),

    CONSTRAINT chk_material_stock_quantity_nonzero
        CHECK (quantity_change <> 0),

    -- WHAT: "in" movement types must be positive, "out" types must be negative.
    -- WHY:  stops a mismatched row like an ORDER_DEDUCTION that accidentally
    --       *adds* stock instead of removing it.
    CONSTRAINT chk_material_stock_quantity_direction
        CHECK (
            (
                movement_type IN (
                    'STOCK_IN',
                    'ORDER_RESTOCK',
                    'ADJUSTMENT_IN'
                )
                AND quantity_change > 0
            )
            OR
            (
                movement_type IN (
                    'ORDER_DEDUCTION',
                    'ADJUSTMENT_OUT',
                    'DAMAGED_OR_WASTED'
                )
                AND quantity_change < 0
            )
        ),

    -- WHAT: order_id is required for order-related movements, forbidden otherwise.
    -- WHY:  a STOCK_IN or ADJUSTMENT movement isn't tied to any order, but an
    --       ORDER_DEDUCTION/ORDER_RESTOCK always has to point back to one.
    CONSTRAINT chk_material_stock_order_reference
        CHECK (
            (
                movement_type IN ('ORDER_DEDUCTION', 'ORDER_RESTOCK')
                AND order_id IS NOT NULL
            )
            OR
            (
                movement_type NOT IN ('ORDER_DEDUCTION', 'ORDER_RESTOCK')
                AND order_id IS NULL
            )
        ),

    CONSTRAINT chk_material_stock_unit_cost
        CHECK (unit_cost_snapshot >= 0),

    CONSTRAINT chk_material_stock_before
        CHECK (stock_before >= 0),

    CONSTRAINT chk_material_stock_after
        CHECK (stock_after >= 0),

    -- WHAT: stock_after must equal stock_before plus this movement's change.
    -- WHY:  the arithmetic itself is guaranteed correct at the database
    --       level — a bug in the PHP that computes these numbers can't slip
    --       an inconsistent row in.
    CONSTRAINT chk_material_stock_calculation
        CHECK (stock_after = stock_before + quantity_change),

    -- WHAT: manual adjustments/waste must always be explained with a note.
    CONSTRAINT chk_material_stock_note
        CHECK (
            movement_type NOT IN (
                'ADJUSTMENT_IN',
                'ADJUSTMENT_OUT',
                'DAMAGED_OR_WASTED'
            )
            OR movement_note IS NOT NULL
        )
);

CREATE INDEX IF NOT EXISTS idx_material_stock_material_time ON material_stock_movements (material_id,
        created_at);
CREATE INDEX IF NOT EXISTS idx_material_stock_order ON material_stock_movements (order_id);
CREATE INDEX IF NOT EXISTS idx_material_stock_administrator ON material_stock_movements (performed_by_administrator_id);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE material_stock_movements_id_seq OWNED BY material_stock_movements.id;
