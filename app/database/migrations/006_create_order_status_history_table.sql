-- FILE PURPOSE: Database definition or seed data for 006 Create Order Status History Table.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: an audit trail of every status change an order goes through.
-- WHY:  `orders.order_status` only ever holds the CURRENT status — this
--       table is what lets you answer "when did this move from CONFIRMED to
--       PREPARING, and who did it?" after the fact.
-- SECTION PURPOSE: Define the `order_status_history` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,

    previous_status VARCHAR(30) DEFAULT NULL,
    new_status VARCHAR(30) NOT NULL,

    -- Null when the change was made by the customer or system
    changed_by_administrator_id BIGINT UNSIGNED DEFAULT NULL,

    change_source VARCHAR(20) NOT NULL,
    change_note VARCHAR(500) DEFAULT NULL,

    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_order_status_history_order_time (
        order_id,
        changed_at
    ),

    KEY idx_order_status_history_administrator (
        changed_by_administrator_id
    ),

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
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
