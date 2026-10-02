CREATE SEQUENCE IF NOT EXISTS administrators_accounts_id_seq;

-- WHAT: login accounts for staff/owners who manage the shop.
-- WHY:  separate from customers_accounts (003) on purpose — admins get
--       extra fields (PENDING_APPROVAL status, a second contact) that a
--       shopper never needs, and mixing the two into one table with a
--       "role" column would make every query filter by role forever.
CREATE TABLE IF NOT EXISTS administrators_accounts (
  id NUMERIC(20,0) NOT NULL DEFAULT nextval('administrators_accounts_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),

  -- Name constraints expanded beyond 20 characters to comfortably accommodate full names
  first_name VARCHAR(100) COLLATE homas_unicode_ci NOT NULL,
  last_name VARCHAR(100) COLLATE homas_unicode_ci NOT NULL,

  -- Email columns expanded to the 255-character standard capacity
  email VARCHAR(255) COLLATE homas_unicode_ci NOT NULL,
  phone_number CHAR(8) COLLATE homas_unicode_ci NOT NULL,

  -- Optional contact details (Defaults safely to NULL)
  second_email VARCHAR(255) COLLATE homas_unicode_ci DEFAULT NULL,
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
  CONSTRAINT uq_administrators_email UNIQUE (email),
  CONSTRAINT uq_administrators_phone_number UNIQUE (phone_number),

  -- Optional contact unique indexes
  CONSTRAINT uq_administrators_second_email UNIQUE (second_email),
  -- WHAT: stops second_email from just being a duplicate of email.
  -- WHY:  a second contact that's identical to the first is a data-entry
  --       mistake, not a real "backup contact".
  CONSTRAINT chk_administrators_different_emails
    CHECK (second_email IS NULL OR second_email <> email),
  CONSTRAINT uq_administrators_second_phone_number UNIQUE (second_phone_number),
  CONSTRAINT chk_administrators_different_phones
    CHECK (second_phone_number IS NULL OR second_phone_number <> phone_number),

  -- WHAT: both phone columns must be exactly 8 digits.
  -- WHY:  Tunisian phone numbers are written as 8 digits with no separators
  --       or country code; PHP should still validate this on input, but the
  --       database refuses to store anything else no matter what inserts it.
  CONSTRAINT chk_administrators_phone
    CHECK (phone_number COLLATE "C" ~ '^[0-9]{8}$'),
  CONSTRAINT chk_administrators_second_phone
    CHECK (second_phone_number IS NULL OR second_phone_number COLLATE "C" ~ '^[0-9]{8}$'),

  -- Enforces valid business state parameters for administrator accounts
  CONSTRAINT chk_administrators_account_status
    CHECK (account_status IN ('ACTIVE', 'SUSPENDED', 'PENDING_APPROVAL'))

);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE administrators_accounts_id_seq OWNED BY administrators_accounts.id;

-- PostgreSQL equivalent of ON UPDATE CURRENT_TIMESTAMP; explicit timestamp overrides remain valid.
CREATE OR REPLACE FUNCTION administrators_accounts_touch_updated_at_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
        NEW.updated_at := statement_timestamp();
    END IF;
    RETURN NEW;
END;
$homas$;
DROP TRIGGER IF EXISTS zz_administrators_accounts_touch_updated_at ON administrators_accounts;
CREATE TRIGGER zz_administrators_accounts_touch_updated_at BEFORE UPDATE ON administrators_accounts
FOR EACH ROW EXECUTE FUNCTION administrators_accounts_touch_updated_at_fn();
