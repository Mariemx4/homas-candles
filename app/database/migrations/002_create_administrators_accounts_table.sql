-- WHAT: login accounts for staff/owners who manage the shop.
-- WHY:  separate from customers_accounts (003) on purpose — admins get
--       extra fields (PENDING_APPROVAL status, a second contact) that a
--       shopper never needs, and mixing the two into one table with a
--       "role" column would make every query filter by role forever.
CREATE TABLE IF NOT EXISTS administrators_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Name constraints expanded beyond 20 characters to comfortably accommodate full names
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,

  -- Email columns expanded to the 255-character standard capacity
  email VARCHAR(255) NOT NULL,
  phone_number CHAR(8) NOT NULL,

  -- Optional contact details (Defaults safely to NULL)
  second_email VARCHAR(255) DEFAULT NULL,
  second_phone_number CHAR(8) DEFAULT NULL,

  -- Sized for current and future password_hash() algorithms.
  password_hash VARCHAR(255) NOT NULL,

  -- STATUS: Account management control field (Active, Suspended, etc.)
  account_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',

  -- TIMESTAMPS: Auditing records for user tracking
  last_login_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Primary contact unique indexes
  UNIQUE KEY uq_administrators_email (email),
  UNIQUE KEY uq_administrators_phone_number (phone_number),

  -- Optional contact unique indexes
  UNIQUE KEY uq_administrators_second_email (second_email),
  -- WHAT: stops second_email from just being a duplicate of email.
  -- WHY:  a second contact that's identical to the first is a data-entry
  --       mistake, not a real "backup contact".
  CONSTRAINT chk_administrators_different_emails
    CHECK (second_email IS NULL OR second_email <> email),
  UNIQUE KEY uq_administrators_second_phone_number (second_phone_number),
  CONSTRAINT chk_administrators_different_phones
    CHECK (second_phone_number IS NULL OR second_phone_number <> phone_number),

  -- WHAT: both phone columns must be exactly 8 digits.
  -- WHY:  Tunisian phone numbers are written as 8 digits with no separators
  --       or country code; PHP should still validate this on input, but the
  --       database refuses to store anything else no matter what inserts it.
  CONSTRAINT chk_administrators_phone
    CHECK (phone_number REGEXP '^[0-9]{8}$'),
  CONSTRAINT chk_administrators_second_phone
    CHECK (second_phone_number IS NULL OR second_phone_number REGEXP '^[0-9]{8}$'),

  -- Enforces valid business state parameters for administrator accounts
  CONSTRAINT chk_administrators_account_status
    CHECK (account_status IN ('ACTIVE', 'SUSPENDED', 'PENDING_APPROVAL'))

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
