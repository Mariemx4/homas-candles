-- Extra gallery photos for a product (for example a set or favour photographed in several ways).
-- Backward-compatible: a new table only; the main photo and colour photos are unchanged.
CREATE TABLE product_photos (
    id BIGINT GENERATED ALWAYS AS IDENTITY,
    product_id NUMERIC(20, 0) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_product_photos PRIMARY KEY (id),
    CONSTRAINT fk_product_photos_product FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT uq_product_photos_path UNIQUE (product_id, file_path),
    CONSTRAINT chk_product_photos_sort CHECK (sort_order >= 0)
);

CREATE INDEX idx_product_photos_product ON product_photos (product_id, sort_order);
