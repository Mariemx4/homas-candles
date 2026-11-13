-- References existing catalogue component records; no second Item/catalogue or recipe system.
ALTER TABLE custom_request_products
    ADD COLUMN color_id NUMERIC(20,0) REFERENCES colors(id),
    ADD COLUMN scent_id NUMERIC(20,0) REFERENCES scents(id),
    ADD COLUMN color_mode VARCHAR(10) NOT NULL DEFAULT 'CUSTOM' CHECK (color_mode IN ('NONE','EXISTING','CUSTOM')),
    ADD COLUMN scent_mode VARCHAR(10) NOT NULL DEFAULT 'CUSTOM' CHECK (scent_mode IN ('NONE','EXISTING','CUSTOM'));
CREATE TABLE custom_request_components (
    id BIGSERIAL PRIMARY KEY,
    request_product_id BIGINT NOT NULL REFERENCES custom_request_products(id) ON DELETE CASCADE,
    position SMALLINT NOT NULL CHECK (position BETWEEN 1 AND 50),
    source VARCHAR(10) NOT NULL CHECK (source IN ('PRODUCT','ITEM','CUSTOM')),
    product_id NUMERIC(20,0) REFERENCES products(id),
    quantity INTEGER NOT NULL CHECK (quantity BETWEEN 1 AND 1000000),
    product_name_snapshot VARCHAR(180) NOT NULL,
    unit_cost_snapshot NUMERIC(12,3) CHECK (unit_cost_snapshot>=0),
    color_id NUMERIC(20,0) REFERENCES colors(id),
    scent_id NUMERIC(20,0) REFERENCES scents(id),
    color_mode VARCHAR(10) NOT NULL CHECK (color_mode IN ('NONE','EXISTING','CUSTOM')),
    scent_mode VARCHAR(10) NOT NULL CHECK (scent_mode IN ('NONE','EXISTING','CUSTOM')),
    color_value VARCHAR(500) NOT NULL DEFAULT '',
    scent_value VARCHAR(500) NOT NULL DEFAULT '',
    specifications TEXT NOT NULL DEFAULT '' CHECK (length(specifications)<=10000),
    UNIQUE(request_product_id,position),
    CHECK ((source='CUSTOM' AND product_id IS NULL) OR (source IN ('PRODUCT','ITEM') AND product_id IS NOT NULL)),
    CHECK (source='CUSTOM' OR (color_mode<>'CUSTOM' AND scent_mode<>'CUSTOM'))
);
