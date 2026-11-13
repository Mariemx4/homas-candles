ALTER TABLE customers_accounts ADD COLUMN second_email VARCHAR(255) COLLATE homas_unicode_ci NULL,
 ADD CONSTRAINT uq_customers_second_email UNIQUE(second_email),
 ADD CONSTRAINT chk_customers_different_emails CHECK(second_email IS NULL OR second_email <> email);

CREATE FUNCTION guard_customer_address_limit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM id FROM customers_accounts WHERE id=NEW.customer_id FOR UPDATE;
 IF (TG_OP='INSERT' OR NEW.customer_id IS DISTINCT FROM OLD.customer_id)
   AND (SELECT COUNT(*) FROM customer_addresses WHERE customer_id=NEW.customer_id)>=3 THEN
   RAISE EXCEPTION 'A customer may save at most three addresses.' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER guard_customer_address_limit BEFORE INSERT OR UPDATE OF customer_id ON customer_addresses
 FOR EACH ROW EXECUTE FUNCTION guard_customer_address_limit();

ALTER TABLE orders DROP CONSTRAINT chk_orders_status;
ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK(order_status IN
 ('REQUESTED','CONFIRMED','PREPARING','PREPARED','WAITING_FOR_PICKUP','PICKED_UP','IN_DELIVERY','DELIVERED','CANCELLED'));
ALTER TABLE order_status_history DROP CONSTRAINT chk_order_status_history_previous,
 DROP CONSTRAINT chk_order_status_history_new;
ALTER TABLE order_status_history ADD CONSTRAINT chk_order_status_history_previous CHECK(previous_status IS NULL OR previous_status IN
 ('REQUESTED','CONFIRMED','PREPARING','PREPARED','WAITING_FOR_PICKUP','PICKED_UP','IN_DELIVERY','DELIVERED','CANCELLED')),
 ADD CONSTRAINT chk_order_status_history_new CHECK(new_status IN
 ('REQUESTED','CONFIRMED','PREPARING','PREPARED','WAITING_FOR_PICKUP','PICKED_UP','IN_DELIVERY','DELIVERED','CANCELLED'));

-- Keep each edit and its pricing explanation alongside the original sale audit.
CREATE TABLE customer_order_edits (
 id BIGSERIAL PRIMARY KEY,
 order_id NUMERIC(20,0) NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
 customer_id NUMERIC(20,0) NOT NULL REFERENCES customers_accounts(id) ON DELETE RESTRICT,
 previous_total DECIMAL(12,3) NOT NULL,
 new_total DECIMAL(12,3) NOT NULL,
 edit_details JSONB NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT statement_timestamp()
);
CREATE INDEX idx_customer_order_edits_order ON customer_order_edits(order_id,id);

-- Count cancelled and trashed orders too; cancellation does not reset the limit.
CREATE FUNCTION guard_customer_daily_orders() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.customer_id IS NOT NULL THEN
  PERFORM id FROM customers_accounts WHERE id=NEW.customer_id FOR UPDATE;
  IF (SELECT COUNT(*) FROM orders WHERE customer_id=NEW.customer_id
      AND created_at>=CURRENT_DATE AND created_at<CURRENT_DATE+INTERVAL '1 day')>=3 THEN
   RAISE EXCEPTION 'You may place at most three orders per day.' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER guard_customer_daily_orders BEFORE INSERT ON orders
 FOR EACH ROW EXECUTE FUNCTION guard_customer_daily_orders();