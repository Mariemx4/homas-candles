-- WHAT: fake catalogue data (products, materials with stock, scents,
--       colors, recipes) layered on top of what seed.sql already inserted.
-- WHY:  gives you something to click through and test on localhost without
--       hand-typing products every time you rebuild the database — never
--       point this at a real/production database.
-- Optional, self-contained development/demo data. Never run in production.
START TRANSACTION;

INSERT INTO languages (code, name) VALUES
    ('en', 'English'),
    ('fr', 'Français'),
    ('ar', 'العربية')
ON DUPLICATE KEY UPDATE name = VALUES(name);


INSERT INTO categories (id, slug, is_active) VALUES
    (1, 'candles', 1),
    (2, 'candle-sets', 1),
    (3, 'bouquets', 1),
    (4, 'event-favors', 1),
    (5, 'home-diffusers', 1),
    (6, 'accessories', 1)
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug),
    is_active = VALUES(is_active);


INSERT INTO category_translations
    (category_id, language_code, name)
VALUES
    (1, 'en', 'Candles'),
    (1, 'fr', 'Bougies'),
    (1, 'ar', 'شموع'),

    (2, 'en', 'Candle Sets'),
    (2, 'fr', 'Coffrets de bougies'),
    (2, 'ar', 'مجموعات الشموع'),

    (3, 'en', 'Candle Bouquets'),
    (3, 'fr', 'Bouquets de bougies'),
    (3, 'ar', 'باقات الشموع'),

    (4, 'en', 'Event Favors'),
    (4, 'fr', 'Cadeaux d’invités'),
    (4, 'ar', 'توزيعات'),

    (5, 'en', 'Home Diffusers'),
    (5, 'fr', 'Diffuseurs d’intérieur'),
    (5, 'ar', 'معطرات المنزل'),

    (6, 'en', 'Accessories'),
    (6, 'fr', 'Accessoires'),
    (6, 'ar', 'إكسسوارات')
ON DUPLICATE KEY UPDATE name = VALUES(name);


INSERT INTO product_types (id, slug, is_active) VALUES
    (1, 'flowers', 1),
    (2, 'bubble', 1),
    (3, 'in-container', 1),
    (4, 'bouquet', 1),
    (5, 'wax-melt', 1),
    (6, 'other', 1),
    (7, 'accessories', 1)
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug),
    is_active = VALUES(is_active);


INSERT INTO product_type_translations
    (product_type_id, language_code, name)
VALUES
    (1, 'en', 'Flowers'),
    (1, 'fr', 'Fleurs'),
    (1, 'ar', 'أزهار'),

    (2, 'en', 'Bubble'),
    (2, 'fr', 'Bulles'),
    (2, 'ar', 'فقاعات'),

    (3, 'en', 'In container'),
    (3, 'fr', 'Dans un contenant'),
    (3, 'ar', 'في وعاء'),

    (4, 'en', 'Bouquet'),
    (4, 'fr', 'Bouquet'),
    (4, 'ar', 'باقة'),

    (5, 'en', 'Wax melt'),
    (5, 'fr', 'Fondant parfumé'),
    (5, 'ar', 'شمع معطر قابل للذوبان'),

    (6, 'en', 'Other'),
    (6, 'fr', 'Autre'),
    (6, 'ar', 'أخرى'),

    (7, 'en', 'Accessories'),
    (7, 'fr', 'Accessoires'),
    (7, 'ar', 'إكسسوارات')
ON DUPLICATE KEY UPDATE name = VALUES(name);


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


INSERT INTO material_type_translations
    (material_type_id, language_code, name)
VALUES
    (1, 'en', 'Raw materials'),
    (1, 'fr', 'Matières premières'),
    (1, 'ar', 'مواد أولية'),

    (2, 'en', 'Cards'),
    (2, 'fr', 'Cartes'),
    (2, 'ar', 'بطاقات'),

    (3, 'en', 'Floral paper'),
    (3, 'fr', 'Papier floral'),
    (3, 'ar', 'ورق تغليف الزهور'),

    (4, 'en', 'Boxes'),
    (4, 'fr', 'Boîtes'),
    (4, 'ar', 'علب'),

    (5, 'en', 'Protective packaging'),
    (5, 'fr', 'Emballage de protection'),
    (5, 'ar', 'تغليف واقٍ'),

    (6, 'en', 'Labor'),
    (6, 'fr', 'Main-d’œuvre'),
    (6, 'ar', 'يد عاملة'),

    (7, 'en', 'Overhead'),
    (7, 'fr', 'Frais généraux'),
    (7, 'ar', 'تكاليف عامة'),

    (8, 'en', 'Tools'),
    (8, 'fr', 'Outils'),
    (8, 'ar', 'أدوات')
ON DUPLICATE KEY UPDATE name = VALUES(name);


-- WHAT: ten fake raw materials with real-ish costs and stock levels.
-- WHY:  gives product_materials below something to build recipes from, and
--       lets you test the stock_quantity math without touching production
--       inventory numbers.
INSERT INTO materials
    (
        id,
        material_type_id,
        sku,
        name,
        unit,
        stock_quantity,
        unit_cost,
        tracks_stock,
        is_active
    )
VALUES
    (1, 1, 'MAT-SOY-WAX', 'Soy wax', 'gram', 50000.000, 0.012, 1, 1),
    (2, 1, 'MAT-BEESWAX', 'Beeswax', 'gram', 25000.000, 0.025, 1, 1),
    (3, 1, 'MAT-WICK', 'Cotton wick', 'piece', 500.000, 0.250, 1, 1),
    (4, 1, 'MAT-CONTAINER', 'Glass container', 'piece', 100.000, 3.500, 1, 1),
    (5, 5, 'MAT-PACKAGING', 'Standard packaging', 'piece', 200.000, 1.200, 1, 1),
    (6, 1, 'MAT-VANILLA', 'Vanilla fragrance oil', 'millilitre', 3000.000, 0.080, 1, 1),
    (7, 1, 'MAT-ROSE', 'Rose fragrance oil', 'millilitre', 2500.000, 0.090, 1, 1),
    (8, 1, 'MAT-LAVENDER', 'Lavender fragrance oil', 'millilitre', 2000.000, 0.085, 1, 1),
    (9, 6, 'COST-LABOR', 'Production labor', 'hour', NULL, 6.000, 0, 1),
    (10, 3, 'MAT-BOUQUET-WRAP', 'Bouquet wrapping', 'piece', 100.000, 2.500, 1, 1)
ON DUPLICATE KEY UPDATE
    material_type_id = VALUES(material_type_id),
    sku = VALUES(sku),
    name = VALUES(name),
    unit = VALUES(unit),
    stock_quantity = VALUES(stock_quantity),
    unit_cost = VALUES(unit_cost),
    tracks_stock = VALUES(tracks_stock),
    is_active = VALUES(is_active);


-- WHAT: the seven fake products shown across the storefront.
-- WHY:  covers every purchase_mode/product_type combination the front-end
--       needs to render: cart-buyable candles (1, 2), a bundled set (3), a
--       request-only bouquet (4), a bulk event favor (5), a diffuser that
--       borrows product 1's price (6, via pricing_reference_product_id),
--       and a stocked accessory that can't be bought alone (7).
INSERT INTO products
    (
        id,
        category_id,
        product_type_id,
        pricing_reference_product_id,
        slug,
        selling_price,
        stock_quantity,
        candle_count,
        minimum_quantity,
        purchase_mode,
        is_made_to_order,
        can_purchase_alone,
        main_image_path,
        is_active
    )
VALUES
    (
        1, 1, 2, NULL,
        'pink-bubble-candle',
        40.000, NULL, NULL, 1,
        'CART', 1, 1,
        'assets/products/candles/pink-bubble-candle/main.jpg',
        1
    ),
    (
        2, 1, 1, NULL,
        'white-flower-candle',
        45.000, NULL, NULL, 1,
        'CART', 1, 1,
        'assets/products/candles/white-flower-candle/main.jpg',
        1
    ),
    (
        3, 2, NULL, NULL,
        'duo-candle-set',
        93.000, NULL, NULL, 1,
        'CART', 1, 1,
        'assets/products/candle-sets/duo-candle-set/main.jpg',
        1
    ),
    (
        4, 3, 4, NULL,
        'rose-candle-bouquet',
        60.000, NULL, 6, 1,
        'REQUEST_ONLY', 1, 1,
        'assets/products/bouquets/rose-candle-bouquet/main.jpg',
        1
    ),
    (
        5, 4, 1, NULL,
        'flower-event-favor',
        12.000, NULL, 1, 10,
        'REQUEST_ONLY', 1, 1,
        'assets/products/event-favors/flower-event-favor/main.jpg',
        1
    ),
    (
        6, 5, 2, 1,
        'bubble-home-diffuser',
        40.000, NULL, NULL, 1,
        'CART', 1, 1,
        'assets/products/home-diffusers/bubble-home-diffuser/main.jpg',
        1
    ),
    (
        7, 6, 7, NULL,
        'gold-decorative-tray',
        8.000, 20, NULL, 1,
        'CART', 0, 0,
        'assets/products/accessories/gold-decorative-tray/main.jpg',
        1
    )
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    product_type_id = VALUES(product_type_id),
    pricing_reference_product_id = VALUES(pricing_reference_product_id),
    slug = VALUES(slug),
    selling_price = VALUES(selling_price),
    stock_quantity = VALUES(stock_quantity),
    candle_count = VALUES(candle_count),
    minimum_quantity = VALUES(minimum_quantity),
    purchase_mode = VALUES(purchase_mode),
    is_made_to_order = VALUES(is_made_to_order),
    can_purchase_alone = VALUES(can_purchase_alone),
    main_image_path = VALUES(main_image_path),
    is_active = VALUES(is_active);


INSERT INTO product_translations
    (product_id, language_code, name, description)
VALUES
    (
        1,
        'en',
        'Pink Bubble Candle',
        'A handmade bubble candle with a customizable color and scent.'
    ),
    (
        1,
        'fr',
        'Bougie bubble rose',
        'Une bougie bubble artisanale avec une couleur et un parfum personnalisables.'
    ),
    (
        1,
        'ar',
        'شمعة فقاعات وردية',
        'شمعة فقاعات مصنوعة يدوياً بلون ورائحة قابلين للتخصيص.'
    ),

    (
        2,
        'en',
        'White Flower Candle',
        'A handmade flower-shaped candle with customizable options.'
    ),
    (
        2,
        'fr',
        'Bougie fleur blanche',
        'Une bougie artisanale en forme de fleur avec des options personnalisables.'
    ),
    (
        2,
        'ar',
        'شمعة زهرة بيضاء',
        'شمعة مصنوعة يدوياً على شكل زهرة مع خيارات قابلة للتخصيص.'
    ),

    (
        3,
        'en',
        'Duo Candle Set',
        'A set containing two handmade candles and a decorative tray.'
    ),
    (
        3,
        'fr',
        'Coffret duo de bougies',
        'Un coffret composé de deux bougies artisanales et d’un plateau décoratif.'
    ),
    (
        3,
        'ar',
        'مجموعة شمعتين',
        'مجموعة تحتوي على شمعتين مصنوعتين يدوياً وصينية زخرفية.'
    ),

    (
        4,
        'en',
        'Rose Candle Bouquet',
        'A request-only bouquet containing six handmade candles.'
    ),
    (
        4,
        'fr',
        'Bouquet de bougies roses',
        'Un bouquet sur demande contenant six bougies artisanales.'
    ),
    (
        4,
        'ar',
        'باقة شموع وردية',
        'باقة حسب الطلب تحتوي على ست شموع مصنوعة يدوياً.'
    ),

    (
        5,
        'en',
        'Flower Event Favor',
        'Custom flower-shaped candle favors with a minimum quantity of ten.'
    ),
    (
        5,
        'fr',
        'Cadeau d’invité floral',
        'Des cadeaux personnalisés en forme de fleur avec un minimum de dix pièces.'
    ),
    (
        5,
        'ar',
        'توزيعات زهور',
        'توزيعات شموع مخصصة على شكل زهور بحد أدنى عشر قطع.'
    ),

    (
        6,
        'en',
        'Bubble Home Diffuser',
        'A bubble-shaped home diffuser using candle-equivalent pricing.'
    ),
    (
        6,
        'fr',
        'Diffuseur d’intérieur bubble',
        'Un diffuseur d’intérieur en forme de bulles avec un prix équivalent à la bougie.'
    ),
    (
        6,
        'ar',
        'معطر منزل بشكل فقاعات',
        'معطر منزل بشكل فقاعات يعتمد تسعير الشمعة المكافئة.'
    ),

    (
        7,
        'en',
        'Gold Decorative Tray',
        'A stocked decorative tray available only with a candle or set.'
    ),
    (
        7,
        'fr',
        'Plateau décoratif doré',
        'Un plateau décoratif disponible uniquement avec une bougie ou un coffret.'
    ),
    (
        7,
        'ar',
        'صينية زخرفية ذهبية',
        'صينية زخرفية متوفرة مع شمعة أو مجموعة فقط.'
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description);


-- WHAT: the three fake scents, each linked to its fragrance-oil material.
INSERT INTO scents
    (id, fragrance_material_id, slug, is_active)
VALUES
    (1, 6, 'vanilla', 1),
    (2, 7, 'rose', 1),
    (3, 8, 'lavender', 1)
ON DUPLICATE KEY UPDATE
    fragrance_material_id = VALUES(fragrance_material_id),
    slug = VALUES(slug),
    is_active = VALUES(is_active);


INSERT INTO scent_translations
    (scent_id, language_code, name)
VALUES
    (1, 'en', 'Vanilla'),
    (1, 'fr', 'Vanille'),
    (1, 'ar', 'فانيليا'),

    (2, 'en', 'Rose'),
    (2, 'fr', 'Rose'),
    (2, 'ar', 'ورد'),

    (3, 'en', 'Lavender'),
    (3, 'fr', 'Lavande'),
    (3, 'ar', 'لافندر')
ON DUPLICATE KEY UPDATE name = VALUES(name);


-- WHAT: the four fake colors, with swatch hex codes.
INSERT INTO colors
    (id, slug, hex_code, is_active)
VALUES
    (1, 'pink', '#F4B6C2', 1),
    (2, 'white', '#FFFFFF', 1),
    (3, 'beige', '#E8D8C4', 1),
    (4, 'red', '#B83232', 1)
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug),
    hex_code = VALUES(hex_code),
    is_active = VALUES(is_active);


INSERT INTO color_translations
    (color_id, language_code, name)
VALUES
    (1, 'en', 'Pink'),
    (1, 'fr', 'Rose'),
    (1, 'ar', 'وردي'),

    (2, 'en', 'White'),
    (2, 'fr', 'Blanc'),
    (2, 'ar', 'أبيض'),

    (3, 'en', 'Beige'),
    (3, 'fr', 'Beige'),
    (3, 'ar', 'بيج'),

    (4, 'en', 'Red'),
    (4, 'fr', 'Rouge'),
    (4, 'ar', 'أحمر')
ON DUPLICATE KEY UPDATE name = VALUES(name);


-- WHAT: which scents each product can be ordered in.
INSERT INTO product_scents
    (product_id, scent_id)
VALUES
    (1, 1),
    (1, 2),
    (1, 3),
    (2, 2),
    (2, 3),
    (3, 1),
    (3, 2),
    (3, 3),
    (6, 1),
    (6, 2),
    (6, 3)
ON DUPLICATE KEY UPDATE scent_id = VALUES(scent_id);


-- WHAT: which colors each product can be ordered in, plus that color's photo.
INSERT INTO product_colors
    (product_id, color_id, image_path)
VALUES
    (1, 1, 'assets/products/candles/pink-bubble-candle/pink.jpg'),
    (1, 2, 'assets/products/candles/pink-bubble-candle/white.jpg'),

    (2, 1, 'assets/products/candles/white-flower-candle/pink.jpg'),
    (2, 2, 'assets/products/candles/white-flower-candle/white.jpg'),

    (3, 1, 'assets/products/candle-sets/duo-candle-set/pink.jpg'),
    (3, 3, 'assets/products/candle-sets/duo-candle-set/beige.jpg'),

    (4, 1, 'assets/products/bouquets/rose-candle-bouquet/pink.jpg'),
    (4, 4, 'assets/products/bouquets/rose-candle-bouquet/red.jpg'),

    (5, 1, 'assets/products/event-favors/flower-event-favor/pink.jpg'),
    (5, 2, 'assets/products/event-favors/flower-event-favor/white.jpg'),

    (6, 1, 'assets/products/home-diffusers/bubble-home-diffuser/pink.jpg'),
    (6, 2, 'assets/products/home-diffusers/bubble-home-diffuser/white.jpg'),

    (7, 3, 'assets/products/accessories/gold-decorative-tray/beige.jpg')
ON DUPLICATE KEY UPDATE image_path = VALUES(image_path);


-- WHAT: the material recipe behind each product.
-- WHY:  'ACTUAL' rows are what the product really consumes; product 6's
--       extra 'CANDLE_EQUIVALENT' rows are a notional recipe used only for
--       cost comparison against candle product 1, whose price it borrows.
INSERT INTO product_materials
    (product_id, material_id, recipe_type, quantity_required)
VALUES
    (1, 1, 'ACTUAL', 120.000),
    (1, 2, 'ACTUAL', 80.000),
    (1, 3, 'ACTUAL', 1.000),
    (1, 5, 'ACTUAL', 1.000),
    (1, 9, 'ACTUAL', 0.500),

    (2, 1, 'ACTUAL', 100.000),
    (2, 2, 'ACTUAL', 100.000),
    (2, 3, 'ACTUAL', 1.000),
    (2, 5, 'ACTUAL', 1.000),
    (2, 9, 'ACTUAL', 0.600),

    (3, 5, 'ACTUAL', 1.000),
    (3, 9, 'ACTUAL', 0.250),

    (4, 10, 'ACTUAL', 1.000),
    (4, 9, 'ACTUAL', 1.000),

    (5, 1, 'ACTUAL', 20.000),
    (5, 2, 'ACTUAL', 10.000),
    (5, 3, 'ACTUAL', 1.000),
    (5, 5, 'ACTUAL', 1.000),
    (5, 9, 'ACTUAL', 0.100),

    (6, 1, 'ACTUAL', 180.000),
    (6, 2, 'ACTUAL', 20.000),
    (6, 5, 'ACTUAL', 1.000),
    (6, 9, 'ACTUAL', 0.400),

    (6, 1, 'CANDLE_EQUIVALENT', 120.000),
    (6, 2, 'CANDLE_EQUIVALENT', 80.000),
    (6, 3, 'CANDLE_EQUIVALENT', 1.000),
    (6, 5, 'CANDLE_EQUIVALENT', 1.000),
    (6, 9, 'CANDLE_EQUIVALENT', 0.500)
ON DUPLICATE KEY UPDATE
    quantity_required = VALUES(quantity_required);


-- WHAT: the "Duo Candle Set" (product 3) is built from products 1, 2, and 7.
INSERT INTO set_components
    (set_product_id, component_product_id, quantity)
VALUES
    (3, 1, 1),
    (3, 2, 1),
    (3, 7, 1)
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);


COMMIT;
