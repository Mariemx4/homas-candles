CREATE SEQUENCE IF NOT EXISTS customers_accounts_id_seq;

-- WHAT: login accounts for shoppers.
-- WHY:  kept separate from administrators_accounts (002) — see that file's
--       top comment for why the two aren't merged into one table.
CREATE TABLE IF NOT EXISTS customers_accounts (
  id NUMERIC(20,0) NOT NULL DEFAULT nextval('customers_accounts_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),

  -- Name constraints expanded beyond 20 characters to comfortably accommodate full names
  first_name VARCHAR(100) COLLATE homas_unicode_ci NOT NULL,
  last_name VARCHAR(100) COLLATE homas_unicode_ci NOT NULL,

  -- Email columns expanded to the 255-character standard capacity
  email VARCHAR(255) COLLATE homas_unicode_ci NOT NULL,
  phone_number CHAR(8) COLLATE homas_unicode_ci NOT NULL,

  -- Optional contact details (Defaults safely to NULL)
  second_phone_number CHAR(8) COLLATE homas_unicode_ci DEFAULT NULL,

  -- Sized for current and future password_hash() algorithms.
  password_hash VARCHAR(255) COLLATE homas_unicode_ci NOT NULL,

  -- STATUS: Account management control field (Active, Suspended, etc.)
  account_status VARCHAR(20) COLLATE homas_unicode_ci NOT NULL DEFAULT 'ACTIVE',

  -- TIMESTAMPS: Auditing records for user tracking
  last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),
  updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),

  PRIMARY KEY (id),

  -- Primary contact unique indexes
  CONSTRAINT uq_customers_email UNIQUE (email),
  CONSTRAINT uq_customers_phone_number UNIQUE (phone_number),

  -- Optional contact unique indexes
  CONSTRAINT uq_customers_second_phone_number UNIQUE (second_phone_number),
  CONSTRAINT chk_customers_different_phones
    CHECK (second_phone_number IS NULL OR second_phone_number <> phone_number),

  -- WHAT: both phone columns must be exactly 8 digits.
  -- WHY:  same Tunisian-phone-format rule as administrators_accounts.
  CONSTRAINT chk_customers_phone
    CHECK (phone_number COLLATE "C" ~ '^[0-9]{8}$'),
  CONSTRAINT chk_customers_second_phone
    CHECK (second_phone_number IS NULL OR second_phone_number COLLATE "C" ~ '^[0-9]{8}$'),

  -- Enforces valid business state parameters for customer accounts
  -- WHY no PENDING_APPROVAL here: unlike admins, customers self-register and
  -- don't need anyone's approval to become ACTIVE.
  CONSTRAINT chk_customers_account_status
    CHECK (account_status IN ('ACTIVE', 'SUSPENDED'))

);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE customers_accounts_id_seq OWNED BY customers_accounts.id;

-- PostgreSQL equivalent of ON UPDATE CURRENT_TIMESTAMP; explicit timestamp overrides remain valid.
CREATE OR REPLACE FUNCTION customers_accounts_touch_updated_at_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
        NEW.updated_at := statement_timestamp();
    END IF;
    RETURN NEW;
END;
$homas$;
DROP TRIGGER IF EXISTS zz_customers_accounts_touch_updated_at ON customers_accounts;
CREATE TRIGGER zz_customers_accounts_touch_updated_at BEFORE UPDATE ON customers_accounts
FOR EACH ROW EXECUTE FUNCTION customers_accounts_touch_updated_at_fn();
