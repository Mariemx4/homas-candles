-- Preserve ownership, audit identities, and historical children even for direct SQL deletion.
-- Current catalogue translations/configuration keep their existing owned-row semantics.
DO $$
DECLARE link RECORD; definition TEXT;
BEGIN
  FOR link IN
    SELECT f.conname, f.conrelid::regclass AS child, pg_get_constraintdef(f.oid) AS definition
    FROM pg_constraint f JOIN pg_class child ON child.oid=f.conrelid JOIN pg_namespace n ON n.oid=child.relnamespace
    WHERE f.contype='f' AND n.nspname=current_schema() AND f.confdeltype IN ('c','n')
      AND child.relname IN ('orders','order_items','payments','order_status_history','material_stock_movements','product_stock_movements',
        'custom_product_requests','request_contact_attempts','order_item_materials','custom_request_products','custom_request_components','customer_addresses','products','scents','set_components','product_materials','product_colors','product_scents','product_quantity_prices','product_translation_reviews')
  LOOP
    definition := regexp_replace(link.definition, 'ON DELETE (CASCADE|SET NULL)', 'ON DELETE RESTRICT');
    EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I',link.child,link.conname);
    EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s',link.child,link.conname,definition);
  END LOOP;
END $$;

ALTER TABLE custom_product_requests ADD COLUMN archived_at TIMESTAMP NULL,
  ADD COLUMN archived_by_administrator_id NUMERIC(20,0) REFERENCES administrators_accounts(id) ON DELETE RESTRICT;
CREATE INDEX idx_custom_requests_archive ON custom_product_requests(archived_at);

-- Promotion usage is a code snapshot, not an FK; lock both sides to prevent a delete/use race.
CREATE FUNCTION protect_used_promotion_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM orders WHERE promo_code_snapshot=OLD.code) THEN
    RAISE EXCEPTION 'Used promotion codes cannot be deleted.' USING ERRCODE='23503';
  END IF;
  RETURN OLD;
END $$;
CREATE TRIGGER guard_used_promotion_delete BEFORE DELETE ON promotion_codes FOR EACH ROW EXECUTE FUNCTION protect_used_promotion_delete();
CREATE FUNCTION lock_order_promotion_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.promo_code_snapshot IS NOT NULL AND (TG_OP='INSERT' OR NEW.promo_code_snapshot IS DISTINCT FROM OLD.promo_code_snapshot) THEN
    PERFORM id FROM promotion_codes WHERE code=NEW.promo_code_snapshot FOR KEY SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Promotion code is unavailable.' USING ERRCODE='23503'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER guard_order_promotion_identity BEFORE INSERT OR UPDATE OF promo_code_snapshot ON orders FOR EACH ROW EXECUTE FUNCTION lock_order_promotion_identity();

-- Translation review actors were previously stored without a relationship. Keep legacy orphan values readable.
ALTER TABLE product_translation_reviews ADD CONSTRAINT fk_translation_review_administrator FOREIGN KEY(reviewed_by) REFERENCES administrators_accounts(id) ON DELETE RESTRICT NOT VALID;

CREATE INDEX idx_orders_promotion_snapshot ON orders(promo_code_snapshot) WHERE promo_code_snapshot IS NOT NULL;
CREATE INDEX idx_translation_reviews_actor ON product_translation_reviews(reviewed_by) WHERE reviewed_by IS NOT NULL;
