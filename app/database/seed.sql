-- FILE PURPOSE: Database definition or seed data for Seed.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: reference/lookup data every environment needs — languages,
--       categories, product types, material types, and their translations.
-- WHY:  this is safe to run in production because it contains no fake
--       products, prices, or stock — just the taxonomy the site is built
--       on. Compare with demo_seed.sql, which adds fake catalogue data on
--       top of this for local development only.
-- Production-safe reference data only. No products, materials, colors, or scents.
START TRANSACTION;

-- SECTION PURPOSE: Insert required reference/development rows into `languages` for application lookups.
INSERT INTO languages (code, name) VALUES
    ('en', 'English'),
    ('fr', 'Français'),
    ('ar', 'العربية')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- WHAT: the six catalogue sections and their translated names.
-- SECTION PURPOSE: Insert required reference/development rows into `categories` for application lookups.
INSERT INTO categories (id, slug, is_active) VALUES
    (1, 'candles', 1),
    (2, 'candle-sets', 1),
    (3, 'bouquets', 1),
    (4, 'event-favors', 1),
    (5, 'home-diffusers', 1),
    (6, 'accessories', 1)
ON DUPLICATE KEY UPDATE slug = VALUES(slug), is_active = VALUES(is_active);

-- SECTION PURPOSE: Insert required reference/development rows into `category_translations` for application lookups.
INSERT INTO category_translations (category_id, language_code, name) VALUES
    (1, 'en', 'Candles'), (1, 'fr', 'Bougies'), (1, 'ar', 'ش�
وع'),
    (2, 'en', 'Candle Sets'), (2, 'fr', 'Coffrets de bougies'), (2, 'ar', '�
ج�
وعات الش�
وع'),
    (3, 'en', 'Candle Bouquets'), (3, 'fr', 'Bouquets de bougies'), (3, 'ar', 'باقات الش�
وع'),
    (4, 'en', 'Event Favors'), (4, 'fr', 'Cadeaux d’invités'), (4, 'ar', 'توزيعات'),
    (5, 'en', 'Home Diffusers'), (5, 'fr', 'Diffuseurs d’intérieur'), (5, 'ar', '�
عطرات ال�
نزل'),
    (6, 'en', 'Accessories'), (6, 'fr', 'Accessoires'), (6, 'ar', 'إكسسوارات')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- WHAT: the shapes a product can take (flowers, bubble, bouquet, ...) and
--       their translated names — see product_types in migration 001 for why
--       this is separate from categories.
-- SECTION PURPOSE: Insert required reference/development rows into `product_types` for application lookups.
INSERT INTO product_types (id, slug, is_active) VALUES
    (1, 'flowers', 1),
    (2, 'bubble', 1),
    (3, 'in-container', 1),
    (4, 'bouquet', 1),
    (5, 'wax-melt', 1),
    (6, 'other', 1),
    (7, 'accessories', 1)
ON DUPLICATE KEY UPDATE slug = VALUES(slug), is_active = VALUES(is_active);

-- SECTION PURPOSE: Insert required reference/development rows into `product_type_translations` for application lookups.
INSERT INTO product_type_translations (product_type_id, language_code, name) VALUES
    (1, 'en', 'Flowers'), (1, 'fr', 'Fleurs'), (1, 'ar', 'أزهار'),
    (2, 'en', 'Bubble'), (2, 'fr', 'Bulles'), (2, 'ar', 'فقاعات'),
    (3, 'en', 'In container'), (3, 'fr', 'Dans un contenant'), (3, 'ar', 'في وعاء'),
    (4, 'en', 'Bouquet'), (4, 'fr', 'Bouquet'), (4, 'ar', 'باقة'),
    (5, 'en', 'Wax melt'), (5, 'fr', 'Fondant parfumé'), (5, 'ar', 'ش�
ع �
عطر قابل للذوبان'),
    (6, 'en', 'Other'), (6, 'fr', 'Autre'), (6, 'ar', 'أخرى'),
    (7, 'en', 'Accessories'), (7, 'fr', 'Accessoires'), (7, 'ar', 'إكسسوارات')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- WHAT: categories of raw inputs (wax, packaging, labor...) and their
--       translated names — no actual stock quantities here, those are
--       fake/demo-only and live in demo_seed.sql's `materials` insert.
-- SECTION PURPOSE: Insert required reference/development rows into `material_types` for application lookups.
INSERT INTO material_types
    (id, slug, is_consumable, is_recipe_input, is_active)
VALUES
    (1, 'raw-material', 1, 1, 1),
    (2, 'card', 1, 1, 1),
    (3, 'floral-paper', 1, 1, 1),
    (4, 'box', 1, 1, 1),
    (5, 'protective-packaging', 1, 1, 1),
    (6, 'labor', 0, 1, 1),
    (7, 'overhead', 0, 1, 1),
    (8, 'tool', 0, 0, 1)
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug),
    is_consumable = VALUES(is_consumable),
    is_recipe_input = VALUES(is_recipe_input),
    is_active = VALUES(is_active);

-- SECTION PURPOSE: Insert required reference/development rows into `material_type_translations` for application lookups.
INSERT INTO material_type_translations (material_type_id, language_code, name) VALUES
    (1, 'en', 'Raw materials'), (1, 'fr', 'Matières premières'), (1, 'ar', '�
واد أولية'),
    (2, 'en', 'Cards'), (2, 'fr', 'Cartes'), (2, 'ar', 'بطاقات'),
    (3, 'en', 'Floral paper'), (3, 'fr', 'Papier floral'), (3, 'ar', 'ورق تغليف الزهور'),
    (4, 'en', 'Boxes'), (4, 'fr', 'Boîtes'), (4, 'ar', 'علب'),
    (5, 'en', 'Protective packaging'), (5, 'fr', 'Emballage de protection'), (5, 'ar', 'تغليف واقٍ'),
    (6, 'en', 'Labor'), (6, 'fr', 'Main-d’œuvre'), (6, 'ar', 'يد عا�
لة'),
    (7, 'en', 'Overhead'), (7, 'fr', 'Frais généraux'), (7, 'ar', 'تكاليف عا�
ة'),
    (8, 'en', 'Tools'), (8, 'fr', 'Outils'), (8, 'ar', 'أدوات')
ON DUPLICATE KEY UPDATE name = VALUES(name);

COMMIT;
