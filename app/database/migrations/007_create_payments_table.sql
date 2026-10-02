CREATE SEQUENCE IF NOT EXISTS payments_id_seq;

-- WHAT: a payment attached to an order (deposit, balance, or full payment).
-- WHY:  separate from `orders` because one order can be paid in more than
--       one transaction (deposit now, balance later), and each payment
--       needs its own manual-verification trail (D17 transfers are checked
--       by an admin before being trusted).
CREATE TABLE IF NOT EXISTS payments (
    id NUMERIC(20,0) NOT NULL DEFAULT nextval('payments_id_seq'::regclass) CHECK (id BETWEEN 0 AND 18446744073709551615),
    order_id NUMERIC(20,0) NOT NULL CHECK (order_id BETWEEN 0 AND 18446744073709551615),

    payment_method VARCHAR(30) COLLATE homas_unicode_ci NOT NULL,
    payment_type VARCHAR(20) COLLATE homas_unicode_ci NOT NULL,
    payment_status VARCHAR(30) COLLATE homas_unicode_ci NOT NULL DEFAULT 'PENDING_VERIFICATION',

    amount DECIMAL(12,3) NOT NULL,
    currency CHAR(3) COLLATE homas_unicode_ci NOT NULL DEFAULT 'TND',

    -- Optional information supplied for a D17 transfer
    d17_reference VARCHAR(100) COLLATE homas_unicode_ci DEFAULT NULL,
    payment_proof_path VARCHAR(500) COLLATE homas_unicode_ci DEFAULT NULL,
    customer_declared_paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,

    -- Filled only after manual review
    verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    verified_by_administrator_id NUMERIC(20,0) DEFAULT NULL CHECK (verified_by_administrator_id BETWEEN 0 AND 18446744073709551615),
    rejection_reason VARCHAR(500) COLLATE homas_unicode_ci DEFAULT NULL,

    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT statement_timestamp(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        DEFAULT statement_timestamp(),

    PRIMARY KEY (id),

    CONSTRAINT uq_payments_d17_reference UNIQUE (d17_reference),

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
);

CREATE INDEX IF NOT EXISTS idx_payments_order_status ON payments (order_id, payment_status);
CREATE INDEX IF NOT EXISTS idx_payments_verifier ON payments (verified_by_administrator_id);

-- Sequence lifecycle follows its auto-numbered column.
ALTER SEQUENCE payments_id_seq OWNED BY payments.id;

-- PostgreSQL equivalent of ON UPDATE CURRENT_TIMESTAMP; explicit timestamp overrides remain valid.
CREATE OR REPLACE FUNCTION payments_touch_updated_at_fn()
RETURNS trigger LANGUAGE plpgsql AS $homas$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
        NEW.updated_at := statement_timestamp();
    END IF;
    RETURN NEW;
END;
$homas$;
DROP TRIGGER IF EXISTS zz_payments_touch_updated_at ON payments;
CREATE TRIGGER zz_payments_touch_updated_at BEFORE UPDATE ON payments
FOR EACH ROW EXECUTE FUNCTION payments_touch_updated_at_fn();
