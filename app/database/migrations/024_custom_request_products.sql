-- Multiple new product definitions belong to one existing request.
-- Orders, production recipes, and catalogue products remain in their existing tables.
CREATE TABLE custom_request_products (
    id BIGSERIAL PRIMARY KEY,
    request_id NUMERIC(20,0) NOT NULL REFERENCES custom_product_requests(id) ON DELETE CASCADE,
    position SMALLINT NOT NULL CHECK (position BETWEEN 1 AND 50),
    working_name VARCHAR(180) NOT NULL CHECK (length(trim(working_name)) > 0),
    category_id NUMERIC(20,0) NOT NULL REFERENCES categories(id),
    product_type_id NUMERIC(20,0) NOT NULL REFERENCES product_types(id),
    requested_quantity INTEGER NOT NULL CHECK (requested_quantity BETWEEN 1 AND 1000000),
    color_preferences VARCHAR(500) NOT NULL DEFAULT '',
    scent_preferences VARCHAR(500) NOT NULL DEFAULT '',
    specifications TEXT NOT NULL DEFAULT '' CHECK (length(specifications) <= 10000),
    converted_order_item_id NUMERIC(20,0) UNIQUE REFERENCES order_items(id),
    draft_product_id NUMERIC(20,0) UNIQUE REFERENCES products(id),
    UNIQUE(request_id, position),
    CHECK (draft_product_id IS NULL OR converted_order_item_id IS NOT NULL)
);
