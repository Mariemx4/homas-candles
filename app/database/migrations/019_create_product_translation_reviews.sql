-- Generated copy is private until an administrator approves it.
CREATE TABLE IF NOT EXISTS product_translation_reviews (
    product_id NUMERIC(20,0) NOT NULL CHECK (product_id BETWEEN 0 AND 18446744073709551615),
    language_code CHAR(2) COLLATE homas_unicode_ci NOT NULL,
    source_language_code CHAR(2) COLLATE homas_unicode_ci NOT NULL,
    source_hash CHAR(64) COLLATE homas_unicode_ci NOT NULL,
    name VARCHAR(180) COLLATE homas_unicode_ci NULL,
    description TEXT COLLATE homas_unicode_ci NULL,
    status VARCHAR(8) COLLATE homas_unicode_ci CHECK (status IN ('PENDING', 'FAILED', 'APPROVED', 'REJECTED')) NOT NULL,
    failure_message VARCHAR(255) COLLATE homas_unicode_ci NULL,
    generated_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    reviewed_by NUMERIC(20,0) NULL CHECK (reviewed_by BETWEEN 0 AND 18446744073709551615),
    PRIMARY KEY (product_id, language_code),
    CONSTRAINT fk_product_translation_review_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_product_translation_review_language FOREIGN KEY (language_code)
        REFERENCES languages (code),
    CONSTRAINT fk_product_translation_review_source FOREIGN KEY (source_language_code)
        REFERENCES languages (code)
);

CREATE INDEX IF NOT EXISTS fk_product_translation_review_language_idx ON product_translation_reviews (language_code);
CREATE INDEX IF NOT EXISTS fk_product_translation_review_source_idx ON product_translation_reviews (source_language_code);
