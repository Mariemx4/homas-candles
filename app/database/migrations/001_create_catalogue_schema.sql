-- FILE PURPOSE: Database definition or seed data for 001 Create Catalogue Schema.
-- DATA FLOW: Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.

-- WHAT: the product catalogue schema (languages, categories, products,
--       materials, and every lookup/translation/junction table around them).
-- WHY:  this is migration 001 because every other table (orders, requests,
--       stock movements...) points back at a product, material, or category
--       defined here.

-- WHAT: the list of languages the site is translated into.
-- WHY:  every *_translations table below points at this table instead of
--       hardcoding 'en'/'fr'/'ar', so adding a language later is one row here.
-- SECTION PURPOSE: Define the `languages` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS languages (
    code CHAR(2) NOT NULL,
    name VARCHAR(50) NOT NULL,

    PRIMARY KEY (code),
    UNIQUE KEY uq_languages_name (name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: top-level catalogue sections (candles, candle-sets, bouquets, ...).
-- WHY:  drives the category pages/menus; slug is what shows up in the URL.
-- SECTION PURPOSE: Define the `categories` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    CONSTRAINT chk_categories_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: the display name of each category, per language.
-- WHY:  kept separate from `categories` so translated text never mixes with
--       structural data (slug, is_active) that's the same in every language.
--       Every *_translations table in this file follows this same split.
-- SECTION PURPOSE: Define the `category_translations` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS category_translations (
    category_id BIGINT UNSIGNED NOT NULL,
    language_code CHAR(2) NOT NULL,
    name VARCHAR(150) NOT NULL,

    PRIMARY KEY (category_id, language_code),
    CONSTRAINT fk_category_translations_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_category_translations_language
        FOREIGN KEY (language_code) REFERENCES languages (code)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: how a product is shaped/sold (flowers, bubble, in-container, bouquet...).
-- WHY:  separate from `categories` on purpose: a category is *where* a
--       product is listed, a product_type is *what shape* it is. A candle
--       and a home diffuser can share the "bubble" type.
-- SECTION PURPOSE: Define the `product_types` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS product_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    UNIQUE KEY uq_product_types_slug (slug),
    CONSTRAINT chk_product_types_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- SECTION PURPOSE: Define the `product_type_translations` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS product_type_translations (
    product_type_id BIGINT UNSIGNED NOT NULL,
    language_code CHAR(2) NOT NULL,
    name VARCHAR(150) NOT NULL,

    PRIMARY KEY (product_type_id, language_code),
    CONSTRAINT fk_product_type_translations_type
        FOREIGN KEY (product_type_id) REFERENCES product_types (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_product_type_translations_language
        FOREIGN KEY (language_code) REFERENCES languages (code)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: categories of raw inputs used to make products (wax, wick, labor...).
-- WHY:  is_consumable/is_recipe_input let you tell "things that get used up"
--       (wax) apart from "things that don't" (a reusable tool), and both
--       apart from "things that don't go in a recipe at all".
-- SECTION PURPOSE: Define the `material_types` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS material_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL,
    is_consumable TINYINT(1) NOT NULL DEFAULT 1,
    is_recipe_input TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    UNIQUE KEY uq_material_types_slug (slug),

    CONSTRAINT chk_material_types_consumable
        CHECK (is_consumable IN (0, 1)),
    CONSTRAINT chk_material_types_recipe_input
        CHECK (is_recipe_input IN (0, 1)),
    CONSTRAINT chk_material_types_active
        CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- SECTION PURPOSE: Define the `material_type_translations` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS material_type_translations (
    material_type_id BIGINT UNSIGNED NOT NULL,
    language_code CHAR(2) NOT NULL,
    name VARCHAR(150) NOT NULL,

    PRIMARY KEY (material_type_id, language_code),

    CONSTRAINT fk_material_type_translations_type
        FOREIGN KEY (material_type_id) REFERENCES material_types (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_material_type_translations_language
        FOREIGN KEY (language_code) REFERENCES languages (code)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: the actual stock items (soy wax, wicks, labor-hours...), one row per
--       material with its own cost and quantity on hand.
-- WHY:  this is the thing `product_materials` below points at to build a
--       recipe, and what `material_stock_movements` (migration 010) adjusts.
--       chk_materials_stock_tracking ties tracks_stock to stock_quantity so
--       you can't have a material that both "tracks stock" and has no
--       number, or "doesn't track stock" but still carries a stale number.
-- SECTION PURPOSE: Define the `materials` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS materials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_type_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(100) NOT NULL,
    name VARCHAR(180) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    stock_quantity DECIMAL(12,3) NULL,
    unit_cost DECIMAL(12,3) NOT NULL,
    tracks_stock TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    UNIQUE KEY uq_materials_sku (sku),
    KEY idx_materials_type (material_type_id),

    CONSTRAINT fk_materials_type
        FOREIGN KEY (material_type_id) REFERENCES material_types (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_materials_stock_quantity
        CHECK (stock_quantity IS NULL OR stock_quantity >= 0),
    CONSTRAINT chk_materials_unit_cost CHECK (unit_cost >= 0),
    CONSTRAINT chk_materials_tracks_stock CHECK (tracks_stock IN (0, 1)),
    CONSTRAINT chk_materials_active CHECK (is_active IN (0, 1)),
    CONSTRAINT chk_materials_stock_tracking
        CHECK (
            (tracks_stock = 1 AND stock_quantity IS NOT NULL)
            OR (tracks_stock = 0 AND stock_quantity IS NULL)
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: one row per sellable product (candle, set, bouquet, accessory...).
-- WHY:  pricing_reference_product_id lets a product (e.g. a diffuser) borrow
--       another product's price instead of duplicating it; purchase_mode
--       controls whether it can be added straight to cart or is
--       request-only (see docs/database-requirements.md for that rule).
-- SECTION PURPOSE: Define the `products` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NULL,
    product_type_id BIGINT UNSIGNED NULL,
    pricing_reference_product_id BIGINT UNSIGNED NULL,
    slug VARCHAR(180) NULL,
    selling_price DECIMAL(12,3) NULL,
    stock_quantity INT UNSIGNED NULL,
    candle_count INT UNSIGNED NULL,
    minimum_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    purchase_mode VARCHAR(20) NOT NULL DEFAULT 'CART',
    is_made_to_order TINYINT(1) NOT NULL DEFAULT 1,
    can_purchase_alone TINYINT(1) NOT NULL DEFAULT 1,
    main_image_path VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    UNIQUE KEY uq_products_main_image_path (main_image_path),
    KEY idx_products_category (category_id),
    KEY idx_products_product_type (product_type_id),
    KEY idx_products_pricing_reference (pricing_reference_product_id),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_products_product_type
        FOREIGN KEY (product_type_id) REFERENCES product_types (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_products_pricing_reference
        FOREIGN KEY (pricing_reference_product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_products_selling_price
        CHECK (selling_price IS NULL OR selling_price >= 0),
    CONSTRAINT chk_products_candle_count
        CHECK (candle_count IS NULL OR candle_count > 0),
    CONSTRAINT chk_products_minimum_quantity CHECK (minimum_quantity > 0),
    CONSTRAINT chk_products_purchase_mode
        CHECK (purchase_mode IN ('CART', 'REQUEST_ONLY')),
    CONSTRAINT chk_products_made_to_order CHECK (is_made_to_order IN (0, 1)),
    CONSTRAINT chk_products_purchase_alone CHECK (can_purchase_alone IN (0, 1)),
    CONSTRAINT chk_products_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- SECTION PURPOSE: Define the `product_translations` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS product_translations (
    product_id BIGINT UNSIGNED NOT NULL,
    language_code CHAR(2) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,

    PRIMARY KEY (product_id, language_code),
    CONSTRAINT fk_product_translations_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_product_translations_language
        FOREIGN KEY (language_code) REFERENCES languages (code)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: the sellable scents, each optionally backed by a fragrance material.
-- WHY:  fragrance_material_id is nullable+UNIQUE: a scent can exist without
--       a linked material yet, but once linked, two scents can't share one.
-- SECTION PURPOSE: Define the `scents` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS scents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fragrance_material_id BIGINT UNSIGNED NULL,
    slug VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    UNIQUE KEY uq_scents_fragrance_material (fragrance_material_id),
    UNIQUE KEY uq_scents_slug (slug),
    CONSTRAINT fk_scents_fragrance_material
        FOREIGN KEY (fragrance_material_id) REFERENCES materials (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_scents_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- SECTION PURPOSE: Define the `scent_translations` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS scent_translations (
    scent_id BIGINT UNSIGNED NOT NULL,
    language_code CHAR(2) NOT NULL,
    name VARCHAR(150) NOT NULL,

    PRIMARY KEY (scent_id, language_code),
    CONSTRAINT fk_scent_translations_scent
        FOREIGN KEY (scent_id) REFERENCES scents (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_scent_translations_language
        FOREIGN KEY (language_code) REFERENCES languages (code)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: the sellable colors, with an optional hex code for swatches.
-- WHY:  chk_colors_hex_code keeps hex_code either empty or a real "#rrggbb"
--       value, so front-end code can trust it as a CSS color with no extra
--       checking.
-- SECTION PURPOSE: Define the `colors` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS colors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(150) NOT NULL,
    hex_code CHAR(7) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    UNIQUE KEY uq_colors_slug (slug),
    CONSTRAINT chk_colors_hex_code
        CHECK (hex_code IS NULL OR hex_code REGEXP '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT chk_colors_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- SECTION PURPOSE: Define the `color_translations` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS color_translations (
    color_id BIGINT UNSIGNED NOT NULL,
    language_code CHAR(2) NOT NULL,
    name VARCHAR(150) NOT NULL,

    PRIMARY KEY (color_id, language_code),
    CONSTRAINT fk_color_translations_color
        FOREIGN KEY (color_id) REFERENCES colors (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_color_translations_language
        FOREIGN KEY (language_code) REFERENCES languages (code)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: which scents/colors a given product can be ordered in.
-- WHY:  many-to-many junction tables — a product can offer several scents
--       and several colors, and a scent/color can belong to many products.
-- SECTION PURPOSE: Define the `product_scents` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS product_scents (
    product_id BIGINT UNSIGNED NOT NULL,
    scent_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (product_id, scent_id),
    CONSTRAINT fk_product_scents_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_product_scents_scent
        FOREIGN KEY (scent_id) REFERENCES scents (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: like product_scents, but also stores the photo for that color variant.
-- SECTION PURPOSE: Define the `product_colors` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS product_colors (
    product_id BIGINT UNSIGNED NOT NULL,
    color_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NULL,

    PRIMARY KEY (product_id, color_id),
    UNIQUE KEY uq_product_colors_image_path (image_path),
    CONSTRAINT fk_product_colors_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_product_colors_color
        FOREIGN KEY (color_id) REFERENCES colors (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: the "recipe" for a product — which materials it needs and how much.
-- WHY:  recipe_type ('ACTUAL' vs 'CANDLE_EQUIVALENT' in the seed data) lets
--       the same product track its real material usage separately from a
--       notional equivalent used for cost comparison — that's why
--       (product_id, material_id, recipe_type) together are the primary key
--       instead of just (product_id, material_id).
-- SECTION PURPOSE: Define the `product_materials` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS product_materials (
    product_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    recipe_type VARCHAR(30) NOT NULL,
    quantity_required DECIMAL(12,3) NOT NULL,

    PRIMARY KEY (product_id, material_id, recipe_type),
    CONSTRAINT fk_product_materials_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_product_materials_material
        FOREIGN KEY (material_id) REFERENCES materials (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_product_materials_recipe_type
        CHECK (recipe_type IN ('ACTUAL', 'CANDLE_EQUIVALENT')),
    CONSTRAINT chk_product_materials_quantity CHECK (quantity_required > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- WHAT: which products make up a bundle/set product (e.g. "Duo Candle Set"
--       = 1x pink candle + 1x white candle + 1x tray).
-- WHY:  set_product_id and component_product_id both point at `products`,
--       so a set is just another product that happens to bundle others;
--       chk_set_components_not_self stops a set from containing itself.
-- SECTION PURPOSE: Define the `set_components` table, its valid columns, and integrity constraints used by repositories.
CREATE TABLE IF NOT EXISTS set_components (
    set_product_id BIGINT UNSIGNED NOT NULL,
    component_product_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,

    PRIMARY KEY (set_product_id, component_product_id),
    CONSTRAINT fk_set_components_set
        FOREIGN KEY (set_product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_set_components_component
        FOREIGN KEY (component_product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_set_components_quantity CHECK (quantity > 0),
    CONSTRAINT chk_set_components_not_self CHECK (set_product_id <> component_product_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
