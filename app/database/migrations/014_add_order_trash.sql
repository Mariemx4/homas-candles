-- Orders in Trash retain their payment, inventory, item, and audit records.
ALTER TABLE orders
    ADD COLUMN deleted_at DATETIME DEFAULT NULL,
    ADD COLUMN deleted_by_administrator_id BIGINT UNSIGNED DEFAULT NULL,
    ADD KEY idx_orders_deleted (deleted_at),
    ADD CONSTRAINT fk_orders_deleted_by FOREIGN KEY (deleted_by_administrator_id)
        REFERENCES administrators_accounts(id) ON DELETE SET NULL;

CREATE OR REPLACE ALGORITHM=MERGE VIEW active_orders AS
    SELECT * FROM orders WHERE deleted_at IS NULL;
