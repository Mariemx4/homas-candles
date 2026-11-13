# Product selectors and Custom Request composition

This report supersedes the prior assumption that every Custom Request component must be new. The existing PHP/PostgreSQL application was modified; no separate search API or Item catalogue was created. Migration 025 was applied locally.

1. **Files changed.** UI: `public/assets/js/custom_select.js`, `admin-manual-order.js`, `admin-custom-request.js`, and `public/assets/css/pages/admin.css`. Views: `app/Views/Pages/admin/orders/create.php`, `app/Views/Components/Admin/custom-request-products.php`, `app/Views/Pages/admin/requests/detail.php`. Backend: `src/Controllers/CustomRequestController.php`; repositories `CustomRequestRepository.php`, `OrderProductionRepository.php`, `AccessoryStockRepository.php`; services `CustomRequestService.php`, `CustomRequestConversionService.php`, `OrderProductionService.php`. Schema: `app/database/migrations/025_custom_request_composition.sql`. Tests: `verify-order-pricing-snapshots.php`, `verify-manual-order-customers.php`, `verify-order-product-selection.js`, and new `verify-searchable-select.js`. This report is also new.

2. **Existing components/functions reused.** `initCustomSelects` now supplies the shared searchable behavior. Existing catalogue loading, active taxonomy/options, customer modes, conversion transaction, order pricing, recipe snapshot/cost functions, production status transitions, accessory stock service/ledger, catalogue component repository, and readiness/activation remain authoritative.

3. **Necessary new code.** Migration 025 adds requested-product option IDs/modes and a child composition table referencing existing catalogue records. It does not add another Product, Item, recipe, customer, or pricing system. Standard and custom requested values have separate persisted modes; physical component costs retain snapshots. Existing stock queries now include requested composition. An existing PostgreSQL row-lock issue was corrected with `FOR UPDATE OF products`.

4. **Single Product control.** The separate search input was removed. A native Product select is progressively enhanced into one editable combobox. Focus/click opens its scrollable list; typing filters that same list; mouse, arrows, Enter, and Escape are supported. Native IDs remain the submitted values. Only 60 suggestions are rendered at once; larger lists ask the administrator to narrow the search. A native select remains available without JavaScript.

5. **Category → Type filtering.** Category and Type remain discovery filters on the same Product combobox. Product options are narrowed using actual catalogue relationships. Search filters within those results. Changing a guide clears incompatible selected Products and their options. These guide fields are not duplicate order data.

6. **Direct selection.** With Category/Type unset, the administrator can search all available Products directly. Selecting a result fills Category and Type from that Product's authoritative catalogue metadata. Clearing guide filters allows searching across categories again.

7. **Color loading.** Active colors already assigned to the Product populate a searchable Color control. No arbitrary normal-order colors are accepted. Server validation checks the chosen ID against the selected Product.

8. **Scent loading.** Active assigned scents populate the same shared searchable control. The existing scent/recipe/stock architecture remains in use; IDs are validated again by the server.

9. **Missing Manual Order options fixed.** Both option groups remain visible with applicable choices or an explicit no-configured-options state. Their enhanced controls refresh when native options change. Changing Products clears stale choices, including shared IDs; initial valid saved form choices are preserved. Disabled empty controls do not submit irrelevant values. Catalogue price inputs and production recipe editors remain absent.

10. **Existing Products in requests.** Each requested product can contain components with Source = Existing Product. The component references an active purchasable catalogue record and has its own valid options and quantity per requested product. It does not clone that record.

11. **Existing Items in requests.** Source = Existing Item selects existing physical non-set component records, including accessory Items. This schema represents Items through the same `products` records used by existing `set_components`; no second Item table was invented. The source records the component's role. Raw Materials are not exposed in request selection.

12. **Products + Items together.** A requested assembly may mix both source roles. Conversion keeps separate requested-product order lines, freezes the known aggregate production definition when every component is existing, and reserves physical accessories through the existing stock service. Preparation deducts their stock through the existing ledger. Repeated references aggregate correctly when copied into eligible draft set composition.

13. **Genuinely custom components.** Source = Custom / New component enables a name, quantity, controlled existing Color/Scent choices or explicit Other / Custom values, and specifications. It creates no public Product. Mixed assemblies require a complete definition in the existing production editor; partial existing recipes cannot bypass that requirement.

14. **Multiple requested products.** Existing add/remove requested-product cards remain. Each card has its own add/remove component rows and specifications. Server and controller bound and validate both arrays, IDs, source roles, quantities, and options. The Existing / Guest / New Account customer component remains shared.

15. **Custom Request Color/Scent.** Existing components show only their assigned configured choices. New/custom definitions can search active standard options or deliberately choose Other / Custom to enable a separately labeled custom-value field. Persistence and request detail distinguish `EXISTING`, `CUSTOM`, and `NONE`; hidden custom values cannot silently replace configured selections.

16. **Obsolete/duplicate cleanup.** Removed the standalone Product search field and its separate filter handlers, unconditional free-text Color/Scent entry, and the “all components are new” presentation. No endpoints or unrelated autocomplete implementations were added. The shared dropdown enhancement handles Product, Item, Color, and Scent. Catalogue loading batches option/tier data, and conversion reuses recipe results for repeated component/option combinations.

17. **Tests executed: all passed.**
    - Expanded PostgreSQL pricing/recipe/composition suite: **125 assertions**, including mixed source validation, explicit custom options, complete production gating, inactive drafts, preserved references, accessory commitments/deductions, and frozen costs.
    - Authenticated manual/request HTTP integration, including the absence of a separate search input, all three searchable normal-order controls, mixed composition controls, customer modes, conversion, CSRF, and draft action.
    - Shared combobox Node suite: focus/click, typing, bounded suggestions, mouse selection, Arrow Up/Down, Enter, Escape, empty states, dynamic option replacement, screen-reader labels, and validation.
    - Product guide/option/multiple-row and customer-mode Node suites.
    - Existing order-edit **44 checks**, scent/inventory **19 checks**, customer-payment **20 checks**, checkout, and pricing-rule suites.
    - PHP syntax checks, JavaScript syntax checks, and `git diff --check`.
    PostgreSQL tests use disposable isolated schemas. SQLite tests passed with the extension explicitly enabled; checkout emits an existing PHP 8.5 fixture deprecation warning.

18. **Manual browser verification still required.** No visual browser verification was performed. Check desktop/mobile field widths, dropdown scrolling/placement, focus indicators, screen-reader announcements, Category/Type changes, Product-dependent options, and add/remove requested-product/component rows in a real browser. Search datasets are loaded locally, so no remote loading state is needed. Drafts stay inactive and require normal Catalogue completion/activation. Existing flat-set rules are preserved: nested sets and component quantities beyond Catalogue's 1000-unit limit stay referenced on the request and require explicit draft composition completion. Full aggregated recipes are not copied alongside referenced set components, which would double-count production materials. Custom component production and public options remain administrator-reviewed Catalogue work.
