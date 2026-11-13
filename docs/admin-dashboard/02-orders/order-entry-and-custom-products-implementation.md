# HOM’AS order entry and custom product implementation

Implemented in the existing PHP/PostgreSQL application. Migration 024 was applied to the local database. Existing order, payment, production, customer, and catalogue workflows remain the foundation.

1. **Files changed.**
   - Views: `app/Views/Pages/admin/orders/create.php`, `admin-order-detail.php`; `app/Views/Pages/admin/requests/create.php`, `admin-custom-request-convert.php`, `admin-custom-request-detail.php`; new `app/Views/Components/Admin/custom-request-products.php`.
   - UI: `public/assets/js/admin/manual-order.js`, `admin-selects.js`, `custom_select.js`; new `admin-custom-request.js`; `public/assets/css/pages/admin.css`.
   - Controller/route: `src/Controllers/ManualOrderController.php`, `CustomRequestController.php`; `public/admin/requests/action.php`.
   - Repositories: `src/Repositories/OrderProductionRepository.php`, `CustomRequestRepository.php`, `AdminProductRepository.php`.
   - Services: `src/Services/ManualOrderService.php`, `OrderPricingService.php`, `CustomRequestService.php`, `CustomRequestConversionService.php`, `OrderService.php`, `AdminReportService.php`.
   - Schema/tests: `app/database/migrations/024_custom_request_products.sql`; `tests/Integration/verify-order-pricing-snapshots.php`, `verify-manual-order-customers.php`, `verify-customer-payments.php`; new `tests/Unit/verify-order-product-selection.js`; this report.

2. **Existing services reused.** `CustomerModeService`, `CustomerAccountRepository`, the shared customer picker, `OrderPricingService::unitPrice/quote/total/deposit`, `OrderProductionService::catalogueRecipe/recipeCostMillis/saveItemRecipe`, the existing request conversion transaction, order repositories/status service, `TaxonomyRepository`, `AdminProductRepository::insertCore/upsertTranslation`, `ProductRecipeRepository::insert`, and normal product readiness/activation.

3. **Existing functions improved.** Catalogue loading now includes category/type context and fetches quantity tiers in one batch instead of per product. Manual preview uses existing recipe costs and validates product options. Request creation/conversion accept bounded product arrays. Existing custom selects refresh dynamically and initialize added rows. The reused translation upsert now uses PostgreSQL `ON CONFLICT`.

4. **Necessary new functions.** Child-definition persistence and order/draft links represent the missing one-request-to-many-new-products relationship. `createDrafts` coordinates the explicit completed-production action. `bulkFrom` reads existing configured pricing; `AdminReportService::grossEstimate` centralizes existing profit subtraction and formats margin for admin screens. No new search API, customer creator, sale-price engine, production recipe store, or order-total engine was created.

5. **Obsolete code removed.** Catalogue price override controls, browser override handling, server override acceptance, and normal-order HTTP price/name/recipe input allowlisting were removed. Custom Request creation no longer duplicates global category/quantity/color/scent controls already captured per product. Historical override audit columns remain for existing records.

6. **Category → Type → Product.** Category narrows types using actual catalogue product relationships; both selections narrow the product list. Changing a guide clears direct search and stale product/options. Types in this schema are independent taxonomy records; new custom definitions use the existing active type choices without inventing category/type associations.

7. **Direct product search.** Typing searches loaded product, category, and type names across the catalogue regardless of current guide filters. Product labels include context to distinguish repeated names. Selection fills Category and Type automatically. The existing keyboard-accessible, scrollable custom select is reused; a live result count accompanies the search.

8. **Product options.** Only active colors and scents already linked to the selected product appear. Irrelevant controls are hidden/disabled. Backend preview and saving validate their IDs and relationships again.

9. **Quantity pricing.** Preview and persistence call the existing authoritative `unitPrice` function. Standard price, indicative bulk minimum when a genuine lower price exists, applied unit price, and line total are displayed. Discounts, fees, final total, and required deposit use the existing sale calculation.

10. **Deferred bulk work.** No new tier formula or rules were invented. Existing configured tiers and event-favor pricing remain intact. A broader redesign of bulk pricing is intentionally deferred.

11. **Manual unit-price editing.** Removed for normal catalogue products, including exceptional overrides. Forged obsolete fields cannot replace server prices. Negotiated batch/unit prices remain valid on Custom Request conversion.

12. **Normal production recipe.** Normal order entry has no recipe editor. Catalogue recipes are captured automatically by existing snapshot logic; configuration stays in Catalogue. Completed/historical order snapshots are preserved.

13. **Multiple normal products.** Existing 1–50 product rows retain independent options, quantities, prices, and recipe snapshots. Add/remove controls now use Product terminology, with safe field renumbering.

14. **Multiple NEW custom products.** The form supports 1–50 separate new definitions: working name, active category/type, quantity, color/scent preferences, and specifications. There is no existing-product selector. Conversion requires every stored definition exactly once and creates separate order lines in one transaction. Legacy single-product requests retain their established conversion path.

15. **Customer modes.** The same Existing / Guest / New Account component and services are reused. Existing requires explicit account selection; Guest keeps a null customer link; New uses secure existing account/address creation and duplicate checks. Only the selected mode is visible and enabled.

16. **Custom → catalogue draft.** After the linked order has reached the existing `PREPARED` state, an authenticated explicit request action creates one catalogue draft per custom definition. It copies the working category/type/name/specifications and the completed per-unit production recipe through existing catalogue repositories. Parent request locking, unique links, and transactions make retries idempotent. The completed order is not rewritten.

17. **Drafts are not automatically public.** `insertCore` creates them with `is_active=0`. Active order selection and public catalogue queries exclude them. No new custom-publication status was added.

18. **Activation.** The administrator follows the draft link into the normal Catalogue editor, completes required information, and uses existing Activate. Normal `toggleActive` and readiness checks reject incomplete drafts. Images, translations, price inputs, public options, and composition follow actual existing catalogue requirements.

19. **Admin estimates.** Creation shows estimated product cost, gross profit, and gross margin in a secondary Admin estimate disclosure. Viewing an order uses frozen `unit_cost_snapshot × quantity`; missing historical costs show Unavailable. Shared report arithmetic is reused. Discounts reduce product revenue; delivery fees are excluded on order screens because delivery expenses are not recorded by this model. This is not net profit. No customer response fields were added.

20. **Customer total hierarchy.** “Total Customer Pays” is the emphasized payment total. Internal estimates have smaller text, a separate disclosure, and explicit estimated-gross wording. Payment/deposit workflows continue to read authoritative saved order totals.

21. **Migration.** `024_custom_request_products.sql` adds only the genuinely missing child-definition table, with request/taxonomy foreign keys, bounded quantities/positions, unique order-item and draft-product links, and specifications limits. It does not duplicate orders, products, payments, or recipes. Applied locally with `scripts/migrate.php`; migrations 001–023 were skipped as already applied.

22. **Validation results.** All passed:
   - Pricing/recipe/custom lifecycle integration: **96 assertions**, including real `PREPARED` transitions, separate drafts, idempotency, public invisibility, activation rejection, forgery rejection, and frozen historical costs.
   - Manual/customer HTTP integration, including all three request customer modes, new-definition creation/conversion, CSRF rejection, and completed-production draft action.
   - Order edit: **44 checks**; pricing workflow: **18 checks**; scent/inventory availability: **19 checks**; customer payments: **20 checks**, including absence of admin profit/cost fields.
   - Existing checkout and pricing-rule SQLite suites; both Node customer-mode and product-selector behavior suites.
   - PHP syntax checks for all changed/new PHP files, JavaScript syntax checks, and `git diff --check`.
   PostgreSQL integration tests use isolated disposable schemas. SQLite tests ran with the local PDO SQLite extension explicitly enabled. Checkout passed with an existing PHP 8.5 deprecation warning about its SQLite fixture function API. Browser visual verification was not performed.

23. **Assumptions / remaining work.** Custom production recipes are defined on converted order lines through the existing production editor before preparation; no second pre-order recipe engine was introduced. Generated drafts intentionally need administrator completion and approval through normal activation. The selector uses the existing loaded catalogue; no duplicate search endpoint was added. Bulk-pricing redesign remains deferred. The repository has no Sites configuration, so Sites guidance was used for the UI work without replatforming or publishing this PHP/PostgreSQL application.
