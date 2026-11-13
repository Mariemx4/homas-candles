CREATE TABLE account_change_history (
 id BIGSERIAL PRIMARY KEY,
 account_kind VARCHAR(20) NOT NULL CHECK(account_kind IN ('customers','administrators')),
 account_id NUMERIC(20,0) NOT NULL,
 actor_administrator_id NUMERIC(20,0) REFERENCES administrators_accounts(id) ON DELETE RESTRICT,
 actor_customer_id NUMERIC(20,0) REFERENCES customers_accounts(id) ON DELETE RESTRICT,
 actor_name VARCHAR(201) NOT NULL,
 action VARCHAR(20) NOT NULL CHECK(action IN ('CREATED','UPDATED','ACTIVATED','DEACTIVATED','DELETED')),
 before_state JSONB,
 after_state JSONB,
 changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT (statement_timestamp() AT TIME ZONE 'UTC'),
 CHECK ((actor_administrator_id IS NULL) <> (actor_customer_id IS NULL)),
 CHECK (before_state IS NULL OR NOT (before_state ?| ARRAY['password','password_hash','csrf_token'])),
 CHECK (after_state IS NULL OR NOT (after_state ?| ARRAY['password','password_hash','csrf_token']))
);
CREATE INDEX idx_account_change_history_subject ON account_change_history(account_kind,account_id,id DESC);
CREATE FUNCTION account_change_history_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Account change history is append only.'; END $$;
CREATE TRIGGER trg_account_history_immutable BEFORE UPDATE OR DELETE ON account_change_history
 FOR EACH ROW EXECUTE FUNCTION account_change_history_immutable();
