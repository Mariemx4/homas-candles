-- Audited customer revisions may update agreed prices only before preparation.
ALTER TABLE customer_order_edits ADD COLUMN transaction_id BIGINT NOT NULL DEFAULT txid_current();

CREATE FUNCTION customer_revision_allowed(order_key NUMERIC) RETURNS boolean LANGUAGE sql AS $$
 SELECT EXISTS (
  SELECT 1 FROM customer_order_edits e JOIN orders o ON o.id=e.order_id
  JOIN customers_accounts c ON c.id=e.customer_id
  WHERE e.id::text=current_setting('homas.customer_edit_id',true)
    AND e.transaction_id=txid_current() AND o.id=order_key AND o.customer_id=e.customer_id
    AND c.account_status='ACTIVE' AND o.deleted_at IS NULL AND o.order_status IN ('REQUESTED','CONFIRMED')
    AND NOT EXISTS (SELECT 1 FROM order_status_history h WHERE h.order_id=o.id
      AND h.new_status IN ('PREPARING','PREPARED','WAITING_FOR_PICKUP','PICKED_UP','IN_DELIVERY','DELIVERED'))
 );
$$;

CREATE OR REPLACE FUNCTION trg_order_items_protect_snapshot_update_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.order_id IS DISTINCT FROM OLD.order_id
 OR NEW.product_name_snapshot IS DISTINCT FROM OLD.product_name_snapshot
 OR NEW.color_name_snapshot IS DISTINCT FROM OLD.color_name_snapshot
 OR NEW.scent_name_snapshot IS DISTINCT FROM OLD.scent_name_snapshot THEN
  RAISE EXCEPTION 'Order item snapshots cannot be changed after the order is placed.';
 END IF;
 IF (NEW.quantity IS DISTINCT FROM OLD.quantity OR NEW.unit_price_snapshot IS DISTINCT FROM OLD.unit_price_snapshot
 OR NEW.line_total_snapshot IS DISTINCT FROM OLD.line_total_snapshot)
 AND EXISTS(SELECT 1 FROM orders WHERE id=OLD.order_id AND price_locked_at IS NOT NULL)
 AND NOT customer_revision_allowed(OLD.order_id) THEN
  RAISE EXCEPTION 'The agreed price of this order is locked.';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION protect_sale_pricing_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.price_override_reason IS DISTINCT FROM OLD.price_override_reason
 OR NEW.recipe_source IS DISTINCT FROM OLD.recipe_source THEN
  RAISE EXCEPTION 'Sale recipe and override snapshots cannot be changed.';
 END IF;
 IF (NEW.catalogue_unit_price_snapshot IS DISTINCT FROM OLD.catalogue_unit_price_snapshot
 OR NEW.quantity_unit_price_snapshot IS DISTINCT FROM OLD.quantity_unit_price_snapshot
 OR NEW.quantity_tier_snapshot IS DISTINCT FROM OLD.quantity_tier_snapshot)
 AND NOT customer_revision_allowed(OLD.order_id) THEN
  RAISE EXCEPTION 'Sale pricing audit snapshots require an audited customer revision.';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION protect_order_discount_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.price_locked_at IS NOT NULL AND (
  NEW.promo_code_snapshot IS DISTINCT FROM OLD.promo_code_snapshot OR
  NEW.promo_type_snapshot IS DISTINCT FROM OLD.promo_type_snapshot OR
  NEW.promo_value_snapshot IS DISTINCT FROM OLD.promo_value_snapshot OR
  NEW.manual_discount_type IS DISTINCT FROM OLD.manual_discount_type OR
  NEW.manual_discount_value IS DISTINCT FROM OLD.manual_discount_value OR
  NEW.manual_discount_reason IS DISTINCT FROM OLD.manual_discount_reason OR
  ((NEW.promo_discount_amount IS DISTINCT FROM OLD.promo_discount_amount OR
    NEW.manual_discount_amount IS DISTINCT FROM OLD.manual_discount_amount OR
    NEW.quantity_savings_snapshot IS DISTINCT FROM OLD.quantity_savings_snapshot)
   AND NOT customer_revision_allowed(OLD.id))
 ) THEN RAISE EXCEPTION 'The agreed discounts of this order are locked.'; END IF;
 RETURN NEW;
END $$;

-- Serialize inserts for one account, including manual orders and concurrent checkout.
-- The business day follows the database CURRENT_DATE used by order timestamps.
CREATE OR REPLACE FUNCTION guard_customer_daily_orders() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.customer_id IS NOT NULL THEN
  PERFORM id FROM customers_accounts WHERE id=NEW.customer_id FOR UPDATE;
  IF (SELECT COUNT(*) FROM orders WHERE customer_id=NEW.customer_id
      AND created_at>=CURRENT_DATE AND created_at<CURRENT_DATE+INTERVAL '1 day')>=3 THEN
   RAISE EXCEPTION 'You may place at most three orders per day.' USING ERRCODE='23514', CONSTRAINT='customer_daily_order_limit';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE INDEX idx_orders_customer_day ON orders(customer_id,created_at);
