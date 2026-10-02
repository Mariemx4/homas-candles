CREATE SEQUENCE IF NOT EXISTS order_status_history_id_seq;

-- WHAT: an audit trail of every status change an order goes through.
-- WHY:  `orders.order_status` only ever holds the CURRENT status — this
--       table is what lets you answer "when did this move from CONFIRMED to
--       PREPARING, and who did it?" after the fact.
CREATE TABLE IF NOT EXISTS order_status_history (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('order_status_history_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    order_id NUMERIC(20,0) NOT NULL CHECK (order_id BETWEEN 0 AND 18446744073709551615),

    previous_status VARCHAR(30) COLLATE homas_unicode_ci DEFAULT NULL,
    new_status VARCHAR(30) COLLATE homas_unicode_ci NOT NULL,

    -- Null when the change was made by the customer or system
    changed_by_administrator_id NUMERIC(20,0) DEFAULT NULL CHECK (changed_by_administrator_id BETWEEN 0 AND 18446744073709551615),

    change_source VARCHAR(20) COLLATE homas_unicode_ci NOT NULL,
    change_note VARCHAR(500) COLLATE homas_unicode_ci DEFAULT NULL,

    changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    CONSTRAINT fk_order_status_history_order
        FOREIGN KEY (order_id)
        REFERENCES orders (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_order_status_history_administrator
        FOREIGN KEY (changed_by_administrator_id)
        REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT chk_order_status_history_source
        CHECK (
            change_source IN (
                'CUSTOMER',
                'ADMINISTRATOR',
                'SYSTEM'
            )
        ),

    -- WHAT/WHY: same status list as orders.chk_orders_status (migration 005)
    -- — kept in sync by hand for now, so a new status means editing both
    -- files plus the `new` list right below.
    CONSTRAINT chk_order_status_history_previous
        CHECK (
            previous_status IS NULL
            OR previous_status IN (
                'REQUESTED',
                'CONFIRMED',
                'PREPARING',
                'PREPARED',
                'WAITING_FOR_PICKUP',
                'PICKED_UP',
                'IN_DELIVERY',
                'DELIVERED'
            )
        ),

    CONSTRAINT chk_order_status_history_new
        CHECK (
            new_status IN (
                'REQUESTED',
                'CONFIRMED',
                'PREPARING',
                'PREPARED',
                'WAITING_FOR_PICKUP',
                'PICKED_UP',
                'IN_DELIVERY',
                'DELIVERED'
            )
        ),

    -- WHAT: a history row must represent an actual change.
    -- WHY:  stops a no-op row like "REQUESTED -> REQUESTED" from cluttering
    --       the timeline.
    CONSTRAINT chk_order_status_history_changed
        CHECK (
            previous_status IS NULL
            OR previous_status <> new_status
        )
);

CREATE INDEX IF NOT EXISTS idx_order_status_history_order_time ON order_status_history (order_id,
        changed_at);
CREATE INDEX IF NOT EXISTS idx_order_status_history_administrator ON order_status_history (changed_by_administrator_id);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE order_status_history_id_seq OWNED BY order_status_history.id;
