-- Orders in Trash retain their payment, inventory, item, and audit records.
ALTER TABLE orders
    ADD COLUMN deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    ADD COLUMN deleted_by_administrator_id NUMERIC(20,0) CHECK (deleted_by_administrator_id BETWEEN 0 AND 18446744073709551615) DEFAULT NULL,
    ADD CONSTRAINT fk_orders_deleted_by FOREIGN KEY (deleted_by_administrator_id)
        REFERENCES administrators_accounts(id) ON DELETE SET NULL;

CREATE OR REPLACE VIEW active_orders AS
    SELECT * FROM orders WHERE deleted_at IS NULL;

CREATE INDEX idx_orders_deleted ON orders (deleted_at);
