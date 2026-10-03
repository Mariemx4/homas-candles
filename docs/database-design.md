# DATABASE DESIGN

## languages

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| code | CHAR(2) | Identifies a language such as en, fr, or ar | Primary key | Yes | Yes |
| name | VARCHAR(50) | Stores the language name | — | Yes | Yes |


## categories

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one category | Primary key | Yes | Yes |
| slug | VARCHAR(150) | Provides the URL-friendly category name | — | Yes | Yes |
| is_active | BOOLEAN | Controls whether the category is available | — | Yes | No |


## category_translations

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| category_id | BIGINT UNSIGNED | Identifies the translated category | Primary key and foreign key → categories.id | Yes | With language_code |
| language_code | CHAR(2) | Identifies the translation language | Primary key and foreign key → languages.code | Yes | With category_id |
| name | VARCHAR(150) | Stores the translated category name | — | Yes | No |

The combination of `category_id` and `language_code` is the primary key. This prevents two translations in the same language for one category.


## product_types

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one product type | Primary key | Yes | Yes |
| slug | VARCHAR(150) | Provides its URL-friendly name | — | Yes | Yes |
| is_active | BOOLEAN | Controls whether the type is available | — | Yes | No |


## product_type_translations

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| product_type_id | BIGINT UNSIGNED | Identifies the translated product type | Primary key and foreign key → product_types.id | Yes | With language_code |
| language_code | CHAR(2) | Identifies the translation language | Primary key and foreign key → languages.code | Yes | With product_type_id |
| name | VARCHAR(150) | Stores the translated type name | — | Yes | No |


## products

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one product | Primary key | Yes | Yes |
| category_id | BIGINT UNSIGNED | Connects the product to its category | Foreign key → categories.id | Required to activate | No |
| product_type_id | BIGINT UNSIGNED | Connects an individual product to its type | Foreign key → product_types.id | Conditional | No |
| pricing_reference_product_id | BIGINT UNSIGNED | Links a diffuser to an equivalent candle when one exists | Foreign key → products.id | No | No |
| slug | VARCHAR(180) | Provides the product-page URL name | — | Required to activate | Yes |
| selling_price | DECIMAL(12,3) | Stores the current calculated selling price | — | Required to activate | No |
| stock_quantity | INT UNSIGNED | Stores accessory stock quantity | — | Accessories only | No |
| candle_count | INT UNSIGNED | Stores candle count for bouquets or event favors | — | No | No |
| minimum_quantity | INT UNSIGNED | Stores the minimum allowed order quantity | — | Yes | No |
| purchase_mode | VARCHAR(20) | Identifies cart or request-only products | — | Yes | No |
| is_made_to_order | BOOLEAN | Identifies products produced after confirmation | — | Yes | No |
| can_purchase_alone | BOOLEAN | Controls whether the product can be purchased alone | — | Yes | No |
| main_image_path | VARCHAR(500) | Stores the main product image path | — | Required to activate | Yes |
| is_active | BOOLEAN | Controls public product availability | — | Yes | No |

Notes:

- `product_type_id` is required for individual products.
- A set’s visible types are derived from its component products.
- `stock_quantity` is used for accessories.
- Event Favors use `minimum_quantity = 10`.
- `purchase_mode` can contain `CART` or `REQUEST_ONLY`.
- Actual cost and net profit are calculated rather than manually maintained.


## product_translations

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| product_id | BIGINT UNSIGNED | Identifies the translated product | Primary key and foreign key → products.id | Yes | With language_code |
| language_code | CHAR(2) | Identifies the translation language | Primary key and foreign key → languages.code | Yes | With product_id |
| name | VARCHAR(180) | Stores the translated product name | — | Yes | No |
| description | TEXT | Stores the translated description | — | Yes | No |

An active product must have English, French, and Arabic translation rows.


## material_types

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one material type | Primary key | Yes | Yes |
| slug | VARCHAR(100) | Provides a stable internal type name | — | Yes | Yes |
| is_consumable | BOOLEAN | Determines whether production consumes the item | — | Yes | No |
| is_recipe_input | BOOLEAN | Determines whether the type may contribute to a product recipe or cost | — | Yes | No |
| is_active | BOOLEAN | Controls whether the type is available for new materials | — | Yes | No |

Material types include raw materials, cards, floral paper, boxes, protective packaging, labor, overhead, and tools.


## material_type_translations

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| material_type_id | BIGINT UNSIGNED | Identifies the translated material type | Primary key and foreign key → material_types.id | Yes | With language_code |
| language_code | CHAR(2) | Identifies the translation language | Primary key and foreign key → languages.code | Yes | With material_type_id |
| name | VARCHAR(150) | Stores the translated type name | — | Yes | No |


## materials

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one material or cost item | Primary key | Yes | Yes |
| material_type_id | BIGINT UNSIGNED | Classifies the material or cost item | Foreign key → material_types.id | Yes | No |
| sku | VARCHAR(100) | Provides an internal material code | — | Yes | Yes |
| name | VARCHAR(180) | Stores its admin-facing name | — | Yes | No |
| unit | VARCHAR(30) | Stores grams, millilitres, pieces, or another unit | — | Yes | No |
| stock_quantity | DECIMAL(12,3) | Stores the available material quantity | — | Stock-tracked items only | No |
| minimum_stock_level | DECIMAL(12,3) NULL | Administrator-configured threshold in the material unit; NULL means unconfigured | Nonnegative CHECK | Required on tracked admin saves; legacy NULL allowed | No |
| unit_cost | DECIMAL(12,3) | Stores the current cost for one unit | — | Yes | No |
| tracks_stock | BOOLEAN | Determines whether an inventory quantity is maintained | — | Yes | No |
| is_active | BOOLEAN | Controls whether the material can be used | — | Yes | No |


## scents

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one scent | Primary key | Yes | Yes |
| fragrance_material_id | BIGINT UNSIGNED | Connects the scent to its stocked fragrance material | Foreign key → materials.id | No | Yes |
| slug | VARCHAR(150) | Provides a stable scent identifier | — | Yes | Yes |
| is_active | BOOLEAN | Controls whether the scent is available | — | Yes | No |


## scent_translations

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| scent_id | BIGINT UNSIGNED | Identifies the translated scent | Primary key and foreign key → scents.id | Yes | With language_code |
| language_code | CHAR(2) | Identifies the translation language | Primary key and foreign key → languages.code | Yes | With scent_id |
| name | VARCHAR(150) | Stores the translated scent name | — | Yes | No |


## colors

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one color | Primary key | Yes | Yes |
| slug | VARCHAR(150) | Provides a stable color identifier | — | Yes | Yes |
| hex_code | CHAR(7) | Stores an optional visual value such as #F4DADA | — | No | No |
| is_active | BOOLEAN | Controls whether the color is available | — | Yes | No |


## color_translations

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| color_id | BIGINT UNSIGNED | Identifies the translated color | Primary key and foreign key → colors.id | Yes | With language_code |
| language_code | CHAR(2) | Identifies the translation language | Primary key and foreign key → languages.code | Yes | With color_id |
| name | VARCHAR(150) | Stores the translated color name | — | Yes | No |


## product_scents

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| product_id | BIGINT UNSIGNED | Identifies the product | Primary key and foreign key → products.id | Yes | With scent_id |
| scent_id | BIGINT UNSIGNED | Identifies an available scent | Primary key and foreign key → scents.id | Yes | With product_id |

The combined primary key prevents assigning the same scent to one product twice.


## product_colors

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| product_id | BIGINT UNSIGNED | Identifies the product | Primary key and foreign key → products.id | Yes | With color_id |
| color_id | BIGINT UNSIGNED | Identifies an available color | Primary key and foreign key → colors.id | Yes | With product_id |
| image_path | VARCHAR(500) | Stores this product color’s image path | — | Required to activate | Yes |

The combined primary key prevents assigning the same color to one product twice.


## product_materials

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| product_id | BIGINT UNSIGNED | Identifies the product recipe | Primary key and foreign key → products.id | Yes | With material_id and recipe_type |
| material_id | BIGINT UNSIGNED | Identifies the required material or cost item | Primary key and foreign key → materials.id | Yes | With product_id and recipe_type |
| recipe_type | VARCHAR(30) | Identifies an actual or candle-equivalent recipe | Primary key | Yes | With product_id and material_id |
| quantity_required | DECIMAL(12,3) | Stores the quantity required for one product | — | Yes | No |

Possible recipe types:

- `ACTUAL`
- `CANDLE_EQUIVALENT`

Only the actual recipe reduces inventory. Candle-equivalent recipes are used for diffuser pricing.


## material_stock_movements

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | Identifies one stock movement | Primary key | Yes | Yes |
| material_id | BIGINT UNSIGNED | Identifies the affected material | Foreign key → materials.id | Yes | With order_id and movement_type when order-related |
| order_id | BIGINT UNSIGNED | Identifies the related order for deductions and restocks | Foreign key → orders.id | For order movements | With material_id and movement_type |
| movement_type | VARCHAR(30) | Describes why stock changed | — | Yes | With order_id and material_id |
| quantity_change | DECIMAL(12,3) | Stores a positive addition or negative removal | — | Yes | No |
| unit_cost_snapshot | DECIMAL(12,3) | Preserves the cost at movement time | — | Yes | No |
| stock_before | DECIMAL(12,3) | Preserves stock before the movement | — | Yes | No |
| stock_after | DECIMAL(12,3) | Preserves stock after the movement | — | Yes | No |
| performed_by_administrator_id | BIGINT UNSIGNED | Identifies the administrator responsible | Foreign key → administrators_accounts.id | No | No |
| movement_note | VARCHAR(500) | Explains adjustments, damage, or waste | — | For manual adjustments and waste | No |
| created_at | TIMESTAMP | Records when the movement occurred | — | Yes | No |

Movement types are `STOCK_IN`, `ORDER_DEDUCTION`, `ORDER_RESTOCK`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, and `DAMAGED_OR_WASTED`. Positive quantities add stock and negative quantities remove it.

The unique `(order_id, material_id, movement_type)` rule prevents duplicate order deductions and restocks. Because SQL unique indexes permit multiple `NULL` values, unrelated manual movements can still occur repeatedly. Every material balance update and its movement record must be committed in one PHP transaction. Preparing an order also includes its order-status update and status-history record in that transaction.


## set_components

| Field | Type | Purpose | Key | Required? | Unique? |
|---|---|---|---|---|---|
| set_product_id | BIGINT UNSIGNED | Identifies the candle set | Primary key and foreign key → products.id | Yes | With component_product_id |
| component_product_id | BIGINT UNSIGNED | Identifies a candle or accessory inside the set | Primary key and foreign key → products.id | Yes | With set_product_id |
| quantity | INT UNSIGNED | Stores how many of the component are included | — | Yes | No |

Rules:

- A set cannot contain itself.
- An active set must contain at least two candle products.
- Accessories may be included in addition to the candles.


# CARDINALITIES

- One category has many category translations.
- One category has many products.
- One product type has many translations.
- One product type can belong to many individual products.
- One product has many translations.
- One product can have many scents.
- One scent can belong to many products.
- One product can have many colors.
- One color can belong to many products.
- One product can require many materials.
- One material can be required by many products.
- One set can contain many component products.
- One product can appear in many sets.
- One diffuser may reference one equivalent candle.
- One candle may provide pricing for several diffusers.


# RELATIONSHIP MAP

languages

  ├──< category_translations >── categories

  ├──< product_type_translations >── product_types

  ├──< product_translations >── products

  ├──< scent_translations >── scents

  └──< color_translations >── colors


categories 1 ──────── many products

product_types 1 ──── many products

products many ────── many scents
            through product_scents

products many ────── many colors
            through product_colors

products many ────── many materials
            through product_materials

set product many ─── many component products
            through set_components

home diffuser many ── zero-or-one matching candle
            through pricing_reference_product_id
