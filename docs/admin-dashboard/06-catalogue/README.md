# Module 06: Catalogue

## Objective

Manage products and their multilingual, visual, pricing, variant, and set configuration without damaging historical orders.

## Build

- Paginated/searchable product list with category, type, active state, and purchase-mode filters.
- Create/edit flows for categories, product types, products, English/French/Arabic translations, scents, colors, and color-specific images.
- Product configuration for price, minimum quantity, made-to-order, standalone purchase, cart/request-only mode, candle count, and diffuser pricing reference.
- Candle-set component editor enforcing component rules.
- Draft/inactive workflow and activation-readiness validation.
- Safe image upload/replacement with generated filenames and cleanup that cannot delete shared or historical assets.
- Archive/deactivate behavior rather than destructive deletion when referenced historically.

## Acceptance criteria

1. All routes require an active administrator; mutations require POST and CSRF.
2. Slugs and identifiers are validated and unique conflicts produce field-level errors without leaking SQL.
3. A product cannot activate until every schema-required category, slug, price, image, and English/French/Arabic translation is valid.
4. Conditional rules for product type, stock quantity, minimum quantity, purchase mode, and diffuser references are enforced server-side.
5. Scent/color assignments cannot be duplicated, and color images meet activation requirements.
6. A set cannot contain itself and an active candle set meets the documented component requirements.
7. Uploads enforce allowed MIME content, extension, maximum size, generated names, and non-executable storage.
8. Deactivation does not alter historical order-item snapshots or break public pages.
9. Forms preserve valid input after validation errors and work across target sizes and keyboard-only use.
10. Tests cover activation readiness, translations, upload rejection, relationship rules, unique conflicts, authorization, and safe archival.
