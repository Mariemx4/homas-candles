-- Generated copy is private until an administrator approves it.
CREATE TABLE IF NOT EXISTS product_translation_reviews (
    product_id BIGINT UNSIGNED NOT NULL,
    language_code CHAR(2) NOT NULL,
    source_language_code CHAR(2) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    name VARCHAR(180) NULL,
    description TEXT NULL,
    status ENUM('PENDING', 'FAILED', 'APPROVED', 'REJECTED') NOT NULL,
    failure_message VARCHAR(255) NULL,
    generated_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (product_id, language_code),
    CONSTRAINT fk_product_translation_review_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_product_translation_review_language FOREIGN KEY (language_code)
        REFERENCES languages (code),
    CONSTRAINT fk_product_translation_review_source FOREIGN KEY (source_language_code)
        REFERENCES languages (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
