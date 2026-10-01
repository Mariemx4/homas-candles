-- Shared customer/admin login limits. Keys are HMAC digests, never raw IPs or identifiers.
CREATE TABLE IF NOT EXISTS login_throttle (
    realm VARCHAR(12) NOT NULL,
    scope VARCHAR(12) NOT NULL,
    bucket_hash CHAR(64) NOT NULL,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_failure_at DATETIME(6) NOT NULL,
    blocked_until DATETIME(6) NULL,
    PRIMARY KEY (realm, scope, bucket_hash),
    KEY idx_login_throttle_last_failure (last_failure_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
