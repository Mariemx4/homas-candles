-- FILE PURPOSE: Database definition or seed data for 007 Create Payments Table.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: a payment attached to an order (deposit, balance, or full payment).
-- WHY:  separate from `orders` because one order can be paid in more than
--       one transaction (deposit now, balance later), and each payment
--       needs its own manual-verification trail (D17 transfers are checked
--       by an admin before being trusted).
-- SECTION PURPOSE: Define the `payments` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,

    payment_method VARCHAR(30) NOT NULL,
    payment_type VARCHAR(20) NOT NULL,
    payment_status VARCHAR(30) NOT NULL DEFAULT 'PENDING_VERIFICATION',

    amount DECIMAL(12,3) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TND',

    -- Optional information supplied for a D17 transfer
    d17_reference VARCHAR(100) DEFAULT NULL,
    payment_proof_path VARCHAR(500) DEFAULT NULL,
    customer_declared_paid_at DATETIME DEFAULT NULL,

    -- Filled only after manual review
    verified_at DATETIME DEFAULT NULL,
    verified_by_administrator_id BIGINT UNSIGNED DEFAULT NULL,
    rejection_reason VARCHAR(500) DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_payments_d17_reference (d17_reference),
    KEY idx_payments_order_status (order_id, payment_status),
    KEY idx_payments_verifier (verified_by_administrator_id),

    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id)
        REFERENCES orders (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_payments_verifier
        FOREIGN KEY (verified_by_administrator_id)
        REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT chk_payments_method
        CHECK (payment_method IN ('D17', 'CASH_ON_DELIVERY')),

    CONSTRAINT chk_payments_type
        CHECK (payment_type IN ('DEPOSIT', 'BALANCE', 'FULL_PAYMENT')),

    CONSTRAINT chk_payments_status
        CHECK (
            payment_status IN (
                'PENDING_VERIFICATION',
                'VERIFIED',
                'REJECTED'
            )
        ),

    CONSTRAINT chk_payments_amount
        CHECK (amount > 0),

    -- Locked to TND for now — the column still exists so a future currency
    -- doesn't need a schema change, just this list extended.
    CONSTRAINT chk_payments_currency
        CHECK (currency = 'TND'),

    -- WHAT: verified_at is set if and only if the payment is VERIFIED.
    -- WHY:  stops a payment being marked VERIFIED with no timestamp, or
    --       carrying a timestamp while sitting in a non-verified status.
    CONSTRAINT chk_payments_verification_time
        CHECK (
            (payment_status = 'VERIFIED' AND verified_at IS NOT NULL)
            OR
            (payment_status <> 'VERIFIED' AND verified_at IS NULL)
        ),

    -- WHAT: a REJECTED payment must always explain why.
    CONSTRAINT chk_payments_rejection_reason
        CHECK (
            payment_status <> 'REJECTED'
            OR rejection_reason IS NOT NULL
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
