-- Use the same bouquet slug as the reference data and business rules.
-- Keep the category ID and every product, request and translation relationship.
-- The unique constraint rejects conflicting categories rather than merging data.
UPDATE categories SET slug = 'bouquets' WHERE slug = 'candle-bouquets';
