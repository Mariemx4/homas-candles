-- Keep each frozen order-item total consistent with its frozen unit price.
-- ROUND uses the same three-decimal precision as the monetary columns.
ALTER TABLE order_items
    ADD CONSTRAINT chk_order_items_line_total_calculation
        CHECK (
            line_total_snapshot = ROUND(unit_price_snapshot * quantity, 3)
        );

-- A D17 decision must retain the administrator who made it. RESTRICT keeps
-- that audit identity from being erased by deleting the administrator row.
ALTER TABLE payments
    DROP FOREIGN KEY fk_payments_verifier;

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_verifier
        FOREIGN KEY (verified_by_administrator_id)
        REFERENCES administrators_accounts (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    ADD CONSTRAINT chk_payments_d17_administrator_review
        CHECK (
            payment_method <> 'D17'
            OR (
                payment_status = 'PENDING_VERIFICATION'
                AND verified_by_administrator_id IS NULL
            )
            OR (
                payment_status IN ('VERIFIED', 'REJECTED')
                AND verified_by_administrator_id IS NOT NULL
            )
        );
