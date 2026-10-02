-- Shared customer/admin login limits. Keys are HMAC digests, never raw IPs or identifiers.
CREATE TABLE IF NOT EXISTS login_throttle (
    realm VARCHAR(12) COLLATE homas_unicode_ci NOT NULL,
    scope VARCHAR(12) COLLATE homas_unicode_ci NOT NULL,
    bucket_hash CHAR(64) COLLATE homas_unicode_ci NOT NULL,
    failures BIGINT NOT NULL DEFAULT 0 CHECK (failures BETWEEN 0 AND 4294967295),
    last_failure_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
    blocked_until TIMESTAMP(6) WITHOUT TIME ZONE NULL,
    PRIMARY KEY (realm, scope, bucket_hash)
);

CREATE INDEX IF NOT EXISTS idx_login_throttle_last_failure ON login_throttle (last_failure_at);
