-- One editable pricing rule per category, shown beside that category's product list in the admin.
-- Backward-compatible: a new table only. The text describes the rule; it does not drive prices yet.
CREATE TABLE category_pricing_rules (
    category_id NUMERIC(20, 0) NOT NULL,
    title VARCHAR(120) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    updated_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_category_pricing_rules PRIMARY KEY (category_id),
    CONSTRAINT fk_category_pricing_rules_category FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_category_pricing_rules_title CHECK (char_length(title) > 0),
    CONSTRAINT chk_category_pricing_rules_body CHECK (char_length(body) > 0)
);

INSERT INTO category_pricing_rules (category_id, title, body)
SELECT category.id, seed.title, seed.body
FROM categories AS category
JOIN (VALUES
    ('candles', 'Candle: known cost x 2.10', 'Retail = known cost (materials and 1 TND packaging) x 2.10, rounded up to 0.50 TND. The lowest allowed price is known cost x 1.40. Quantity prices fall smoothly towards that minimum.'),
    ('candle-sets', 'Candle set: components x 2', 'Price from the candles inside the set (cost x 2 each), plus accessories at cost. Enter the price yourself when the contents are not yet known.'),
    ('bouquets', 'Bouquet: base cost x 2', 'Base price is the actual cost x 2. The final price is agreed with the customer, so treat this as a starting point.'),
    ('event-favors', 'Event favour: quantity tiers', 'Unit cost x a tier multiplier that depends on the quantity: 175% for small batches, then 150% and 130% for larger ones.'),
    ('home-diffusers', 'Diffuser: same as its candle', 'The diffuser takes the price of the candle it is linked to.'),
    ('accessories', 'Accessory: purchase cost', 'Sold at the purchase cost, with no markup.')
) AS seed (slug, title, body) ON seed.slug = category.slug;
