-- FILE PURPOSE: Database definition or seed data for 003 Create Customers Accounts Table.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: login accounts for shoppers.
-- WHY:  kept separate from administrators_accounts (002) — see that file's
--       top comment for why the two aren't merged into one table.
-- SECTION PURPOSE: Define the `customers_accounts` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS customers_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Name constraints expanded beyond 20 characters to comfortably accommodate full names
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,

  -- Email columns expanded to the 255-character standard capacity
  email VARCHAR(255) NOT NULL,
  phone_number CHAR(8) NOT NULL,

  -- Optional contact details (Defaults safely to NULL)
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
  UNIQUE KEY uq_customers_email (email),
  UNIQUE KEY uq_customers_phone_number (phone_number),

  -- Optional contact unique indexes
  UNIQUE KEY uq_customers_second_phone_number (second_phone_number),
  CONSTRAINT chk_customers_different_phones
    CHECK (second_phone_number IS NULL OR second_phone_number <> phone_number),

  -- WHAT: both phone columns must be exactly 8 digits.
  -- WHY:  same Tunisian-phone-format rule as administrators_accounts.
  CONSTRAINT chk_customers_phone
    CHECK (phone_number REGEXP '^[0-9]{8}$'),
  CONSTRAINT chk_customers_second_phone
    CHECK (second_phone_number IS NULL OR second_phone_number REGEXP '^[0-9]{8}$'),

  -- Enforces valid business state parameters for customer accounts
  -- WHY no PENDING_APPROVAL here: unlike admins, customers self-register and
  -- don't need anyone's approval to become ACTIVE.
  CONSTRAINT chk_customers_account_status
    CHECK (account_status IN ('ACTIVE', 'SUSPENDED'))

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
