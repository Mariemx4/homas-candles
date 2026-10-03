-- Preserve existing statuses and records; add explicit rejection time and replay protection.
ALTER TABLE payments ADD COLUMN rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE payments ADD COLUMN request_key CHAR(64) DEFAULT NULL;
ALTER TABLE payments ADD CONSTRAINT uq_payments_request_key UNIQUE (request_key);
-- Legacy rejection times are not known. Do not fabricate them from updated_at.
ALTER TABLE payments ADD CONSTRAINT chk_payments_rejected_at
    CHECK (rejected_at IS NULL OR payment_status = 'REJECTED');
