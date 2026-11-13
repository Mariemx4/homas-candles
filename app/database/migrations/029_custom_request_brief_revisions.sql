-- Working brief corrections retain the original and every subsequent revision.
CREATE TABLE custom_request_brief_revisions (
    id BIGSERIAL PRIMARY KEY,
    request_id NUMERIC(20,0) NOT NULL REFERENCES custom_product_requests(id) ON DELETE RESTRICT,
    administrator_id NUMERIC(20,0) NOT NULL REFERENCES administrators_accounts(id) ON DELETE RESTRICT,
    reason VARCHAR(500) NOT NULL CHECK (length(trim(reason)) BETWEEN 1 AND 500),
    before_brief JSONB NOT NULL,
    after_brief JSONB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_request_brief_revisions ON custom_request_brief_revisions(request_id, id);
